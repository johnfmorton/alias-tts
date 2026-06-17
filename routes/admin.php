<?php

use App\Http\Controllers\Admin\ApiKeyController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\VoiceController;
use Illuminate\Support\Facades\Route;

/*
| Control panel. Registered in bootstrap/app.php behind ['web','auth',EnsureUserIsAdmin]
| with the '/admin' prefix and 'admin.' name prefix.
*/

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

// API keys
Route::get('/api-keys', [ApiKeyController::class, 'index'])->name('api-keys.index');
Route::get('/api-keys/create', [ApiKeyController::class, 'create'])->name('api-keys.create');
Route::post('/api-keys', [ApiKeyController::class, 'store'])->name('api-keys.store');
Route::post('/api-keys/{apiKey}/toggle', [ApiKeyController::class, 'toggle'])->name('api-keys.toggle');
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
