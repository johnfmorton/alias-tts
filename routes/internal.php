<?php

use App\Http\Controllers\Internal\PipelineController;
use Illuminate\Support\Facades\Route;

/*
| Stateless pipeline primitives for the Genblaze orchestrator, mounted at
| /v1/internal/* behind ValidateInternalSecret (see bootstrap/app.php). Each
| route exposes one stage of the existing TTS pipeline so the external Genblaze
| runner can own the chunk -> generate -> score -> trim -> stitch orchestration
| while every heavy operation still runs here, reusing the exact services the
| public /v1 + Studio paths use.
*/
Route::post('/chunk', [PipelineController::class, 'chunk'])->name('chunk');
Route::post('/generate', [PipelineController::class, 'generate'])->name('generate');
Route::post('/score', [PipelineController::class, 'score'])->name('score');
Route::post('/trim', [PipelineController::class, 'trim'])->name('trim');
Route::post('/stitch', [PipelineController::class, 'stitch'])->name('stitch');

// Live pipeline-progress pings from the runner (drives the Studio panel's
// real-time checklist); does not touch the TTS pipeline itself.
Route::post('/genblaze/progress', [PipelineController::class, 'progress'])->name('genblaze.progress');
