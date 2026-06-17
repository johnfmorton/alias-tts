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
});
