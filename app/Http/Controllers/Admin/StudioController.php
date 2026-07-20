<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ChecksCredit;
use App\Http\Controllers\Controller;
use App\Models\TtsProject;
use App\Models\TuningPreset;
use App\Models\User;
use App\Models\Voice;
use App\Services\Audio\AudioConverter;
use App\Services\Credit\CreditService;
use App\Services\ProjectService;
use App\Services\Pronunciation\PronunciationDetector;
use App\Services\Pronunciation\PronunciationDictionary;
use App\Services\Pronunciation\PronunciationSubstituter;
use App\Services\SpeechService;
use App\Services\SpokenQuotes;
use App\Services\TextChunker;
use App\Services\TextNormalizer;
use App\Services\Tts\ChunkGaps;
use App\Services\Tts\ModelCatalog;
use App\Services\Tts\ParalinguisticTags;
use App\Services\Tts\TtsProvider;
use App\Services\Tts\VoiceReference;
use App\Services\Tts\VoiceSettingsResolver;
use App\Services\VoiceService;
use App\Support\GenerationCost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Studio Inspector — a dry-run of the PROJECT pipeline. Paste text and see
 * exactly what a project made from it would read: normalized, with the writer's
 * approved pronunciation respellings and spoken-quote setting applied, split
 * into chunks (the SAME normalizer + substituter + chunker as
 * {@see ProjectService::createFromText()}), priced at the
 * viewer's rate, and audible two ways:
 *
 *   - each chunk on its own ({@see self::synthesize()}),
 *   - the full production stitch — chunk, synth each, concatenate with seam
 *     silence ({@see self::stitch()}).
 *
 * (A whole-text-as-ONE-call render used to be a third way; it was removed from
 * the UI because no delivery path produces that audio — synthesize() still
 * accepts arbitrary-length text, so the A/B is a curl away when debugging.)
 *
 * Every render path applies the same per-chunk cleanup production uses (edge
 * trim, fades, and the long tail-artifact cut), so what the user hears here
 * matches what a project delivers. Per-chunk renders are stashed by token
 * ({@see self::stashTake()}) so the closing CTA — create a project from these
 * findings ({@see StudioProjectController::storeFromInspector()}) — carries
 * the audio across as real takes instead of throwing paid renders away.
 */
class StudioController extends Controller
{
    use ChecksCredit;

    /** Where per-chunk Inspector renders wait (local disk) for a possible project carry-over. */
    private const STASH_DIR = 'inspector-takes';

    /** Stashed renders not carried into a project within this window are pruned. */
    private const STASH_TTL_HOURS = 24;

    public function __construct(
        private readonly TtsProvider $provider,
        private readonly AudioConverter $converter,
        private readonly TextChunker $chunker,
        private readonly TextNormalizer $normalizer,
        private readonly VoiceSettingsResolver $settingsResolver,
        private readonly CreditService $credit,
        private readonly PronunciationDetector $detector,
        private readonly PronunciationDictionary $dictionary,
        private readonly PronunciationSubstituter $substituter,
        private readonly SpokenQuotes $quotes,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // SuperAdmins see everyone's projects, but the list lands on their own
        // by default: ?owner=<id> narrows to one owner, ?owner=all widens to
        // everyone. Regular users are always scoped to themselves, so the
        // param is ignored for them — it must never widen what they can see.
        $ownerId = null;
        if ($user->isSuperAdmin()) {
            $owner = (string) $request->query('owner', '');
            $ownerId = ctype_digit($owner) ? (int) $owner : ($owner === 'all' ? null : $user->id);
        }

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
            // The owner-filter dropdown's tail: users who actually own a
            // project, minus the signed-in admin — the component renders them
            // first, followed by "All owners".
            'owners' => $user->isSuperAdmin()
                ? User::whereIn('id', TtsProject::whereNotNull('user_id')->select('user_id'))
                    ->whereKeyNot($user->id)
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
     * Run the pasted text through the full project text pipeline and return the
     * breakdown — normalized/respelled text, chunks, applied pronunciations, and
     * a cost estimate. No provider calls — instant — so the user can inspect
     * everything before spending money.
     */
    public function preview(Request $request): JsonResponse
    {
        if ($error = $this->validationError($request, [
            'text' => ['required', 'string', 'max:'.(int) config('tts.max_async_text_length', 40000)],
            'voice' => ['nullable', 'string'],
        ])) {
            return $error;
        }

        $prepared = $this->prepareText((string) $request->input('text'), $request->user()->id);
        $segments = $this->segment($prepared['text']);

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
            'normalized' => $prepared['text'],
            'chars' => mb_strlen($prepared['text']),
            'chunks' => $chunks,
            'pronunciation' => ['applied' => $prepared['applied']],
            'spoken_quotes' => $prepared['quotes'],
            'estimate' => $this->estimatePayload($segments, $this->resolveVoice($request), $request->user()),
        ]);
    }

    /**
     * The PROJECT text pipeline — normalize, apply the writer's approved
     * pronunciation dictionary, voice paired quotes per their setting — so the
     * Inspector shows exactly the text a project created from this tab
     * ({@see ProjectService::createFromText()}) will read.
     * Spoken-quote mode is resolved HERE from the per-user settings overlay,
     * mirroring how the Studio controllers pass it explicitly (never read
     * inside a service — /v1 must stay off).
     *
     * @return array{text: string, applied: list<array{term: string, phonetic: string}>, quotes: int}
     */
    private function prepareText(string $text, ?int $userId): array
    {
        $map = $this->dictionary->approvedMap($userId);

        $substituted = $this->substituter->apply($this->normalizer->normalize($text), $map);
        $quoted = $this->quotes->apply(
            $substituted['text'],
            (string) config('tts.spoken_quotes', SpokenQuotes::MODE_OFF),
            (int) config('tts.block_space_run', 4),
        );

        // term → phonetic pairs for the "applied" readout, in applied order.
        $phonetics = [];
        foreach ($map as $entry) {
            $phonetics[$entry['term']] = $entry['phonetic'];
        }

        return [
            'text' => $quoted['text'],
            'applied' => array_map(
                fn (string $term) => ['term' => $term, 'phonetic' => (string) ($phonetics[$term] ?? '')],
                $substituted['applied'],
            ),
            'quotes' => $quoted['applied'],
        ];
    }

    /**
     * What generating every chunk once will cost, priced like the project spend
     * readouts ({@see StudioProjectController::spendReadout()}): a SuperAdmin
     * sees the actual provider figure (plus what users are billed when a markup
     * is configured); everyone else is quoted at their own marked-up rate.
     * Null hides the readout (no rates configured / nothing to price).
     *
     * @param  array<int, array{text: string, breakAfter: string}>  $segments
     * @return ?array{label: string, title: string, chars: int, balance: ?array{label: string, low: bool}}
     */
    private function estimatePayload(array $segments, ?Voice $voice, User $user): ?array
    {
        if (! GenerationCost::enabled() || $voice === null || $segments === []) {
            return null;
        }

        $chars = 0;
        foreach ($segments as $segment) {
            $chars += mb_strlen($segment['text']);
        }

        $model = ModelCatalog::forVoice($voice);
        $byModel = [$model => $chars];
        $markup = $this->credit->markup();
        $count = count($segments);

        if ($user->isSuperAdmin()) {
            $label = GenerationCost::label($byModel);
            $title = sprintf(
                'Estimated provider cost to render all %d chunk(s) once: %s characters at the %s rate. Re-rendering a chunk bills again.',
                $count,
                number_format($chars),
                ModelCatalog::label($model),
            );
            if ($markup > 1.0) {
                $title .= sprintf(
                    ' Users are billed %s× = %s.',
                    rtrim(rtrim(number_format($markup, 2), '0'), '.'),
                    GenerationCost::label($byModel, $markup),
                );
            }
        } else {
            $label = GenerationCost::label($byModel, $markup);
            $title = sprintf(
                'Estimated cost to render all %d chunk(s) once: %s characters at your account\'s %s rate. Re-rendering a chunk bills again.',
                $count,
                number_format($chars),
                ModelCatalog::label($model),
            );
        }

        return [
            'label' => $label,
            'title' => $title,
            'chars' => $chars,
            // The signed-in user pays for Inspector renders, so show THEIR
            // balance (null = unlimited and the readout stays hidden).
            'balance' => $this->creditBadge($user),
        ];
    }

    /**
     * The "credit $X.XX" badge payload for a signed-in user — server-formatted,
     * null for an unlimited account (the readout stays hidden). Shared by the
     * preview estimate and the per-render response header so the badge tracks
     * spend live instead of going stale until the next Preview. Pass a freshly
     * re-read user after a charge, since {@see CreditService::charge()} debits
     * via query and leaves the in-memory model's balance untouched.
     */
    private function creditBadge(?User $user): ?array
    {
        $balance = $user?->credit_balance_micro;
        if ($balance === null) {
            return null;
        }

        return [
            'label' => 'credit '.CreditService::formatMicro((int) $balance),
            'low' => (int) $balance <= 0,
        ];
    }

    /**
     * Ask the LLM (the same Genblaze chat step the new-project flow uses) for
     * pronunciation suggestions on the pasted text, minus terms the writer has
     * already decided. Degrade-safe: when the feature is off or the LLM is
     * unreachable this answers {available: false} and the Inspector simply
     * doesn't show the panel — never an error in the user's face.
     */
    public function pronunciationSuggestions(Request $request): JsonResponse
    {
        if ($error = $this->validationError($request, [
            'text' => ['required', 'string', 'max:'.(int) config('tts.max_async_text_length', 40000)],
        ])) {
            return $error;
        }

        $userId = $request->user()->id;
        $detection = $this->detector->detect(
            $this->normalizer->normalize((string) $request->input('text')),
            $userId,
        );

        if (! ($detection['available'] ?? false)) {
            return response()->json(['available' => false, 'suggestions' => []]);
        }

        // Drop anything already in the writer's dictionary (the detector gets
        // these as known_terms too — belt-and-suspenders, like the review flow).
        $known = array_map(fn ($t) => mb_strtolower($t), $this->dictionary->knownTerms($userId));
        $suggestions = array_values(array_filter(
            $detection['substitutions'] ?? [],
            fn ($s) => isset($s['term'], $s['phonetic'])
                && ! in_array(mb_strtolower((string) $s['term']), $known, true),
        ));

        return response()->json(['available' => true, 'suggestions' => $suggestions]);
    }

    /**
     * Approve ONE suggestion into the writer's dictionary, straight from the
     * Inspector panel. The entry applies on the next preview — the client
     * prompts a re-run rather than silently rewriting chunks the user may
     * already have rendered.
     */
    public function approvePronunciation(Request $request): JsonResponse
    {
        if ($error = $this->validationError($request, [
            'term' => ['required', 'string', 'max:120'],
            'phonetic' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:60'],
            'confidence' => ['nullable', 'string', 'max:20'],
            'note' => ['nullable', 'string', 'max:500'],
            'match_mode' => ['nullable', 'in:case_sensitive,case_insensitive'],
        ])) {
            return $error;
        }

        $entries = $this->dictionary->approveSuggestions($request->user()->id, [
            $request->only(['term', 'phonetic', 'category', 'confidence', 'note', 'match_mode']),
        ]);

        return $entries->isEmpty()
            ? response()->json(['message' => 'Nothing to add.'], 422)
            : response()->json(['ok' => true]);
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
            'stash' => ['nullable', 'boolean'],
        ])) {
            return $error;
        }

        if ($error = $this->creditError($request->user())) {
            return $error;
        }

        $voice = $this->resolveVoice($request);
        if (! $voice) {
            return response()->json(['message' => 'No voice configured — add a voice first.'], 422);
        }

        try {
            $settings = $this->settings($request, $voice);
            $raw = $this->provider->synthesize(
                (string) $request->input('text'),
                $this->referencePath($voice),
                $settings,
            );

            // Inspector renders bypass SpeechService/ProjectService, so this
            // is their only billing point (ledger-only — no spend counters;
            // the counters increment if/when the render is carried into a
            // project, where that spend then lives).
            $this->credit->charge(
                $request->user()->id,
                mb_strlen((string) $request->input('text')),
                ModelCatalog::forVoice($voice),
                'inspector',
            );

            // Per-chunk renders ('stash') keep their RAW provider bytes on
            // disk so "Create a project" can carry this exact take across —
            // raw, like ProjectService stores takes, so the production
            // cleanup below is never baked in twice.
            $token = $request->boolean('stash')
                ? $this->stashTake($request->user()->id, $raw, $voice, $settings, (string) $request->input('text'))
                : null;

            // Run the same per-chunk cleanup production uses (edge trim, fades,
            // and the long tail-artifact cut — skipped when the text ends in a
            // rendered sound tag) so the preview matches what users actually
            // receive.
            [$bytes, $mime] = $this->converter->concatenate(
                [$raw],
                'wav',
                $this->provider->outputContainer(),
                [],
                [$this->preservesTail($voice, (string) $request->input('text'))],
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Generation failed: '.$e->getMessage()], 502);
        }

        $response = response($bytes, 200)->header('Content-Type', $mime);
        if ($token !== null) {
            $response->header('X-Inspector-Take', $token);
        }
        // The charge above already debited the balance; hand the fresh figure
        // back so the "credit" badge updates live (omitted for unlimited).
        if ($badge = $this->creditBadge($request->user()->fresh())) {
            $response->header('X-Credit-Balance', (string) json_encode($badge));
        }

        return $response;
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

        if ($error = $this->creditError($request->user())) {
            return $error;
        }

        $voice = $this->resolveVoice($request);
        if (! $voice) {
            return response()->json(['message' => 'No voice configured — add a voice first.'], 422);
        }

        // Same text pipeline as preview(), so the stitched render speaks the
        // exact chunks the breakdown showed (respellings and quotes included).
        $segments = $this->segment($this->prepareText((string) $request->input('text'), $request->user()->id)['text']);
        if ($segments === []) {
            return response()->json(['message' => 'Nothing to synthesize.'], 422);
        }

        try {
            $reference = $this->referencePath($voice);
            $settings = $this->settings($request, $voice);

            $rawParts = [];
            $seamGapsMs = [];
            $preserveTails = [];
            foreach ($segments as $i => $segment) {
                $rawParts[] = $this->provider->synthesize($segment['text'], $reference, $settings);
                // Paid the moment the provider returns — charge per segment so
                // a later failure doesn't hide money already spent.
                $this->credit->charge(
                    $request->user()->id,
                    mb_strlen($segment['text']),
                    ModelCatalog::forVoice($voice),
                    'inspector',
                );
                $seamGapsMs[] = ChunkGaps::seamGap(
                    $segment['breakAfter'],
                    $segment['text'],
                    $segments[$i + 1]['text'] ?? '',
                );
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

        $response = response($bytes, 200)->header('Content-Type', $mime);
        // Same live-balance refresh as synthesize(): the per-segment charges
        // above have already debited the owner (omitted for unlimited).
        if ($badge = $this->creditBadge($request->user()->fresh())) {
            $response->header('X-Credit-Balance', (string) json_encode($badge));
        }

        return $response;
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
            'breaks.*' => ['in:sentence,paragraph,continuation'],
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

        $rawParts = [];
        $seamGapsMs = [];
        $preserveTails = [];
        foreach (array_values($request->file('files')) as $i => $file) {
            $rawParts[] = (string) file_get_contents($file->getRealPath());
            $seamGapsMs[] = ChunkGaps::seamGap(
                (string) ($breaks[$i] ?? 'sentence'),
                (string) ($texts[$i] ?? ''),
                (string) ($texts[$i + 1] ?? ''),
            );
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
     * travels inside the settings array here — request-only, since the
     * inspector feeds Studio project creation, where seed is a chunk-level
     * pin, not a voice default (that default stays for the seedless /v1 API
     * and CLI paths).
     *
     * @return array<string, mixed>
     */
    private function settings(Request $request, Voice $voice): array
    {
        $settings = $this->settingsResolver->resolve($voice, $this->overrides($request));

        if ($request->filled('seed')) {
            $settings['seed'] = (int) $request->input('seed');
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

    /**
     * Park a per-chunk render's RAW provider bytes (plus a provenance sidecar:
     * the exact text read, the voice, and the tuning/seed used) under a random
     * token on the LOCAL disk, so {@see StudioProjectController::storeFromInspector()}
     * can later attach it to the matching project chunk as a real take. Local
     * because these are ephemeral — never the tts storage disk, whose orphan
     * sweep and receipts must not see half-adopted audio. The sidecar is the
     * server's own record: attachment trusts it, not the client's claims.
     */
    private function stashTake(int $userId, string $bytes, Voice $voice, array $settings, string $text): string
    {
        $this->pruneStash($userId);

        $token = (string) Str::uuid();
        $disk = Storage::disk('local');
        $disk->put(self::stashPath($userId, $token), $bytes);

        $seed = isset($settings['seed']) ? (int) $settings['seed'] : 0;
        $disk->put(self::stashPath($userId, $token, 'json'), (string) json_encode([
            'text' => $text,
            'voice_id' => $voice->id,
            // The knobs this render actually used, trimmed to what a take's
            // settings snapshot shows (same key set as ProjectService::tuningOnly()).
            'settings' => array_intersect_key($settings, array_flip([
                'stability', 'style', 'exaggeration', 'cfg_weight', 'temperature',
                'top_p', 'top_k', 'repetition_penalty',
            ])),
            // Chatterbox convention: seed <= 0 means "the provider picked one"
            // — recorded as null so the take never fakes a reproducible pin.
            'seed' => $seed > 0 ? $seed : null,
        ]));

        return $token;
    }

    /** A stashed Inspector render's file, scoped to its owner (no cross-user reads possible). */
    public static function stashPath(int $userId, string $token, string $ext = 'wav'): string
    {
        return self::STASH_DIR.'/'.$userId.'/'.$token.'.'.$ext;
    }

    /** Drop this user's stashed renders that outlived the carry-over window. */
    private function pruneStash(int $userId): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subHours(self::STASH_TTL_HOURS)->getTimestamp();

        foreach ($disk->files(self::STASH_DIR.'/'.$userId) as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
            }
        }
    }
}
