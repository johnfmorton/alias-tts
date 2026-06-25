<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Voice;
use App\Services\Genblaze\GenblazeRunnerClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Throwable;

/**
 * "Generate via Genblaze" — the judge-facing entry point. Renders a small form
 * and proxies generation to the Genblaze runner's POST /run, returning the
 * per-chunk provenance (attempts, re-rolls, B2 take URLs, verified manifest) for
 * the page to render. The run is unattended end-to-end: Genblaze orchestrates
 * generate → QA-gated re-roll → stitch and writes every take to B2.
 */
class GenblazeController extends Controller
{
    public function __construct(private readonly GenblazeRunnerClient $runner) {}

    public function index(): View
    {
        return view('admin.studio.genblaze', [
            'voices' => Voice::orderBy('name')->get(),
            'defaultVoiceSlug' => Voice::defaultSlug(),
            'health' => $this->runner->health(),
        ]);
    }

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

        if (! Voice::resolve((string) $request->input('voice'))) {
            return response()->json(['message' => 'Unknown voice.'], 422);
        }

        // The run is synchronous — the runner holds this request for the whole
        // generate → re-roll → stitch. Lift PHP's execution cap (the HTTP client
        // timeout still bounds it). NOTE: a long multi-chunk run can still hit the
        // web server's fastcgi/proxy read timeout; moving this to a queued job +
        // status poll is the proper fix for long text.
        set_time_limit(0);

        try {
            $provenance = $this->runner->run(
                text: (string) $request->input('text'),
                voice: (string) $request->input('voice'),
                seed: $request->filled('seed') ? (int) $request->input('seed') : null,
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Genblaze run failed — '.$e->getMessage()], 502);
        }

        return response()->json($provenance);
    }
}
