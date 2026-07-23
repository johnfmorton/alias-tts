<?php

use App\Services\Health\HealthReport;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly database dump to the configured storage disk (local or S3/B2), under
// db_backup/YYYY/MM/DD/. Runs before the audio cleanups. Bump the frequency
// (e.g. ->twiceDaily() or ->hourly()) for finer-grained restore points — the
// retention thinning below is built to handle several dumps per day.
Schedule::command('db:backup')->dailyAt('02:30');

// Thin old dumps: keep everything < 30 days, the oldest per day for 30–90 days,
// the oldest per month for 90 days–12 months, and drop anything older.
Schedule::command('db:prune-backups')->dailyAt('02:40');

// Daily TTL cleanup of expired generated audio (rows + files on the configured
// disk, local or S3). Requires the OS scheduler to run `php artisan schedule:run`
// every minute — see docs/DEPLOYMENT.md. Verify with `php artisan schedule:list`.
Schedule::command('speech:cleanup')->dailyAt('03:00');

// Daily prune of expired, untouched API-failure recovery projects (see
// tts.api_project_mode). 'always'-mode and panel-made projects have no TTL and
// are never pruned. Staggered just after speech:cleanup.
Schedule::command('projects:prune-recovery')->dailyAt('03:10');

// Daily prune of expired prepared reference clips (record/upload → preview
// staging). A saved clip is consumed immediately; this clears abandoned previews.
Schedule::command('voices:prune-clips')->dailyAt('03:20');

// Liveness heartbeat: stamps the current time every minute so `tts:doctor` can
// tell whether cron is actually running `schedule:run` — not just that tasks
// are registered in code. A stale/missing beat means the cron isn't firing.
Schedule::call(fn () => Cache::put(HealthReport::SCHEDULER_HEARTBEAT_KEY, now()->getTimestamp()))
    ->everyMinute()
    ->name('tts:scheduler-heartbeat');
