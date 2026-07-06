<?php

namespace App\Services\Tts;

use App\Models\Voice;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves a voice's reference clip to a readable LOCAL filesystem path — the
 * shape the TTS providers consume (they base64 the file straight off disk).
 *
 * Works regardless of `TTS_STORAGE_DISK`: a clip already on the local disk
 * (including clips uploaded before a switch to S3) is used in place; a clip on
 * the configured S3/B2 disk is cached down to the local disk first, because
 * `Storage::disk('s3')->path()` is NOT a real filesystem path. Returns null when
 * the voice has no reference (a voice with no reference clip uses Chatterbox's
 * native voice) or the clip is genuinely gone.
 *
 * A missing clip is never silent: synthesizing without the prompt makes a warm
 * Chatterbox container reuse whatever voice it conditioned last — audibly wrong
 * output with no error. Built-ins self-heal from their bundled seed asset (a
 * TTS_STORAGE_ROOT change or bucket cleanup can strand the stored clip); any
 * other dead reference path logs a warning.
 */
class VoiceReference
{
    public static function localPath(?Voice $voice): ?string
    {
        $path = $voice?->reference_audio_path;
        if (! $path) {
            return null;
        }

        $resolved = self::existingLocalPath($path);
        if ($resolved !== null) {
            return $resolved;
        }

        if (self::healBuiltin($voice, $path)) {
            $resolved = self::existingLocalPath($path);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        Log::warning('Voice reference clip is missing from every disk — synthesis would not be prompted with this voice.', [
            'voice' => $voice->slug,
            'reference_audio_path' => $path,
            'storage_disk' => (string) config('tts.storage_disk'),
        ]);

        // Local disk keeps the historical contract: return the (missing) path so
        // the provider fails loudly instead of silently synthesizing unprompted.
        if ((string) config('tts.storage_disk') === 'local') {
            return Storage::disk('local')->path($path);
        }

        return null;
    }

    /**
     * The stored clip as a readable local file, or null when it exists on no
     * disk. Non-local disks cache the clip down to the local disk once.
     */
    private static function existingLocalPath(string $path): ?string
    {
        $disk = (string) config('tts.storage_disk');

        if ($disk === 'local') {
            $file = Storage::disk('local')->path($path);

            return is_file($file) ? $file : null;
        }

        $local = Storage::disk('local');
        if ($local->exists($path)) {
            return $local->path($path);
        }

        $remote = Storage::disk($disk);
        if ($remote->exists($path)) {
            $local->put($path, (string) $remote->get($path));

            return $local->path($path);
        }

        return null;
    }

    /**
     * Restore a built-in voice's missing clip from the bundled seed asset.
     * Heals only at the canonical bundled path — a re-pointed
     * reference_audio_path means an admin attached their own clip, and
     * substituting the seed would silently change which voice they hear.
     */
    private static function healBuiltin(Voice $voice, string $path): bool
    {
        if (! $voice->isBuiltin()) {
            return false;
        }

        $asset = $voice->builtinSeedAsset();
        if ($asset === null || ! is_file($asset)) {
            return false;
        }

        $canonical = trim((string) config('tts.reference_path', 'voices'), '/').'/'.$voice->slug.'.wav';
        if ($path !== $canonical) {
            return false;
        }

        Storage::disk((string) config('tts.storage_disk'))->put($path, (string) file_get_contents($asset));

        Log::info('Restored a built-in voice reference clip from the bundled seed asset.', [
            'voice' => $voice->slug,
            'reference_audio_path' => $path,
            'storage_disk' => (string) config('tts.storage_disk'),
        ]);

        return true;
    }
}
