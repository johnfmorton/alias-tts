<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\PruneRecoveryProjects;
use App\Enums\ChunkStatus;
use App\Http\Controllers\Concerns\ChecksCredit;
use App\Http\Controllers\Concerns\ServesRangedAudio;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateProjectChunksJob;
use App\Models\TtsChunk;
use App\Models\TtsChunkTake;
use App\Models\TtsProject;
use App\Models\TtsProjectJob;
use App\Models\TuningPreset;
use App\Models\User;
use App\Models\Voice;
use App\Services\Credit\CreditService;
use App\Services\ProjectExportService;
use App\Services\ProjectService;
use App\Services\Pronunciation\PronunciationDetector;
use App\Services\Pronunciation\PronunciationDictionary;
use App\Services\SpokenQuotes;
use App\Services\TextNormalizer;
use App\Services\Tts\ChatterboxTuning;
use App\Services\Tts\ChatterboxTurboTuning;
use App\Services\Tts\DeliveryPresets;
use App\Services\Tts\ModelCatalog;
use App\Services\Tts\VoiceSettingsResolver;
use App\Support\GenerationCost;
use App\Support\GenerationEstimator;
use App\Support\SpendCounters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Editable TTS projects: create from text, generate/regenerate individual
 * chunks, rebuild the stitched final file, and download it. The per-chunk
 * generate/edit/rebuild endpoints are AJAX (JSON), so — like {@see
 * StudioController} — they validate explicitly and return JSON rather than the
 * redirect the app's default handler gives admin routes.
 */
class StudioProjectController extends Controller
{
    use ChecksCredit, ServesRangedAudio;

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectExportService $export,
        private readonly VoiceSettingsResolver $settingsResolver,
        private readonly TextNormalizer $normalizer,
        private readonly PronunciationDetector $detector,
        private readonly PronunciationDictionary $dictionary,
        private readonly CreditService $credit,
    ) {}

    public function create(Request $request): View
    {
        // The user's own voices + built-ins, in THEIR drag order (Voices page).
        // The first voice in that order is pre-selected — reordering is how a
        // user picks their effective default voice.
        $voices = Voice::orderedFor($request->user()->id)->get();

        return view('admin.studio.projects.create', [
            'voices' => $voices,
            'defaultVoiceSlug' => $voices->first()?->slug,
            // The user's named tuning presets, offered as an optional "delivery"
            // pick that seeds this project's tuning.
            'presets' => TuningPreset::forUser($request->user()->id)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->createRules());

        $voice = Voice::resolveFor($data['voice'], $request->user()->id);
        if (! $voice) {
            return back()->withInput()->with('error', 'Unknown voice.');
        }

        $project = $this->persist($request, $data, $voice, $data['text'], $this->dictionary->approvedMap($request->user()?->id));

        return redirect()->route('admin.studio.projects.show', $project)
            ->with('success', 'Project created — generate the chunks below.');
    }

    /**
     * The Inspector's closing CTA (AJAX): create a project from the inspected
     * text — same pipeline as {@see store()} (approved dictionary + spoken
     * quotes) — and carry across any chunk renders the user already paid for
     * there. The client sends the stash tokens {@see StudioController::synthesize()}
     * minted; each token's RAW bytes attach to the chunk whose text and voice
     * match the render's own provenance sidecar (a mismatch — say the text was
     * edited, or the voice switched, after rendering — just leaves that chunk
     * pending: stored audio must never contradict its script). Credit is NOT
     * charged again; those renders were billed at synthesis time.
     */
    public function storeFromInspector(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->createRules() + [
            'takes' => ['array', 'max:500'],
            'takes.*.index' => ['required', 'integer', 'min:0'],
            'takes.*.token' => ['required', 'uuid'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }
        $data = $validator->validated();

        $voice = Voice::resolveFor($data['voice'], $request->user()->id);
        if (! $voice) {
            return response()->json(['message' => 'Unknown voice.'], 422);
        }

        // Blank title → the same text-snippet naming API-made projects get.
        if (trim((string) ($data['title'] ?? '')) === '') {
            $data['title'] = trim(mb_substr($data['text'], 0, 40));
        }

        $project = $this->persist($request, $data, $voice, $data['text'], $this->dictionary->approvedMap($request->user()->id));

        $attached = $this->attachInspectorTakes($request, $project, $voice, (array) ($data['takes'] ?? []));

        $request->session()->flash('success', $attached > 0
            ? sprintf('Project created — %d Inspector render%s carried over as %s.',
                $attached, $attached === 1 ? '' : 's', $attached === 1 ? 'a take' : 'takes')
            : 'Project created — generate the chunks below.');

        return response()->json([
            'ok' => true,
            'url' => route('admin.studio.projects.show', $project),
            'attached' => $attached,
        ]);
    }

    /**
     * Adopt stashed Inspector renders as takes on their matching chunks and
     * count how many made it. Trusts only the server-written sidecar (text,
     * voice, knobs, seed), never the client's claims; consumed stash files are
     * deleted, unusable ones are left for the TTL prune.
     *
     * @param  list<array{index: int|string, token: string}>  $takes
     */
    private function attachInspectorTakes(Request $request, TtsProject $project, Voice $voice, array $takes): int
    {
        if ($takes === []) {
            return 0;
        }

        $disk = Storage::disk('local');
        $chunks = $project->chunks()->orderBy('position')->get()->keyBy('position');
        $attached = 0;

        foreach ($takes as $take) {
            $chunk = $chunks->get((int) $take['index']);
            $path = StudioController::stashPath($request->user()->id, (string) $take['token']);
            $metaPath = StudioController::stashPath($request->user()->id, (string) $take['token'], 'json');

            $meta = $disk->exists($metaPath) ? json_decode((string) $disk->get($metaPath), true) : null;

            $usable = $chunk !== null
                && is_array($meta)
                && (string) ($meta['text'] ?? '') === $chunk->text
                && (string) ($meta['voice_id'] ?? '') === (string) $voice->id
                && $disk->exists($path);

            if (! $usable) {
                continue;
            }

            $this->projects->attachInspectorTake(
                $chunk,
                (string) $disk->get($path),
                is_array($meta['settings'] ?? null) ? $meta['settings'] : [],
                isset($meta['seed']) && (int) $meta['seed'] > 0 ? (int) $meta['seed'] : null,
            );

            $disk->delete([$path, $metaPath]);
            $attached++;
        }

        return $attached;
    }

    /**
     * Pronunciation gate: between submitting text and chunking, ask the LLM (a
     * Genblaze chat step) for respelling suggestions. When the feature is off, the
     * LLM is unavailable, or nothing new is found, this transparently creates the
     * project — applying any already-approved dictionary entries — and skips the
     * review screen entirely. Never blocks on the LLM.
     */
    public function review(Request $request): RedirectResponse|View
    {
        $data = $request->validate($this->createRules() + [
            'detect_token' => ['nullable', 'string'],
        ]);

        $voice = Voice::resolveFor($data['voice'], $request->user()->id);
        if (! $voice) {
            return back()->withInput()->with('error', 'Unknown voice.');
        }

        $userId = $request->user()?->id;

        // The async create flow ({@see detect()} + initCreateProjectForm) has
        // already run the ~minute-long LLM check and posts back its one-shot
        // token — render the review screen from those cached suggestions rather
        // than paying for the check a second time. A missing/expired token (JS
        // off, or a direct POST) falls back to running the check inline here.
        $detected = $this->pullCachedSuggestions($request, $userId)
            ?? $this->detectSuggestions($data['text'], $userId);
        $suggestions = $detected['suggestions'];

        // Nothing to review → apply the existing dictionary and create now.
        if ($suggestions === []) {
            return redirect()
                ->route('admin.studio.projects.show', $this->persistWithDictionary($request, $data, $voice, $userId))
                ->with('success', 'Project created — generate the chunks below.');
        }

        return view('admin.studio.projects.review', [
            'suggestions' => $suggestions,
            'voice' => $voice,
            'params' => [
                'title' => (string) ($data['title'] ?? ''),
                'text' => $data['text'],
                'voice' => $data['voice'],
                'seed' => $data['seed'] ?? null,
                'preset' => $data['preset'] ?? null,
                'exaggeration' => $data['exaggeration'] ?? null,
                'cfg_weight' => $data['cfg_weight'] ?? null,
                'temperature' => $data['temperature'] ?? null,
            ],
            'provenance' => $detected['provenance'] ?? null,
        ]);
    }

    /**
     * Async pronunciation gate (JSON): the create form's JS calls this up front
     * so the slow LLM check runs behind a live "Checking pronunciations…" state
     * with a Skip button that can abort it. Creates NOTHING — it only reports
     * whether there's anything to review, so aborting or skipping is always safe
     * (no half-made project, no duplicate). When suggestions exist they're
     * stashed under a one-shot token the client posts to {@see review()}, which
     * renders the screen without re-running the check.
     */
    public function detect(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->createRules());
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }
        $data = $validator->validated();

        $userId = $request->user()?->id;
        $detected = $this->detectSuggestions($data['text'], $userId);

        // Nothing to review → tell the client to create straight away (store()).
        if ($detected['suggestions'] === []) {
            return response()->json(['skip' => true]);
        }

        $token = (string) Str::uuid();
        Cache::put($this->detectCacheKey($userId, $token), $detected, now()->addMinutes(10));

        return response()->json(['token' => $token]);
    }

    /**
     * Run the LLM pronunciation check and shape the raw substitutions into the
     * review screen's rows: drop terms already in the writer's dictionary (the
     * detector passes these as known_terms too — belt-and-suspenders), mark
     * ones the writer declined before so they never come back pre-checked, and
     * pre-check only high-confidence newcomers.
     *
     * @return array{suggestions: list<array<string, mixed>>, provenance: array<string, mixed>|null}
     */
    private function detectSuggestions(string $text, ?int $userId): array
    {
        $normalized = $this->normalizer->normalize($text);
        $detection = $this->detector->detect($normalized, $userId);

        $known = array_map(fn ($t) => mb_strtolower($t), $this->dictionary->knownTerms($userId));
        $suggestions = array_values(array_filter(
            $detection['substitutions'] ?? [],
            fn ($s) => isset($s['term']) && ! in_array(mb_strtolower((string) $s['term']), $known, true),
        ));

        $rejected = $this->dictionary->rejectedTerms($userId);
        $suggestions = array_map(function (array $s) use ($rejected) {
            $s['previously_rejected'] = in_array(mb_strtolower((string) $s['term']), $rejected, true);
            $s['checked'] = ! $s['previously_rejected'] && ($s['confidence'] ?? '') === 'high';

            return $s;
        }, $suggestions);

        return ['suggestions' => $suggestions, 'provenance' => $detection['provenance'] ?? null];
    }

    /**
     * Consume the one-shot token {@see detect()} minted, returning its cached
     * suggestions when present and valid. Scoped to the user and pulled (not
     * peeked), so a token is good for exactly one review render.
     *
     * @return array{suggestions: list<array<string, mixed>>, provenance: array<string, mixed>|null}|null
     */
    private function pullCachedSuggestions(Request $request, ?int $userId): ?array
    {
        $token = trim((string) $request->input('detect_token', ''));
        if ($token === '') {
            return null;
        }

        $cached = Cache::pull($this->detectCacheKey($userId, $token));

        return is_array($cached) ? $cached : null;
    }

    private function detectCacheKey(?int $userId, string $token): string
    {
        return "pron_detect:{$userId}:{$token}";
    }

    /**
     * Persist the writer's approved suggestions, apply the full dictionary to the
     * project text, then create the project (the chunking screen follows).
     */
    public function applyAndStore(Request $request): RedirectResponse
    {
        $data = $request->validate($this->createRules() + [
            'approve' => ['array'],
            'substitutions' => ['array'],
            'substitutions.*.term' => ['required_with:substitutions', 'string'],
            'substitutions.*.phonetic' => ['required_with:substitutions', 'string'],
            'substitutions.*.category' => ['nullable', 'string'],
            'substitutions.*.confidence' => ['nullable', 'string'],
            'substitutions.*.note' => ['nullable', 'string'],
            'substitutions.*.match_mode' => ['nullable', 'in:case_sensitive,case_insensitive'],
        ]);

        $voice = Voice::resolveFor($data['voice'], $request->user()->id);
        if (! $voice) {
            return back()->withInput()->with('error', 'Unknown voice.');
        }

        $userId = $request->user()?->id;

        // Checked rows join the dictionary; unchecked rows are remembered as
        // declined so the review screen stops pre-checking them next time.
        $approvedIdx = array_flip(array_map('intval', (array) $request->input('approve', [])));
        $approved = array_values(array_intersect_key($data['substitutions'] ?? [], $approvedIdx));
        $declined = array_values(array_diff_key($data['substitutions'] ?? [], $approvedIdx));
        if ($approved !== []) {
            $this->dictionary->approveSuggestions($userId, $approved);
        }
        if ($declined !== []) {
            $this->dictionary->rejectSuggestions($userId, $declined);
        }

        return redirect()
            ->route('admin.studio.projects.show', $this->persistWithDictionary($request, $data, $voice, $userId))
            ->with('success', 'Project created — pronunciations applied. Generate the chunks below.');
    }

    /**
     * Create a project, applying the writer's approved dictionary. The ORIGINAL
     * text is stored as the project's source; the substitution is applied (inside
     * {@see ProjectService::createFromText}) only to the chunked/normalized text,
     * so "Start over" re-opens what the writer actually typed.
     */
    private function persistWithDictionary(Request $request, array $data, Voice $voice, ?int $userId): TtsProject
    {
        return $this->persist($request, $data, $voice, $data['text'], $this->dictionary->approvedMap($userId));
    }

    /**
     * @param  list<array{term: string, phonetic: string, match_mode?: string}>  $pronunciationMap
     */
    private function persist(Request $request, array $data, Voice $voice, string $text, array $pronunciationMap = []): TtsProject
    {
        return $this->projects->createFromText(
            title: (string) ($data['title'] ?? ''),
            voice: $voice,
            text: $text,
            settings: $this->settings($request, $voice),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.project_output_format') ?: config('tts.default_output_format'),
            // Studio's seed control is chunk-level only ("Take & tuning" pins a
            // re-roll) — a new project starts unpinned even if the voice carries
            // a default seed for the seedless /v1 API and CLI paths.
            seed: $request->filled('seed') ? (int) $request->input('seed') : null,
            pronunciationMap: $pronunciationMap,
            // The requester's per-user setting (ApplyUserSettings middleware),
            // resolved here — never inside ProjectService — so /v1 stays off.
            spokenQuotes: (string) config('tts.spoken_quotes', SpokenQuotes::MODE_OFF),
            userId: $request->user()?->id,
        );
    }

    /** @return array<string, array<int, string>> */
    private function createRules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:200'],
            'text' => ['required', 'string', 'max:'.(int) config('tts.max_async_text_length', 40000)],
            'voice' => ['required', 'string'],
            'seed' => ['nullable', 'integer', 'min:0'],
            'preset' => ['nullable', 'integer'],
            'exaggeration' => ['nullable', 'numeric', 'between:0.25,2'],
            'cfg_weight' => ['nullable', 'numeric', 'between:0.2,1'],
            'temperature' => ['nullable', 'numeric', 'between:0.5,1.5'],
            'top_p' => ['nullable', 'numeric', 'between:0.5,1'],
            'top_k' => ['nullable', 'integer', 'between:1,2000'],
            'repetition_penalty' => ['nullable', 'numeric', 'between:1,2'],
        ];
    }

    public function show(Request $request, TtsProject $project): View
    {
        $project->load('voice');
        $chunks = $project->chunks()->with(['takes', 'voice'])->get();
        $hasActiveRun = TtsProjectJob::activeFor($project->id) !== null;

        return view('admin.studio.projects.show', [
            'project' => $project,
            // A background "Generate remaining" run is queued/working — the page
            // resumes following it (polling) instead of offering a fresh start.
            'hasActiveRun' => $hasActiveRun,
            // "About 2 min to generate the 40 remaining clips" — the up-front
            // estimate from learned history, shown until a run takes the line
            // over. Suppressed while a run is already following.
            'preRunEstimate' => $hasActiveRun ? null : $this->preRunEstimate($project, $chunks),
            // The access policy lets a SuperAdmin open anyone's project for
            // support. When this viewer is not the owner, the page names the
            // owner and gates the first edit behind a warning dialog.
            'foreignOwner' => $this->foreignOwner($request, $project),
            'chunks' => $chunks,
            // Per-model spend splits, pre-rendered into viewer-aware readouts
            // (a limited user sees marked-up prices; a SuperAdmin the actual
            // figures — see spendReadout()). Loaded in one query per owner
            // type; a chunk/project with no counter rows falls back to its
            // legacy all-chatterbox total.
            'projectSpendReadout' => $this->spendReadout(
                SpendCounters::forOwner('project', $project->id, (int) $project->spent_characters),
                'project',
            ),
            'chunkSpendReadouts' => $this->chunkSpendReadouts($chunks),
            // The project OWNER's remaining prepaid credit (null = unlimited,
            // which hides the readout). Fresh read: charges are query-builder
            // decrements the bound models never see.
            'creditBalance' => $this->ownerBalanceMicro($project),
            'voices' => Voice::orderedFor($this->projectOwnerId($request, $project))->get(),
            // Offered final-audio formats for the header picker (token => "MP3"/"WAV").
            'outputFormats' => $this->outputFormatLabels(),
            // Named presets for the per-chunk "apply preset" pick (fills the
            // chunk's knobs client-side; saving still goes through Save tuning).
            'presets' => TuningPreset::forUser($request->user()->id)->orderBy('name')->get(),
            // Each chunk's take history, prebuilt so the panel renders without a
            // per-chunk fetch and the JS reuses the same shape it gets from the
            // action endpoints. Keyed by chunk id.
            'takesByChunk' => $chunks->mapWithKeys(fn (TtsChunk $c) => [$c->id => $this->takesPayload($project, $c)])->all(),
        ]);
    }

    /**
     * Switch the project's voice (AJAX). Marks generated chunks Stale so the
     * editor prompts a regenerate; see {@see ProjectService::changeVoice()}.
     */
    public function updateVoice(Request $request, TtsProject $project): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'voice' => ['required', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $voice = Voice::resolveFor((string) $request->input('voice'), $this->projectOwnerId($request, $project));
        if (! $voice) {
            return response()->json(['message' => 'Unknown voice.'], 422);
        }

        $this->projects->changeVoice($project, $voice);

        return response()->json([
            'ok' => true,
            'voice' => $voice->slug,
            'voice_name' => $voice->name,
            'project_status' => $project->refresh()->status->value,
        ]);
    }

    /**
     * Change the project's final-audio format after creation (AJAX). The per-user
     * "Final audio format" setting only stamps NEW projects; this lets a user
     * switch an existing project's mp3/wav. Chunk audio is untouched — the built
     * final just needs a rebuild, which {@see ProjectService::changeOutputFormat()}
     * flags by marking the project Stale.
     */
    public function updateOutputFormat(Request $request, TtsProject $project): JsonResponse
    {
        $options = array_keys($this->outputFormatLabels());

        $validator = Validator::make($request->all(), [
            'output_format' => ['required', 'string', Rule::in($options)],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $this->projects->changeOutputFormat($project, (string) $request->input('output_format'));
        $project->refresh();

        return response()->json([
            'ok' => true,
            'output_format' => $project->output_format,
            'project_status' => $project->status->value,
        ]);
    }

    /**
     * Human-readable {token => short label} map of the offered final-audio formats,
     * read from the managed-settings registry so the project picker stays in sync
     * with the Settings page (same option set, same validation). The label is the
     * codec segment (mp3_44100_128 → "MP3") — compact enough for the header select.
     *
     * @return array<string, string>
     */
    private function outputFormatLabels(): array
    {
        $entry = config('settings.managed')['tts.project_output_format'] ?? [];
        $options = $entry['options'] ?? ['mp3_44100_128', 'wav_44100'];

        return collect($options)
            ->mapWithKeys(fn (string $token) => [$token => strtoupper((string) Str::before($token, '_'))])
            ->all();
    }

    /**
     * Set (or clear) a single chunk's voice override (AJAX). An empty value
     * restores inheritance of the project voice. See {@see ProjectService::setChunkVoice()}.
     */
    public function updateChunkVoice(Request $request, TtsProject $project, TtsChunk $chunk): JsonResponse
    {
        $this->assertChunkBelongs($project, $chunk);

        $validator = Validator::make($request->all(), [
            'voice' => ['nullable', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $slug = trim((string) $request->input('voice'));
        $voice = null;
        if ($slug !== '') {
            $voice = Voice::resolveFor($slug, $this->projectOwnerId($request, $project));
            if (! $voice) {
                return response()->json(['message' => 'Unknown voice.'], 422);
            }
        }

        $chunk = $this->projects->setChunkVoice($chunk, $voice);

        return response()->json([
            'ok' => true,
            'status' => $chunk->status->value,
            'voice' => $voice?->slug,
            'voice_name' => $voice?->name,
            'inherits' => $chunk->voice_id === null,
            'project_status' => $project->refresh()->status->value,
        ]);
    }

    public function update(Request $request, TtsProject $project): JsonResponse
    {
        $title = trim((string) $request->input('title'));

        // Validate the trimmed value so a whitespace-only title fails `required`.
        $validator = Validator::make(['title' => $title], [
            'title' => ['required', 'string', 'max:200'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $project->update(['title' => $title]);

        return response()->json(['ok' => true, 'title' => $project->title]);
    }

    /**
     * Dismiss the "recovered from a failed API generation" flag (AJAX). Clears the
     * api_failure origin, its failure metadata, and the prune TTL so the project
     * becomes a regular panel entry — no red banner on this page, no "API failure"
     * badge on the index, and {@see PruneRecoveryProjects}
     * leaves it alone. Also strips the auto-generated "API failure: …" title prefix
     * (a hand-edited title is left untouched). Idempotent.
     */
    public function dismissFailure(TtsProject $project): JsonResponse
    {
        $attrs = [
            'origin' => null,
            'failure_reason' => null,
            'failed_chunk_index' => null,
            'expires_at' => null,
        ];

        // Only rewrite the title while it still carries the auto-generated prefix;
        // if the user renamed it, keep their title as-is.
        if (Str::startsWith((string) $project->title, 'API failure')) {
            $stripped = trim(ltrim(Str::after((string) $project->title, 'API failure'), ': '));
            $attrs['title'] = $stripped !== '' ? $stripped : 'Untitled project';
        }

        $project->update($attrs);

        return response()->json(['ok' => true, 'title' => $project->title]);
    }

    public function destroy(TtsProject $project): RedirectResponse
    {
        $this->projects->deleteProject($project);

        return redirect()->route('admin.studio.index')->with('success', 'Project deleted.');
    }

    /**
     * Deep-copy the project (rows AND audio files) into an independent duplicate
     * owned by the current user, then land on the copy.
     */
    public function duplicate(Request $request, TtsProject $project): RedirectResponse
    {
        // Snapshot before duplicating: only voices minted BY the copy are
        // announced below. A pre-existing voice the copy was matched to is
        // not news to its owner.
        $preexisting = Voice::pluck('id');

        $copy = $this->projects->duplicate($project, $request->user());

        $message = 'Project duplicated — you are now viewing the copy.';
        // Duplicating another user's project may clone voices to the
        // duplicator (voices are per user); say so, or the new rows on their
        // Voices page appear out of nowhere.
        $adopted = $this->adoptedVoiceNames($project, $copy, $preexisting);
        if ($adopted->isNotEmpty()) {
            $names = $adopted->map(fn (string $name) => "“{$name}”")->join(', ', ' and ');
            $message .= $adopted->count() === 1
                ? " Its voice {$names} was also copied to your voices."
                : " Its voices {$names} were also copied to your voices.";
        }

        return redirect()->route('admin.studio.projects.show', $copy)
            ->with('success', $message);
    }

    /**
     * Names of the voice clones {@see ProjectService::duplicate()} just minted
     * for the duplicator: the voices the copy references that the source does
     * not and that did not exist before the call. Empty for the everyday case
     * of duplicating your own project, and for a foreign voice matched to an
     * identical one the duplicator already had.
     */
    private function adoptedVoiceNames(TtsProject $source, TtsProject $copy, Collection $preexisting): Collection
    {
        $refs = fn (TtsProject $p) => $p->chunks()->pluck('voice_id')->push($p->voice_id)->filter()->unique();

        return Voice::whereIn('id', $refs($copy)->diff($refs($source))->diff($preexisting))->pluck('name');
    }

    /**
     * Housekeeping: delete every non-selected take (rows + files). The audio each
     * chunk is using, the final, and the seal are all kept, so nothing that plays
     * changes — this only reclaims the alternates before archiving/deleting a
     * project. Full-page form POST from the ⋯ menu (behind a confirm), so it
     * redirects like destroy().
     */
    public function cleanup(TtsProject $project): RedirectResponse
    {
        // A background run is minting new takes right now — cleaning up while it
        // writes could delete a take in the moment between its insert and its
        // selection. Same guard as rebuild(), phrased for a redirect.
        if (TtsProjectJob::activeFor($project->id)) {
            return redirect()->route('admin.studio.projects.show', $project)
                ->with('error', 'A background generation run is working on this project — wait for it to finish or stop it first.');
        }

        $removed = $this->projects->cleanupTakes($project);

        return redirect()->route('admin.studio.projects.show', $project)
            ->with('success', match (true) {
                $removed === 0 => 'Nothing to clean up — every take is a selected one.',
                $removed === 1 => 'Project cleaned up — 1 unused take was removed.',
                default => "Project cleaned up — {$removed} unused takes were removed.",
            });
    }

    /** Source-text editor for "Start over" (re-chunk from scratch). */
    public function edit(Request $request, TtsProject $project): View
    {
        return view('admin.studio.projects.edit', [
            'project' => $project,
            'foreignOwner' => $this->foreignOwner($request, $project),
        ]);
    }

    /**
     * The owner's name when the viewer is editing someone ELSE's project (a
     * SuperAdmin doing support — nobody else passes the access policy), or
     * null for the everyday case of a user in their own project. The editor
     * uses this to warn before the first edit of another user's work.
     */
    private function foreignOwner(Request $request, TtsProject $project): ?string
    {
        if ($project->user_id === $request->user()->id) {
            return null;
        }

        return $project->user?->name ?? 'a deleted user';
    }

    /**
     * The user whose per-user resources actions on this project must resolve
     * against: its OWNER, not the requester. Voices are per user, so resolving
     * for a SuperAdmin editing someone else's project would stamp the
     * SuperAdmin's voice row onto it — a voice the owner can't see, which
     * duplicate() would then have to clone back as a confusing "-2" copy.
     * Pronunciation lexicons are strictly per-writer for the same reason: a
     * reset must re-chunk with the OWNER's approved pronunciations, not the
     * visiting admin's. Ownerless (pre-multi-user) projects fall back to the
     * requester.
     */
    private function projectOwnerId(Request $request, TtsProject $project): int
    {
        return $project->user_id ?? $request->user()->id;
    }

    /**
     * Destructive: re-chunk the (possibly edited) text from scratch, discarding
     * all generated audio. A full-page form submit, so it redirects like store().
     */
    public function reset(Request $request, TtsProject $project): RedirectResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:'.(int) config('tts.max_async_text_length', 40000)],
        ]);

        $this->projects->resetFromText(
            $project,
            $data['text'],
            $this->dictionary->approvedMap($this->projectOwnerId($request, $project)),
            (string) config('tts.spoken_quotes', SpokenQuotes::MODE_OFF),
        );

        return redirect()->route('admin.studio.projects.show', $project)
            ->with('success', 'Project reset — generate the chunks below.');
    }

    /** Insert a new (empty) chunk at a position; the editor reloads to re-render. */
    public function storeChunk(Request $request, TtsProject $project): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'position' => ['required', 'integer', 'min:0', 'max:'.$project->chunks()->count()],
            'text' => ['nullable', 'string', 'max:'.(int) config('tts.max_async_text_length', 40000)],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        // A new chunk inherits the project voice — enforce that engine's
        // per-call cap now rather than letting generation fail later.
        if ($error = $this->chunkTextCapError($project->voice, (string) $request->input('text', ''))) {
            return $error;
        }

        $this->projects->insertChunk(
            $project,
            (int) $request->input('position'),
            (string) $request->input('text', ''),
        );

        return response()->json(['ok' => true]);
    }

    public function updateChunk(Request $request, TtsProject $project, TtsChunk $chunk): JsonResponse
    {
        $this->assertChunkBelongs($project, $chunk);

        $validator = Validator::make($request->all(), [
            'text' => ['required', 'string', 'max:'.(int) config('tts.max_async_text_length', 40000)],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        if ($error = $this->chunkTextCapError($chunk->voice ?? $project->voice, (string) $request->input('text'))) {
            return $error;
        }

        $result = $this->projects->updateChunkText($chunk, (string) $request->input('text'));
        $chunk = $result['chunk'];

        return response()->json([
            'ok' => true,
            'status' => $chunk->status->value,
            'characters' => $chunk->characters,
            'project_status' => $project->refresh()->status->value,
            // A split changed the chunk list structurally — the editor reloads.
            'rechunked' => $result['created'] > 0,
        ]);
    }

    /**
     * Delete a chunk (with its takes) and renumber the rest. Refused for the last
     * remaining chunk — a project needs at least one. AJAX (JSON); the editor
     * reloads to re-render the renumbered list, like insert.
     */
    public function destroyChunk(TtsProject $project, TtsChunk $chunk): JsonResponse
    {
        $this->assertChunkBelongs($project, $chunk);

        if ($project->chunks()->count() <= 1) {
            return response()->json(['message' => "You can't delete the only chunk — a project needs at least one."], 422);
        }

        try {
            $this->projects->deleteChunk($chunk);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not delete this chunk: '.$e->getMessage()], 502);
        }

        return response()->json([
            'ok' => true,
            'project_status' => $project->refresh()->status->value,
        ]);
    }

    /**
     * Toggle "skip in final assembly" — the reversible, non-destructive sibling
     * of destroy: the chunk keeps its text, audio and takes but is excluded
     * from rebuild/preview stitching. Takes an explicit {skipped: bool} body
     * (idempotent under double-clicks). A built final goes stale because it no
     * longer reflects intent.
     */
    public function skipChunk(Request $request, TtsProject $project, TtsChunk $chunk): JsonResponse
    {
        $this->assertChunkBelongs($project, $chunk);

        $validator = Validator::make($request->all(), [
            'skipped' => ['required', 'boolean'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $chunk = $this->projects->setChunkSkipped($chunk, $request->boolean('skipped'));

        return response()->json([
            'ok' => true,
            'skipped' => (bool) $chunk->skipped,
            'project_status' => $project->refresh()->status->value,
        ]);
    }

    /**
     * Acknowledge a flagged chunk's QA verdict ("Dismiss" in the badge popover):
     * the reviewer listened and is keeping this take. Quiets the red "check"
     * badge to a muted "reviewed" without touching the audio; a later regenerate
     * writes a fresh verdict and re-flags if the problem recurs.
     */
    public function dismissChunkQa(TtsProject $project, TtsChunk $chunk): JsonResponse
    {
        $this->assertChunkBelongs($project, $chunk);

        $chunk = $this->projects->dismissChunkQa($chunk);

        return response()->json([
            'ok' => true,
            'asr_badge' => $chunk->asrBadge(),
        ]);
    }

    /**
     * Render one chunk. The Studio's Regenerate submits the whole tuning panel
     * (Delivery/fine-tune knobs + seed) with the click, and it's persisted BEFORE
     * synthesis — so the render always uses exactly what's on screen, and the
     * stored tuning always matches the latest render. A request without any
     * panel keys (older callers, tests) renders at the stored tuning unchanged.
     * A fresh take of the same settings is just a regenerate with a blank seed.
     */
    public function generateChunk(Request $request, TtsProject $project, TtsChunk $chunk): JsonResponse
    {
        $this->assertChunkBelongs($project, $chunk);

        if ($error = $this->activeRunError($project)) {
            return $error;
        }

        if (trim((string) $chunk->text) === '') {
            return response()->json(['message' => 'This chunk is empty — add text before generating.'], 422);
        }

        // Renders spend the PROJECT OWNER's credit (recordTake charges them),
        // so the gate checks the owner too — even for a SuperAdmin editing here.
        if ($error = $this->creditError($project->user)) {
            return $error;
        }

        if ($error = $this->persistChunkVoice($request, $project, $chunk)) {
            return $error;
        }

        if ($error = $this->persistTuning($request, $chunk)) {
            return $error;
        }

        try {
            $chunk = $this->projects->generateChunk($chunk);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Generation failed: '.$e->getMessage()], 502);
        }

        return response()->json(array_merge([
            'ok' => true,
            'status' => $chunk->status->value,
            'asr_badge' => $chunk->asrBadge(),
            'project_status' => $project->refresh()->status->value,
            'spend' => $this->spendPayload($project, $chunk),
        ], $this->takesPayload($project, $chunk)));
    }

    /**
     * Persist the tuning panel riding on a generate/queue request: validate the
     * knobs and store them as the chunk's override (absent/blank keys clear back
     * to inherit). Returns the 422 to send on invalid input, null when the panel
     * was stored — or when the request carried no panel keys at all, which
     * leaves the stored tuning untouched.
     */
    private function persistTuning(Request $request, TtsChunk $chunk): ?JsonResponse
    {
        if (! $request->hasAny(array_keys($this->knobRules()))) {
            return null;
        }

        $validator = Validator::make($request->all(), $this->knobRules());
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $this->projects->updateChunkTuning($chunk, $this->knobInput($request));

        return null;
    }

    /**
     * Persist the voice riding on a generate/queue request (the picker is now a
     * pending edit, saved with the render like the text and tuning). Absent
     * `voice` key ⇒ untouched; an empty value ⇒ clear back to the project voice;
     * an unknown slug ⇒ 422. Any transient "stale" from the change is immaterial:
     * generateChunk renders straight to Completed, and a queued chunk stales like
     * every other clip in the run.
     */
    private function persistChunkVoice(Request $request, TtsProject $project, TtsChunk $chunk): ?JsonResponse
    {
        if (! $request->has('voice')) {
            return null;
        }

        $slug = trim((string) $request->input('voice'));
        $voice = null;
        if ($slug !== '') {
            $voice = Voice::resolveFor($slug, $this->projectOwnerId($request, $project));
            if (! $voice) {
                return response()->json(['message' => 'Unknown voice.'], 422);
            }
        }

        $this->projects->setChunkVoice($chunk, $voice);

        return null;
    }

    /**
     * Learned-history estimate (ms) to generate a set of outstanding chunks,
     * grouped by the model each will render on plus the inter-chunk pace. Drives
     * the stored run estimate and the pre-run hint. `$chunks` must have `voice`
     * loaded so the model lookup stays a single query for the whole set.
     *
     * @param  Collection<int, TtsChunk>  $chunks
     */
    private function estimateRemainingMs(TtsProject $project, $chunks): int
    {
        $counts = [];
        foreach ($chunks as $chunk) {
            $model = ModelCatalog::forVoice($chunk->voice ?? $project->voice);
            $counts[$model] = ($counts[$model] ?? 0) + 1;
        }

        return GenerationEstimator::seedMs($counts, (int) config('tts.studio_generate_pace_ms', 800));
    }

    /**
     * The at-a-glance "About X to generate the N remaining clips" hint the
     * project page shows before a run starts (rendered into #project-final-status,
     * which the run then takes over). Null when nothing is outstanding.
     *
     * @param  Collection<int, TtsChunk>  $chunks  all project chunks, voice loaded
     */
    private function preRunEstimate(TtsProject $project, $chunks): ?string
    {
        $outstanding = $chunks
            ->reject(fn (TtsChunk $c) => $c->status === ChunkStatus::Completed || $c->skipped)
            ->values();

        if ($outstanding->isEmpty()) {
            return null;
        }

        $human = GenerationEstimator::humanize((int) round($this->estimateRemainingMs($project, $outstanding) / 1000));
        $n = $outstanding->count();

        return ucfirst($human).' to generate the '.$n.' remaining '.($n === 1 ? 'clip' : 'clips').'.';
    }

    /**
     * "Generate remaining" — dispatch a background run over every outstanding
     * (non-completed, non-skipped) chunk, so the run survives the user leaving
     * the page. One run per project: a second click while one is active joins
     * it instead of starting another. The page then follows via
     * {@see generationStatus()}.
     */
    public function generateRemaining(Request $request, TtsProject $project): JsonResponse
    {
        if ($active = TtsProjectJob::activeFor($project->id)) {
            return response()->json(['ok' => true, 'job' => $active->statusPayload()]);
        }

        $outstanding = $project->chunks()
            ->where('status', '!=', ChunkStatus::Completed->value)
            ->where('skipped', false)
            ->with('voice')
            ->get();

        if ($outstanding->isEmpty()) {
            return response()->json(['message' => 'Every chunk is already generated — build the final to stitch.'], 422);
        }

        // Same owner-credit gate as the per-chunk endpoints; the job re-checks
        // before every chunk (the balance can drain mid-run).
        if ($error = $this->creditError($project->user)) {
            return $error;
        }

        $job = TtsProjectJob::create([
            'tts_project_id' => $project->id,
            'user_id' => $project->user_id,
            'created_by_id' => $request->user()?->id,
            'chunk_ids' => $outstanding->pluck('id')->all(),
            'chunks_total' => $outstanding->count(),
            // Up-front estimate from the learned per-model history: the pre-run
            // number, and the seed the live ETA uses until the first chunk lands.
            'estimated_ms' => $this->estimateRemainingMs($project, $outstanding),
        ]);

        GenerateProjectChunksJob::dispatch($job->id);

        // 202 unless the queue ran it inline (QUEUE_CONNECTION=sync) — then the
        // run is already over and the payload says so.
        $job->refresh();

        return response()->json(['ok' => true, 'job' => $job->statusPayload()], $job->isActive() ? 202 : 200);
    }

    /**
     * "Regenerate" while a background run is active: append this chunk to the
     * run instead of racing the worker (the manual endpoints 409 then — see
     * {@see activeRunError()}). A generated chunk goes Stale in the same
     * transaction the run adopts it, so the worker can't skip it as
     * already-completed; queueing a chunk that's still waiting in the run is a
     * no-op, so double-clicks can't book a clip twice. The one unwinnable race
     * — the worker finishing the run in the same instant — leaves the chunk
     * Stale but unprocessed; the page has settled by then and plain Regenerate
     * covers it.
     */
    public function queueChunk(Request $request, TtsProject $project, TtsChunk $chunk): JsonResponse
    {
        $this->assertChunkBelongs($project, $chunk);

        if ($chunk->skipped) {
            return response()->json(['message' => 'This chunk is skipped — include it (🔊) before regenerating.'], 422);
        }

        if (trim((string) $chunk->text) === '') {
            return response()->json(['message' => 'This chunk is empty — add text before generating.'], 422);
        }

        if ($error = $this->creditError($project->user)) {
            return $error;
        }

        // The queued render happens on the worker from the chunk's stored voice
        // and tuning, so the panel riding on this click is persisted the same way
        // a direct Regenerate persists it.
        if ($error = $this->persistChunkVoice($request, $project, $chunk)) {
            return $error;
        }

        if ($error = $this->persistTuning($request, $chunk)) {
            return $error;
        }

        $active = TtsProjectJob::activeFor($project->id);
        if (! $active) {
            return response()->json(['message' => 'The background run just finished — use Regenerate directly.'], 409);
        }
        if ($active->cancel_requested) {
            return response()->json(['message' => 'This run is stopping — wait for it to settle, then regenerate the clip.'], 409);
        }

        $run = DB::transaction(function () use ($project, $chunk) {
            $run = TtsProjectJob::query()
                ->where('tts_project_id', $project->id)
                ->active()
                ->latest('created_at')
                ->lockForUpdate()
                ->first();

            if (! $run || $run->cancel_requested) {
                return null;
            }

            $ids = array_values((array) $run->chunk_ids);
            // done+failed = how many list entries the worker has moved past, so
            // the slice is what it hasn't reached (including the one in flight).
            $waiting = array_slice($ids, $run->chunks_done + $run->chunks_failed);

            if (! in_array($chunk->id, $waiting, true)) {
                if ($chunk->status === ChunkStatus::Completed) {
                    $chunk->update(['status' => ChunkStatus::Stale]);
                }
                $run->update(['chunk_ids' => [...$ids, $chunk->id], 'chunks_total' => $run->chunks_total + 1]);
            }

            return $run;
        });

        if (! $run) {
            return response()->json(['message' => 'The background run just finished — use Regenerate directly.'], 409);
        }

        return response()->json([
            'ok' => true,
            'status' => $chunk->refresh()->status->value,
            'message' => sprintf('Clip %d added to this run.', $chunk->position + 1),
            'job' => $run->refresh()->statusPayload(),
        ]);
    }

    /**
     * Poll target for the project page: the latest run's state plus per-chunk
     * results. Chunks the run has finished with (completed/failed) carry the
     * full card payload (takes, spend, badge) — the same shape generateChunk()
     * returns, so the JS reuses the same render path; chunks still waiting are
     * just {id, status} to keep the poll light.
     */
    public function generationStatus(TtsProject $project): JsonResponse
    {
        $job = TtsProjectJob::query()
            ->where('tts_project_id', $project->id)
            ->latest('created_at')
            ->first();

        if (! $job) {
            return response()->json(['job' => null, 'chunks' => [], 'project_status' => $project->status->value]);
        }

        $chunks = TtsChunk::query()
            ->whereIn('id', array_values((array) $job->chunk_ids))
            ->where('tts_project_id', $project->id)
            ->with('takes')
            ->orderBy('position')
            ->get()
            ->map(function (TtsChunk $chunk) use ($project) {
                $base = ['id' => $chunk->id, 'status' => $chunk->status->value];

                if (! in_array($chunk->status, [ChunkStatus::Completed, ChunkStatus::Failed], true)) {
                    return $base;
                }

                return array_merge($base, [
                    'asr_badge' => $chunk->asrBadge(),
                    'error' => $chunk->status === ChunkStatus::Failed ? $chunk->error_message : null,
                    'spend' => $this->spendPayload($project, $chunk),
                ], $this->takesPayload($project, $chunk));
            })
            ->values()
            ->all();

        return response()->json([
            'job' => $job->statusPayload(),
            'chunks' => $chunks,
            'project_status' => $project->status->value,
        ]);
    }

    /**
     * Current pre-run estimate for the project's outstanding chunks (AJAX). The
     * project page refetches this whenever the outstanding set changes — a chunk
     * edited into staleness, generated, skipped, or its voice switched — so the
     * "About X to generate the N remaining clips" hint stays current without a
     * reload (the server-rendered value only reflects page-load state). A null
     * estimate means nothing is outstanding, and the hint hides.
     */
    public function estimate(TtsProject $project): JsonResponse
    {
        $project->loadMissing('voice');
        $chunks = $project->chunks()->with('voice')->get();

        return response()->json(['estimate' => $this->preRunEstimate($project, $chunks)]);
    }

    /**
     * A friendly 409 while a background run is active on this project — manual
     * generate/reroll/rebuild would race the worker over the same chunks (and
     * the same money).
     */
    private function activeRunError(TtsProject $project): ?JsonResponse
    {
        if (TtsProjectJob::activeFor($project->id)) {
            return response()->json(['message' => 'A background generation run is working on this project — wait for it to finish or stop it first.'], 409);
        }

        return null;
    }

    public function chunkAudio(Request $request, TtsProject $project, TtsChunk $chunk): Response
    {
        $this->assertChunkBelongs($project, $chunk);

        $bytes = $this->projects->chunkAudioBytes($chunk);
        if ($bytes === null) {
            return response()->json(['message' => 'This chunk has not been generated yet.'], 404);
        }

        // Range-aware so the per-chunk player works in iOS Safari (see ServesRangedAudio).
        return $this->rangedAudio($bytes, 'audio/wav', $request);
    }

    /** The chunk's saved takes (newest first) for the "Takes & tuning" panel. */
    public function listTakes(TtsProject $project, TtsChunk $chunk): JsonResponse
    {
        $this->assertChunkBelongs($project, $chunk);

        return response()->json(array_merge(
            ['spend' => $this->spendPayload($project, $chunk)],
            $this->takesPayload($project, $chunk),
        ));
    }

    public function takeAudio(Request $request, TtsProject $project, TtsChunk $chunk, TtsChunkTake $take): Response
    {
        $this->assertTakeBelongs($project, $chunk, $take);

        $bytes = $this->projects->takeAudioBytes($take);
        if ($bytes === null) {
            return response()->json(['message' => 'This take audio is no longer available.'], 404);
        }

        // Range-aware so each take's player works in iOS Safari (see ServesRangedAudio).
        return $this->rangedAudio($bytes, 'audio/wav', $request);
    }

    /**
     * Make a saved take the chunk's current audio. The service restores the
     * take's whole snapshot (text + tuning + seed, not just the sound), and the
     * response carries that restored state so the page can set the textarea,
     * knobs, and seed field to match what's now selected.
     */
    public function selectTake(TtsProject $project, TtsChunk $chunk, TtsChunkTake $take): JsonResponse
    {
        $this->assertTakeBelongs($project, $chunk, $take);

        $chunk = $this->projects->selectTake($take);

        return response()->json(array_merge([
            'ok' => true,
            'status' => $chunk->status->value,
            'asr_badge' => $chunk->asrBadge(),
            'project_status' => $project->refresh()->status->value,
            'audio_url' => route('admin.studio.projects.chunks.audio', [$project, $chunk]),
            'spend' => $this->spendPayload($project, $chunk),
            'text' => $chunk->text,
            'characters' => $chunk->characters,
            // The voice the take restored onto the chunk, so the page can move the
            // picker to it (and re-sync the engine's knobs). Null on a legacy take
            // that carried no voice — the picker is then left as-is.
            'voice' => $chunk->voice?->slug,
            'tuning' => $this->tuningValues($chunk),
            'seed' => is_array($chunk->settings) ? ($chunk->settings['seed'] ?? null) : null,
        ], $this->takesPayload($project, $chunk)));
    }

    /**
     * The chunk's stored override as a knob => value|null map matching the
     * panel's inputs (null = inherited). Legacy ElevenLabs-form overrides
     * (stability/style) are resolved to the native knobs the panel shows, so
     * restoring an old take still fills honest numbers.
     *
     * @return array<string, float|int|null>
     */
    private function tuningValues(TtsChunk $chunk): array
    {
        $settings = is_array($chunk->settings) ? $chunk->settings : [];

        $values = [];
        foreach (array_keys($this->knobRules()) as $knob) {
            if ($knob !== 'seed') {
                $values[$knob] = $settings[$knob] ?? null;
            }
        }

        if (isset($settings['stability']) || isset($settings['style'])) {
            $native = ChatterboxTuning::resolveNative($settings);
            $values['exaggeration'] ??= $native['exaggeration'];
            $values['cfg_weight'] ??= $native['cfg_weight'];
        }

        return $values;
    }

    /** Permanently delete a take (refused while it's the selected one). */
    public function deleteTake(TtsProject $project, TtsChunk $chunk, TtsChunkTake $take): JsonResponse
    {
        $this->assertTakeBelongs($project, $chunk, $take);

        if ($take->audio_path === $chunk->audio_path) {
            return response()->json(['message' => "You can't delete the take that's currently selected — pick another take first."], 422);
        }

        try {
            $this->projects->deleteTake($take);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not delete this take: '.$e->getMessage()], 502);
        }

        return response()->json(array_merge(
            ['ok' => true, 'spend' => $this->spendPayload($project, $chunk)],
            $this->takesPayload($project, $chunk->refresh()),
        ));
    }

    public function previewConcat(Request $request, TtsProject $project): Response
    {
        $validator = Validator::make($request->all(), [
            'chunks' => ['required', 'array', 'min:1'],
            'chunks.*' => ['string'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        try {
            [$bytes, $mime] = $this->projects->previewConcat($project, (array) $request->input('chunks'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Preview failed: '.$e->getMessage()], 502);
        }

        return response($bytes, 200)->header('Content-Type', $mime);
    }

    public function rebuild(TtsProject $project): JsonResponse
    {
        if ($error = $this->activeRunError($project)) {
            return $error;
        }

        try {
            $this->projects->rebuild($project);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Rebuild failed: '.$e->getMessage()], 502);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Seal the current final as the approved cut: record the approver, the moment,
     * and the SHA-256 of a frozen snapshot of the bytes. AJAX (JSON) like rebuild().
     */
    public function seal(Request $request, TtsProject $project): JsonResponse
    {
        try {
            $this->projects->seal($project, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Seal failed: '.$e->getMessage()], 502);
        }

        return response()->json([
            'ok' => true,
            'sha256' => $project->final_sha256,
            'short' => substr((string) $project->final_sha256, 0, 12),
            'sealed_at' => optional($project->sealed_at)->toIso8601String(),
            'sealed_at_human' => optional($project->sealed_at)->toDayDateTimeString(),
            'approver' => $project->sealApprover(),
            'receipt_url' => route('admin.studio.projects.receipt', $project),
            'verify_url' => route('verify', ['sha' => $project->final_sha256]),
        ]);
    }

    /**
     * Drop an approval made by mistake: clear the seal but keep the final audio, so
     * the project stays Ready and can be re-approved. AJAX (JSON) like seal().
     */
    public function unseal(TtsProject $project): JsonResponse
    {
        try {
            $this->projects->unseal($project);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Unapprove failed: '.$e->getMessage()], 502);
        }

        return response()->json([
            'ok' => true,
            'project_status' => $project->status->value,
        ]);
    }

    /**
     * Download the verifiable receipt .zip (final + provenance record that links
     * to the hosted /verify page) for a sealed project.
     */
    public function receipt(TtsProject $project): Response
    {
        try {
            $zip = $this->export->buildReceiptZip($project);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Receipt failed: '.$e->getMessage()], 502);
        }

        // Shared base name so the .zip, the folder it unzips to, and the audio
        // inside all read as a set (love-what-you-do-sealed-bbe2014e.*).
        $filename = $project->sealedBaseName().'.zip';

        return response($zip, 200)
            ->header('Content-Type', 'application/zip')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }

    /**
     * Download the everything-zip for offline archiving: the receipt package
     * (approved audio + receipt.html + manifest.json) plus a clips/ directory
     * holding every saved take — run Clean up first to archive only the selected
     * ones. Sealed projects only, like receipt().
     */
    public function archive(TtsProject $project): Response
    {
        try {
            $zip = $this->export->buildArchiveZip($project);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Archive failed: '.$e->getMessage()], 502);
        }

        // Same base name family as the receipt zip, tagged -archive so the two
        // downloads never collide on disk.
        $filename = $project->sealedBaseName().'-archive.zip';

        return response($zip, 200)
            ->header('Content-Type', 'application/zip')
            ->header('Content-Disposition', 'attachment; filename="'.$filename.'"');
    }

    public function finalAudio(Request $request, TtsProject $project): Response
    {
        $bytes = $this->projects->finalAudioBytes($project);
        if ($bytes === null) {
            return response()->json(['message' => 'No final audio — rebuild the project first.'], 404);
        }

        $ext = pathinfo((string) $project->final_audio_path, PATHINFO_EXTENSION) ?: 'mp3';
        // Tag the filename with the first 8 chars of the audio's SHA-256 so each
        // distinct build downloads under its own name (no OS "(1) (2)" churn) —
        // and the tag is the same fingerprint the verify page / seal show.
        $fingerprint = substr(hash('sha256', $bytes), 0, 8);
        $filename = Str::slug($project->title ?: 'project').'-'.$fingerprint.'.'.$ext;

        // Range-aware so iOS Safari can seek the final player instead of showing
        // "Live Broadcast" with a dead scrubber (see ServesRangedAudio).
        return $this->rangedAudio($bytes, $project->mime_type ?: 'audio/mpeg', $request, [
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    private function assertChunkBelongs(TtsProject $project, TtsChunk $chunk): void
    {
        abort_unless($chunk->tts_project_id === $project->id, 404);
    }

    private function assertTakeBelongs(TtsProject $project, TtsChunk $chunk, TtsChunkTake $take): void
    {
        $this->assertChunkBelongs($project, $chunk);
        abort_unless($take->tts_chunk_id === $chunk->id, 404);
    }

    /**
     * Serialize a chunk's takes for the panel: newest first, each playable, with a
     * human label of the tuning that produced it and its ASR badge. The selected
     * take is the one the chunk's audio currently points at.
     *
     * @return array{selected_take_id: string|null, takes: list<array<string, mixed>>}
     */
    /**
     * Current lifetime-spend readouts (chunk + project), pre-formatted server-side
     * so the JS never re-implements money math (the ChatterboxTuning JS-mirror
     * lesson). Fresh PK reads: recordTake() bumps the counters via query-builder
     * increments, so the route-bound models are stale by the time a response is
     * built. Null when no rate is configured — the readouts don't exist then.
     *
     * @return array{chunk: array{spent: int, label: string, title: string},
     *               project: array{spent: int, label: string, title: string}}|null
     */
    /**
     * A friendly 422 when a chunk's text exceeds its effective voice's
     * per-call input cap (Chatterbox Turbo: 500 chars), or null when fine.
     * Saving oversized text would only fail later at generation — with money
     * on the line — so it's refused at the edit instead.
     */
    private function chunkTextCapError(?Voice $voice, string $text): ?JsonResponse
    {
        $model = ModelCatalog::forVoice($voice);
        $cap = ModelCatalog::maxInputChars($model);

        if ($cap > 0 && mb_strlen($text) > $cap) {
            return response()->json(['message' => sprintf(
                '%s accepts at most %d characters per chunk — this one is %d. Split it into two chunks, or switch the voice.',
                ModelCatalog::label($model),
                $cap,
                mb_strlen($text),
            )], 422);
        }

        return null;
    }

    private function spendPayload(TtsProject $project, TtsChunk $chunk): ?array
    {
        if (! GenerationCost::enabled()) {
            return null;
        }

        $chunkSpent = (int) TtsChunk::whereKey($chunk->id)->value('spent_characters');
        $projectSpent = (int) TtsProject::whereKey($project->id)->value('spent_characters');

        // Per-model splits so each engine's own rate prices its characters;
        // the totals above only decide readout visibility (spent > 0).
        $chunkReadout = $this->spendReadout(SpendCounters::forOwner('chunk', $chunk->id, $chunkSpent), 'chunk');
        $projectReadout = $this->spendReadout(SpendCounters::forOwner('project', $project->id, $projectSpent), 'project');

        $balance = $this->ownerBalanceMicro($project);

        return [
            'chunk' => [
                'spent' => $chunkSpent,
                'label' => $chunkReadout['label'],
                'title' => $chunkReadout['title'],
            ],
            'project' => [
                'spent' => $projectSpent,
                'label' => 'project spend '.$projectReadout['label'],
                // Bare figure for the header stat chip's .stat-value — the
                // "project spend" wording lives in the chip's static key, not
                // the value. (label stays for any non-chip consumer/test.)
                'value' => $projectReadout['label'],
                'title' => $projectReadout['title'],
            ],
            // Server-formatted like the labels above (JS never does money
            // math); null = the owner is unlimited and the readout stays hidden.
            'balance' => $balance === null ? null : [
                'label' => 'credit '.CreditService::formatMicro($balance),
                'value' => CreditService::formatMicro($balance),
                'low' => $balance <= 0,
            ],
        ];
    }

    /**
     * Viewer-aware {label, title} for one spend split: a SuperAdmin sees the
     * actual provider figures (plus what users are billed, when a markup is
     * configured); everyone else is quoted at the marked-up rate — their
     * price, not the owner's Replicate bill.
     *
     * @param  int|array<string, int>  $byModel
     * @return array{label: string, title: string}
     */
    private function spendReadout(int|array $byModel, string $scope): array
    {
        $markup = $this->credit->markup();

        if (auth()->user()?->isSuperAdmin()) {
            $title = GenerationCost::title($byModel, $scope);
            if ($markup > 1.0) {
                $title .= sprintf(
                    ' Users are billed %s× = %s.',
                    rtrim(rtrim(number_format($markup, 2), '0'), '.'),
                    GenerationCost::label($byModel, $markup),
                );
            }

            return ['label' => GenerationCost::label($byModel), 'title' => $title];
        }

        return [
            'label' => GenerationCost::label($byModel, $markup),
            'title' => GenerationCost::title($byModel, $scope, $markup),
        ];
    }

    /**
     * Pre-rendered per-chunk spend readouts for the initial page paint, keyed
     * by chunk id (the action endpoints refresh them via spendPayload()).
     *
     * @param  Collection<int, TtsChunk>  $chunks
     * @return array<string, array{label: string, title: string, spent: int}>
     */
    private function chunkSpendReadouts(Collection $chunks): array
    {
        $byModel = SpendCounters::forOwners('chunk', $chunks->pluck('id')->all());

        return $chunks->mapWithKeys(function (TtsChunk $chunk) use ($byModel) {
            $map = $byModel[$chunk->id]
                ?? ($chunk->spent_characters > 0 ? ['chatterbox' => (int) $chunk->spent_characters] : []);

            return [$chunk->id => $this->spendReadout($map, 'chunk') + ['spent' => (int) $chunk->spent_characters]];
        })->all();
    }

    /**
     * The project owner's CURRENT balance in micro-dollars (null = unlimited
     * or ownerless). Always a fresh query: credit charges are query-builder
     * decrements that route-bound models never see.
     */
    private function ownerBalanceMicro(TtsProject $project): ?int
    {
        if ($project->user_id === null) {
            return null;
        }

        $balance = User::whereKey($project->user_id)->value('credit_balance_micro');

        return $balance === null ? null : (int) $balance;
    }

    private function takesPayload(TtsProject $project, TtsChunk $chunk): array
    {
        // The chunk's current voice decides how a take with an engine-neutral
        // override (empty, or temperature-only) is labelled; a take whose own
        // knobs name an engine overrides this (see takeEngine()).
        $engine = ModelCatalog::forVoice($chunk->voice ?? $project->voice);
        // The chunk's current effective voice — a take made with a DIFFERENT voice
        // is worth naming on its pill (the common single-voice project stays quiet).
        $currentVoiceId = $chunk->voice_id ?? $project->voice_id;
        $chunk->takes->loadMissing('voice'); // one query for every take's voice name
        $selectedId = null;
        $takes = $chunk->takes->map(function (TtsChunkTake $take) use ($project, $chunk, $engine, $currentVoiceId, &$selectedId) {
            $selected = $take->audio_path !== null && $take->audio_path === $chunk->audio_path;
            if ($selected) {
                $selectedId = $take->id;
            }

            return [
                'id' => $take->id,
                'source' => $take->source,
                'created_human' => $take->created_at?->diffForHumans(),
                'audio_url' => route('admin.studio.projects.chunks.takes.audio', [$project, $chunk, $take]),
                'selected' => $selected,
                'tuning_label' => $this->tuningLabel(is_array($take->settings) ? $take->settings : [], $engine),
                // The voice this take used, named only when it differs from the
                // chunk's current voice (so the pill flags a cross-voice take).
                'voice_name' => ($take->voice_id !== null && $take->voice_id !== $currentVoiceId) ? $take->voice?->name : null,
                // Audio length recorded at synthesis, so the player can print the
                // duration without loading metadata (null on unparsable legacy takes).
                'duration_ms' => $take->duration_ms,
                // The seed this take was pinned to, or null when it rolled random.
                'seed' => $take->seed,
                'asr_badge' => $take->asrBadge(),
            ];
        })->all();

        return ['selected_take_id' => $selectedId, 'takes' => $takes];
    }

    /**
     * Human label of the tuning a take was rendered at — the friendly Delivery
     * archetype name (Steady / Balanced / Expressive) when the take's knobs
     * match one, else "Custom: …" spelling out the engine's native knobs. An
     * empty override resolves to the engine's neutral defaults, which ARE the
     * Balanced archetype, so an inherited take reads "Balanced" rather than a
     * bare "inherited". Engine-aware: classic prints exaggeration/cfg-pace,
     * turbo prints top-p/top-k/rep-penalty (temperature is shared).
     *
     * @param  array<string, mixed>  $settings
     * @param  string  $chunkEngine  the chunk's current voice engine, consulted
     *                               only when the take's own knobs don't reveal one.
     */
    private function tuningLabel(array $settings, string $chunkEngine): string
    {
        $engine = $this->takeEngine($settings, $chunkEngine);
        $native = $this->nativeTuning($settings, $engine);

        return $this->matchArchetype($native, $engine)
            ?? 'Custom: '.$this->customTuning($native, $engine);
    }

    /**
     * The engine a take was rendered with. Turbo-only knobs (top_p/top_k/
     * repetition_penalty) or classic-only knobs (exaggeration/cfg_weight) pin
     * the answer even if the chunk's voice has since switched engines; an
     * engine-neutral override (empty, or only temperature/stability/style)
     * can't tell, so it trusts the chunk's current voice.
     *
     * @param  array<string, mixed>  $settings
     */
    private function takeEngine(array $settings, string $chunkEngine): string
    {
        if (array_intersect_key($settings, array_flip(['top_p', 'top_k', 'repetition_penalty'])) !== []) {
            return 'chatterbox-turbo';
        }

        if (array_intersect_key($settings, array_flip(['exaggeration', 'cfg_weight'])) !== []) {
            return ModelCatalog::DEFAULT;
        }

        return $chunkEngine;
    }

    /**
     * Resolve a settings override to the full native knob set for its engine,
     * including the effective temperature (its default when unset) so an
     * archetype match can compare every knob.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, float|int>
     */
    private function nativeTuning(array $settings, string $engine): array
    {
        if ($engine === 'chatterbox-turbo') {
            return ChatterboxTurboTuning::resolveNative($settings);
        }

        return ChatterboxTuning::resolveNative($settings) + [
            'temperature' => ChatterboxTuning::clampTemperature(
                (float) ($settings['temperature'] ?? ChatterboxTuning::TEMPERATURE_DEFAULT),
            ),
        ];
    }

    /**
     * The Delivery archetype label whose knob values a resolved native map
     * matches exactly (float knobs within a hair, top_k as an int), or null for
     * a Custom mix. Reads the same {@see DeliveryPresets} the chips apply.
     *
     * @param  array<string, float|int>  $native
     */
    private function matchArchetype(array $native, string $engine): ?string
    {
        foreach (DeliveryPresets::forEngine($engine) as $preset) {
            foreach ($preset['values'] as $knob => $value) {
                $current = $native[$knob] ?? null;
                $matches = $knob === 'top_k'
                    ? (int) $current === (int) $value
                    : $current !== null && abs((float) $current - (float) $value) < 0.005;
                if (! $matches) {
                    continue 2;
                }
            }

            return $preset['label'];
        }

        return null;
    }

    /**
     * The "Custom" knob readout for a resolved native map, worded to match the
     * fine-tune sliders the user sees for that engine.
     *
     * @param  array<string, float|int>  $native
     */
    private function customTuning(array $native, string $engine): string
    {
        if ($engine === 'chatterbox-turbo') {
            return sprintf(
                'top-p %.2f · top-k %d · rep. penalty %.2f · temp %.2f',
                $native['top_p'], $native['top_k'], $native['repetition_penalty'], $native['temperature'],
            );
        }

        return sprintf(
            'exaggeration %.2f · cfg/pace %.2f · temp %.2f',
            $native['exaggeration'], $native['cfg_weight'], $native['temperature'],
        );
    }

    /**
     * Validation rules for the per-chunk tuning knobs. Single place the Studio
     * panel's knob names + ranges are declared, shared by generate/queue
     * (via {@see persistTuning}).
     *
     * @return array<string, list<string>>
     */
    private function knobRules(): array
    {
        return [
            'exaggeration' => ['nullable', 'numeric', 'between:0.25,2'],
            'cfg_weight' => ['nullable', 'numeric', 'between:0.2,1'],
            'temperature' => ['nullable', 'numeric', 'between:0.5,1.5'],
            'top_p' => ['nullable', 'numeric', 'between:0.5,1'],
            'top_k' => ['nullable', 'integer', 'between:1,2000'],
            'repetition_penalty' => ['nullable', 'numeric', 'between:1,2'],
            'seed' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * The per-chunk tuning override from the request as a name => value|null map
     * (null = inherit/clear). Single place the panel's control names are read. The
     * float knobs cast to float; `seed` casts to int (blank/absent => inherit,
     * which ultimately rolls random). Shared by generate/queue.
     *
     * @return array<string, float|int|null>
     */
    private function knobInput(Request $request): array
    {
        $knobs = [];
        foreach (array_keys($this->knobRules()) as $knob) {
            $knobs[$knob] = $request->filled($knob)
                ? (in_array($knob, ['seed', 'top_k'], true) ? (int) $request->input($knob) : (float) $request->input($knob))
                : null;
        }

        return $knobs;
    }

    /**
     * Resolve the project's stored settings snapshot through the shared
     * {@see VoiceSettingsResolver} (config defaults -> voice defaults -> the
     * delivery preset / native knobs chosen at creation). Seed is tracked on the
     * project column, so the resolver deliberately leaves it out.
     *
     * @return array<string, mixed>
     */
    private function settings(Request $request, Voice $voice): array
    {
        $overrides = [];

        // A chosen delivery preset seeds the knobs; explicit knob values still
        // win over it. A preset id the user doesn't own resolves to nothing.
        if ($request->filled('preset')) {
            $preset = TuningPreset::forUser($request->user()->id)->find($request->input('preset'));
            foreach (['exaggeration', 'cfg_weight', 'temperature', 'top_p', 'top_k', 'repetition_penalty'] as $knob) {
                if ($preset?->{$knob} !== null) {
                    $overrides[$knob] = $preset->{$knob};
                }
            }
        }

        foreach (['exaggeration', 'cfg_weight', 'temperature', 'top_p', 'repetition_penalty'] as $knob) {
            if ($request->filled($knob)) {
                $overrides[$knob] = (float) $request->input($knob);
            }
        }

        if ($request->filled('top_k')) {
            $overrides['top_k'] = (int) $request->input('top_k');
        }

        return $this->settingsResolver->resolve($voice, $overrides);
    }
}
