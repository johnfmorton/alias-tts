<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A third-party sign-in identity (Google / GitHub) linked to a local user, via
 * Laravel Socialite. Uniqueness (provider + provider_id, and user + provider) is
 * enforced at the database.
 */
#[Fillable(['user_id', 'provider', 'provider_id', 'name', 'email', 'avatar'])]
class ConnectedAccount extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
