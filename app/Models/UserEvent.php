<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One non-money account event (role/status change, creation, forced reset)
 * for the user detail timeline. Money events live in credit_transactions.
 */
#[Fillable(['user_id', 'kind', 'meta', 'actor_id'])]
class UserEvent extends Model
{
    public const KIND_CREATED = 'created';

    public const KIND_INVITED = 'invited';

    public const KIND_ROLE_CHANGE = 'role_change';

    public const KIND_STATUS_CHANGE = 'status_change';

    public const KIND_PASSWORD_RESET = 'password_reset';

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** Shorthand used by the controller actions that write the timeline. */
    public static function record(User $user, string $kind, ?User $actor = null, array $meta = []): self
    {
        return self::create([
            'user_id' => $user->id,
            'kind' => $kind,
            'meta' => $meta ?: null,
            'actor_id' => $actor?->id,
        ]);
    }
}
