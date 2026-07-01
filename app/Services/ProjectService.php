<?php

namespace App\Services;

use App\Enums\ChunkStatus;
use App\Enums\ProjectStatus;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\TtsChunk;
use App\Models\TtsChunkTake;
use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use App\Services\Asr\AsrClient;
use App\Services\Asr\ChunkQualityVerdict;
use App\Services\Asr\ChunkRemediator;
use App\Services\Audio\AudioConverter;
use App\Services\Pronunciation\PronunciationSubstituter;
use App\Services\Tts\TtsProvider;
use App\Services\Tts\VoiceReference;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        private AsrClient $asr,
        private ChunkRemediator $remediator,
        private PronunciationSubstituter $substituter,
    ) {}

    /**
     * Normalize + chunk the text and create the project with its (ungenerated)
     * chunk rows. `source_text` preserves the writer's ORIGINAL submission (what
     * "Start over" re-opens); any pronunciation respellings are applied only to
     * `normalized_text`/the chunks (what the voice actually reads).
     *
     * @param  array<string, mixed>  $settings
     * @param  list<array{term: string, phonetic: string, match_mode?: string}>  $pronunciationMap
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
        array $pronunciationMap = [],
        ?int $userId = null,
    ): TtsProject {
        $normalized = $this->substituter->apply($this->normalizer->normalize($text), $pronunciationMap)['text'];
        $segments = $this->segmentText($normalized);

        $project = TtsProject::create([
            'api_key_id' => $apiKey?->id,
            // Owner: the signed-in panel user, or the API key's owner. Projects
            // are personal (TtsProjectPolicy) — never leave this null on create.
            'user_id' => $userId ?? $apiKey?->user_id,
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
     * Materialize an editable project from a finished /v1 {@see Speech} — the
     * API → Studio hand-off (see tts.api_project_mode). The project is ungenerated
     * (Draft); the /v1 path keeps no per-chunk audio, so this seeds the text and
     * the admin regenerates. A failure also records the provider error and the
     * segment index that threw (best-effort: normalization may re-chunk slightly).
     * A recovery ('api_failure') project gets a TTL so it's pruned if never
     * touched; an 'always' ('api') project is kept.
     */
    public function createFromSpeech(
        Speech $speech,
        string $origin,
        ?string $failureReason = null,
        ?int $failedChunkIndex = null,
        ?int $seed = null,
    ): TtsProject {
        $snippet = trim(mb_substr((string) $speech->text, 0, 40));

        $project = $this->createFromText(
            title: ($failureReason !== null ? 'API failure' : 'API generation').($snippet !== '' ? ': '.$snippet : ''),
            voice: $speech->voice,
            text: $speech->text,
            settings: is_array($speech->settings) ? $speech->settings : [],
            modelId: $speech->model_id,
            outputFormat: $speech->output_format,
            seed: $seed,
            apiKey: $speech->apiKey,
        );

        $project->update([
            'origin' => $origin,
            'source_speech_id' => $speech->id,
            'failure_reason' => $failureReason,
            'failed_chunk_index' => $failedChunkIndex,
            'expires_at' => $origin === 'api_failure'
                ? now()->addHours((int) config('tts.api_project_ttl_hours', 168))
                : null,
        ]);

        return $project;
    }

    /**
     * Materialize a FULLY-GENERATED project from a SUCCESSFUL /v1 {@see Speech}
     * (api_project_mode=always). Unlike {@see createFromSpeech()} — which seeds an
     * empty project for the admin to regenerate — this persists the work /v1 just
     * did: each already-synthesized segment becomes a Completed chunk holding its
     * raw audio, and the API's concatenated final file is carried across, so the
     * project opens Ready (chunks playable + editable, final built). The chunks are
     * the EXACT segments /v1 read (no normalize, no re-chunk), so every stored
     * audio lines up 1:1 with its text; a later edit still re-rolls only that one
     * chunk. The raw audio is stored the same way {@see generateChunk()} stores it,
     * so Rebuild / per-chunk editing behave identically to a Studio-built project.
     *
     * @param  list<array{text: string, breakAfter: string, audio: string}>  $segments
     */
    public function createGeneratedFromSpeech(Speech $speech, array $segments, ?int $seed = null): TtsProject
    {
        $snippet = trim(mb_substr((string) $speech->text, 0, 40));

        $project = TtsProject::create([
            'api_key_id' => $speech->apiKey?->id,
            'user_id' => $speech->apiKey?->user_id,
            'origin' => 'api',
            'source_speech_id' => $speech->id,
            'title' => 'API generation'.($snippet !== '' ? ': '.$snippet : ''),
            'voice_id' => $speech->voice->id,
            'settings' => is_array($speech->settings) ? $speech->settings : [],
            'model_id' => $speech->model_id,
            'output_format' => $speech->output_format,
            'seed' => $seed,
            // /v1 reads the raw request text (no normalization pass), so the
            // original and "normalized" forms are the same here.
            'source_text' => $speech->text,
            'normalized_text' => $speech->text,
            'status' => ProjectStatus::Draft,
        ]);

        foreach (array_values($segments) as $i => $segment) {
            $chunk = $project->chunks()->create([
                'position' => $i,
                'text' => $segment['text'],
                'break_after' => $segment['breakAfter'],
                'status' => ChunkStatus::Pending,
                'characters' => mb_strlen($segment['text']),
            ]);

            $this->recordTake($chunk, $segment['audio'], 'generate');
        }

        $this->carryFinalAudio($project, $speech);

        return $project->refresh();
    }

    /**
     * Copy a finished Speech's concatenated audio into the project as its final
     * file (an INDEPENDENT copy — the source Speech is pruned on its own TTL) and
     * mark the project Ready. If the Speech audio is already gone, the project is
     * left Draft with its generated chunks; a Rebuild reproduces the final locally
     * from the stored chunk audio.
     */
    private function carryFinalAudio(TtsProject $project, Speech $speech): void
    {
        $disk = Storage::disk($this->disk());

        if (! $speech->audio_path || ! $disk->exists($speech->audio_path)) {
            return;
        }

        $ext = pathinfo($speech->audio_path, PATHINFO_EXTENSION) ?: 'mp3';
        $path = config('tts.storage_path').'/projects/'.$project->id.'/final.'.$ext;
        $disk->put($path, $disk->get($speech->audio_path));

        $project->update([
            'final_audio_path' => $path,
            'mime_type' => $speech->mime_type,
            'status' => ProjectStatus::Ready,
        ]);
    }

    /**
     * Re-chunk a (possibly edited) project's text from scratch: normalize +
     * chunk the new text, delete every existing chunk and all stored audio,
     * recreate fresh (ungenerated) chunk rows, and return the project to Draft.
     * Destructive — all generated audio is discarded. Voice/settings/seed are
     * left untouched. As with create, `source_text` keeps the original text and
     * the dictionary is re-applied only to `normalized_text`/the chunks.
     *
     * @param  list<array{term: string, phonetic: string, match_mode?: string}>  $pronunciationMap
     */
    public function resetFromText(TtsProject $project, string $text, array $pronunciationMap = []): TtsProject
    {
        // Re-chunking throws the old audio away — un-approve first (the snapshot is
        // also removed by the directory wipe below).
        $this->clearSeal($project);

        $normalized = $this->substituter->apply($this->normalizer->normalize($text), $pronunciationMap)['text'];
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
     * Switch the project's voice. Only chunks that INHERIT the project voice
     * (voice_id is null) were generated with the old voice, so just those Completed
     * chunks are marked Stale and the final file is flagged out of date — the
     * editor surfaces this so the user regenerates. Chunks with an explicit
     * per-chunk voice are independent of the project voice and keep their audio.
     * The project's tuning snapshot, seed, and per-chunk overrides are untouched.
     */
    public function changeVoice(TtsProject $project, Voice $voice): TtsProject
    {
        DB::transaction(function () use ($project, $voice) {
            $project->update(['voice_id' => $voice->id]);
            $project->chunks()
                ->whereNull('voice_id')
                ->where('status', ChunkStatus::Completed)
                ->update(['status' => ChunkStatus::Stale]);
        });

        $this->markFinalOutdated($project);

        return $project->refresh()->load('voice');
    }

    /**
     * Set (or clear) a chunk's per-chunk voice override. A null voice restores
     * inheritance of the project voice. A generated chunk goes Stale — its audio
     * was made with the previous voice — and the final file is flagged out of
     * date; an ungenerated chunk is left as-is.
     */
    public function setChunkVoice(TtsChunk $chunk, ?Voice $voice): TtsChunk
    {
        $newVoiceId = $voice?->id;
        if ($newVoiceId === $chunk->voice_id) {
            return $chunk; // no change — don't needlessly stale the chunk
        }

        $attributes = ['voice_id' => $newVoiceId];
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
                $this->referencePath($chunk->voice ?? $project->voice),
                $this->providerSettings($project, $chunk, pinSeed: ! $reroll),
            );

            // Score BEFORE recording so the verdict rides on the take row itself.
            $verdict = $this->asr->enabled()
                ? $this->remediator->score($chunk->text, $bytes, "chunk-{$chunk->id}")
                : null;

            $this->recordTake(
                $chunk,
                $bytes,
                $reroll ? 'reroll' : 'generate',
                $this->tuningOnly(is_array($chunk->settings) ? $chunk->settings : []),
                $verdict,
            );

            if ($verdict !== null && ! $verdict->ok) {
                Log::warning('ASR flagged a generated chunk', [
                    'chunk' => $chunk->id,
                    'problems' => $verdict->problems,
                    'trail_s' => $verdict->trailS,
                    'max_gap_s' => $verdict->maxGapS,
                    'tail_cov' => $verdict->tailCov,
                ]);

                // studio_action=auto remediates. On a MANUAL reroll the user asked
                // for exactly one new take, so never auto-reroll again — but a junk
                // TAIL is a lossless post-speech trim, so still apply that.
                if ($this->asr->studioAction() === 'auto') {
                    $this->autoRemediate($chunk, $bytes, $verdict, allowReroll: ! $reroll);
                }
            }

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
     * Record one synthesized take of a chunk: write its own immutable audio file,
     * insert the take row (tuning override snapshot + optional ASR verdict), prune
     * old takes, and — unless it's a bare preview ($select=false) — point the chunk
     * at it as the current audio. Returns the take. Because the provider is
     * non-deterministic even with a fixed seed, every take's bytes are persisted so
     * none is ever lost ("keep every take").
     *
     * $override is the per-chunk tuning override that was in effect (empty = the
     * chunk inherited the project setting). It's snapshotted so the take list can
     * show what produced it and a later "select" can restore the same knobs.
     *
     * @param  'generate'|'reroll'|'preview'|'use'|'remediate'  $source
     * @param  array<string, mixed>  $override
     * @param  array<string, mixed>  $reportExtra  merged into asr_report (e.g. action=rerolled)
     */
    private function recordTake(
        TtsChunk $chunk,
        string $bytes,
        string $source,
        array $override = [],
        ?ChunkQualityVerdict $verdict = null,
        array $reportExtra = [],
        bool $select = true,
        bool $keepAsrWhenUnscored = false,
    ): TtsChunkTake {
        $takeId = (string) Str::orderedUuid();
        $path = $this->takePath($chunk, $takeId);
        Storage::disk($this->disk())->put($path, $bytes);

        $report = $verdict === null
            ? null
            : ($reportExtra === [] ? $verdict->toArray() : array_merge($verdict->toArray(), $reportExtra));

        $take = $chunk->takes()->create([
            'id' => $takeId,
            'audio_path' => $path,
            'settings' => $override ?: null,
            'source' => $source,
            'asr_score' => $verdict?->score,
            'asr_report' => $report,
            'characters' => mb_strlen($chunk->text),
        ]);

        if ($select) {
            $attributes = [
                'audio_path' => $path,
                'status' => ChunkStatus::Completed,
                'error_message' => null,
            ];
            // The stored audio changed, so the chunk's ASR must reflect THIS take:
            // its verdict, or null when unscored — except an auto-remediation whose
            // re-roll couldn't be scored, which keeps the original verdict.
            if ($verdict !== null) {
                $attributes['asr_score'] = $verdict->score;
                $attributes['asr_report'] = $report;
            } elseif (! $keepAsrWhenUnscored) {
                $attributes['asr_score'] = null;
                $attributes['asr_report'] = null;
            }
            $chunk->update($attributes);
        }

        $this->pruneTakes($chunk);

        return $take;
    }

    /**
     * Retention: keep the newest `tts.takes.keep` committed takes and the newest
     * `tts.takes.keep_preview` previews per chunk (previews are cheap auditions, so
     * pruned harder), always preserving the currently-selected take. Older takes'
     * rows and files are deleted; anything pruned is logged.
     */
    private function pruneTakes(TtsChunk $chunk): void
    {
        $keep = max(1, (int) config('tts.takes.keep', 10));
        $keepPreview = max(0, (int) config('tts.takes.keep_preview', 3));

        $selectedPath = $chunk->audio_path;
        $all = $chunk->takes()->get(); // newest first
        $candidates = $all->reject(fn (TtsChunkTake $t) => $t->audio_path === $selectedPath);

        [$previews, $committed] = $candidates->partition(fn (TtsChunkTake $t) => $t->source === 'preview');
        $doomed = $previews->slice($keepPreview)->concat($committed->slice($keep))->values();

        if ($doomed->isEmpty()) {
            return;
        }

        $doomedIds = $doomed->pluck('id')->all();
        $survivingPaths = $all->reject(fn (TtsChunkTake $t) => in_array($t->id, $doomedIds, true))
            ->pluck('audio_path')->all();

        $disk = Storage::disk($this->disk());
        foreach ($doomed as $take) {
            // Never delete a file another surviving take still references (guards the
            // in-place legacy file that may share the chunk's old path).
            if (! in_array($take->audio_path, $survivingPaths, true)) {
                $disk->delete($take->audio_path);
            }
            $take->delete();
        }

        Log::info('Pruned chunk takes', [
            'chunk' => $chunk->id,
            'pruned' => $doomed->count(),
            'previews' => $previews->slice($keepPreview)->count(),
            'committed' => $committed->slice($keep)->count(),
        ]);
    }

    /**
     * Act on a flagged chunk under action=auto via the shared remediator (re-roll
     * missing content keeping the best take, or precise-trim a junk tail), then
     * record the winning take + what was done as the chunk's selected audio.
     */
    private function autoRemediate(TtsChunk $chunk, string $bytes, ChunkQualityVerdict $verdict, bool $allowReroll = true): void
    {
        $project = $chunk->project;

        $outcome = $this->remediator->remediate(
            $chunk->text,
            $bytes,
            $verdict,
            fn (): string => $this->provider->synthesize(
                $chunk->text,
                $this->referencePath($chunk->voice ?? $project->voice),
                $this->providerSettings($project, $chunk, pinSeed: false),
            ),
            "chunk-{$chunk->id}",
            $allowReroll,
        );

        // Nothing was applied (a manual re-roll whose defect needs a fresh take,
        // which we won't force): leave the take the user asked for in place.
        if ($outcome->action === 'none') {
            return;
        }

        // A null outcome verdict means a re-roll couldn't be scored (sidecar
        // dropped): keep the new audio but leave the original verdict on the chunk.
        $this->recordTake(
            $chunk,
            $outcome->bytes,
            'remediate',
            $this->tuningOnly(is_array($chunk->settings) ? $chunk->settings : []),
            $outcome->verdict,
            $outcome->verdict !== null ? $outcome->reportExtra() : [],
            keepAsrWhenUnscored: true,
        );

        Log::info('ASR auto-remediated a chunk', [
            'chunk' => $chunk->id,
            'action' => $outcome->action,
            'attempts' => $outcome->rerollAttempts,
        ]);
    }

    /**
     * Set a chunk's per-chunk tuning override; a null value clears that key so it
     * falls back to the project's setting. A generated chunk goes Stale (its audio
     * no longer matches the tuning) and the final file is flagged out of date; an
     * ungenerated chunk is left as-is.
     *
     * @param  array<string, float|null>  $override  the typed knobs (null = clear)
     */
    public function updateChunkTuning(TtsChunk $chunk, array $override): TtsChunk
    {
        $settings = $this->applyOverride(is_array($chunk->settings) ? $chunk->settings : [], $override);

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
     * Synthesize one chunk at a transient stability/style override (A/B preview)
     * and return the raw audio bytes. The chunk's stored override, current audio,
     * and status are NOT touched — but the preview is saved as a NON-selected take
     * (capture-every-take) so it appears in the chunk's history and can be selected
     * later. Lets the user audition a candidate tuning before committing it.
     *
     * @param  array<string, float|null>  $override  the typed knobs (null = inherit)
     */
    public function previewChunkTuning(TtsChunk $chunk, array $override): string
    {
        $project = $chunk->project;
        $settings = is_array($project->settings) ? $project->settings : [];
        $typed = array_filter($override, fn ($v) => $v !== null);
        foreach ($typed as $key => $value) {
            $settings[$key] = $value;
        }
        if ($project->seed !== null) {
            $settings['seed'] = $project->seed;
        }

        $bytes = $this->provider->synthesize($chunk->text, $this->referencePath($chunk->voice ?? $project->voice), $settings);

        $this->recordTake($chunk, $bytes, 'preview', $this->tuningOnly($typed), select: false);

        return $bytes;
    }

    /**
     * "Use this take": store the exact preview bytes the user auditioned (uploaded
     * back from the browser) as the chunk's selected audio, recording the override
     * it was previewed at and marking the chunk Completed. Re-scores ASR for the
     * new audio (score only — never auto-remediate; the admin chose this take
     * deliberately). Because the provider is non-deterministic even with a fixed
     * seed, promoting the actual bytes is the only way to keep a good preview.
     *
     * @param  array<string, float|null>  $override  the typed knobs (null = clear)
     */
    public function useChunkPreview(TtsChunk $chunk, string $bytes, array $override): TtsChunk
    {
        $settings = $this->applyOverride(is_array($chunk->settings) ? $chunk->settings : [], $override);
        $chunk->update(['settings' => $settings ?: null]);

        $verdict = $this->asr->enabled()
            ? $this->remediator->score($chunk->text, $bytes, "chunk-{$chunk->id}")
            : null;

        $this->recordTake($chunk, $bytes, 'use', $this->tuningOnly($settings), $verdict);

        $this->markFinalOutdated($chunk->project);

        return $chunk;
    }

    /** The container/format of raw provider audio (e.g. "wav"). */
    public function providerContainer(): string
    {
        return $this->provider->outputContainer();
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

        // New bytes replace whatever was approved — drop the seal so it must be
        // re-approved deliberately. The project lands Ready + unsealed.
        $this->clearSeal($project);

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

    /** The frozen, approved snapshot bytes (what the receipt ships), or null. */
    public function sealedAudioBytes(TtsProject $project): ?string
    {
        if (! $project->sealed_audio_path || ! Storage::disk($this->disk())->exists($project->sealed_audio_path)) {
            return null;
        }

        return Storage::disk($this->disk())->get($project->sealed_audio_path);
    }

    /**
     * Seal the current final as the human-approved cut: snapshot the exact bytes
     * to an immutable path and record their SHA-256 + the approver + the moment.
     * Requires a Ready project with a final (we seal what was approved, never
     * auto-rebuild). Re-sealing identical bytes just refreshes the stamp.
     */
    public function seal(TtsProject $project, User $approver): TtsProject
    {
        if ($project->status !== ProjectStatus::Ready) {
            throw new RuntimeException('Only a ready project can be sealed — rebuild the final first.');
        }

        $bytes = $this->finalAudioBytes($project);
        if ($bytes === null) {
            throw new RuntimeException('This project has no final audio to seal.');
        }

        $sha = hash('sha256', $bytes);

        // Idempotent: re-sealing the same bytes just re-stamps who/when.
        if ($project->isSealed() && $project->final_sha256 === $sha) {
            $project->update([
                'sealed_at' => now(),
                'sealed_by_id' => $approver->id,
                'sealed_by_name' => $approver->name,
                'sealed_by_email' => $approver->email,
            ]);

            return $project->refresh();
        }

        // Clear any prior seal (and its snapshot) before writing the new one.
        $this->clearSeal($project);

        $ext = pathinfo((string) $project->final_audio_path, PATHINFO_EXTENSION) ?: 'mp3';
        $path = config('tts.storage_path').'/projects/'.$project->id.'/sealed/'.$sha.'.'.$ext;
        Storage::disk($this->disk())->put($path, $bytes);

        $project->update([
            'final_sha256' => $sha,
            'final_bytes' => strlen($bytes),
            'sealed_audio_path' => $path,
            'sealed_at' => now(),
            'sealed_by_id' => $approver->id,
            'sealed_by_name' => $approver->name,
            'sealed_by_email' => $approver->email,
        ]);

        return $project->refresh();
    }

    /**
     * Drop a project's seal: delete the frozen snapshot and null the seal columns.
     * A no-op when unsealed. Called whenever the audio changes (edit/rebuild/reset)
     * so a stale "approved" claim can never outlive the bytes it pointed at.
     */
    private function clearSeal(TtsProject $project): void
    {
        if ($project->sealed_at === null) {
            return;
        }

        if ($project->sealed_audio_path) {
            Storage::disk($this->disk())->delete($project->sealed_audio_path);
        }

        $project->update([
            'final_sha256' => null,
            'final_bytes' => null,
            'sealed_audio_path' => null,
            'sealed_at' => null,
            'sealed_by_id' => null,
            'sealed_by_name' => null,
            'sealed_by_email' => null,
        ]);
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
        // A chunk change un-approves the final — clear the seal unconditionally,
        // before the status guard below (which early-returns when there's no final
        // yet or the project is already stale).
        $this->clearSeal($project);

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
        return VoiceReference::localPath($voice);
    }

    /** A take's own immutable file (one per render, never overwritten). */
    private function takePath(TtsChunk $chunk, string $takeId): string
    {
        return config('tts.storage_path').'/projects/'.$chunk->tts_project_id.'/chunks/'.$chunk->id.'/takes/'.$takeId.'.wav';
    }

    /**
     * Keep only the tuning knobs from a settings array (drops seed and the
     * ElevenLabs-compat keys that don't affect the Studio panel). Handles both the
     * EL form (stability/style) and the native form (exaggeration/cfg_weight).
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function tuningOnly(array $settings): array
    {
        return array_intersect_key($settings, array_flip(['stability', 'style', 'exaggeration', 'cfg_weight']));
    }

    /** Native knob -> the ElevenLabs key it supersedes once the Studio writes native. */
    private const EL_TWIN = ['exaggeration' => 'style', 'cfg_weight' => 'stability'];

    /**
     * Apply a per-chunk tuning override onto a settings map: set each non-null
     * knob, clear each null one, and — when a native knob is written — drop its
     * stale ElevenLabs twin so a chunk never carries both forms (the Studio speaks
     * native; the provider would prefer native and ignore the EL key anyway).
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, float|null>  $override
     * @return array<string, mixed>
     */
    private function applyOverride(array $settings, array $override): array
    {
        foreach ($override as $key => $value) {
            if ($value !== null) {
                $settings[$key] = $value;
                if (isset(self::EL_TWIN[$key])) {
                    unset($settings[self::EL_TWIN[$key]]);
                }
            } else {
                unset($settings[$key]);
            }
        }

        return $settings;
    }

    public function takeAudioBytes(TtsChunkTake $take): ?string
    {
        if (! $take->audio_path || ! Storage::disk($this->disk())->exists($take->audio_path)) {
            return null;
        }

        return Storage::disk($this->disk())->get($take->audio_path);
    }

    /**
     * Make a saved take the chunk's current audio: point audio_path at its file
     * and carry its ASR verdict (so the badge describes the audio you'll now hear).
     * The chunk's tuning override is left untouched — selecting a take chooses its
     * SOUND, not its settings; each take shows the tuning it was rendered at in the
     * list. Marks the final file out of date.
     */
    public function selectTake(TtsChunkTake $take): TtsChunk
    {
        $chunk = $take->chunk;

        $chunk->update([
            'audio_path' => $take->audio_path,
            'status' => ChunkStatus::Completed,
            'error_message' => null,
            'asr_score' => $take->asr_score,
            'asr_report' => $take->asr_report,
        ]);

        $this->markFinalOutdated($chunk->project);

        return $chunk;
    }

    /**
     * Permanently delete a take (row + file). Refuses to delete the currently
     * selected take — the caller must select another first — and never removes a
     * file another take still references (the in-place legacy file).
     */
    public function deleteTake(TtsChunkTake $take): void
    {
        $chunk = $take->chunk;
        if ($take->audio_path === $chunk->audio_path) {
            throw new RuntimeException("You can't delete the take that's currently selected — pick another take first.");
        }

        $shared = $chunk->takes()
            ->where('id', '!=', $take->id)
            ->where('audio_path', $take->audio_path)
            ->exists();
        if (! $shared) {
            Storage::disk($this->disk())->delete($take->audio_path);
        }

        $take->delete();
    }

    private function disk(): string
    {
        return config('tts.storage_disk');
    }
}
