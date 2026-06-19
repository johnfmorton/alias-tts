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
        $segments = $this->chunker->segment(
            $normalized,
            (int) config('tts.chunk_chars', 280),
            (int) config('tts.block_space_run', 4),
            (int) config('tts.min_chunk_chars', 30),
            (int) config('tts.short_trailer_words', 3),
        );

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

        foreach ($segments as $i => $segment) {
            $project->chunks()->create([
                'position' => $i,
                'text' => $segment['text'],
                'break_after' => $segment['breakAfter'],
                'status' => ChunkStatus::Pending,
                'characters' => mb_strlen($segment['text']),
            ]);
        }

        return $project;
    }

    /**
     * Synthesize one chunk and store its raw audio. Used for both first
     * generation and regeneration after an edit. Marks the project's final file
     * out of date. Throws on provider failure (after recording it on the chunk).
     */
    public function generateChunk(TtsChunk $chunk): TtsChunk
    {
        $project = $chunk->project;

        try {
            $bytes = $this->provider->synthesize(
                $chunk->text,
                $this->referencePath($project->voice),
                $this->providerSettings($project),
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
     * Update a chunk's text. Its stored audio (if any) no longer matches, so it
     * is marked Stale and the project's final file is flagged out of date.
     */
    public function updateChunkText(TtsChunk $chunk, string $text): TtsChunk
    {
        $chunk->update([
            'text' => $text,
            'characters' => mb_strlen($text),
            // A never-generated chunk stays Pending; a generated one goes Stale.
            'status' => $chunk->audio_path ? ChunkStatus::Stale : ChunkStatus::Pending,
        ]);

        $this->markFinalOutdated($chunk->project);

        return $chunk;
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
     * Flag the project's final file as out of date after a chunk change. A
     * project with no final yet stays Draft.
     */
    private function markFinalOutdated(TtsProject $project): void
    {
        if ($project->final_audio_path && $project->status !== ProjectStatus::Stale) {
            $project->update(['status' => ProjectStatus::Stale]);
        }
    }

    /** @return array<string, mixed> */
    private function providerSettings(TtsProject $project): array
    {
        $settings = is_array($project->settings) ? $project->settings : [];

        if ($project->seed !== null) {
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
