<?php

namespace App\Services;

use App\Enums\SpeechStatus;
use App\Exceptions\SpeechGenerationException;
use App\Jobs\GenerateSpeechJob;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\TtsProject;
use App\Models\Voice;
use App\Services\Asr\AsrClient;
use App\Services\Asr\ChunkRemediator;
use App\Services\Audio\AudioConverter;
use App\Services\Credit\CreditService;
use App\Services\Tts\ChunkGaps;
use App\Services\Tts\ModelCatalog;
use App\Services\Tts\ParalinguisticTags;
use App\Services\Tts\TtsProvider;
use App\Services\Tts\VoiceReference;
use App\Support\GenerationTimings;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SpeechService
{
    public function __construct(
        private TtsProvider $provider,
        private AudioConverter $converter,
        private TextChunker $chunker,
        private AsrClient $asr,
        private ChunkRemediator $remediator,
        private ProjectService $projects,
        private CreditService $credit,
        private SpeechProgressStore $progress,
    ) {}

    /**
     * Synthesize (or return a cached) Speech for the given voice + text + settings.
     * Generation is synchronous so the endpoint can return audio bytes directly,
     * matching the ElevenLabs request/response contract.
     *
     * $studioProject false marks an internal preview/test call (voice preview,
     * pronunciation test, health test): play-once audio that must never hand
     * off to Studio, regardless of tts.api_project_mode.
     */
    public function synthesize(
        ApiKey $apiKey,
        Voice $voice,
        string $text,
        array $settings,
        string $modelId,
        string $outputFormat,
        ?int $seed = null,
        bool $forceRefresh = false,
        ?string $engine = null,
        bool $studioProject = true,
    ): Speech {
        // Stamp the effective engine (voice's model, or a per-request override
        // from the OpenAI dialect) BEFORE hashing/persisting so the cache key
        // separates engines and the stored settings carry the choice to the
        // provider — including through the queued job and ASR reroll paths.
        $settings = ModelCatalog::stamp($settings, $voice, $engine);

        $seed = $this->resolveSeed($voice, $seed);
        $cacheHash = $this->cacheHash($voice, $text, $settings, $modelId, $outputFormat, $seed);

        if (! $forceRefresh) {
            $cached = $this->findCached($voice, $cacheHash);
            if ($cached) {
                return $cached;
            }
        }

        $speech = $this->createRecord($apiKey, $voice, $text, $settings, $modelId, $outputFormat, $cacheHash);

        try {
            $this->process($speech, $seed, $studioProject);
        } catch (Throwable $e) {
            // process() already marked the record Failed and (per api_project_mode)
            // may have created a linked recovery project; carry it so the controller
            // can surface the project's edit link in the error response.
            throw new SpeechGenerationException($e, TtsProject::where('source_speech_id', $speech->id)->first());
        }

        return $speech;
    }

    /**
     * Queue (or return a cached / in-flight) Speech. Returns immediately with a
     * Processing record; a GenerateSpeechJob runs the generation in the
     * background. This removes the synchronous ~300s ceiling for long text.
     */
    public function queueSynthesis(
        ApiKey $apiKey,
        Voice $voice,
        string $text,
        array $settings,
        string $modelId,
        string $outputFormat,
        ?int $seed = null,
        bool $forceRefresh = false,
        ?string $engine = null,
        bool $studioProject = true,
    ): Speech {
        // Same engine stamp as synthesize() — see there.
        $settings = ModelCatalog::stamp($settings, $voice, $engine);

        $seed = $this->resolveSeed($voice, $seed);
        $cacheHash = $this->cacheHash($voice, $text, $settings, $modelId, $outputFormat, $seed);

        if (! $forceRefresh) {
            if ($cached = $this->findCached($voice, $cacheHash)) {
                return $cached;
            }
            // Don't start a second job for an identical request already running.
            if ($inFlight = $this->findInFlight($voice, $cacheHash)) {
                return $inFlight;
            }
        }

        $speech = $this->createRecord($apiKey, $voice, $text, $settings, $modelId, $outputFormat, $cacheHash);

        GenerateSpeechJob::dispatch($speech->id, $seed, $studioProject);

        return $speech;
    }

    /**
     * Run generation for an existing Processing record: chunk, synthesize each
     * part, concatenate, store, and mark the record Completed (or Failed). Shared
     * by the synchronous path and the queued job.
     */
    public function process(Speech $speech, ?int $seed = null, bool $studioProject = true): Speech
    {
        $failingIndex = null;

        try {
            $referencePath = $this->referencePath($speech->voice);

            $providerSettings = $speech->settings ?? [];
            if ($seed !== null) {
                $providerSettings['seed'] = $seed;
            }

            // Chatterbox is short-form, so split long text into chunks, generate
            // each, and concatenate the audio into a single file. Segments carry
            // a pause tag so the audio layer can insert a longer silence at
            // paragraph seams than at sentence seams.
            $segments = $this->chunker->segment(
                $speech->text,
                (int) config('tts.chunk_chars', 280),
                (int) config('tts.block_space_run', 4),
                (int) config('tts.min_chunk_chars', 30),
                (int) config('tts.short_trailer_words', 3),
                (string) config('tts.chunk_mode', TextChunker::MODE_PACKED),
            );
            if ($segments === []) {
                $segments = [['text' => $speech->text, 'breakAfter' => 'sentence']];
            }

            // Live "clip N of M" snapshot for the job-status poll. Best-effort
            // cache writes; the store never throws into the render.
            $total = count($segments);
            $model = $providerSettings['model'] ?? ModelCatalog::DEFAULT;
            // Pass the model so the poll's ETA can seed from that engine's
            // learned average before the first segment finishes.
            $this->progress->begin($speech->id, $total, $model);

            $supportsTags = ModelCatalog::supportsTags($providerSettings['model'] ?? null);

            $rawParts = [];
            $seamGapsMs = [];
            $preserveTails = [];
            foreach ($segments as $i => $segment) {
                $failingIndex = $i; // attribute a provider/QA throw to this segment
                // Wall-clock for this segment (synth + any hidden QA reroll),
                // fed to the learned per-model timing that drives the ETA.
                $segStart = microtime(true);
                $part = $this->provider->synthesize($segment['text'], $referencePath, $providerSettings);

                // The render is paid the moment the provider returns, so debit
                // the key owner NOW — even if a later QA/concat/store step
                // fails, that money is spent. If this Speech is later imported
                // into a Studio project, the import books spend counters only
                // (recordTake chargeCredit: false) so credit is never charged
                // twice. Hidden ASR remediation re-rolls inside qualityCheck()
                // are deliberately uncharged — same blind spot as the Studio
                // spend counters; the markup absorbs it.
                $this->credit->charge(
                    $speech->apiKey?->user_id,
                    mb_strlen($segment['text']),
                    $providerSettings['model'] ?? ModelCatalog::DEFAULT,
                    'api',
                    'speech',
                    (string) $speech->id,
                );

                if ($this->asr->enabled()) {
                    $part = $this->qualityCheck($speech, $i, $segment['text'], $part, $referencePath, $providerSettings);
                }

                GenerationTimings::record($model, (int) round((microtime(true) - $segStart) * 1000));

                $rawParts[] = $part;
                $seamGapsMs[] = ChunkGaps::seamGap(
                    $segment['breakAfter'],
                    $segment['text'],
                    $segments[$i + 1]['text'] ?? '',
                );
                // A segment ending in a rendered sound tag keeps its tail — the
                // artifact detectors would mistake the laugh/sigh for junk.
                $preserveTails[] = $supportsTags && ParalinguisticTags::endsWith($segment['text']);
                $failingIndex = null; // segment done; a later (concat/store) failure isn't its fault

                // Counted only after synth + charge + QA (incl. hidden ASR
                // re-rolls) succeed, so the count never decrements.
                $this->progress->advance($speech->id, $i + 1, $total);
            }

            $this->progress->stitching($speech->id, $total);

            [$bytes, $mime, $ext] = $this->converter->concatenate(
                $rawParts,
                $speech->output_format,
                $this->provider->outputContainer($providerSettings['model'] ?? null),
                $seamGapsMs,
                $preserveTails,
            );

            $disk = config('tts.storage_disk');
            $audioPath = config('tts.storage_path').'/'.$speech->id.'.'.$ext;

            // put() reports failure (disk full, permissions, a root-owned
            // directory from a CLI run as the wrong user) as false, not an
            // exception — unchecked, the speech would be marked Completed
            // with no file and every download would 500.
            if (Storage::disk($disk)->put($audioPath, $bytes) === false) {
                throw new RuntimeException("Could not write the generated audio to disk \"{$disk}\" at \"{$audioPath}\" — check storage permissions and free space.");
            }

            $speech->update([
                'status' => SpeechStatus::Completed,
                'audio_path' => $audioPath,
                'mime_type' => $mime,
            ]);

            $this->progress->clear($speech->id);

            if ($studioProject) {
                $this->maybeCreateApiProject(
                    $speech,
                    $seed,
                    failed: false,
                    generatedSegments: $this->generatedSegments($segments, $rawParts),
                );
            }
        } catch (Throwable $e) {
            $speech->update([
                'status' => SpeechStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);

            $this->progress->clear($speech->id);

            // A failed preview surfaces its error inline on the page it came
            // from — a recovery project for a throwaway sentence is noise.
            if ($studioProject) {
                $this->maybeCreateApiProject($speech, $seed, failed: true, failureReason: $e->getMessage(), failedChunkIndex: $failingIndex);
            }

            throw $e;
        }

        return $speech;
    }

    /**
     * Honor tts.api_project_mode by handing a finished /v1 generation off to
     * Studio as an editable project: 'on_error' creates one only on failure (a
     * recovery project the admin can repair + rebuild), 'always' on every call,
     * 'never' nothing. Creation must never mask the generation outcome, so a
     * failure here is logged and swallowed; an async-retry duplicate is skipped.
     *
     * A SUCCESSFUL 'always' call carries the work across complete — each
     * already-synthesized segment becomes a generated chunk and the concatenated
     * final is copied over — so the admin opens a ready project, not an empty one
     * to regenerate. A failure has no usable audio, so it seeds a bare recovery
     * project from the text alone.
     *
     * @param  list<array{text: string, breakAfter: string, audio: string}>|null  $generatedSegments
     */
    private function maybeCreateApiProject(Speech $speech, ?int $seed, bool $failed, ?string $failureReason = null, ?int $failedChunkIndex = null, ?array $generatedSegments = null): void
    {
        $mode = (string) config('tts.api_project_mode', 'never');
        if (! ($mode === 'always' || ($mode === 'on_error' && $failed))) {
            return;
        }

        if (TtsProject::where('source_speech_id', $speech->id)->exists()) {
            return;
        }

        try {
            if (! $failed && $generatedSegments !== null) {
                $this->projects->createGeneratedFromSpeech($speech, $generatedSegments, $seed);

                return;
            }

            $this->projects->createFromSpeech(
                $speech,
                origin: $failed ? 'api_failure' : 'api',
                failureReason: $failed ? $failureReason : null,
                failedChunkIndex: $failed ? $failedChunkIndex : null,
                seed: $seed,
            );
        } catch (Throwable $e) {
            Log::warning('Could not create an API Studio project', [
                'speech' => $speech->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pair each segment's text + seam with the raw audio that was generated for
     * it, so an 'always'-mode Studio project can persist them as completed chunks
     * — 1:1 with what /v1 actually read (no re-chunk, no re-synthesis). $rawParts
     * is appended one entry per segment, so the indexes line up.
     *
     * @param  array<int, array{text: string, breakAfter: string}>  $segments
     * @param  array<int, string>  $rawParts
     * @return list<array{text: string, breakAfter: string, audio: string}>
     */
    private function generatedSegments(array $segments, array $rawParts): array
    {
        $out = [];
        foreach (array_values($segments) as $i => $segment) {
            $out[] = [
                'text' => $segment['text'],
                'breakAfter' => $segment['breakAfter'],
                'audio' => $rawParts[$i],
            ];
        }

        return $out;
    }

    /**
     * ASR transcript QA for one synthesized segment — the synchronous/queued
     * path's analogue of {@see ProjectService::generateChunk()}'s per-chunk
     * check. Logs a flagged segment and, under api_action=auto, returns a
     * remediated take (re-roll missing content keeping the best, or precise-trim a
     * junk tail). This path is unattended (no human to read a badge), so it is the
     * one you typically set to 'auto'. The sync path holds no per-segment record,
     * so there is no verdict to persist — a flagged segment is logged. Degrades
     * safely: a down sidecar returns the original bytes untouched.
     *
     * @param  array<string, mixed>  $providerSettings
     */
    private function qualityCheck(Speech $speech, int $index, string $text, string $bytes, ?string $referencePath, array $providerSettings): string
    {
        $verdict = $this->remediator->score($text, $bytes, "speech-{$speech->id}-{$index}");
        if ($verdict === null || $verdict->ok) {
            return $bytes;
        }

        Log::warning('ASR flagged a speech segment', [
            'speech' => $speech->id,
            'segment' => $index,
            'problems' => $verdict->problems,
            'trail_s' => $verdict->trailS,
            'max_gap_s' => $verdict->maxGapS,
            'tail_cov' => $verdict->tailCov,
        ]);

        if ($this->asr->apiAction() !== 'auto') {
            return $bytes;
        }

        // A re-roll uses a fresh random seed — drop any pinned seed for this take.
        $rerollSettings = $providerSettings;
        unset($rerollSettings['seed']);

        $outcome = $this->remediator->remediate(
            $text,
            $bytes,
            $verdict,
            fn (): string => $this->provider->synthesize($text, $referencePath, $rerollSettings),
            "speech-{$speech->id}-{$index}",
        );

        Log::info('ASR auto-remediated a speech segment', [
            'speech' => $speech->id,
            'segment' => $index,
            'action' => $outcome->action,
            'attempts' => $outcome->rerollAttempts,
        ]);

        return $outcome->bytes;
    }

    /**
     * A still-running identical request, if one exists, so a re-submission joins
     * the in-flight job instead of starting a duplicate. Bounded to the async
     * job timeout so a crashed job's stale record isn't reused indefinitely.
     */
    public function findInFlight(Voice $voice, string $cacheHash): ?Speech
    {
        return Speech::query()
            ->where('voice_id', $voice->id)
            ->where('cache_hash', $cacheHash)
            ->where('status', SpeechStatus::Processing)
            ->where('created_at', '>', Carbon::now()->subSeconds((int) config('tts.async_timeout', 1800)))
            ->latest()
            ->first();
    }

    private function resolveSeed(Voice $voice, ?int $seed): ?int
    {
        // Fall back to the voice's default seed when the request didn't pin one.
        if ($seed === null && is_array($voice->settings) && isset($voice->settings['seed'])) {
            return (int) $voice->settings['seed'];
        }

        return $seed;
    }

    private function createRecord(
        ApiKey $apiKey,
        Voice $voice,
        string $text,
        array $settings,
        string $modelId,
        string $outputFormat,
        string $cacheHash,
    ): Speech {
        return Speech::create([
            'api_key_id' => $apiKey->id,
            'voice_id' => $voice->id,
            'text' => $text,
            'cache_hash' => $cacheHash,
            'settings' => $settings,
            'model_id' => $modelId,
            'output_format' => $outputFormat,
            'status' => SpeechStatus::Processing,
            'characters' => mb_strlen($text),
            'expires_at' => Carbon::now()->addHours((int) config('tts.ttl_hours')),
        ]);
    }

    public function findCached(Voice $voice, string $cacheHash): ?Speech
    {
        $speech = Speech::query()
            ->where('voice_id', $voice->id)
            ->where('cache_hash', $cacheHash)
            ->where('status', SpeechStatus::Completed)
            ->where('expires_at', '>', Carbon::now())
            ->latest()
            ->first();

        if ($speech && $speech->audio_path
            && Storage::disk(config('tts.storage_disk'))->exists($speech->audio_path)) {
            return $speech;
        }

        return null;
    }

    public function audioBytes(Speech $speech): string
    {
        return Storage::disk(config('tts.storage_disk'))->get($speech->audio_path);
    }

    public function deleteSpeech(Speech $speech): void
    {
        if ($speech->audio_path) {
            Storage::disk(config('tts.storage_disk'))->delete($speech->audio_path);
        }

        $speech->delete();
    }

    private function referencePath(Voice $voice): ?string
    {
        return VoiceReference::localPath($voice);
    }

    private function cacheHash(Voice $voice, string $text, array $settings, string $modelId, string $outputFormat, ?int $seed = null): string
    {
        ksort($settings);

        return hash('sha256', implode('|', [
            $voice->id,
            $this->referenceFingerprint($voice),
            $modelId,
            $outputFormat,
            $seed === null ? 'random' : (string) $seed,
            json_encode($settings),
            $text,
        ]));
    }

    /**
     * Fingerprints the voice's reference clip (mtime:size) so re-recording a
     * voice automatically invalidates previously cached audio.
     */
    private function referenceFingerprint(Voice $voice): string
    {
        if (! $voice->reference_audio_path) {
            return 'none';
        }

        $disk = Storage::disk(config('tts.storage_disk'));

        if (! $disk->exists($voice->reference_audio_path)) {
            return 'missing';
        }

        return $disk->lastModified($voice->reference_audio_path).':'.$disk->size($voice->reference_audio_path);
    }
}
