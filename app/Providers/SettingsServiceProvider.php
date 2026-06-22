<?php

namespace App\Providers;

use App\Services\Settings\SettingsManager;
use Illuminate\Support\ServiceProvider;

class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsManager::class);
    }

    public function boot(): void
    {
        // Layer saved DB overrides onto config so every config() read reflects the
        // admin Settings page. No-ops safely before the table exists (fresh install
        // / mid-migration) and never overrides a key pinned in .env.
        $this->app->make(SettingsManager::class)->applyToConfig();
    }
}
