<?php

namespace App\Services;

use App\Enums\SpeechStatus;
use App\Jobs\GenerateSpeechJob;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\Voice;
use App\Services\Audio\AudioConverter;
use App\Services\Tts\TtsProvider;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SpeechService
{
    public function __construct(
        private TtsProvider $provider,
        private AudioConverter $converter,
        private TextChunker $chunker,
    ) {}

    /**
     * Synthesize (or return a cached) Speech for the given voice + text + settings.
     * Generation is synchronous so the endpoint can return audio bytes directly,
     * matching the ElevenLabs request/response contract.
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
    ): Speech {
        $seed = $this->resolveSeed($voice, $seed);
        $cacheHash = $this->cacheHash($voice, $text, $settings, $modelId, $outputFormat, $seed);

        if (! $forceRefresh) {
            $cached = $this->findCached($voice, $cacheHash);
            if ($cached) {
                return $cached;
            }
        }

        $speech = $this->createRecord($apiKey, $voice, $text, $settings, $modelId, $outputFormat, $cacheHash);

        $this->process($speech, $seed);

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
    ): Speech {
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

        GenerateSpeechJob::dispatch($speech->id, $seed);

        return $speech;
    }

    /**
     * Run generation for an existing Processing record: chunk, synthesize each
     * part, concatenate, store, and mark the record Completed (or Failed). Shared
     * by the synchronous path and the queued job.
     */
    public function process(Speech $speech, ?int $seed = null): Speech
    {
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
            );
            if ($segments === []) {
                $segments = [['text' => $speech->text, 'breakAfter' => 'sentence']];
            }

            $sentenceGap = (int) config('tts.chunk_gap_ms', 120);
            $paragraphGap = (int) config('tts.paragraph_gap_ms', 400);

            $rawParts = [];
            $seamGapsMs = [];
            foreach ($segments as $segment) {
                $rawParts[] = $this->provider->synthesize($segment['text'], $referencePath, $providerSettings);
                $seamGapsMs[] = $segment['breakAfter'] === 'paragraph' ? $paragraphGap : $sentenceGap;
            }

            [$bytes, $mime, $ext] = $this->converter->concatenate(
                $rawParts,
                $speech->output_format,
                $this->provider->outputContainer(),
                $seamGapsMs,
            );

            $disk = config('tts.storage_disk');
            $audioPath = config('tts.storage_path').'/'.$speech->id.'.'.$ext;
            Storage::disk($disk)->put($audioPath, $bytes);

            $speech->update([
                'status' => SpeechStatus::Completed,
                'audio_path' => $audioPath,
                'mime_type' => $mime,
            ]);
        } catch (Throwable $e) {
            $speech->update([
                'status' => SpeechStatus::Failed,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $speech;
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
        if (! $voice->reference_audio_path) {
            return null;
        }

        // Providers read the reference from a local filesystem path.
        return Storage::disk(config('tts.storage_disk'))->path($voice->reference_audio_path);
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
