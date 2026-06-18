<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily TTL cleanup of expired generated audio (rows + files on the configured
// disk, local or S3). Requires the OS scheduler to run `php artisan schedule:run`
// every minute — see docs/DEPLOYMENT.md. Verify with `php artisan schedule:list`.
Schedule::command('speech:cleanup')->dailyAt('03:00');
