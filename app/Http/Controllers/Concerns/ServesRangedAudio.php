<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serve in-memory audio bytes with HTTP range-request support.
 *
 * iOS Safari range-probes any media URL it loads into an <audio>/<video>
 * element. If the server answers a `Range:` request with a plain 200 (the whole
 * body) instead of a 206 slice, Safari can't determine the media's duration: an
 * MP3 shows "Live Broadcast" with a dead scrubber, and a WAV chunk fails to play
 * outright. Honoring Range (206 + Content-Range + Accept-Ranges + Content-Length)
 * fixes both. Desktop browsers are happy with the plain 200 path too.
 */
trait ServesRangedAudio
{
    /**
     * @param  array<string, string>  $headers  extra headers (e.g. Content-Disposition)
     */
    protected function rangedAudio(string $bytes, string $mime, Request $request, array $headers = []): Response
    {
        $size = strlen($bytes);
        $headers = array_merge($headers, [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
        ]);

        $range = (string) $request->headers->get('Range', '');

        // No (or unparseable) Range: serve the whole body, but advertise that
        // ranges are supported so the client seeks via a follow-up request.
        if (! preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) || ($m[1] === '' && $m[2] === '')) {
            return response($bytes, 200, $headers + ['Content-Length' => (string) $size]);
        }

        if ($m[1] === '') {
            // Suffix range — the last N bytes (bytes=-N).
            $length = min((int) $m[2], $size);
            $start = $size - $length;
            $end = $size - 1;
        } else {
            $start = (int) $m[1];
            $end = $m[2] === '' ? $size - 1 : min((int) $m[2], $size - 1);
        }

        if ($size === 0 || $start > $end || $start >= $size) {
            return response('', 416, $headers + ['Content-Range' => "bytes */{$size}"]);
        }

        return response(substr($bytes, $start, $end - $start + 1), 206, $headers + [
            'Content-Range' => "bytes {$start}-{$end}/{$size}",
            'Content-Length' => (string) ($end - $start + 1),
        ]);
    }
}
