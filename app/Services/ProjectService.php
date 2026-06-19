<?php

namespace App\Services;

use App\Enums\ChunkStatus;
use App\Enums\ProjectStatus;
use App\Models\ApiKey;
use App\Models\TtsChunk;
use App\Models\TtsProject;
use App\Models\Voice;
use App\Services\Audio\AudioConverter;
use App\Services\Tts\TtsProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Orchestrates editable TTS projects. Mirrors the segment → synthesize → trim →
 * concatenate flow of {@see SpeechService::process()}, but PERSISTS each chunk's
 * raw audio so that:
 *
 *   - editing one sentence only re-synthesizes that one chunk, and
 *   - the final file is rebuilt by concatenating stored chunk audio locally
 *     (ffmpeg only, no provider calls).
 *
 * Chunk audio is stored RAW (the provider's WAV output, pre-trim) so {@see
 * AudioConverter::concatenate()} can apply the same trim + seam silence at
 * rebuild time that production applies.
 */
class ProjectService
{
    public function __construct(
        private TtsProvider $provider,
        private AudioConverter $converter,
        private TextChunker $chunker,
        private TextNormalizer $normalizer,
    ) {}

    /**
     * Normalize + chunk the text and create the project with its (ungenerated)
     * chunk rows.
     *
     * @param  array<string, mixed>  $settings
     */
    public function createFromText(
        string $title,
        Voice $voice,
        string $text,
        array $settings,
        string $modelId,
        string $outputFormat,
        ?int $seed,
        ?ApiKey $apiKey = null,
    ): TtsProject {
        $normalized = $this->normalizer->normalize($text);
        $segments = $this->segmentText($normalized);

        $project = TtsProject::create([
            'api_key_id' => $apiKey?->id,
            'title' => $title !== '' ? $title : 'Untitled project',
            'voice_id' => $voice->id,
            'settings' => $settings,
            'model_id' => $modelId,
            'output_format' => $outputFormat,
            'seed' => $seed,
            'source_text' => $text,
            'normalized_text' => $normalized,
            'status' => ProjectStatus::Draft,
        ]);

        $this->createChunks($project, $segments);

        return $project;
    }

    /**
     * Re-chunk a (possibly edited) project's text from scratch: normalize +
     * chunk the new text, delete every existing chunk and all stored audio,
     * recreate fresh (ungenerated) chunk rows, and return the project to Draft.
     * Destructive — all generated audio is discarded. Voice/settings/seed are
     * left untouched.
     */
    public function resetFromText(TtsProject $project, string $text): TtsProject
    {
        $normalized = $this->normalizer->normalize($text);
        $segments = $this->segmentText($normalized);

        // Mutate the rows in a transaction; only wipe audio off disk once it
        // commits, so a failed re-chunk can't leave rows pointing at deleted files.
        DB::transaction(function () use ($project, $text, $normalized, $segments) {
            $project->chunks()->delete();
            $this->createChunks($project, $segments);
            $project->update([
                'source_text' => $text,
                'normalized_text' => $normalized,
                'final_audio_path' => null,
                'mime_type' => null,
                'status' => ProjectStatus::Draft,
            ]);
        });

        Storage::disk($this->disk())->deleteDirectory(config('tts.storage_path').'/projects/'.$project->id);

        return $project->refresh();
    }

    /**
     * Insert a new (empty by default, ungenerated) chunk at $position, shifting
     * every chunk at or after it down by one. Audio is keyed by chunk id, not
     * position, so renumbering never moves files on disk.
     */
    public function insertChunk(TtsProject $project, int $position, string $text = ''): TtsChunk
    {
        $chunk = DB::transaction(function () use ($project, $position, $text) {
            $project->chunks()->where('position', '>=', $position)->increment('position');

            return $project->chunks()->create([
                'position' => $position,
                'text' => $text,
                'break_after' => 'sentence',
                'status' => ChunkStatus::Pending,
                'characters' => mb_strlen($text),
            ]);
        });

        $this->markFinalOutdated($project);

        return $chunk;
    }

    /**
     * Synthesize one chunk and store its raw audio. Used for both first
     * generation and regeneration after an edit. Marks the project's final file
     * out of date. Throws on provider failure (after recording it on the chunk).
     */
    public function generateChunk(TtsChunk $chunk, bool $reroll = false): TtsChunk
    {
        $project = $chunk->project;

        try {
            $bytes = $this->provider->synthesize(
                $chunk->text,
                $this->referencePath($project->voice),
                $this->providerSettings($project, $chunk, pinSeed: ! $reroll),
            );

            $path = $this->chunkPath($chunk);
            Storage::disk($this->disk())->put($path, $bytes);

            $chunk->update([
                'audio_path' => $path,
                'status' => ChunkStatus::Completed,
                'error_message' => null,
            ]);

            $this->markFinalOutdated($project);
        } catch (Throwable $e) {
            $chunk->update([
                'status' => ChunkStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $chunk;
    }

    /**
     * Set a chunk's per-chunk tuning override (stability/style); a null value
     * clears that key so it falls back to the project's setting. A generated
     * chunk goes Stale (its audio no longer matches the tuning) and the final
     * file is flagged out of date; an ungenerated chunk is left as-is.
     */
    public function updateChunkTuning(TtsChunk $chunk, ?float $stability, ?float $style): TtsChunk
    {
        $settings = is_array($chunk->settings) ? $chunk->settings : [];
        foreach (['stability' => $stability, 'style' => $style] as $key => $value) {
            if ($value !== null) {
                $settings[$key] = $value;
            } else {
                unset($settings[$key]);
            }
        }

        $attributes = ['settings' => $settings ?: null];
        if ($chunk->status === ChunkStatus::Completed) {
            $attributes['status'] = ChunkStatus::Stale;
        }
        $chunk->update($attributes);

        if ($chunk->audio_path) {
            $this->markFinalOutdated($chunk->project);
        }

        return $chunk;
    }

    /**
     * Update a chunk's text. The new text is re-chunked with the same budget
     * used at creation: if it still fits one chunk, it is stored in place; if it
     * grew beyond the budget, the chunk is split — it keeps the first segment and
     * the remainder become new chunks inserted right after it (the surrounding
     * chunks' audio is untouched, since audio is keyed by chunk id). Either way
     * the edited chunk's stored audio (if any) no longer matches, so it is marked
     * Stale and the project's final file is flagged out of date.
     *
     * @return array{chunk: TtsChunk, created: int} The (still position-0) edited
     *                                              chunk and how many new chunks the split added.
     */
    public function updateChunkText(TtsChunk $chunk, string $text): array
    {
        $segments = $this->segmentText($text);

        // A never-generated chunk stays Pending; a generated one goes Stale.
        $editedStatus = $chunk->audio_path ? ChunkStatus::Stale : ChunkStatus::Pending;

        // Common case: the edit still fits a single chunk — store it in place.
        if (count($segments) <= 1) {
            $chunk->update([
                'text' => $text,
                'characters' => mb_strlen($text),
                'status' => $editedStatus,
            ]);

            $this->markFinalOutdated($chunk->project);

            return ['chunk' => $chunk, 'created' => 0];
        }

        // Over-budget edit: split into segments. The edited chunk keeps the first
        // segment; the rest are inserted as new pending chunks after it.
        $project = $chunk->project;
        $position = $chunk->position;
        $extra = array_slice($segments, 1);
        // The chunker runs on the edited text in isolation, so its last segment is
        // always a 'sentence' seam — preserve the boundary to the FOLLOWING sibling
        // by giving the final new chunk the edited chunk's original break_after.
        $originalBreakAfter = $chunk->break_after;

        DB::transaction(function () use ($project, $chunk, $segments, $extra, $position, $editedStatus, $originalBreakAfter) {
            $project->chunks()->where('position', '>', $position)->increment('position', count($extra));

            $chunk->update([
                'text' => $segments[0]['text'],
                'characters' => mb_strlen($segments[0]['text']),
                'break_after' => $segments[0]['breakAfter'],
                'status' => $editedStatus,
            ]);

            foreach (array_values($extra) as $i => $segment) {
                $isLast = $i === count($extra) - 1;
                $project->chunks()->create([
                    'position' => $position + 1 + $i,
                    'text' => $segment['text'],
                    'break_after' => $isLast ? $originalBreakAfter : $segment['breakAfter'],
                    'status' => ChunkStatus::Pending,
                    'characters' => mb_strlen($segment['text']),
                ]);
            }
        });

        $this->markFinalOutdated($project);

        return ['chunk' => $chunk, 'created' => count($extra)];
    }

    /**
     * Concatenate the chunks' stored raw audio (in order) into the project's
     * final file — local ffmpeg only, no provider calls. Requires every chunk to
     * have audio; reports how many are missing otherwise.
     */
    public function rebuild(TtsProject $project): TtsProject
    {
        $chunks = $project->chunks()->get();

        if ($chunks->isEmpty()) {
            throw new RuntimeException('This project has no chunks.');
        }

        $missing = $chunks->whereNull('audio_path')->count();
        if ($missing > 0) {
            throw new RuntimeException("{$missing} chunk(s) still need to be generated before rebuilding.");
        }

        [$bytes, $mime, $ext] = $this->concatenateChunks($chunks, $project->output_format);

        $path = config('tts.storage_path').'/projects/'.$project->id.'/final.'.$ext;
        Storage::disk($this->disk())->put($path, $bytes);

        $project->update([
            'final_audio_path' => $path,
            'mime_type' => $mime,
            'status' => ProjectStatus::Ready,
        ]);

        return $project;
    }

    /**
     * Stitch a SUBSET of a project's chunks (in document order) the way rebuild
     * does — same trim + seam — and return the bytes WITHOUT persisting. Lets the
     * editor audition how two adjacent chunks join at their seam.
     *
     * @param  array<int, string>  $chunkIds
     * @return array{0: string, 1: string} [bytes, mimeType]
     */
    public function previewConcat(TtsProject $project, array $chunkIds): array
    {
        $chunks = $project->chunks()
            ->whereIn('id', $chunkIds)
            ->whereNotNull('audio_path')
            ->orderBy('position')
            ->get();

        if ($chunks->isEmpty()) {
            throw new RuntimeException('Select at least one generated chunk to preview.');
        }

        [$bytes, $mime] = $this->concatenateChunks($chunks, $project->output_format);

        return [$bytes, $mime];
    }

    /**
     * Concatenate chunks' stored raw audio (in their given order) with the right
     * seam silence per chunk. Shared by rebuild() and previewConcat().
     *
     * @param  Collection<int, TtsChunk>  $chunks
     * @return array{0: string, 1: string, 2: string} [bytes, mimeType, extension]
     */
    private function concatenateChunks($chunks, string $outputFormat): array
    {
        $sentenceGap = (int) config('tts.chunk_gap_ms', 120);
        $paragraphGap = (int) config('tts.paragraph_gap_ms', 400);
        $disk = Storage::disk($this->disk());

        $rawParts = [];
        $seamGapsMs = [];
        foreach ($chunks as $chunk) {
            $rawParts[] = $disk->get($chunk->audio_path);
            $seamGapsMs[] = $chunk->break_after === 'paragraph' ? $paragraphGap : $sentenceGap;
        }

        return $this->converter->concatenate(
            $rawParts,
            $outputFormat,
            $this->provider->outputContainer(),
            $seamGapsMs,
        );
    }

    public function chunkAudioBytes(TtsChunk $chunk): ?string
    {
        if (! $chunk->audio_path || ! Storage::disk($this->disk())->exists($chunk->audio_path)) {
            return null;
        }

        return Storage::disk($this->disk())->get($chunk->audio_path);
    }

    public function finalAudioBytes(TtsProject $project): ?string
    {
        if (! $project->final_audio_path || ! Storage::disk($this->disk())->exists($project->final_audio_path)) {
            return null;
        }

        return Storage::disk($this->disk())->get($project->final_audio_path);
    }

    /** Delete a project, its chunks (cascade), and all of its stored audio. */
    public function deleteProject(TtsProject $project): void
    {
        Storage::disk($this->disk())->deleteDirectory(config('tts.storage_path').'/projects/'.$project->id);

        $project->delete();
    }

    /**
     * Chunk already-normalized text with the project's standard budget.
     *
     * @return array<int, array{text: string, breakAfter: string}>
     */
    private function segmentText(string $normalized): array
    {
        return $this->chunker->segment(
            $normalized,
            (int) config('tts.chunk_chars', 280),
            (int) config('tts.block_space_run', 4),
            (int) config('tts.min_chunk_chars', 30),
            (int) config('tts.short_trailer_words', 3),
        );
    }

    /**
     * Create ungenerated chunk rows for the given segments, numbering them from
     * $startPosition. Does NOT shift existing chunks — callers that insert into
     * the middle must open the slots themselves first.
     *
     * @param  array<int, array{text: string, breakAfter: string}>  $segments
     */
    private function createChunks(TtsProject $project, array $segments, int $startPosition = 0): void
    {
        foreach (array_values($segments) as $i => $segment) {
            $project->chunks()->create([
                'position' => $startPosition + $i,
                'text' => $segment['text'],
                'break_after' => $segment['breakAfter'],
                'status' => ChunkStatus::Pending,
                'characters' => mb_strlen($segment['text']),
            ]);
        }
    }

    /**
     * Flag the project's final file as out of date after a chunk change. A
     * project with no final yet stays Draft.
     */
    private function markFinalOutdated(TtsProject $project): void
    {
        if ($project->final_audio_path && $project->status !== ProjectStatus::Stale) {
            $project->update(['status' => ProjectStatus::Stale]);
        }
    }

    /**
     * Provider settings for one chunk: the project's resolved settings overlaid
     * with the chunk's own stability/style override (Phase 2), plus the pinned
     * seed. A re-roll passes $pinSeed = false so the provider picks a fresh random
     * seed — a new "take" to escape a bad generation without editing the text.
     *
     * @return array<string, mixed>
     */
    private function providerSettings(TtsProject $project, ?TtsChunk $chunk = null, bool $pinSeed = true): array
    {
        $settings = is_array($project->settings) ? $project->settings : [];

        if ($chunk && is_array($chunk->settings)) {
            $settings = array_merge($settings, $chunk->settings);
        }

        if ($pinSeed && $project->seed !== null) {
            $settings['seed'] = $project->seed;
        }

        return $settings;
    }

    private function referencePath(?Voice $voice): ?string
    {
        if (! $voice || ! $voice->reference_audio_path) {
            return null;
        }

        return Storage::disk($this->disk())->path($voice->reference_audio_path);
    }

    private function chunkPath(TtsChunk $chunk): string
    {
        return config('tts.storage_path').'/projects/'.$chunk->tts_project_id.'/chunks/'.$chunk->id.'.wav';
    }

    private function disk(): string
    {
        return config('tts.storage_disk');
    }
}
