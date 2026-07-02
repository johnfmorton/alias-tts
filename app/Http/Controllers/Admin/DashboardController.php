<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\Voice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $voices = Voice::orderedFor($request->user()->id)->get();
        $keyIds = $user->apiKeys()->pluck('id');

        $stats = [
            'voices' => $voices->count(),
            // Per-user: your own keys and your own generations, not the whole server's.
            'apiKeys' => $keyIds->count(),
            'speeches' => Speech::whereIn('api_key_id', $keyIds)->count(),
        ];

        // Copy-paste connection details for the Bespoken Craft plugin. The key is the
        // user's OWN key (null if they haven't created one — the view prompts them).
        $connect = [
            'baseUrl' => rtrim((string) config('app.url'), '/'),
            'apiKey' => optional(ApiKey::ownedActiveFor($user->id))->key,
            'voiceIds' => $voices->pluck('slug')->all(),
        ];

        return view('admin.dashboard', compact('stats', 'connect', 'voices'));
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
