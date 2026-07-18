<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\PronunciationEntry;
use App\Models\Speech;
use App\Models\TtsProject;
use App\Models\Voice;
use App\Services\Settings\SettingsManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, SettingsManager $settings): View
    {
        $user = $request->user();
        $voices = Voice::orderedFor($request->user()->id)->get();
        $keyIds = $user->apiKeys()->pluck('id');

        $stats = [
            'voices' => $voices->count(),
            // Per-user: your own keys and your own generations, not the whole server's.
            'apiKeys' => $keyIds->count(),
            'speeches' => Speech::whereIn('api_key_id', $keyIds)->count(),
            // Pronunciations are a strictly private per-user lexicon.
            'pronunciations' => PronunciationEntry::ownedBy($user->id)->count(),
            // Match Studio's scoping so the card count equals what "Open in Studio"
            // lists: SuperAdmins see every project, everyone else only their own.
            'projects' => TtsProject::when(! $user->isSuperAdmin(), fn ($q) => $q->where('user_id', $user->id))->count(),
        ];

        // Copy-paste connection details for the Bespoken Craft plugin. The key is the
        // user's OWN key (null if they haven't created one — the view prompts them).
        $connect = [
            'baseUrl' => rtrim((string) config('app.url'), '/'),
            'apiKey' => optional(ApiKey::ownedActiveFor($user->id))->key,
            'voiceIds' => $voices->pluck('slug')->all(),
        ];

        // ApplyUserSettings has already overlaid this user's overrides onto
        // config, so this is the per-user value, not the instance default.
        $gettingStarted = [
            'show' => (bool) config('tts.show_getting_started'),
            'locked' => $settings->isLocked('tts.show_getting_started'),
        ];

        return view('admin.dashboard', compact('stats', 'connect', 'voices', 'gettingStarted'));
    }

    /**
     * Show/hide the Dashboard's getting-started guide for the signed-in user.
     * JSON for the panel's fetch dismiss; redirect for the plain form posts
     * (no-JS dismiss, Dashboard restore link, Account page). When
     * TTS_SHOW_GETTING_STARTED is pinned in .env, saveFor() skips the write and
     * the controls that reach here are hidden anyway — a stale page degrades to
     * a no-op instead of an error.
     */
    public function setGettingStarted(Request $request, SettingsManager $settings): JsonResponse|RedirectResponse
    {
        // Explicit JSON 422 — admin paths don't auto-render validation as JSON.
        $validator = Validator::make($request->all(), ['show' => ['required', 'boolean']]);
        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $validator->errors()->first()], 422);
            }

            return redirect()->route('admin.dashboard')->withErrors($validator);
        }

        $show = $request->boolean('show');

        $settings->saveFor($request->user()->id, ['tts.show_getting_started' => $show]);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('admin.dashboard')->with(
            'success',
            $show ? 'Getting-started guide restored.'
                  : 'Guide hidden — bring it back anytime from Settings or your Account page.'
        );
    }

    /**
     * Rotate the current user's own connection key — for when it leaks. Scoped to
     * their key server-side, so one user can never reset another's. The previous
     * value stops working at once.
     */
    public function resetApiKey(Request $request): RedirectResponse
    {
        $apiKey = ApiKey::ownedActiveFor($request->user()->id);

        if (! $apiKey) {
            return redirect()->route('admin.dashboard')
                ->with('error', 'No active API key to reset — create one on the API Keys page first.');
        }

        $apiKey->rotate();

        return redirect()->route('admin.dashboard')
            ->with('success', 'API key reset — update your Bespoken plugin with the new value below.');
    }
}
