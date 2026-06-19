<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TtsProject;
use App\Models\Voice;
use App\Services\Audio\AudioConverter;
use App\Services\TextChunker;
use App\Services\TextNormalizer;
use App\Services\Tts\TtsProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Studio — a debugging surface over the text → normalize → chunk → synthesize
 * pipeline. It lets an admin paste arbitrary text, see exactly how it is cleaned
 * and split into chunks (using the SAME normalizer + chunker as production), and
 * then hear it three ways:
 *
 *   - whole text as ONE Chatterbox call ({@see self::synthesize()}),
 *   - each chunk on its own, raw provider output ({@see self::synthesize()}),
 *   - the full production stitch — chunk, synth each, concatenate with seam
 *     silence ({@see self::stitch()}).
 *
 * The per-chunk view returns the RAW provider audio (no trimming/fading) so the
 * Chatterbox seam artifacts we are debugging are audible. This is the read-only
 * foundation for the editable-project work (Phase 2).
 */
class StudioController extends Controller
{
    public function __construct(
        private readonly TtsProvider $provider,
        private readonly AudioConverter $converter,
        private readonly TextChunker $chunker,
        private readonly TextNormalizer $normalizer,
    ) {}

    public function index(): View
    {
        return view('admin.studio.index', [
            'voices' => Voice::orderBy('name')->get(),
            'projects' => TtsProject::withCount('chunks')->latest()->get(),
        ]);
    }

    /**
     * Normalize + chunk the pasted text and return the breakdown. No provider
     * calls — instant — so the user can inspect chunking before spending money.
     */
    public function preview(Request $request): JsonResponse
    {
        if ($error = $this->validationError($request, [
            'text' => ['required', 'string', 'max:'.(int) config('tts.max_async_text_length', 40000)],
        ])) {
            return $error;
        }

        $normalized = $this->normalizer->normalize((string) $request->input('text'));
        $segments = $this->segment($normalized);

        $chunks = [];
        foreach ($segments as $i => $segment) {
            $chunks[] = [
                'index' => $i,
                'text' => $segment['text'],
                'breakAfter' => $segment['breakAfter'],
                'chars' => mb_strlen($segment['text']),
            ];
        }

        return response()->json([
            'normalized' => $normalized,
            'chars' => mb_strlen($normalized),
            'chunks' => $chunks,
        ]);
    }

    /**
     * Synthesize exactly the given text as a SINGLE Chatterbox prediction and
     * return the raw provider audio (no trim/concat). Used both for one chunk in
     * isolation and for the whole text "as one call". The text must already be
     * normalized by the client (it is sent verbatim to the provider).
     */
    public function synthesize(Request $request): Response
    {
        if ($error = $this->validationError($request, [
            'text' => ['required', 'string', 'max:'.(int) config('tts.max_async_text_length', 40000)],
            'voice' => ['nullable', 'string'],
        ])) {
            return $error;
        }

        $voice = $this->resolveVoice($request);
        if (! $voice) {
            return response()->json(['message' => 'No voice configured — add a voice first.'], 422);
        }

        try {
            $bytes = $this->provider->synthesize(
                (string) $request->input('text'),
                $this->referencePath($voice),
                $this->settings($request, $voice),
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Generation failed: '.$e->getMessage()], 502);
        }

        return response($bytes, 200)->header('Content-Type', $this->containerMime());
    }

    /**
     * Run the full production pipeline on the text — normalize, chunk, synthesize
     * each chunk, and concatenate with seam silence — and return the stitched
     * file, so the user can A/B the production output against the raw per-chunk
     * and single-call renders.
     */
    public function stitch(Request $request): Response
    {
        if ($error = $this->validationError($request, [
            'text' => ['required', 'string', 'max:'.(int) config('tts.max_async_text_length', 40000)],
            'voice' => ['nullable', 'string'],
        ])) {
            return $error;
        }

        $voice = $this->resolveVoice($request);
        if (! $voice) {
            return response()->json(['message' => 'No voice configured — add a voice first.'], 422);
        }

        $segments = $this->segment($this->normalizer->normalize((string) $request->input('text')));
        if ($segments === []) {
            return response()->json(['message' => 'Nothing to synthesize.'], 422);
        }

        $sentenceGap = (int) config('tts.chunk_gap_ms', 120);
        $paragraphGap = (int) config('tts.paragraph_gap_ms', 400);

        try {
            $reference = $this->referencePath($voice);
            $settings = $this->settings($request, $voice);

            $rawParts = [];
            $seamGapsMs = [];
            foreach ($segments as $segment) {
                $rawParts[] = $this->provider->synthesize($segment['text'], $reference, $settings);
                $seamGapsMs[] = $segment['breakAfter'] === 'paragraph' ? $paragraphGap : $sentenceGap;
            }

            [$bytes, $mime] = $this->converter->concatenate(
                $rawParts,
                config('tts.default_output_format'),
                $this->provider->outputContainer(),
                $seamGapsMs,
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Stitch failed: '.$e->getMessage()], 502);
        }

        return response($bytes, 200)->header('Content-Type', $mime);
    }

    /**
     * Concatenate audio chunks the client has ALREADY generated — exactly as
     * production does ({@see AudioConverter::concatenate()}: per-chunk tail-trim
     * + seam silence) — and return the stitched file. The client uploads the raw
     * WAV blobs it is holding so we stitch the very audio the user heard (not a
     * fresh, non-deterministic re-synthesis), which is what reproduces bugs like
     * a short trailing word ("Why?") being trimmed away at a seam.
     *
     * One file -> hear what the trim alone does to that chunk; two adjacent files
     * -> hear what the seam join does between them.
     */
    public function concat(Request $request): Response
    {
        if ($error = $this->validationError($request, [
            'files' => ['required', 'array', 'min:1', 'max:200'],
            'files.*' => ['file', 'max:51200'], // 50 MB/chunk; raw WAV is ~85 KB/s mono
            'breaks' => ['array'],
            'breaks.*' => ['in:sentence,paragraph'],
        ])) {
            return $error;
        }

        $breaks = array_values((array) $request->input('breaks', []));
        $sentenceGap = (int) config('tts.chunk_gap_ms', 120);
        $paragraphGap = (int) config('tts.paragraph_gap_ms', 400);

        $rawParts = [];
        $seamGapsMs = [];
        foreach (array_values($request->file('files')) as $i => $file) {
            $rawParts[] = (string) file_get_contents($file->getRealPath());
            $seamGapsMs[] = (($breaks[$i] ?? 'sentence') === 'paragraph') ? $paragraphGap : $sentenceGap;
        }

        try {
            [$bytes, $mime] = $this->converter->concatenate(
                $rawParts,
                config('tts.default_output_format'),
                $this->provider->outputContainer(),
                $seamGapsMs,
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Concatenation failed: '.$e->getMessage()], 502);
        }

        return response($bytes, 200)->header('Content-Type', $mime);
    }

    /**
     * Chunk with the production knobs so the displayed breakdown matches what the
     * real generation pipeline ({@see \App\Services\SpeechService::process()})
     * produces.
     *
     * @return array<int, array{text: string, breakAfter: 'sentence'|'paragraph'}>
     */
    private function segment(string $text): array
    {
        return $this->chunker->segment(
            $text,
            (int) config('tts.chunk_chars', 280),
            (int) config('tts.block_space_run', 4),
            (int) config('tts.min_chunk_chars', 30),
            (int) config('tts.short_trailer_words', 3),
        );
    }

    /**
     * Validate and, on failure, return a JSON 422 the frontend's fetch handlers
     * understand. The app only auto-renders JSON for api/* and v1/* paths, so
     * these admin AJAX endpoints validate explicitly rather than redirecting.
     */
    private function validationError(Request $request, array $rules): ?JsonResponse
    {
        $validator = Validator::make($request->all(), $rules);

        return $validator->fails()
            ? response()->json(['message' => $validator->errors()->first()], 422)
            : null;
    }

    private function resolveVoice(Request $request): ?Voice
    {
        if ($request->filled('voice')) {
            return Voice::resolve((string) $request->input('voice'));
        }

        return Voice::orderBy('name')->first();
    }

    /**
     * Build provider settings: config defaults, overlaid with the voice's own
     * defaults, then any per-request debug overrides (stability / style / seed).
     *
     * @return array<string, mixed>
     */
    private function settings(Request $request, Voice $voice): array
    {
        $settings = config('tts.default_voice_settings', []);

        if (is_array($voice->settings)) {
            $settings = array_merge($settings, $voice->settings);
        }

        foreach (['stability', 'style'] as $knob) {
            if ($request->filled($knob)) {
                $settings[$knob] = (float) $request->input($knob);
            }
        }

        $seed = $request->filled('seed') ? (int) $request->input('seed') : ($voice->settings['seed'] ?? null);
        if ($seed !== null) {
            $settings['seed'] = (int) $seed;
        }

        return $settings;
    }

    /** Providers read the reference clip from a local filesystem path. */
    private function referencePath(Voice $voice): ?string
    {
        if (! $voice->reference_audio_path) {
            return null;
        }

        return Storage::disk(config('tts.storage_disk'))->path($voice->reference_audio_path);
    }

    /** MIME for the provider's raw output container (wav by default). */
    private function containerMime(): string
    {
        return match (strtolower($this->provider->outputContainer())) {
            'mp3' => 'audio/mpeg',
            'ulaw' => 'audio/basic',
            default => 'audio/wav',
        };
    }
}
