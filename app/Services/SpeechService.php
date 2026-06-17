<?php

namespace App\Services;

use App\Enums\SpeechStatus;
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
        // Fall back to the voice's default seed when the request didn't pin one.
        if ($seed === null && is_array($voice->settings) && isset($voice->settings['seed'])) {
            $seed = (int) $voice->settings['seed'];
        }

        $cacheHash = $this->cacheHash($voice, $text, $settings, $modelId, $outputFormat, $seed);

        if (! $forceRefresh) {
            $cached = $this->findCached($voice, $cacheHash);
            if ($cached) {
                return $cached;
            }
        }

        $speech = Speech::create([
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

        try {
            $referencePath = $this->referencePath($voice);

            $providerSettings = $settings;
            if ($seed !== null) {
                $providerSettings['seed'] = $seed;
            }

            // Chatterbox is short-form, so split long text into chunks, generate
            // each, and concatenate the audio into a single file.
            $chunks = $this->chunker->split($text, (int) config('tts.chunk_chars', 280));
            if ($chunks === []) {
                $chunks = [$text];
            }

            $rawParts = [];
            foreach ($chunks as $chunk) {
                $rawParts[] = $this->provider->synthesize($chunk, $referencePath, $providerSettings);
            }

            [$bytes, $mime, $ext] = $this->converter->concatenate(
                $rawParts,
                $outputFormat,
                $this->provider->outputContainer(),
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
