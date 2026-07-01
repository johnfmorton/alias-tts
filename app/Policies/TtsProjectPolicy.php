<?php

namespace App\Policies;

use App\Models\TtsProject;
use App\Models\User;

/**
 * Studio projects are personal: only the owner may view or change one, with a
 * SuperAdmin retaining full access for support (e.g. API recovery projects).
 * A project whose user_id is null — its owner was deleted — is SuperAdmin-only.
 * Enforced as `can:access,project` middleware on every {project} route.
 */
class TtsProjectPolicy
{
    public function access(User $user, TtsProject $project): bool
    {
        return $user->isSuperAdmin()
            || ($project->user_id !== null && $project->user_id === $user->id);
    }
}
