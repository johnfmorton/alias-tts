<?php

namespace App\Services\Asr;

use App\Services\Settings\SettingsManager;

/**
 * Implements "ASR transcript QA defaults on if available". The master switch
 * ships off (config/tts.php) because most installs have no Whisper sidecar, and
 * probing a missing one on every generation would cost a failed round-trip per
 * chunk. Instead this enabler is invoked only from the admin health surfaces
 * (the health page and `tts:doctor`) — places where an admin is already looking
 * and a probe is cheap — and flips QA on the first time it sees the sidecar
 * healthy, persisting the choice as a DB override the admin can later turn off.
 *
 * It never overrides a deliberate decision: if the switch is pinned in .env or
 * already saved (on or off), {@see attempt()} is a no-op. So a one-shot
 * auto-enable, never a fight with the admin.
 */
class AsrAutoEnabler
{
    public function __construct(
        private readonly AsrClient $asr,
        private readonly SettingsManager $settings,
    ) {}

    /**
     * Probe the sidecar and enable QA if it is healthy and the admin has not
     * already chosen. Returns true only when this call flipped the switch on, so
     * the caller can tell the admin it happened.
     */
    public function attempt(): bool
    {
        // Already on, or the admin pinned/saved a choice (on OR off) — respect it.
        if ((bool) config('tts.asr.enabled', false) || $this->settings->isExplicitlySet('tts.asr.enabled')) {
            return false;
        }

        $health = $this->asr->health();
        if (! $health['reachable'] || ($health['body']['status'] ?? null) !== 'ok') {
            return false; // no sidecar (or model not loaded) — leave it off, write nothing
        }

        $this->settings->save(['tts.asr.enabled' => true]);
        config(['tts.asr.enabled' => true]); // reflect it for the rest of this request

        return true;
    }
}
