<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateProjectRequest;
use App\Models\ApiKey;
use App\Models\MagicLoginToken;
use App\Models\TtsProject;
use App\Models\User;
use App\Models\Voice;
use App\Services\ProjectService;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creates an editable Studio project from text — the non-generating sibling of
 * {@see TextToSpeechController}. Instead of returning audio, it normalizes and
 * chunks the text into a {@see TtsProject} (no provider calls) and returns a
 * single-use link that logs the user into the control panel on that project,
 * ready to generate chunk-by-chunk.
 *
 *   POST /v1/projects
 *   header: xi-api-key: <key>
 *   body:   { voice_id, text, title?, model_id?, voice_settings?, seed?, output_format? }
 */
class ProjectApiController extends Controller
{
    public function __construct(
        private ProjectService $projects,
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

        $voice = Voice::resolve((string) $request->input('voice_id'));
        if (! $voice) {
            return $this->error("A voice with voice_id '{$request->input('voice_id')}' could not be found.", 404);
        }

        $project = $this->projects->createFromText(
            title: $this->resolveTitle($request->input('title')),
            voice: $voice,
            text: (string) $request->input('text'),
            settings: $request->voiceSettings(),
            modelId: $request->modelId(),
            outputFormat: $request->outputFormat(),
            seed: $request->seed(),
            apiKey: $apiKey,
        );

        [$editUrl, $editUrlExpiresAt] = $this->mintEditLink($project, $apiKey);

        return response()->json([
            'id' => $project->id,
            'title' => $project->title,
            'status' => $project->status->value,
            'voice_id' => $voice->slug,
            'characters' => mb_strlen((string) $project->normalized_text),
            'chunk_count' => $project->chunks()->count(),
            'created_at' => $project->created_at?->toIso8601String(),
            'url' => route('admin.studio.projects.show', $project),
            'edit_url' => $editUrl,
            'edit_url_expires_at' => $editUrlExpiresAt,
        ], 201)->header('request-id', $project->id);
    }

    /**
     * Mint the single-use auto-login link for the project. Logs in the admin
     * account (the only one today); the seam for per-user ownership is the
     * project's api_key_id once keys map to users. Returns [url, expiresAt],
     * both null if no admin account exists to log in.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function mintEditLink(TtsProject $project, ApiKey $apiKey): array
    {
        $user = User::where('is_super_admin', true)->first();
        if (! $user) {
            return [null, null];
        }

        [$token, $plaintext] = MagicLoginToken::mint(
            $user,
            $project,
            $apiKey,
            (int) config('tts.magic_login_ttl_minutes', 60),
        );

        return [
            route('projects.open', ['token' => $plaintext]),
            $token->expires_at?->toIso8601String(),
        ];
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
