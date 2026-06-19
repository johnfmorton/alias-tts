<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A single-use, expiring token that logs a user into the control panel and
 * drops them on a project. The raw token lives only in the link we hand the
 * client; the database stores its sha256 hash, so a leaked row can't be
 * replayed. Redemption is atomic, so a double-click can't log in twice.
 */
class MagicLoginToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'token_hash',
        'user_id',
        'tts_project_id',
        'api_key_id',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(TtsProject::class, 'tts_project_id');
    }

    /**
     * Mint a token for $user landing on $project. Returns the persisted record
     * and the RAW token (shown once, embedded in the link) — the raw value is
     * never stored.
     *
     * @return array{0: self, 1: string}
     */
    public static function mint(User $user, TtsProject $project, ?ApiKey $apiKey, int $ttlMinutes): array
    {
        $plaintext = Str::random(48);

        $token = self::create([
            'token_hash' => hash('sha256', $plaintext),
            'user_id' => $user->id,
            'tts_project_id' => $project->id,
            'api_key_id' => $apiKey?->id,
            'expires_at' => Carbon::now()->addMinutes($ttlMinutes),
        ]);

        return [$token, $plaintext];
    }

    /**
     * Atomically claim a token by its raw value. Returns the record on success,
     * or null if it's unknown, already used, or expired. The conditional update
     * is the lock: only the first caller flips used_at, so concurrent clicks
     * can't both succeed.
     */
    public static function redeem(string $plaintext): ?self
    {
        $hash = hash('sha256', $plaintext);

        $token = self::where('token_hash', $hash)->first();
        if (! $token) {
            return null;
        }

        $claimed = self::where('id', $token->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->update(['used_at' => Carbon::now()]);

        return $claimed === 1 ? $token->refresh() : null;
    }
}
