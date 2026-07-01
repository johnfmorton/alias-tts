<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\ApiKeyController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\GenblazeController;
use App\Http\Controllers\Admin\HealthController;
use App\Http\Controllers\Admin\HealthTestController;
use App\Http\Controllers\Admin\PronunciationController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SocialAuthController;
use App\Http\Controllers\Admin\StudioController;
use App\Http\Controllers\Admin\StudioProjectController;
use App\Http\Controllers\Admin\TwoFactorController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VoiceController;
use App\Http\Middleware\EnsureUserIsSuperAdmin;
use Illuminate\Support\Facades\Route;

/*
| Control panel. Registered in bootstrap/app.php behind ['web','auth',EnsureAccountIsActive]
| with the '/admin' prefix and 'admin.' name prefix. Open to any signed-in, active user;
| SuperAdmin-only routes add EnsureUserIsSuperAdmin per-route.
*/

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::post('/reset-api-key', [DashboardController::class, 'resetApiKey'])->name('dashboard.reset-key');

// Account — self-service profile, security, and sign-in. Open to any signed-in user.
Route::get('/account', [AccountController::class, 'index'])->name('account.index');
Route::put('/account/profile', [AccountController::class, 'updateProfile'])->name('account.profile');
Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');
Route::post('/account/avatar', [AccountController::class, 'updateAvatar'])->name('account.avatar');
Route::delete('/account/avatar', [AccountController::class, 'deleteAvatar'])->name('account.avatar.delete');
Route::delete('/account', [AccountController::class, 'destroy'])->name('account.destroy');
// Two-factor (TOTP) setup + connected-account (SSO) management.
Route::post('/account/two-factor', [TwoFactorController::class, 'enable'])->name('account.2fa.enable');
Route::post('/account/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('account.2fa.confirm');
Route::delete('/account/two-factor', [TwoFactorController::class, 'disable'])->name('account.2fa.disable');
Route::post('/account/two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('account.2fa.recovery');
Route::post('/account/connections/{provider}', [SocialAuthController::class, 'startConnect'])->name('account.connections.connect');
Route::delete('/account/connections/{provider}', [SocialAuthController::class, 'disconnect'])->name('account.connections.disconnect');
// Streams a user's avatar from the private disk (the bucket has no public URL).
Route::get('/avatars/{user}', [AccountController::class, 'avatar'])->name('avatars.show');

// Stop impersonating — in the general group so the impersonated (possibly non-admin) user can return.
Route::post('/impersonate/leave', [UserController::class, 'leaveImpersonation'])->name('impersonate.leave');

// Users — SuperAdmin only (design 2B). The nav's ADMIN section appears once these exist.
Route::middleware(EnsureUserIsSuperAdmin::class)->group(function () {
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::post('/users/invite', [UserController::class, 'invite'])->name('users.invite');
    Route::patch('/users/{user}/role', [UserController::class, 'updateRole'])->name('users.role');
    Route::post('/users/{user}/suspend', [UserController::class, 'suspend'])->name('users.suspend');
    Route::post('/users/{user}/force-reset', [UserController::class, 'forceReset'])->name('users.force-reset');
    Route::post('/users/{user}/impersonate', [UserController::class, 'impersonate'])->name('users.impersonate');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});

// Settings — service configuration (env-pinned values are read-only).
Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

// Health — web view over the same checks as `php artisan tts:doctor`.
Route::get('/health', [HealthController::class, 'index'])->name('health');

// Live provider tests (real, billable generation calls).
Route::post('/health/test/short', [HealthTestController::class, 'short'])->name('health.test.short');
Route::post('/health/test/long', [HealthTestController::class, 'long'])->name('health.test.long');
Route::get('/health/test/{id}/status', [HealthTestController::class, 'status'])->name('health.test.status');
Route::get('/health/test/{id}/audio', [HealthTestController::class, 'audio'])->name('health.test.audio');

// Studio — inspect normalization/chunking and hear text whole, per-chunk, or stitched.
Route::prefix('studio')->name('studio.')->group(function () {
    Route::get('/', [StudioController::class, 'index'])->name('index');
    Route::post('/preview', [StudioController::class, 'preview'])->name('preview');
    Route::post('/synthesize', [StudioController::class, 'synthesize'])->name('synthesize');
    Route::post('/stitch', [StudioController::class, 'stitch'])->name('stitch');
    Route::post('/concat', [StudioController::class, 'concat'])->name('concat');
    Route::post('/advanced', [StudioController::class, 'setAdvanced'])->name('advanced');
    Route::post('/voice-defaults', [StudioController::class, 'saveVoiceDefaults'])->name('voice-defaults');
    Route::post('/presets', [StudioController::class, 'storePreset'])->name('presets.store');
    Route::delete('/presets/{preset}', [StudioController::class, 'destroyPreset'])->name('presets.destroy');

    // "Generate via Genblaze" — dispatches an async run to the Genblaze runner and
    // polls for provenance; asset() proxies the B2 audio so a private bucket plays.
    Route::get('/genblaze', [GenblazeController::class, 'index'])->name('genblaze');
    Route::post('/genblaze/run', [GenblazeController::class, 'run'])->name('genblaze.run');
    Route::get('/genblaze/runs/{run}', [GenblazeController::class, 'status'])->name('genblaze.status');
    Route::get('/genblaze/asset', [GenblazeController::class, 'asset'])->name('genblaze.asset');

    // Editable projects: persist chunks, regenerate one at a time, rebuild the stitch.
    Route::prefix('projects')->name('projects.')->group(function () {
        Route::get('/create', [StudioProjectController::class, 'create'])->name('create');
        Route::post('/review', [StudioProjectController::class, 'review'])->name('review');
        Route::post('/apply', [StudioProjectController::class, 'applyAndStore'])->name('apply');
        Route::post('/', [StudioProjectController::class, 'store'])->name('store');

        // Projects are personal: every {project} route requires the owner (or a
        // SuperAdmin) — TtsProjectPolicy::access. Chunk/take mismatches inside a
        // project are separately 404'd in the controller.
        Route::middleware('can:access,project')->group(function () {
            Route::get('/{project}', [StudioProjectController::class, 'show'])->name('show');
            Route::patch('/{project}', [StudioProjectController::class, 'update'])->name('update');
            Route::post('/{project}/dismiss-failure', [StudioProjectController::class, 'dismissFailure'])->name('dismiss-failure');
            Route::patch('/{project}/voice', [StudioProjectController::class, 'updateVoice'])->name('voice');
            Route::delete('/{project}', [StudioProjectController::class, 'destroy'])->name('destroy');
            Route::get('/{project}/edit', [StudioProjectController::class, 'edit'])->name('edit');
            Route::post('/{project}/reset', [StudioProjectController::class, 'reset'])->name('reset');
            Route::get('/{project}/audio', [StudioProjectController::class, 'finalAudio'])->name('audio');
            Route::post('/{project}/preview', [StudioProjectController::class, 'previewConcat'])->name('preview');
            Route::post('/{project}/rebuild', [StudioProjectController::class, 'rebuild'])->name('rebuild');
            // Seal the final as the human-approved cut, then download a verifiable receipt zip.
            Route::post('/{project}/seal', [StudioProjectController::class, 'seal'])->name('seal');
            Route::get('/{project}/receipt', [StudioProjectController::class, 'receipt'])->name('receipt');
            Route::post('/{project}/chunks', [StudioProjectController::class, 'storeChunk'])->name('chunks.store');
            Route::patch('/{project}/chunks/{chunk}', [StudioProjectController::class, 'updateChunk'])->name('chunks.update');
            Route::patch('/{project}/chunks/{chunk}/voice', [StudioProjectController::class, 'updateChunkVoice'])->name('chunks.voice');
            Route::patch('/{project}/chunks/{chunk}/tuning', [StudioProjectController::class, 'tuneChunk'])->name('chunks.tuning');
            Route::post('/{project}/chunks/{chunk}/preview-tuning', [StudioProjectController::class, 'previewChunkTuning'])->name('chunks.preview-tuning');
            Route::post('/{project}/chunks/{chunk}/use-preview', [StudioProjectController::class, 'useChunkPreview'])->name('chunks.use-preview');
            Route::post('/{project}/chunks/{chunk}/generate', [StudioProjectController::class, 'generateChunk'])->name('chunks.generate');
            Route::post('/{project}/chunks/{chunk}/reroll', [StudioProjectController::class, 'rerollChunk'])->name('chunks.reroll');
            Route::get('/{project}/chunks/{chunk}/audio', [StudioProjectController::class, 'chunkAudio'])->name('chunks.audio');
            // Take history: every render is kept; the user can audition, re-select, or delete a take.
            Route::get('/{project}/chunks/{chunk}/takes', [StudioProjectController::class, 'listTakes'])->name('chunks.takes.index');
            Route::get('/{project}/chunks/{chunk}/takes/{take}/audio', [StudioProjectController::class, 'takeAudio'])->name('chunks.takes.audio');
            Route::post('/{project}/chunks/{chunk}/takes/{take}/select', [StudioProjectController::class, 'selectTake'])->name('chunks.takes.select');
            Route::delete('/{project}/chunks/{chunk}/takes/{take}', [StudioProjectController::class, 'deleteTake'])->name('chunks.takes.delete');
        });
    });
});

// API keys
Route::get('/api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
Route::get('/api-keys/create', [ApiKeyController::class, 'create'])->name('api-keys.create');
Route::post('/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
Route::post('/api-keys/{apiKey}/toggle', [ApiKeyController::class, 'toggle'])->name('api-keys.toggle');
Route::post('/api-keys/{apiKey}/regenerate', [ApiKeyController::class, 'regenerate'])->name('api-keys.regenerate');
Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

// Pronunciation dictionary — the signed-in writer's personal, private lexicon.
Route::get('/pronunciations', [PronunciationController::class, 'index'])->name('pronunciations.index');
Route::get('/pronunciations/create', [PronunciationController::class, 'create'])->name('pronunciations.create');
Route::post('/pronunciations', [PronunciationController::class, 'store'])->name('pronunciations.store');
Route::get('/pronunciations/{entry}/edit', [PronunciationController::class, 'edit'])->name('pronunciations.edit');
Route::put('/pronunciations/{entry}', [PronunciationController::class, 'update'])->name('pronunciations.update');
Route::delete('/pronunciations/{entry}', [PronunciationController::class, 'destroy'])->name('pronunciations.destroy');

// Voices
Route::get('/voices', [VoiceController::class, 'index'])->name('voices.index');
Route::get('/voices/create', [VoiceController::class, 'create'])->name('voices.create');
Route::post('/voices', [VoiceController::class, 'store'])->name('voices.store');
Route::post('/voices/import', [VoiceController::class, 'import'])->name('voices.import');
Route::get('/voices/{voice}/edit', [VoiceController::class, 'edit'])->name('voices.edit');
Route::put('/voices/{voice}', [VoiceController::class, 'update'])->name('voices.update');
Route::get('/voices/{voice}/export', [VoiceController::class, 'export'])->name('voices.export');
Route::post('/voices/{voice}/test', [VoiceController::class, 'test'])->name('voices.test');
Route::delete('/voices/{voice}', [VoiceController::class, 'destroy'])->name('voices.destroy');
