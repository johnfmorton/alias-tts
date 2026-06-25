<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ServesRangedAudio;
use App\Http\Controllers\Controller;
use App\Models\TtsChunk;
use App\Models\TtsProject;
use App\Models\Voice;
use App\Services\ProjectService;
use App\Services\Pronunciation\PronunciationDetector;
use App\Services\Pronunciation\PronunciationDictionary;
use App\Services\Pronunciation\PronunciationSubstituter;
use App\Services\TextNormalizer;
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
        private readonly VoiceSettingsResolver $settingsResolver,
        private readonly TextNormalizer $normalizer,
        private readonly PronunciationDetector $detector,
        private readonly PronunciationSubstituter $substituter,
        private readonly PronunciationDictionary $dictionary,
    ) {}

    public function create(): View
    {
        return view('admin.studio.projects.create', [
            'voices' => Voice::orderBy('name')->get(),
            // Pre-select the built-in default voice so a new project uses the
            // native voice unless the user actively picks another — without this
            // the <select> silently defaults to whichever voice sorts first by
            // name, which bound new projects to an arbitrary cloning voice.
            'defaultVoiceSlug' => Voice::defaultSlug(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->createRules());

        $voice = Voice::resolve($data['voice']);
        if (! $voice) {
            return back()->withInput()->with('error', 'Unknown voice.');
        }

        $project = $this->persist($request, $data, $voice, $data['text']);

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

        $voice = Voice::resolve($data['voice']);
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
                'stability' => $data['stability'] ?? null,
                'style' => $data['style'] ?? null,
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

        $voice = Voice::resolve($data['voice']);
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

    /** Create a project after applying the writer's approved dictionary to its text. */
    private function persistWithDictionary(Request $request, array $data, Voice $voice, ?int $userId): TtsProject
    {
        $normalized = $this->normalizer->normalize($data['text']);
        $applied = $this->substituter->apply($normalized, $this->dictionary->approvedMap($userId))['text'];

        return $this->persist($request, $data, $voice, $applied);
    }

    private function persist(Request $request, array $data, Voice $voice, string $text): TtsProject
    {
        return $this->projects->createFromText(
            title: (string) ($data['title'] ?? ''),
            voice: $voice,
            text: $text,
            settings: $this->settings($request, $voice),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: $request->filled('seed') ? (int) $request->input('seed') : ($voice->settings['seed'] ?? null),
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
            'stability' => ['nullable', 'numeric', 'between:0,1'],
            'style' => ['nullable', 'numeric', 'between:0,1'],
        ];
    }

    public function show(TtsProject $project): View
    {
        return view('admin.studio.projects.show', [
            'project' => $project->load('voice'),
            'chunks' => $project->chunks()->get(),
            'voices' => Voice::orderBy('name')->get(),
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

        $voice = Voice::resolve((string) $request->input('voice'));
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
            $voice = Voice::resolve($slug);
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

        $this->projects->resetFromText($project, $data['text']);

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

        return response()->json([
            'ok' => true,
            'status' => $chunk->status->value,
            'asr_badge' => $chunk->asrBadge(),
            'project_status' => $project->refresh()->status->value,
        ]);
    }

    /**
     * Set a chunk's per-chunk stability/style override. A generated chunk goes
     * Stale so the editor prompts a regenerate. See docs/STUDIO-TUNING.md Phase 2.
     */
    public function tuneChunk(Request $request, TtsProject $project, TtsChunk $chunk): JsonResponse
    {
        $this->assertChunkBelongs($project, $chunk);

        $validator = Validator::make($request->all(), [
            'stability' => ['nullable', 'numeric', 'between:0,1'],
            'style' => ['nullable', 'numeric', 'between:0,1'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $chunk = $this->projects->updateChunkTuning(
            $chunk,
            $request->filled('stability') ? (float) $request->input('stability') : null,
            $request->filled('style') ? (float) $request->input('style') : null,
        );

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

        return response()->json([
            'ok' => true,
            'status' => $chunk->status->value,
            'asr_badge' => $chunk->asrBadge(),
            'project_status' => $project->refresh()->status->value,
        ]);
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

        $validator = Validator::make($request->all(), [
            'stability' => ['nullable', 'numeric', 'between:0,1'],
            'style' => ['nullable', 'numeric', 'between:0,1'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        try {
            $bytes = $this->projects->previewChunkTuning(
                $chunk,
                $request->filled('stability') ? (float) $request->input('stability') : null,
                $request->filled('style') ? (float) $request->input('style') : null,
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Preview failed: '.$e->getMessage()], 502);
        }

        $mime = $this->projects->providerContainer() === 'wav' ? 'audio/wav' : 'audio/mpeg';

        return response($bytes, 200)->header('Content-Type', $mime);
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

    public function finalAudio(Request $request, TtsProject $project): Response
    {
        $bytes = $this->projects->finalAudioBytes($project);
        if ($bytes === null) {
            return response()->json(['message' => 'No final audio — rebuild the project first.'], 404);
        }

        $ext = pathinfo((string) $project->final_audio_path, PATHINFO_EXTENSION) ?: 'mp3';
        $filename = Str::slug($project->title ?: 'project').'.'.$ext;

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

    /**
     * Resolve the project's stored settings snapshot through the shared
     * {@see VoiceSettingsResolver} (config defaults -> voice defaults -> the
     * stability/style chosen at creation). Seed is tracked on the project
     * column, so the resolver deliberately leaves it out.
     *
     * @return array<string, mixed>
     */
    private function settings(Request $request, Voice $voice): array
    {
        $overrides = [];
        foreach (['stability', 'style'] as $knob) {
            if ($request->filled($knob)) {
                $overrides[$knob] = (float) $request->input($knob);
            }
        }

        return $this->settingsResolver->resolve($voice, $overrides);
    }
}
