<?php

use App\Http\Controllers\Admin\ApiKeyController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HealthController;
use App\Http\Controllers\Admin\HealthTestController;
use App\Http\Controllers\Admin\StudioController;
use App\Http\Controllers\Admin\StudioProjectController;
use App\Http\Controllers\Admin\VoiceController;
use Illuminate\Support\Facades\Route;

/*
| Control panel. Registered in bootstrap/app.php behind ['web','auth',EnsureUserIsAdmin]
| with the '/admin' prefix and 'admin.' name prefix.
*/

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

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

    // Editable projects: persist chunks, regenerate one at a time, rebuild the stitch.
    Route::prefix('projects')->name('projects.')->group(function () {
        Route::get('/create', [StudioProjectController::class, 'create'])->name('create');
        Route::post('/', [StudioProjectController::class, 'store'])->name('store');
        Route::get('/{project}', [StudioProjectController::class, 'show'])->name('show');
        Route::delete('/{project}', [StudioProjectController::class, 'destroy'])->name('destroy');
        Route::get('/{project}/audio', [StudioProjectController::class, 'finalAudio'])->name('audio');
        Route::post('/{project}/rebuild', [StudioProjectController::class, 'rebuild'])->name('rebuild');
        Route::patch('/{project}/chunks/{chunk}', [StudioProjectController::class, 'updateChunk'])->name('chunks.update');
        Route::post('/{project}/chunks/{chunk}/generate', [StudioProjectController::class, 'generateChunk'])->name('chunks.generate');
        Route::get('/{project}/chunks/{chunk}/audio', [StudioProjectController::class, 'chunkAudio'])->name('chunks.audio');
    });
});

// API keys
Route::get('/api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
Route::get('/api-keys/create', [ApiKeyController::class, 'create'])->name('api-keys.create');
Route::post('/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
Route::post('/api-keys/{apiKey}/toggle', [ApiKeyController::class, 'toggle'])->name('api-keys.toggle');
Route::post('/api-keys/{apiKey}/regenerate', [ApiKeyController::class, 'regenerate'])->name('api-keys.regenerate');
Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api-keys.destroy');

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
