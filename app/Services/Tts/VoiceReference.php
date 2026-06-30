<?php

namespace App\Services\Tts;

use App\Models\Voice;
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
 */
class VoiceReference
{
    public static function localPath(?Voice $voice): ?string
    {
        $path = $voice?->reference_audio_path;
        if (! $path) {
            return null;
        }

        $disk = (string) config('tts.storage_disk');

        // Local disk: the stored path already IS a real filesystem path — return
        // it directly (the historical behavior; the provider reports a clear
        // error if the file is genuinely missing).
        if ($disk === 'local') {
            return Storage::disk('local')->path($path);
        }

        // Non-local (S3/B2): `->path()` is not a readable file. Prefer a clip
        // already on the local disk (e.g. uploaded before the switch to S3),
        // otherwise cache the remote clip down to the local disk once.
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
}
