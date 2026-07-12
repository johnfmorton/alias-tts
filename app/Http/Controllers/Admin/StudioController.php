<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TtsProject;
use App\Models\TuningPreset;
use App\Models\User;
use App\Models\Voice;
use App\Services\Audio\AudioConverter;
use App\Services\SpeechService;
use App\Services\TextChunker;
use App\Services\TextNormalizer;
use App\Services\Tts\ModelCatalog;
use App\Services\Tts\ParalinguisticTags;
use App\Services\Tts\TtsProvider;
use App\Services\Tts\VoiceReference;
use App\Services\Tts\VoiceSettingsResolver;
use App\Services\VoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
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
 *   - each chunk on its own ({@see self::synthesize()}),
 *   - the full production stitch — chunk, synth each, concatenate with seam
 *     silence ({@see self::stitch()}).
 *
 * Every render path applies the same per-chunk cleanup production uses (edge
 * trim, fades, and the long tail-artifact cut), so what an admin hears in Studio
 * matches what users receive. This is the read-only foundation for the
 * editable-project work (Phase 2).
 */
class StudioController extends Controller
{
    public function __construct(
        private readonly TtsProvider $provider,
        private readonly AudioConverter $converter,
        private readonly TextChunker $chunker,
        private readonly TextNormalizer $normalizer,
        private readonly VoiceSettingsResolver $settingsResolver,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // SuperAdmins can narrow the everyone's-projects list to a single owner
        // (?owner=<id>). Regular users are always scoped to themselves, so the
        // param is ignored for them — it must never widen what they can see.
        $ownerId = $user->isSuperAdmin() && ctype_digit((string) $request->query('owner'))
            ? (int) $request->query('owner')
            : null;

        return view('admin.studio.index', [
            'voices' => Voice::orderedFor($user->id)->get(),
            // Projects are personal; a SuperAdmin sees everyone's, labeled by owner.
            // Paginated so a growing list never buries the Inspector tab; page size
            // is env-tunable (TTS_STUDIO_PROJECTS_PER_PAGE, default 10). The tab's
            // count badge reads the paginator total, not the page count.
            'projects' => TtsProject::withCount('chunks')
                ->when(! $user->isSuperAdmin(), fn ($q) => $q->where('user_id', $user->id))
                ->when($user->isSuperAdmin(), fn ($q) => $q->with('user:id,name'))
                ->when($ownerId !== null, fn ($q) => $q->where('user_id', $ownerId))
                ->latest()
                ->paginate(max(1, (int) config('tts.studio_projects_per_page', 10)))
                ->withQueryString(),
            // The owner-filter dropdown: only users who actually own a project.
            'owners' => $user->isSuperAdmin()
                ? User::whereIn('id', TtsProject::whereNotNull('user_id')->select('user_id'))
                    ->orderBy('name')->get(['id', 'name'])
                : collect(),
            'ownerId' => $ownerId,
        ]);
    }

    /**
     * Save a named exaggeration/cfg_weight preset for reuse in the tuning bench
     * and the project/chunk delivery pickers. Presets are personal: the name only
     * has to be unique within the signed-in user's own set.
     */
    public function storePreset(Request $request): JsonResponse
    {
        if ($error = $this->validationError($request, [
            'name' => ['required', 'string', 'max:60',
                Rule::unique('tuning_presets', 'name')->where('user_id', $request->user()->id)],
            'exaggeration' => ['nullable', 'numeric', 'between:0.25,2'],
            'cfg_weight' => ['nullable', 'numeric', 'between:0.2,1'],
            'temperature' => ['nullable', 'numeric', 'between:0.5,1.5'],
            'top_p' => ['nullable', 'numeric', 'between:0.5,1'],
            'top_k' => ['nullable', 'integer', 'between:1,2000'],
            'repetition_penalty' => ['nullable', 'numeric', 'between:1,2'],
            'model' => ['nullable', 'string', Rule::in(ModelCatalog::keys())],
        ])) {
            return $error;
        }

        // The engine the preset was authored for; classic stores as NULL (the
        // pre-catalog shape) and pickers filter by it.
        $model = (string) $request->input('model', ModelCatalog::DEFAULT);

        $preset = TuningPreset::create([
            'user_id' => $request->user()->id,
            'name' => trim((string) $request->input('name')),
            'exaggeration' => $request->filled('exaggeration') ? (float) $request->input('exaggeration') : null,
            'cfg_weight' => $request->filled('cfg_weight') ? (float) $request->input('cfg_weight') : null,
            'temperature' => $request->filled('temperature') ? (float) $request->input('temperature') : null,
            'top_p' => $request->filled('top_p') ? (float) $request->input('top_p') : null,
            'top_k' => $request->filled('top_k') ? (int) $request->input('top_k') : null,
            'repetition_penalty' => $request->filled('repetition_penalty') ? (float) $request->input('repetition_penalty') : null,
            'model' => $model === ModelCatalog::DEFAULT ? null : $model,
        ]);

        return response()->json([
            'ok' => true,
            'preset' => [
                'id' => $preset->id,
                'name' => $preset->name,
                'exaggeration' => $preset->exaggeration,
                'cfg_weight' => $preset->cfg_weight,
                'temperature' => $preset->temperature,
                'top_p' => $preset->top_p,
                'top_k' => $preset->top_k,
                'repetition_penalty' => $preset->repetition_penalty,
                'model' => $preset->engineModel(),
            ],
        ]);
    }

    public function destroyPreset(Request $request, TuningPreset $preset): JsonResponse
    {
        // Personal: another user's preset is as good as nonexistent.
        if ($preset->user_id !== $request->user()->id && ! $request->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Unknown preset.'], 404);
        }

        $preset->delete();

        return response()->json(['ok' => true]);
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

            // Run the same per-chunk cleanup production uses (edge trim, fades,
            // and the long tail-artifact cut — skipped when the text ends in a
            // rendered sound tag) so the preview matches what users actually
            // receive.
            [$bytes, $mime] = $this->converter->concatenate(
                [$bytes],
                'wav',
                $this->provider->outputContainer(),
                [],
                [$this->preservesTail($voice, (string) $request->input('text'))],
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Generation failed: '.$e->getMessage()], 502);
        }

        return response($bytes, 200)->header('Content-Type', $mime);
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
            $preserveTails = [];
            foreach ($segments as $segment) {
                $rawParts[] = $this->provider->synthesize($segment['text'], $reference, $settings);
                $seamGapsMs[] = $segment['breakAfter'] === 'paragraph' ? $paragraphGap : $sentenceGap;
                $preserveTails[] = $this->preservesTail($voice, $segment['text']);
            }

            [$bytes, $mime] = $this->converter->concatenate(
                $rawParts,
                config('tts.default_output_format'),
                $this->provider->outputContainer(),
                $seamGapsMs,
                $preserveTails,
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
            // Each uploaded blob's source text + the voice it rendered with, so
            // the trim can spare a rendered trailing sound tag exactly like
            // production would (absent = the full cleanup, as before).
            'voice' => ['nullable', 'string'],
            'texts' => ['array'],
            'texts.*' => ['nullable', 'string'],
        ])) {
            return $error;
        }

        $breaks = array_values((array) $request->input('breaks', []));
        $texts = array_values((array) $request->input('texts', []));
        $voice = $request->filled('voice') ? Voice::resolveFor((string) $request->input('voice'), $request->user()->id) : null;
        $sentenceGap = (int) config('tts.chunk_gap_ms', 120);
        $paragraphGap = (int) config('tts.paragraph_gap_ms', 400);

        $rawParts = [];
        $seamGapsMs = [];
        $preserveTails = [];
        foreach (array_values($request->file('files')) as $i => $file) {
            $rawParts[] = (string) file_get_contents($file->getRealPath());
            $seamGapsMs[] = (($breaks[$i] ?? 'sentence') === 'paragraph') ? $paragraphGap : $sentenceGap;
            $preserveTails[] = $voice !== null && $this->preservesTail($voice, (string) ($texts[$i] ?? ''));
        }

        try {
            [$bytes, $mime] = $this->converter->concatenate(
                $rawParts,
                config('tts.default_output_format'),
                $this->provider->outputContainer(),
                $seamGapsMs,
                $preserveTails,
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Concatenation failed: '.$e->getMessage()], 502);
        }

        return response($bytes, 200)->header('Content-Type', $mime);
    }

    /**
     * Chunk with the production knobs so the displayed breakdown matches what the
     * real generation pipeline ({@see SpeechService::process()})
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
            (string) config('tts.chunk_mode', TextChunker::MODE_PACKED),
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
            return Voice::resolveFor((string) $request->input('voice'), $request->user()->id);
        }

        // No voice given: fall back to the first of the user's own ordering.
        return Voice::orderedFor($request->user()->id)->first();
    }

    /**
     * Persist the per-user "Advanced tuning" toggle so Studio remembers whether
     * to reveal the knobs + A/B bench on the next visit. Default off keeps the
     * inspector friendly. See docs/STUDIO-TUNING.md.
     */
    public function setAdvanced(Request $request): JsonResponse
    {
        $request->user()->update(['studio_advanced' => $request->boolean('enabled')]);

        return response()->json(['ok' => true]);
    }

    /**
     * "Save to voice defaults" from the tuning bench: write the chosen native
     * knobs (exaggeration/cfg_weight) onto the voice so every future request
     * (including the plugin's) inherits them when it doesn't send its own. A blank
     * knob clears that default.
     */
    public function saveVoiceDefaults(Request $request, VoiceService $voices): JsonResponse
    {
        if ($error = $this->validationError($request, [
            'voice' => ['required', 'string'],
            'exaggeration' => ['nullable', 'numeric', 'between:0.25,2'],
            'cfg_weight' => ['nullable', 'numeric', 'between:0.2,1'],
            'temperature' => ['nullable', 'numeric', 'between:0.5,1.5'],
            'top_p' => ['nullable', 'numeric', 'between:0.5,1'],
            'top_k' => ['nullable', 'integer', 'between:1,2000'],
            'repetition_penalty' => ['nullable', 'numeric', 'between:1,2'],
        ])) {
            return $error;
        }

        $voice = Voice::resolveFor((string) $request->input('voice'), $request->user()->id);
        if (! $voice) {
            return response()->json(['message' => 'Unknown voice.'], 422);
        }

        // Shared voices (the built-ins) sound the same for every user — writing
        // tuning onto one would change what everyone hears. Same rule as the
        // voice edit form; the escape hatch is "Duplicate" on the Voices page.
        if (! $voice->isManagedBy($request->user())) {
            return response()->json([
                'message' => "\"{$voice->name}\" is shared with every user. Duplicate it on the Voices page to tune your own copy.",
            ], 403);
        }

        $voices->saveTuning($voice, $this->overrides($request));

        return response()->json(['ok' => true, 'message' => "Saved as {$voice->name}'s defaults."]);
    }

    /**
     * Build provider settings through the shared {@see VoiceSettingsResolver}
     * (config defaults -> voice defaults -> per-request debug overrides), then
     * fold in the seed. The inspector calls the provider directly, so the seed
     * travels inside the settings array here (request override -> voice default).
     *
     * @return array<string, mixed>
     */
    private function settings(Request $request, Voice $voice): array
    {
        $settings = $this->settingsResolver->resolve($voice, $this->overrides($request));

        $seed = $request->filled('seed') ? (int) $request->input('seed') : ($voice->settings['seed'] ?? null);
        if ($seed !== null) {
            $settings['seed'] = (int) $seed;
        }

        // The chosen voice picks the engine (reserved keys ride OUTSIDE the
        // resolver, which whitelists them away).
        return ModelCatalog::stamp($settings, $voice);
    }

    /**
     * The tunable knobs the request explicitly set. The Studio speaks the
     * engines' native knobs (exaggeration/cfg_weight for classic Chatterbox,
     * top_p/top_k/repetition_penalty for Turbo, temperature for both); the
     * provider/resolver also accept the ElevenLabs stability/style the public
     * /v1 API speaks. Foreign-engine keys are ignored downstream, never errors.
     *
     * @return array<string, float|int>
     */
    private function overrides(Request $request): array
    {
        $overrides = [];
        foreach (['exaggeration', 'cfg_weight', 'temperature', 'top_p', 'repetition_penalty'] as $knob) {
            if ($request->filled($knob)) {
                $overrides[$knob] = (float) $request->input($knob);
            }
        }

        if ($request->filled('top_k')) {
            $overrides['top_k'] = (int) $request->input('top_k');
        }

        return $overrides;
    }

    /** A readable local path to the voice's reference clip (cached from S3 if needed). */
    private function referencePath(Voice $voice): ?string
    {
        return VoiceReference::localPath($voice);
    }

    /**
     * Whether audio rendered from $text by $voice ends in a WANTED sound (a
     * turbo tag like [laugh]) — the tail-artifact trim must spare it.
     */
    private function preservesTail(Voice $voice, string $text): bool
    {
        return ModelCatalog::supportsTags(ModelCatalog::forVoice($voice))
            && ParalinguisticTags::endsWith($text);
    }
}
