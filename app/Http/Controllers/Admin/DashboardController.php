<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Speech;
use App\Models\Voice;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $voices = Voice::orderBy('name')->get();
        $apiKeys = ApiKey::orderByDesc('created_at')->get();

        $stats = [
            'voices' => $voices->count(),
            'apiKeys' => $apiKeys->count(),
            'speeches' => Speech::count(),
        ];

        // Copy-paste connection details for the Bespoken Craft plugin.
        $connect = [
            'baseUrl' => rtrim((string) config('app.url'), '/'),
            'apiKey' => optional($apiKeys->firstWhere('is_active', true))->key,
            'voiceIds' => $voices->pluck('slug')->all(),
        ];

        return view('admin.dashboard', compact('stats', 'connect', 'voices', 'apiKeys'));
    }
}
