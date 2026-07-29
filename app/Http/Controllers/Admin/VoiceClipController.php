<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ServesRangedAudio;
use App\Http\Controllers\Controller;
use App\Jobs\PrepareVoiceClipJob;
use App\Models\AppEvent;
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
     *
     * Cleanup runs in a queued job so the upload returns fast: when it will run,
     * the clip is staged PROCESSING and this returns a status URL the browser
     * polls (see {@see status()}); the sync queue driver finishes it inline, so a
     * refresh reflects the already-READY result there and in tests.
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
            $clip = $this->clips->stage(
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

        if ($clip->status === VoiceClip::STATUS_PROCESSING) {
            PrepareVoiceClipJob::dispatch($clip->id);
            // A real (database/redis) queue leaves it PROCESSING for the poll; the
            // sync driver has already finished the job, so reflect the truth.
            $clip->refresh();
        }

        // The in-browser recorder always names its blob 'recording.<ext>';
        // uploads keep the user's own filename.
        AppEvent::record(AppEvent::VOICE_CLIP_ADDED, $request->user()->id, AppEvent::SOURCE_STUDIO, [
            'method' => str_starts_with($request->file('audio')->getClientOriginalName(), 'recording.') ? 'record' : 'upload',
        ]);

        return response()->json($this->payload($clip));
    }

    /**
     * Poll target while cleanup runs. Returns the same shape as {@see store()}:
     * a bare PROCESSING marker until the job finishes, then the full A/B payload.
     */
    public function status(Request $request, VoiceClip $clip): JsonResponse
    {
        abort_unless($clip->user_id === $request->user()->id && $clip->expires_at->isFuture(), 404);

        return response()->json($this->payload($clip));
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

    /**
     * JSON describing a staged clip. While PROCESSING it carries only the token
     * and a status URL to poll; once READY it carries the original + (if any)
     * enhanced variant URLs and any degrade-safe cleanup warning.
     *
     * @return array<string, mixed>
     */
    private function payload(VoiceClip $clip): array
    {
        // Set only on the freshly staged instance (store()) — polls won't have
        // it, so the JS carries it across from the first response.
        $notice = $clip->trimmedFromSeconds !== null
            ? sprintf(
                'Trimmed from %ds to %ds at a natural pause — the engines only read the first ~15 seconds.',
                (int) round($clip->trimmedFromSeconds),
                (int) round((float) $clip->original_duration),
            )
            : null;

        if ($clip->status === VoiceClip::STATUS_PROCESSING) {
            return [
                'ok' => true,
                'token' => $clip->token,
                'status' => VoiceClip::STATUS_PROCESSING,
                'status_url' => route('admin.voices.clips.status', ['clip' => $clip->token]),
                'expires_at' => $clip->expires_at->toIso8601String(),
                'notice' => $notice,
            ];
        }

        return [
            'ok' => true,
            'token' => $clip->token,
            'status' => VoiceClip::STATUS_READY,
            'expires_at' => $clip->expires_at->toIso8601String(),
            'notice' => $notice,
            'original' => [
                'url' => route('admin.voices.clips.audio', ['clip' => $clip->token, 'variant' => 'original']),
                'duration' => $clip->original_duration,
            ],
            'enhanced' => $clip->enhanced_path ? [
                'url' => route('admin.voices.clips.audio', ['clip' => $clip->token, 'variant' => 'enhanced']),
                'duration' => $clip->enhanced_duration,
            ] : null,
            'enhance_error' => $clip->enhance_error,
        ];
    }
}
