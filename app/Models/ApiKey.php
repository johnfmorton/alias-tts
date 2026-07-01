<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'name',
        'key',
        'is_active',
        'rate_limit',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'rate_limit' => 'integer',
    ];

    /** The user who owns this key — scopes the dictionary it syncs and its usage. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function speeches(): HasMany
    {
        return $this->hasMany(Speech::class, 'api_key_id');
    }

    /**
     * The signed-in user's own active connection key (most recent), or null.
     * Strictly per-user: there is NO cross-user or unowned fallback, so one user
     * never sees another's — or a legacy shared — key. Legacy unowned keys are
     * reassigned to the primary admin by migration, so `null` here means the user
     * simply hasn't created a key yet (the dashboard prompts them to).
     */
    public static function ownedActiveFor(int $userId): ?self
    {
        return self::where('is_active', true)
            ->where('user_id', $userId)
            ->orderByDesc('created_at')
            ->first();
    }

    public static function generate(string $name, ?int $rateLimit = null, ?int $userId = null): self
    {
        return self::create([
            'user_id' => $userId,
            'name' => $name,
            'key' => 'sk_'.Str::random(32),
            'is_active' => true,
            'rate_limit' => $rateLimit,
        ]);
    }

    /**
     * Issue a new secret for this key. The previous value stops working.
     */
    public function rotate(): self
    {
        $this->update(['key' => 'sk_'.Str::random(32)]);

        return $this;
    }
}
