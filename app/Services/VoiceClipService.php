<?php

namespace App\Services;

use App\Jobs\PrepareVoiceClipJob;
use App\Models\VoiceClip;
use App\Services\Audio\AudioConverter;
use App\Services\Enhance\EnhanceProvider;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Owns reference-clip cleanup (denoise + enhance) and the prepare/preview staging
 * flow for the voice pages. Shared by the no-JS synchronous save path and the
 * AJAX prepare endpoint. Every cleanup is DEGRADE-SAFE: a failed enhance falls
 * back to the original clip with a user-facing warning, never an error.
 */
class VoiceClipService
{
    public function __construct(
        private AudioConverter $converter,
        private EnhanceProvider $enhancer,
    ) {}

    /**
     * Stage a recorded/uploaded clip for preview: decode to WAV, cap its length,
     * and store the original under a fresh token. Cleanup itself (denoise +
     * enhance) is NOT run here — that Replicate call can take a minute or more
     * and, run in-band, held the POST open until a gateway 504'd it (see
     * {@see PrepareVoiceClipJob}). When enhancement will run the clip
     * is staged as PROCESSING for the job to finish and the browser to poll;
     * otherwise it's READY immediately. Either way the stored bytes are exactly
     * what the preview URLs serve and what {@see claim()} returns, so the chosen
     * take is byte-identical end to end.
     *
     * @throws RuntimeException on an undecodable or over-long clip (→ 422)
     */
    public function stage(string $rawBytes, bool $enhance, int $userId): VoiceClip
    {
        $wav = $this->converter->decodeToWav($rawBytes);

        $duration = $this->converter->wavDurationSeconds($wav);

        // Over-long takes are trimmed here, ONCE — before storage and before
        // the (paid) enhance run — at a natural pause, never mid-word. The
        // whole stored clip ships with every chunk render, yet the engines
        // only read its head, so extra length is pure per-render payload.
        $trimmedFrom = null;
        $capSeconds = (float) config('tts.reference_max_seconds', 25);
        if ($duration !== null && $capSeconds > 0 && $duration > $capSeconds + 0.5) {
            $trimmed = $this->converter->trimReference($wav, $capSeconds);
            if ($trimmed !== null) {
                $trimmedFrom = $duration;
                $wav = $trimmed;
                $duration = $this->converter->wavDurationSeconds($wav);
            }
        }

        // Absurdity ceiling (mainly for installs that disable the trim).
        $max = (int) config('tts.enhance.max_clip_seconds', 120);
        if ($duration !== null && $duration > $max) {
            throw new RuntimeException('That clip is too long ('.round($duration).'s) — keep it under '.$max.'s.');
        }

        // Opportunistically clear this user's expired clips so a box without cron
        // doesn't accumulate staging files.
        $this->pruneExpired($userId);

        $willEnhance = $enhance && config('tts.enhance.enabled');

        $token = Str::random(40);
        $disk = Storage::disk(config('tts.storage_disk'));
        $originalPath = config('tts.voice_clip_path').'/'.$token.'/original.wav';
        $disk->put($originalPath, $wav);

        $clip = VoiceClip::create([
            'user_id' => $userId,
            'token' => $token,
            'original_path' => $originalPath,
            'enhanced_path' => null,
            'original_duration' => $duration,
            'enhanced_duration' => null,
            'enhance_error' => null,
            'status' => $willEnhance ? VoiceClip::STATUS_PROCESSING : VoiceClip::STATUS_READY,
            'expires_at' => now()->addHours((int) config('tts.enhance.clip_ttl_hours', 24)),
        ]);
        $clip->trimmedFromSeconds = $trimmedFrom;

        return $clip;
    }

    /**
     * Run cleanup over a staged clip's original take and flip it to READY. Called
     * from {@see PrepareVoiceClipJob} off the request cycle. Degrade-safe
     * end to end: a failed (or timed-out) enhance leaves the original in place with
     * a user-facing warning, and the clip still becomes READY so the poller stops.
     */
    public function runEnhancement(VoiceClip $clip): void
    {
        $wav = $this->bytes($clip, 'original');
        if ($wav === null) {
            // The original vanished (expired + pruned mid-flight) — nothing to
            // enhance, but release the poller.
            $clip->update(['status' => VoiceClip::STATUS_READY]);

            return;
        }

        $result = $this->enhanceOrOriginal($wav);

        $enhancedPath = null;
        $enhancedDuration = null;
        if ($result['enhanced']) {
            $enhancedPath = config('tts.voice_clip_path').'/'.$clip->token.'/enhanced.wav';
            Storage::disk(config('tts.storage_disk'))->put($enhancedPath, $result['bytes']);
            $enhancedDuration = $this->converter->wavDurationSeconds($result['bytes']);
        }

        $clip->update([
            'enhanced_path' => $enhancedPath,
            'enhanced_duration' => $enhancedDuration,
            'enhance_error' => $result['error'],
            'status' => VoiceClip::STATUS_READY,
        ]);
    }

    /**
     * Resolve a prepared clip to the exact stored bytes for the chosen variant,
     * scoped to its owner. Used by the voice form's final save.
     *
     * @return array{bytes: string, ext: string}
     *
     * @throws RuntimeException when the token is missing, expired, foreign, or its
     *                          chosen variant's file is gone
     */
    public function claim(string $token, string $choice, int $userId): array
    {
        $clip = VoiceClip::live()->where('token', $token)->where('user_id', $userId)->first();
        $path = $clip?->pathFor($choice);
        $disk = Storage::disk(config('tts.storage_disk'));

        if (! $clip || $path === null || ! $disk->exists($path)) {
            throw new RuntimeException('That prepared clip has expired — please record or upload it again.');
        }

        return ['bytes' => (string) $disk->get($path), 'ext' => 'wav'];
    }

    /** Delete a prepared clip's files and row (single-use, after a successful save). */
    public function discard(string $token): void
    {
        $clip = VoiceClip::where('token', $token)->first();
        if ($clip === null) {
            return;
        }

        $this->deleteFiles($clip);
        $clip->delete();
    }

    /** Raw stored bytes for a clip variant, or null if that variant is absent/gone. */
    public function bytes(VoiceClip $clip, string $variant): ?string
    {
        $path = $clip->pathFor($variant);
        if ($path === null) {
            return null;
        }

        $disk = Storage::disk(config('tts.storage_disk'));

        return $disk->exists($path) ? (string) $disk->get($path) : null;
    }

    /** Prune expired prepared clips (all users, or one). Returns the count removed. */
    public function pruneExpired(?int $userId = null): int
    {
        $query = VoiceClip::where('expires_at', '<=', now());
        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $count = 0;
        foreach ($query->get() as $clip) {
            $this->deleteFiles($clip);
            $clip->delete();
            $count++;
        }

        return $count;
    }

    private function deleteFiles(VoiceClip $clip): void
    {
        Storage::disk(config('tts.storage_disk'))
            ->deleteDirectory(config('tts.voice_clip_path').'/'.$clip->token);
    }

    /**
     * Decode arbitrary upload bytes to canonical WAV and clean them up. Used by
     * the no-JS form POST when the "clean up" checkbox is on. Callers should
     * already have gated on config('tts.enhance.enabled').
     *
     * @return array{bytes: string, enhanced: bool, error: string|null}
     */
    public function enhanceUploadedClip(string $rawBytes): array
    {
        return $this->enhanceOrOriginal($this->converter->decodeToWav($rawBytes));
    }

    /**
     * Run the configured enhancer over decoded WAV bytes. Returns the enhanced
     * WAV on success, or the original WAV plus a warning if the enhancer failed.
     *
     * @return array{bytes: string, enhanced: bool, error: string|null}
     */
    public function enhanceOrOriginal(string $wavBytes): array
    {
        $out = $this->enhancer->enhance($wavBytes, [
            'denoise_only' => (bool) config('tts.enhance.denoise_only', false),
        ]);

        if ($out === null) {
            return [
                'bytes' => $wavBytes,
                'enhanced' => false,
                'error' => 'Audio cleanup failed — the original clip was used instead.',
            ];
        }

        return ['bytes' => $out, 'enhanced' => true, 'error' => null];
    }
}
