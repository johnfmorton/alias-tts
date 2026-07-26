<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ChecksCredit;
use App\Http\Controllers\Concerns\ServesRangedAudio;
use App\Http\Controllers\Controller;
use App\Jobs\RunGenblazeJob;
use App\Models\Voice;
use App\Services\Genblaze\GenblazeRunnerClient;
use App\Services\Genblaze\GenblazeRunStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Generate via Genblaze" — the judge-facing entry point. The run is unattended
 * end-to-end (Genblaze orchestrates generate → QA-gated re-roll → stitch and
 * writes every take + a verifiable manifest to B2), and can take minutes, so the
 * button dispatches a queued {@see RunGenblazeJob} and the panel polls
 * {@see status()} — never holding an HTTP request open. Provenance audio is
 * proxied back through {@see asset()} so it plays even from a PRIVATE B2 bucket.
 */
class GenblazeController extends Controller
{
    use ChecksCredit, ServesRangedAudio;

    public function __construct(
        private readonly GenblazeRunnerClient $runner,
        private readonly GenblazeRunStore $runs,
    ) {}

    public function index(Request $request): View
    {
        // The user's voices + built-ins in their own drag order; pre-select
        // the first one, same as the New Project form.
        $voices = Voice::orderedFor($request->user()->id)->get();

        return view('admin.studio.genblaze', [
            'voices' => $voices,
            'defaultVoiceSlug' => $voices->first()?->slug,
            'health' => $this->runner->health(),
        ]);
    }

    /** Kick off an async run and return its id + poll URL (HTTP 202). */
    public function run(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'text' => ['required', 'string', 'max:'.(int) config('tts.max_async_text_length', 40000)],
            'voice' => ['required', 'string'],
            'seed' => ['nullable', 'integer'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $voice = Voice::resolveFor((string) $request->input('voice'), $request->user()->id);
        if (! $voice) {
            return response()->json(['message' => 'Unknown voice.'], 422);
        }

        if ($error = $this->creditError($request->user())) {
            return $error;
        }

        $id = (string) Str::uuid();
        $this->runs->create($id);

        RunGenblazeJob::dispatch(
            $id,
            (string) $request->input('text'),
            // The runner echoes this into the UNSCOPED internal generate
            // endpoint, and slugs are only unique per user — hand it the UUID.
            $voice->id,
            $request->filled('seed') ? (int) $request->input('seed') : null,
            $request->user()?->id,
        );

        return response()->json([
            'run_id' => $id,
            'status' => $this->runs->get($id)['status'] ?? 'queued',
            'status_url' => route('admin.studio.genblaze.status', $id),
        ], 202);
    }

    /** Poll a run's state; on completion the provenance is returned with proxied play URLs. */
    public function status(string $run): JsonResponse
    {
        $state = $this->runs->get($run);
        if ($state === null) {
            return response()->json(['message' => 'Unknown or expired run.'], 404);
        }

        $payload = [
            'run_id' => $run,
            'status' => $state['status'] ?? 'unknown',
            'error' => $state['error'] ?? null,
            // Live per-stage pings (chunk/generate/stitch/seal/upload) the runner
            // reports mid-run, plus the pronounce step the job records — so the
            // panel lights up its checklist as the text moves through the pipeline.
            'progress' => $state['progress'] ?? [],
        ];
        if (($state['status'] ?? null) === 'completed' && isset($state['result'])) {
            $payload['result'] = $this->withPlayUrls((array) $state['result']);
        }

        return response()->json($payload);
    }

    /**
     * Stream a Genblaze provenance object from B2 through the app (authenticated
     * `s3` read), so the panel plays it even when the bucket is private. Restricted
     * to the `genblaze/` prefix so it can never serve an arbitrary bucket key.
     */
    public function asset(Request $request): Response
    {
        $key = ltrim((string) $request->query('key', ''), '/');
        abort_unless($key !== '' && str_starts_with($key, 'genblaze/'), 404);

        $disk = Storage::disk('s3');
        abort_unless($disk->exists($key), 404);

        $mime = str_ends_with($key, '.wav') ? 'audio/wav' : 'audio/mpeg';

        // ?download=1 → serve as an attachment, so a judge can grab the sealed
        // final deliverable the way a real client would. The <audio> src omits
        // the flag and still streams.
        $headers = [];
        $disposition = null;
        if ($request->boolean('download')) {
            $ext = str_ends_with($key, '.wav') ? 'wav' : 'mp3';
            $disposition = 'attachment; filename="alias-sealed-final.'.$ext.'"';
            $headers['Content-Disposition'] = $disposition;
        }

        if ($redirect = $this->presignedAudioRedirect('s3', $key, $mime, $disposition)) {
            return $redirect;
        }

        return $this->rangedAudio((string) $disk->get($key), $mime, $request, $headers);
    }

    /**
     * Add app-proxied `*_play_url`s alongside the runner's raw B2 URLs, so a
     * private bucket still plays in-browser while the real B2 location stays
     * visible for provenance.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function withPlayUrls(array $result): array
    {
        if (isset($result['final_url'])) {
            $result['final_play_url'] = $this->playUrl((string) $result['final_url']);
        }

        $result['chunks'] = array_map(function ($chunk) {
            if (is_array($chunk) && isset($chunk['audio_url'])) {
                $chunk['play_url'] = $this->playUrl((string) $chunk['audio_url']);
            }

            return $chunk;
        }, (array) ($result['chunks'] ?? []));

        return $result;
    }

    private function playUrl(string $b2Url): ?string
    {
        $key = $this->keyFromB2Url($b2Url);

        return $key === null ? null : route('admin.studio.genblaze.asset', ['key' => $key]);
    }

    /** Map a runner B2 object URL back to its bucket key (null if it isn't a Genblaze object in our bucket). */
    private function keyFromB2Url(string $url): ?string
    {
        $bucket = (string) config('filesystems.disks.s3.bucket');
        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        if ($bucket === '' || ! str_starts_with($path, $bucket.'/')) {
            return null;
        }

        $key = substr($path, strlen($bucket) + 1);

        // Under a shared-bucket TTS_STORAGE_ROOT the runner's URLs carry the
        // instance prefix, but disk keys must stay relative to it (the disk
        // re-applies the root on every read).
        $root = trim((string) config('filesystems.disks.s3.root', ''), '/');
        if ($root !== '' && str_starts_with($key, $root.'/')) {
            $key = substr($key, strlen($root) + 1);
        }

        return str_starts_with($key, 'genblaze/') ? $key : null;
    }
}
