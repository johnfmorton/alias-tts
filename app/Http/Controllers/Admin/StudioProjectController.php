<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TtsChunk;
use App\Models\TtsProject;
use App\Models\Voice;
use App\Services\ProjectService;
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
    public function __construct(private readonly ProjectService $projects) {}

    public function create(): View
    {
        return view('admin.studio.projects.create', [
            'voices' => Voice::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'text' => ['required', 'string', 'max:'.(int) config('tts.max_async_text_length', 40000)],
            'voice' => ['required', 'string'],
            'seed' => ['nullable', 'integer'],
            'stability' => ['nullable', 'numeric', 'between:0,1'],
            'style' => ['nullable', 'numeric', 'between:0,1'],
        ]);

        $voice = Voice::resolve($data['voice']);
        if (! $voice) {
            return back()->withInput()->with('error', 'Unknown voice.');
        }

        $project = $this->projects->createFromText(
            title: (string) ($data['title'] ?? ''),
            voice: $voice,
            text: $data['text'],
            settings: $this->settings($request, $voice),
            modelId: config('tts.default_model_id'),
            outputFormat: config('tts.default_output_format'),
            seed: $request->filled('seed') ? (int) $request->input('seed') : ($voice->settings['seed'] ?? null),
        );

        return redirect()->route('admin.studio.projects.show', $project)
            ->with('success', 'Project created — generate the chunks below.');
    }

    public function show(TtsProject $project): View
    {
        return view('admin.studio.projects.show', [
            'project' => $project->load('voice'),
            'chunks' => $project->chunks()->get(),
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

    public function updateChunk(Request $request, TtsProject $project, TtsChunk $chunk): JsonResponse
    {
        $this->assertChunkBelongs($project, $chunk);

        $validator = Validator::make($request->all(), [
            'text' => ['required', 'string', 'max:'.(int) config('tts.max_async_text_length', 40000)],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $chunk = $this->projects->updateChunkText($chunk, (string) $request->input('text'));

        return response()->json([
            'ok' => true,
            'status' => $chunk->status->value,
            'characters' => $chunk->characters,
            'project_status' => $project->refresh()->status->value,
        ]);
    }

    public function generateChunk(TtsProject $project, TtsChunk $chunk): JsonResponse
    {
        $this->assertChunkBelongs($project, $chunk);

        try {
            $chunk = $this->projects->generateChunk($chunk);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Generation failed: '.$e->getMessage()], 502);
        }

        return response()->json([
            'ok' => true,
            'status' => $chunk->status->value,
            'project_status' => $project->refresh()->status->value,
        ]);
    }

    public function chunkAudio(TtsProject $project, TtsChunk $chunk): Response
    {
        $this->assertChunkBelongs($project, $chunk);

        $bytes = $this->projects->chunkAudioBytes($chunk);
        if ($bytes === null) {
            return response()->json(['message' => 'This chunk has not been generated yet.'], 404);
        }

        return response($bytes, 200)->header('Content-Type', 'audio/wav');
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

    public function finalAudio(TtsProject $project): Response
    {
        $bytes = $this->projects->finalAudioBytes($project);
        if ($bytes === null) {
            return response()->json(['message' => 'No final audio — rebuild the project first.'], 404);
        }

        $ext = pathinfo((string) $project->final_audio_path, PATHINFO_EXTENSION) ?: 'mp3';
        $filename = Str::slug($project->title ?: 'project').'.'.$ext;

        return response($bytes, 200)
            ->header('Content-Type', $project->mime_type ?: 'audio/mpeg')
            ->header('Content-Disposition', 'inline; filename="'.$filename.'"');
    }

    private function assertChunkBelongs(TtsProject $project, TtsChunk $chunk): void
    {
        abort_unless($chunk->tts_project_id === $project->id, 404);
    }

    /**
     * Config defaults overlaid with the voice's defaults, then per-request debug
     * overrides. Seed is tracked on the project column, not here.
     *
     * @return array<string, mixed>
     */
    private function settings(Request $request, Voice $voice): array
    {
        $settings = config('tts.default_voice_settings', []);

        if (is_array($voice->settings)) {
            $settings = array_merge($settings, $voice->settings);
        }
        unset($settings['seed']);

        foreach (['stability', 'style'] as $knob) {
            if ($request->filled($knob)) {
                $settings[$knob] = (float) $request->input($knob);
            }
        }

        return $settings;
    }
}
