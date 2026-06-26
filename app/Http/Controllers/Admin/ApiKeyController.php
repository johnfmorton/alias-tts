<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApiKeyController extends Controller
{
    public function index(): View
    {
        $apiKeys = ApiKey::withCount('speeches')->orderByDesc('created_at')->get();

        return view('admin.api-keys.index', compact('apiKeys'));
    }

    public function create(): View
    {
        return view('admin.api-keys.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'rate_limit' => ['nullable', 'integer', 'min:1'],
        ]);

        // Own the key with the creating user. A user picker arrives with the
        // deferred user-creation UI; today the super-admin is the only user.
        $apiKey = ApiKey::generate($validated['name'], $validated['rate_limit'] ?? null, $request->user()?->id);

        return redirect()->route('admin.api-keys.index')
            ->with('success', 'API key created.')
            ->with('new_key', $apiKey->key);
    }

    public function toggle(ApiKey $apiKey): RedirectResponse
    {
        $apiKey->update(['is_active' => ! $apiKey->is_active]);

        return redirect()->route('admin.api-keys.index')
            ->with('success', 'API key '.($apiKey->is_active ? 'activated' : 'deactivated').'.');
    }

    public function regenerate(ApiKey $apiKey): RedirectResponse
    {
        $apiKey->rotate();

        return redirect()->route('admin.api-keys.index')
            ->with('success', "Key '{$apiKey->name}' regenerated — update your clients with the new value.")
            ->with('new_key', $apiKey->key);
    }

    public function destroy(ApiKey $apiKey): RedirectResponse
    {
        $apiKey->delete();

        return redirect()->route('admin.api-keys.index')
            ->with('success', 'API key deleted.');
    }
}
