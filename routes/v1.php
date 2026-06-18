<?php

use App\Http\Controllers\TextToSpeechController;
use App\Http\Middleware\RateLimitApiRequests;
use App\Http\Middleware\ValidateApiKey;
use Illuminate\Support\Facades\Route;

/*
| ElevenLabs-compatible API surface, mounted at the root (no /api prefix) so
| existing ElevenLabs clients only need to swap the base URL.
*/
Route::middleware([ValidateApiKey::class, RateLimitApiRequests::class])->group(function () {
    Route::post('/v1/text-to-speech/{voice_id}', [TextToSpeechController::class, 'store'])
        ->name('tts.store');

    // Streaming variant: same synchronous handler for now (returns full audio).
    Route::post('/v1/text-to-speech/{voice_id}/stream', [TextToSpeechController::class, 'store'])
        ->name('tts.stream');

    // Async (Bespoken extension): queue generation for long text. The POST is
    // the expensive, generating call, so it stays rate-limited.
    Route::post('/v1/text-to-speech/{voice_id}/jobs', [TextToSpeechController::class, 'queue'])
        ->name('tts.jobs.queue');
});

/*
| Poll + fetch for async jobs. Authenticated but NOT rate-limited: these are
| cheap reads the client polls repeatedly, and counting them would exhaust a
| key's hourly generation quota.
*/
Route::middleware([ValidateApiKey::class])->group(function () {
    Route::get('/v1/text-to-speech/jobs/{id}', [TextToSpeechController::class, 'status'])
        ->name('tts.jobs.status');

    Route::get('/v1/text-to-speech/jobs/{id}/audio', [TextToSpeechController::class, 'audio'])
        ->name('tts.jobs.audio');
});
