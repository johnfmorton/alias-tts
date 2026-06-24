<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ApiKey;
use App\Models\MagicLoginToken;
use App\Models\TtsProject;
use App\Models\User;

/**
 * Mints the single-use auto-login link that drops an admin into the control
 * panel on a given project. Shared by the project-create endpoint and the
 * text-to-speech failure-recovery surfacing.
 */
trait MintsProjectEditLinks
{
    /**
     * Logs in the (only) super-admin today; the seam for per-user ownership is
     * the project's api_key_id once keys map to users. Returns [url, expiresAtIso],
     * both null when no admin account exists to log in.
     *
     * @return array{0: ?string, 1: ?string}
     */
    protected function mintEditLink(TtsProject $project, ?ApiKey $apiKey): array
    {
        $user = User::where('is_super_admin', true)->first();
        if (! $user) {
            return [null, null];
        }

        [$token, $plaintext] = MagicLoginToken::mint(
            $user,
            $project,
            $apiKey,
            (int) config('tts.magic_login_ttl_minutes', 60),
        );

        return [
            route('projects.open', ['token' => $plaintext]),
            $token->expires_at?->toIso8601String(),
        ];
    }
}
