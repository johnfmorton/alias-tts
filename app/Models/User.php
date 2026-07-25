<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'is_super_admin', 'studio_advanced', 'status', 'last_active_at', 'avatar_path'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Account lifecycle states (see the 2026_07_01 migration). */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_INVITED = 'invited';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'studio_advanced' => 'boolean',
            // Prepaid credit in micro-dollars; NULL = unlimited. Deliberately
            // NOT fillable — only CreditService writes it (ledger-backed).
            'credit_balance_micro' => 'integer',
            'last_active_at' => 'datetime',
            // 2FA material is encrypted at rest; recovery codes are an encrypted array.
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /** 2FA is active once the user has confirmed a TOTP code against their secret. */
    public function hasTwoFactorEnabled(): bool
    {
        return ! is_null($this->two_factor_confirmed_at);
    }

    /** A secret has been generated but the confirming code hasn't been entered yet. */
    public function hasTwoFactorPending(): bool
    {
        return ! is_null($this->two_factor_secret) && is_null($this->two_factor_confirmed_at);
    }

    /** SSO identities linked to this account (Google, GitHub). */
    public function connectedAccounts(): HasMany
    {
        return $this->hasMany(ConnectedAccount::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * The role label the UI shows. The design exposes exactly two roles, mapped
     * onto the existing `is_super_admin` boolean — no roles table required.
     */
    public function roleLabel(): string
    {
        return $this->isSuperAdmin() ? 'SuperAdmin' : 'User';
    }

    /** Up to two uppercase initials for the avatar placeholder ("John F Morton" → "JM"). */
    public function initials(): string
    {
        $words = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $words = array_values(array_filter($words));

        if ($words === []) {
            return Str::upper(Str::substr((string) $this->email, 0, 2));
        }

        if (count($words) === 1) {
            return Str::upper(Str::substr($words[0], 0, 2));
        }

        return Str::upper(Str::substr($words[0], 0, 1).Str::substr(end($words), 0, 1));
    }

    /**
     * URL for an uploaded avatar, or null to fall back to initials. Avatars live on
     * the private storage disk (B2 in prod), so the URL points at an app proxy route
     * that streams the object — like Genblaze audio. `v` busts caches on re-upload.
     */
    public function avatarUrl(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        return route('admin.avatars.show', [
            'user' => $this->getKey(),
            'v' => substr(md5($this->avatar_path), 0, 8),
        ]);
    }

    /** This writer's learned pronunciation lexicon. */
    public function pronunciationEntries(): HasMany
    {
        return $this->hasMany(PronunciationEntry::class);
    }

    /** API keys owned by this user (for per-user sync + usage monitoring). */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /** Credit ledger rows (grants + charges), newest first via the index. */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /** Non-money account events (role/status changes, creation, resets). */
    public function events(): HasMany
    {
        return $this->hasMany(UserEvent::class);
    }

    /** True when this account has a metered credit balance (NULL = unlimited). */
    public function hasLimitedCredit(): bool
    {
        return $this->credit_balance_micro !== null;
    }
}
