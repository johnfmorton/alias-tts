<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Rejects an uploaded media file that carries a real (non-cover-art) video stream.
 *
 * The `mimes:` rule only checks the *guessed* type, and several audio containers
 * overlap byte-for-byte with video ones — m4a and mov are both ISO-BMFF, and Ogg
 * can legally carry Theora video. That overlap is exactly the seam an attacker
 * uses to slip a crafted video stream past an audio-only filter and into ffmpeg's
 * video decoders (e.g. MagicYUV / "PixelSmash", CVE-2026-8461).
 *
 * This probes the actual stream content with ffprobe and fails if a video stream
 * is present. `-select_streams V` (capital V) matches video streams that are NOT
 * attached pictures/thumbnails, so ordinary embedded cover art in an MP3/M4A is
 * still allowed through.
 *
 * Scope is deliberately narrow: it rejects only a *positively detected* video
 * stream. If ffprobe is unavailable, or can't parse the file at all, it defers
 * to the `file`/`mimes` rules and the `-vn`-guarded normalization downstream
 * rather than blocking the upload — this is one layer of defense, not the gate.
 */
class AudioOnlyUpload implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Not an upload, or already broken — let the `file`/`mimes` rules speak.
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return;
        }

        $path = $value->getRealPath();
        if ($path === false || $path === '') {
            return;
        }

        $process = new Process([
            (string) config('tts.ffprobe_path', 'ffprobe'),
            '-v', 'error',
            '-select_streams', 'V',           // video streams, excluding attached cover art
            '-show_entries', 'stream=codec_type',
            '-of', 'csv=p=0',
            $path,
        ]);
        $process->setTimeout(30);

        try {
            $process->run();
        } catch (Throwable) {
            // ffprobe unavailable/unrunnable: defer. The -vn-guarded
            // normalization downstream still prevents any video decode-to-output.
            return;
        }

        // Couldn't parse the file (corrupt, or not real media): the `file`/`mimes`
        // rules and the downstream ffmpeg decode report that. We only act on a
        // positively detected video stream here.
        if (! $process->isSuccessful()) {
            return;
        }

        if (trim($process->getOutput()) !== '') {
            $fail('The audio file must contain audio only; it includes a video stream.');
        }
    }
}
