<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\Credit\CreditService;
use Illuminate\Http\JsonResponse;

/**
 * Pre-generation credit gate for admin-panel fetch endpoints. Returns a 402
 * JSON body the frontend's shared errorMessage() helper already surfaces as
 * a toast — admin AJAX routes must emit JSON explicitly (only api/* and v1/*
 * auto-render JSON errors). Pass the user whose credit the action SPENDS:
 * the project owner for Studio project actions (a SuperAdmin rendering in a
 * user's project spends that user's credit), the signed-in user elsewhere.
 * Only generation starts are gated — playback, stitch, seal, and downloads
 * of existing audio never are.
 */
trait ChecksCredit
{
    private function creditError(?User $user): ?JsonResponse
    {
        if (app(CreditService::class)->canSpend($user)) {
            return null;
        }

        return response()->json(['message' => CreditService::OUT_OF_CREDIT_MESSAGE], 402);
    }
}
