<?php

namespace App\Services\Asr;

use App\Models\User;
use App\Services\Settings\SettingsManager;

/**
 * Implements "ASR transcript QA defaults on if available". The master switch
 * ships off (config/tts.php) because most installs have no Whisper sidecar, and
 * probing a missing one on every generation would cost a failed round-trip per
 * chunk. Instead this enabler is invoked only from the admin health surfaces
 * (the health page and `tts:doctor`) — places where someone is already looking
 * and a probe is cheap — and flips QA on the first time it sees the sidecar
 * healthy, persisting the choice as a per-user override the user can later turn
 * off (settings are per-user).
 *
 * It never overrides a deliberate decision: if the switch is pinned in .env or
 * a user already saved a choice (on or off), that user is left alone. So a
 * one-shot auto-enable per user, never a fight with anyone.
 */
class AsrAutoEnabler
{
    public function __construct(
        private readonly AsrClient $asr,
        private readonly SettingsManager $settings,
    ) {}

    /**
     * Probe the sidecar and enable QA for ONE user (the health-page visitor) if
     * it is healthy and they have not already chosen. Returns true only when
     * this call flipped their switch on, so the caller can tell them.
     */
    public function attempt(int $userId): bool
    {
        // Already on (env or their own saved choice, merged into config by the
        // admin middleware), or they pinned/saved a choice — respect it.
        if ((bool) config('tts.asr.enabled', false) || $this->settings->isExplicitlySetFor($userId, 'tts.asr.enabled')) {
            return false;
        }

        if (! $this->sidecarHealthy()) {
            return false; // no sidecar (or model not loaded) — leave it off, write nothing
        }

        $this->settings->saveFor($userId, ['tts.asr.enabled' => true]);
        config(['tts.asr.enabled' => true]); // reflect it for the rest of this request

        return true;
    }

    /**
     * `tts:doctor` variant — no signed-in user, so sweep every user who has not
     * made an explicit choice. Returns how many users were switched on.
     */
    public function attemptForAllUsers(): int
    {
        if ($this->settings->isLocked('tts.asr.enabled')) {
            return 0; // pinned in .env — instance-wide and read-only
        }

        $undecided = User::query()->pluck('id')
            ->reject(fn (int $id): bool => $this->settings->isExplicitlySetFor($id, 'tts.asr.enabled'));

        if ($undecided->isEmpty() || ! $this->sidecarHealthy()) {
            return 0;
        }

        foreach ($undecided as $userId) {
            $this->settings->saveFor($userId, ['tts.asr.enabled' => true]);
        }

        return $undecided->count();
    }

    private function sidecarHealthy(): bool
    {
        $health = $this->asr->health();

        return $health['reachable'] && ($health['body']['status'] ?? null) === 'ok';
    }
}
