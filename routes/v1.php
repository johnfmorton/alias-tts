<?php

use App\Http\Controllers\OpenAiSpeechController;
use App\Http\Controllers\ProjectApiController;
use App\Http\Controllers\PronunciationApiController;
use App\Http\Controllers\TextToSpeechController;
use App\Http\Middleware\EnsureCreditAvailable;
use App\Http\Middleware\RateLimitApiRequests;
use App\Http\Middleware\ValidateApiKey;
use Illuminate\Support\Facades\Route;

/*
| ElevenLabs-compatible API surface, mounted at the root (no /api prefix) so
| existing ElevenLabs clients only need to swap the base URL.
*/
Route::middleware([ValidateApiKey::class, RateLimitApiRequests::class, EnsureCreditAvailable::class])->group(function () {
    Route::post('/v1/text-to-speech/{voice_id}', [TextToSpeechController::class, 'store'])
        ->name('tts.store');

    // Streaming variant: same synchronous handler for now (returns full audio).
    Route::post('/v1/text-to-speech/{voice_id}/stream', [TextToSpeechController::class, 'store'])
        ->name('tts.stream');

    // Async (Alias extension): queue generation for long text. The POST is
    // the expensive, generating call, so it stays rate-limited.
    Route::post('/v1/text-to-speech/{voice_id}/jobs', [TextToSpeechController::class, 'queue'])
        ->name('tts.jobs.queue');

    // OpenAI-compatible surface: same SpeechService, OpenAI request/response
    // shape, so apps that speak OpenAI's TTS API work by swapping the base URL.
    // The `openai.*` route name is what the shared auth/rate-limit middleware
    // keys on to emit OpenAI-shaped errors. See docs/OPENAI-COMPAT.md.
    Route::post('/v1/audio/speech', [OpenAiSpeechController::class, 'store'])
        ->name('openai.speech');
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

    // Create an editable project from text (no generation — just normalize +
    // chunk + persist), so it stays off the generation rate limit above.
    Route::post('/v1/projects', [ProjectApiController::class, 'store'])
        ->name('api.projects.store');

    // Read-only dictionary sync for the Bespoken plugin. A cheap, pollable read.
    Route::get('/v1/pronunciations', [PronunciationApiController::class, 'index'])
        ->name('api.pronunciations.index');
});
