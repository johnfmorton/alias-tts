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
        $voices = Voice::orderBy('name')->get();

        $stats = [
            'voices' => $voices->count(),
            'apiKeys' => ApiKey::count(),
            'speeches' => Speech::count(),
        ];

        // Copy-paste connection details for the Bespoken Craft plugin.
        $connect = [
            'baseUrl' => rtrim((string) config('app.url'), '/'),
            'apiKey' => optional(ApiKey::resolveForUser($request->user()?->id))->key,
            'voiceIds' => $voices->pluck('slug')->all(),
        ];

        return view('admin.dashboard', compact('stats', 'connect', 'voices'));
    }

    /**
     * Rotate the connection key shown on the dashboard — for when it leaks.
     * Resolves the current user's key server-side (so one user can't reset
     * another's), and claims a legacy unowned key for them as it rotates so
     * future resets stay owner-scoped. The previous value stops working at once.
     */
    public function resetApiKey(Request $request): RedirectResponse
    {
        $userId = $request->user()?->id;
        $apiKey = ApiKey::resolveForUser($userId);

        if (! $apiKey) {
            return redirect()->route('admin.dashboard')
                ->with('error', 'No active API key to reset — create one on the API Keys page first.');
        }

        if ($apiKey->user_id === null && $userId !== null) {
            $apiKey->user_id = $userId;
        }
        $apiKey->rotate();

        return redirect()->route('admin.dashboard')
            ->with('success', 'API key reset — update your Bespoken plugin with the new value below.');
    }
}
