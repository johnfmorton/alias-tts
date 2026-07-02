<?php

namespace App\Providers;

use App\Services\Settings\SettingsManager;
use Illuminate\Support\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Settings are per-user, so nothing is applied at boot — the overlay
        // happens once the user is known: ApplyUserSettings (admin panel),
        // ValidateApiKey (/v1, the key's owner), or the queue job's owner.
        // Console and unauthenticated paths run on pristine .env/config defaults.
        $this->app->singleton(SettingsManager::class);
    }
}
