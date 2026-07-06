<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ServesRangedAudio;
use App\Http\Controllers\Controller;
use App\Models\VoiceClip;
use App\Rules\AudioOnlyUpload;
use App\Services\VoiceClipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Prepare a reference clip for preview: accept a recorded or uploaded clip,
 * decode it, optionally clean it up (denoise + enhance), and stage the original
 * and enhanced WAVs so the browser can A/B them before the voice form is
 * submitted. Like the Studio AJAX endpoints (see {@see StudioProjectController}),
 * these validate explicitly and return JSON rather than the redirect the app's
 * default handler gives admin routes.
 */
class VoiceClipController extends Controller
{
    use ServesRangedAudio;

    public function __construct(private VoiceClipService $clips) {}

    /**
     * Prepare a clip. Accepts recorder output (webm/mp4/ogg) and file uploads
     * alike — ffmpeg transcodes at decode. The mimetypes list includes
     * video/webm + video/mp4 because fileinfo sniffs audio-only webm/mp4 as video
     * containers; {@see AudioOnlyUpload} (ffprobe) is the real video-stream gate.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'audio' => [
                'required', 'file', 'max:20480',
                'mimetypes:audio/webm,video/webm,audio/ogg,application/ogg,audio/mp4,video/mp4,audio/x-m4a,audio/mp4a-latm,audio/aac,audio/mpeg,audio/wav,audio/x-wav,audio/wave,audio/vnd.wave,audio/flac,audio/x-flac',
                new AudioOnlyUpload,
            ],
            'enhance' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        try {
            $clip = $this->clips->prepare(
                (string) file_get_contents($request->file('audio')->getRealPath()),
                $request->boolean('enhance'),
                $request->user()->id,
            );
        } catch (RuntimeException $e) {
            // Undecodable or over-long clip — a client problem.
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not prepare the clip. Please try again.'], 502);
        }

        return response()->json([
            'ok' => true,
            'token' => $clip->token,
            'expires_at' => $clip->expires_at->toIso8601String(),
            'original' => [
                'url' => route('admin.voices.clips.audio', ['clip' => $clip->token, 'variant' => 'original']),
                'duration' => $clip->original_duration,
            ],
            'enhanced' => $clip->enhanced_path ? [
                'url' => route('admin.voices.clips.audio', ['clip' => $clip->token, 'variant' => 'enhanced']),
                'duration' => $clip->enhanced_duration,
            ] : null,
            'enhance_error' => $clip->enhance_error,
        ]);
    }

    /** Range-aware audio for the A/B players (iOS Safari needs 206 — see ServesRangedAudio). */
    public function audio(Request $request, VoiceClip $clip, string $variant): Response
    {
        abort_unless($clip->user_id === $request->user()->id && $clip->expires_at->isFuture(), 404);

        $bytes = $this->clips->bytes($clip, $variant);
        if ($bytes === null) {
            abort(404);
        }

        return $this->rangedAudio($bytes, 'audio/wav', $request);
    }
}
