<?php

namespace App\Exceptions;

use App\Models\TtsProject;
use App\Services\SpeechService;
use RuntimeException;
use Throwable;

/**
 * Thrown by {@see SpeechService::synthesize()} when synchronous
 * generation fails, carrying the recovery Studio project (when api_project_mode
 * created one) so the controller can point the API response at it. The message
 * mirrors the underlying provider error so the API response detail is unchanged.
 */
class SpeechGenerationException extends RuntimeException
{
    public function __construct(Throwable $previous, public readonly ?TtsProject $recoveryProject = null)
    {
        parent::__construct($previous->getMessage(), (int) $previous->getCode(), $previous);
    }
}
