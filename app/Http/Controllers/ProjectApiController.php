<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateProjectRequest;
use App\Models\ApiKey;
use App\Models\TtsProject;
use App\Models\Voice;
use App\Services\ProjectService;
use App\Services\Tts\VoiceSettingsResolver;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creates an editable Studio project from text — the non-generating sibling of
 * {@see TextToSpeechController}. Instead of returning audio, it normalizes and
 * chunks the text into a {@see TtsProject} (no provider calls) owned by the API
 * key's user, who opens it in the control panel to generate chunk-by-chunk after
 * a normal login.
 *
 *   POST /v1/projects
 *   header: xi-api-key: <key>
 *   body:   { voice_id, text, title?, model_id?, voice_settings?, seed?, output_format? }
 */
class ProjectApiController extends Controller
{
    public function __construct(
        private ProjectService $projects,
        private VoiceSettingsResolver $settingsResolver,
    ) {}

    public function store(CreateProjectRequest $request): Response
    {
        // The ValidateApiKey middleware already rejects missing/invalid/inactive
        // keys (401/403). This guard makes the "no project without a valid key"
        // invariant explicit and survives any future route re-wiring.
        $apiKey = $request->attributes->get('api_key');
        if (! $apiKey instanceof ApiKey) {
            return $this->error('An API key is required to create a project.', 401);
        }

        $voice = Voice::resolveFor((string) $request->input('voice_id'), $apiKey->user_id);
        if (! $voice) {
            return $this->error("A voice with voice_id '{$request->input('voice_id')}' could not be found.", 404);
        }

        $project = $this->projects->createFromText(
            title: $this->resolveTitle($request->input('title')),
            voice: $voice,
            text: (string) $request->input('text'),
            settings: $this->settingsResolver->resolve($voice, $request->voiceSettingOverrides()),
            modelId: $request->modelId(),
            outputFormat: $request->outputFormat(),
            seed: $request->seed(),
            apiKey: $apiKey,
            // Marks the project as born from the /v1/projects call so the Studio
            // list can tell it apart from a hand-made project AND from an audio
            // generation persisted by the text-to-speech endpoints ('api').
            origin: 'api_project',
        );

        return response()->json([
            'id' => $project->id,
            'title' => $project->title,
            'status' => $project->status->value,
            'voice_id' => $voice->slug,
            'characters' => mb_strlen((string) $project->normalized_text),
            'chunk_count' => $project->chunks()->count(),
            'created_at' => $project->created_at?->toIso8601String(),
            'url' => route('admin.studio.projects.show', $project),
        ], 201)->header('request-id', $project->id);
    }

    /**
     * Use the supplied title, or fall back to a friendly auto-title like
     * "Audio project #47 - June 19, 2026". The number is a simple running count
     * (titles aren't unique, so a gap after a delete is harmless).
     */
    private function resolveTitle(?string $title): string
    {
        $title = trim((string) $title);
        if ($title !== '') {
            return $title;
        }

        return 'Audio project #'.(TtsProject::count() + 1).' - '.Carbon::now()->format('F j, Y');
    }

    private function error(string $message, int $status): Response
    {
        return response()->json([
            'detail' => ['message' => $message, 'status' => $status],
        ], $status);
    }
}
