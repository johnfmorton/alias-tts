<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\PruneRecoveryProjects;
use App\Http\Controllers\Concerns\ServesRangedAudio;
use App\Http\Controllers\Controller;
use App\Models\TtsChunk;
use App\Models\TtsChunkTake;
use App\Models\TtsProject;
use App\Models\TuningPreset;
use App\Models\Voice;
use App\Services\ProjectExportService;
use App\Services\ProjectService;
use App\Services\Pronunciation\PronunciationDetector;
use App\Services\Pronunciation\PronunciationDictionary;
use App\Services\TextNormalizer;
use App\Services\Tts\ChatterboxTuning;
use App\Services\Tts\VoiceSettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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
    use ServesRangedAudio;

    public function __construct(
        private readonly ProjectService $projects,
        private readonly ProjectExportService $export,
        private readonly VoiceSettingsResolver $settingsResolver,
        private readonly TextNormalizer $normalizer,
        private readonly PronunciationDetector $detector,
        private readonly PronunciationDictionary $dictionary,
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
     * Pronunciation gate: between submitting text and chunking, ask the LLM (a
     * Genblaze chat step) for respelling suggestions. When the feature is off, the
     * LLM is unavailable, or nothing new is found, this transparently creates the
     * project — applying any already-approved dictionary entries — and skips the
     * review screen entirely. Never blocks on the LLM.
     */
    public function review(Request $request): RedirectResponse|View
    {
        $data = $request->validate($this->createRules());

        $voice = Voice::resolveFor($data['voice'], $request->user()->id);
        if (! $voice) {
            return back()->withInput()->with('error', 'Unknown voice.');
        }

        $userId = $request->user()?->id;
        $normalized = $this->normalizer->normalize($data['text']);
        $detection = $this->detector->detect($normalized, $userId);

        // Drop anything already in the writer's dictionary (the detector passes
        // these as known_terms too — this is belt-and-suspenders).
        $known = array_map(fn ($t) => mb_strtolower($t), $this->dictionary->knownTerms($userId));
        $suggestions = array_values(array_filter(
            $detection['substitutions'] ?? [],
            fn ($s) => isset($s['term']) && ! in_array(mb_strtolower((string) $s['term']), $known, true),
        ));

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
            ],
            'provenance' => $detection['provenance'] ?? null,
        ]);
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

        // Keep only the rows the writer checked.
        $approvedIdx = array_flip(array_map('intval', (array) $request->input('approve', [])));
        $approved = array_values(array_intersect_key($data['substitutions'] ?? [], $approvedIdx));
        if ($approved !== []) {
            $this->dictionary->approveSuggestions($userId, $approved);
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
            outputFormat: config('tts.default_output_format'),
            seed: $request->filled('seed') ? (int) $request->input('seed') : ($voice->settings['seed'] ?? null),
            pronunciationMap: $pronunciationMap,
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
            'seed' => ['nullable', 'integer'],
            'preset' => ['nullable', 'integer'],
            'exaggeration' => ['nullable', 'numeric', 'between:0.25,2'],
            'cfg_weight' => ['nullable', 'numeric', 'between:0.2,1'],
        ];
    }

    public function show(Request $request, TtsProject $project): View
    {
        $project->load('voice');
        $chunks = $project->chunks()->with('takes')->get();

        return view('admin.studio.projects.show', [
            'project' => $project,
            'chunks' => $chunks,
            'voices' => Voice::orderedFor($request->user()->id)->get(),
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

        $voice = Voice::resolveFor((string) $request->input('voice'), $request->user()->id);
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
            $voice = Voice::resolveFor($slug, $request->user()->id);
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

    /** Source-text editor for "Start over" (re-chunk from scratch). */
    public function edit(TtsProject $project): View
    {
        return view('admin.studio.projects.edit', ['project' => $project]);
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

        $this->projects->resetFromText($project, $data['text'], $this->dictionary->approvedMap($request->user()?->id));

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

    public function generateChunk(TtsProject $project, TtsChunk $chunk): JsonResponse
    {
        $this->assertChunkBelongs($project, $chunk);

        if (trim((string) $chunk->text) === '') {
            return response()->json(['message' => 'This chunk is empty — add text before generating.'], 422);
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
        ], $this->takesPayload($project, $chunk)));
    }

    /**
     * Set a chunk's per-chunk stability/style override. A generated chunk goes
     * Stale so the editor prompts a regenerate. See docs/STUDIO-TUNING.md Phase 2.
     */
    public function tuneChunk(Request $request, TtsProject $project, TtsChunk $chunk): JsonResponse
    {
        $this->assertChunkBelongs($project, $chunk);

        $validator = Validator::make($request->all(), $this->knobRules());
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $chunk = $this->projects->updateChunkTuning($chunk, $this->knobInput($request));

        return response()->json([
            'ok' => true,
            'status' => $chunk->status->value,
            'project_status' => $project->refresh()->status->value,
        ]);
    }

    /**
     * Re-roll: regenerate one chunk with a fresh random seed (ignoring the
     * project's pinned seed) to get a different "take" without editing the text.
     */
    public function rerollChunk(TtsProject $project, TtsChunk $chunk): JsonResponse
    {
        $this->assertChunkBelongs($project, $chunk);

        if (trim((string) $chunk->text) === '') {
            return response()->json(['message' => 'This chunk is empty — add text before generating.'], 422);
        }

        try {
            $chunk = $this->projects->generateChunk($chunk, reroll: true);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Generation failed: '.$e->getMessage()], 502);
        }

        return response()->json(array_merge([
            'ok' => true,
            'status' => $chunk->status->value,
            'asr_badge' => $chunk->asrBadge(),
            'project_status' => $project->refresh()->status->value,
        ], $this->takesPayload($project, $chunk)));
    }

    /**
     * A/B preview (3c): synthesize this chunk at a transient stability/style and
     * return the raw audio WITHOUT persisting, so the user can audition a
     * candidate tuning against the chunk's current audio before committing.
     */
    public function previewChunkTuning(Request $request, TtsProject $project, TtsChunk $chunk): Response
    {
        $this->assertChunkBelongs($project, $chunk);

        if (trim((string) $chunk->text) === '') {
            return response()->json(['message' => 'This chunk is empty — add text before previewing.'], 422);
        }

        $validator = Validator::make($request->all(), $this->knobRules());
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        try {
            $bytes = $this->projects->previewChunkTuning($chunk, $this->knobInput($request));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Preview failed: '.$e->getMessage()], 502);
        }

        $mime = $this->projects->providerContainer() === 'wav' ? 'audio/wav' : 'audio/mpeg';

        return response($bytes, 200)->header('Content-Type', $mime);
    }

    /**
     * "Use this take": persist the exact clip the user just previewed (uploaded
     * back from the browser) as this chunk's audio, with the stability/style it
     * was auditioned at. The provider is non-deterministic even with a pinned
     * seed, so regenerating can't reproduce a good preview — keeping the actual
     * bytes is the only reliable way. See docs/STUDIO-TUNING.md.
     */
    public function useChunkPreview(Request $request, TtsProject $project, TtsChunk $chunk): JsonResponse
    {
        $this->assertChunkBelongs($project, $chunk);

        $validator = Validator::make($request->all(), array_merge([
            'audio' => ['required', 'file', 'mimetypes:audio/wav,audio/x-wav,audio/wave,audio/vnd.wave,audio/mpeg', 'max:20480'],
        ], $this->knobRules()));
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        try {
            $chunk = $this->projects->useChunkPreview(
                $chunk,
                (string) file_get_contents($request->file('audio')->getRealPath()),
                $this->knobInput($request),
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Could not save this take: '.$e->getMessage()], 502);
        }

        return response()->json(array_merge([
            'ok' => true,
            'status' => $chunk->status->value,
            'asr_badge' => $chunk->asrBadge(),
            'project_status' => $project->refresh()->status->value,
        ], $this->takesPayload($project, $chunk)));
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

        return response()->json($this->takesPayload($project, $chunk));
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

    /** Make a saved take the chunk's current audio (audition a prior take, pick a better one). */
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
        ], $this->takesPayload($project, $chunk)));
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

        return response()->json(array_merge(['ok' => true], $this->takesPayload($project, $chunk->refresh())));
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
            'verify_url' => route('verify').'#expect='.$project->final_sha256,
        ]);
    }

    /**
     * Download the verifiable receipt .zip (final + provenance + offline verifier)
     * for a sealed project.
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
    private function takesPayload(TtsProject $project, TtsChunk $chunk): array
    {
        $selectedId = null;
        $takes = $chunk->takes->map(function (TtsChunkTake $take) use ($project, $chunk, &$selectedId) {
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
                'tuning_label' => $this->tuningLabel(is_array($take->settings) ? $take->settings : []),
                'asr_badge' => $take->asrBadge(),
            ];
        })->all();

        return ['selected_take_id' => $selectedId, 'takes' => $takes];
    }

    /**
     * Human label of the tuning a take was rendered at. Reads native keys
     * (exaggeration/cfg_weight) when present, else maps the ElevenLabs
     * stability/style to their native equivalents so legacy takes read
     * consistently. An empty override means the take inherited the project tuning.
     *
     * @param  array<string, mixed>  $settings
     */
    private function tuningLabel(array $settings): string
    {
        $tuning = array_intersect_key($settings, array_flip(['stability', 'style', 'exaggeration', 'cfg_weight']));
        if ($tuning === []) {
            return 'inherited';
        }

        $native = ChatterboxTuning::resolveNative($settings);

        return sprintf('exaggeration %.2f · cfg/pace %.2f', $native['exaggeration'], $native['cfg_weight']);
    }

    /**
     * Validation rules for the per-chunk tuning knobs. Single place the Studio
     * panel's knob names + ranges are declared, shared by tune/preview/use.
     *
     * @return array<string, list<string>>
     */
    private function knobRules(): array
    {
        return [
            'exaggeration' => ['nullable', 'numeric', 'between:0.25,2'],
            'cfg_weight' => ['nullable', 'numeric', 'between:0.2,1'],
        ];
    }

    /**
     * The per-chunk tuning knobs from the request as a name => value|null map
     * (null = inherit/clear). Single place the panel's knob names are read.
     *
     * @return array<string, float|null>
     */
    private function knobInput(Request $request): array
    {
        $knobs = [];
        foreach (array_keys($this->knobRules()) as $knob) {
            $knobs[$knob] = $request->filled($knob) ? (float) $request->input($knob) : null;
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
            if ($preset?->exaggeration !== null) {
                $overrides['exaggeration'] = $preset->exaggeration;
            }
            if ($preset?->cfg_weight !== null) {
                $overrides['cfg_weight'] = $preset->cfg_weight;
            }
        }

        foreach (['exaggeration', 'cfg_weight'] as $knob) {
            if ($request->filled($knob)) {
                $overrides[$knob] = (float) $request->input($knob);
            }
        }

        return $this->settingsResolver->resolve($voice, $overrides);
    }
}
