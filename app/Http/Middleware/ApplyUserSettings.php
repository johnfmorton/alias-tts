<?php

namespace App\Http\Middleware;

use App\Services\Settings\SettingsManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Overlays the signed-in user's saved settings onto runtime config for this
 * request, so every config('tts.*') read in the panel reflects THEIR choices —
 * never another user's. Runs after auth in the /admin group.
 */
class ApplyUserSettings
{
    public function __construct(private readonly SettingsManager $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->settings->applyForUser($request->user()?->id);

        return $next($request);
    }
}
