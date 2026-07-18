<?php

namespace App\Services\Settings;

use App\Models\UserSetting;
use App\Services\Asr\AsrAutoEnabler;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Resolves and persists UI-managed settings, scoped PER USER. Precedence:
 *
 *     .env (locked, read-only in UI)  →  the user's DB override  →  config default
 *
 * The registry + the cache-safe `locked` flags live in config/settings.php. This
 * service layers ONE user's overrides onto runtime config for the duration of
 * that user's request or queue job ({@see applyForUser()}) so every existing
 * config() read transparently reflects THAT user's settings. Callers:
 *
 *   - admin panel: ApplyUserSettings middleware (the signed-in user)
 *   - /v1 API:     ValidateApiKey middleware (the key's owner)
 *   - queue jobs:  GenerateSpeechJob / RunGenblazeJob (the owning user)
 *   - no user (console, unauthenticated): pristine .env/config defaults
 *
 * Before each overlay the managed keys are reset to a baseline captured from
 * pristine config, so a long-lived queue worker never leaks user A's settings
 * into user B's job.
 */
class SettingsManager
{
    /** @var array<int, array<string, mixed>> per-user cache of DB overrides (path => value) */
    private array $overrides = [];

    /** @var array<string, mixed>|null pristine config values for the managed keys */
    private ?array $baseline = null;

    /**
     * The managed-settings registry, keyed by config path.
     *
     * @return array<string, array<string, mixed>>
     */
    public function managed(): array
    {
        return (array) config('settings.managed', []);
    }

    /** Is this key pinned in .env (and therefore read-only in the UI, for everyone)? */
    public function isLocked(string $configPath): bool
    {
        return (bool) ($this->managed()[$configPath]['locked'] ?? false);
    }

    /**
     * Has a deliberate choice been made for this key FOR THIS USER — pinned in
     * .env (locked, instance-wide) or saved as their override — as opposed to it
     * still riding the config default? Lets a feature auto-default itself exactly
     * once per user without ever overriding an explicit decision (see
     * {@see AsrAutoEnabler}).
     */
    public function isExplicitlySetFor(?int $userId, string $configPath): bool
    {
        return $this->isLocked($configPath)
            || array_key_exists($configPath, $this->overridesFor($userId));
    }

    /**
     * One user's DB overrides keyed by config path. Loaded once per instance per
     * user; empty (and never throwing) if the table doesn't exist yet — a fresh
     * install or a boot mid-migration must not break.
     *
     * @return array<string, mixed>
     */
    public function overridesFor(?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        if (isset($this->overrides[$userId])) {
            return $this->overrides[$userId];
        }

        try {
            if (! Schema::hasTable('user_settings')) {
                return $this->overrides[$userId] = [];
            }

            return $this->overrides[$userId] = UserSetting::query()
                ->where('user_id', $userId)
                ->get()
                ->mapWithKeys(fn (UserSetting $s): array => [$s->key => $s->value])
                ->all();
        } catch (Throwable) {
            return $this->overrides[$userId] = [];
        }
    }

    /**
     * Layer ONE user's overrides onto runtime config for every managed key NOT
     * pinned in .env. Managed keys are first reset to the pristine baseline, so
     * switching users (requests in one worker, jobs in one queue process) always
     * starts clean. `null` resets to pure .env/config defaults.
     */
    public function applyForUser(?int $userId): void
    {
        $managed = $this->managed();

        // Snapshot pristine config once per process, before the first overlay.
        if ($this->baseline === null) {
            $this->baseline = [];
            foreach (array_keys($managed) as $configPath) {
                $this->baseline[$configPath] = config($configPath);
            }
        }

        config($this->baseline);

        foreach ($this->overridesFor($userId) as $configPath => $value) {
            $entry = $managed[$configPath] ?? null;
            if ($entry === null || ($entry['locked'] ?? false)) {
                continue; // .env always wins
            }

            config([$configPath => $value]);
        }
    }

    /**
     * The value to show (and edit) in the form: the env value when locked, else
     * the user's saved value, else the config default — resolving the `inherits`
     * chain for the per-path action keys (which are null until explicitly set).
     * Assumes {@see applyForUser()} already ran for the viewing user (the admin
     * middleware guarantees it).
     */
    public function displayValue(string $configPath): mixed
    {
        $entry = $this->managed()[$configPath] ?? null;

        // config() already reflects env (locked) and the user's merged overrides.
        $value = config($configPath);
        if ($value !== null || $entry === null) {
            return $value;
        }

        return isset($entry['inherits']) ? config($entry['inherits']) : null;
    }

    /**
     * The pristine config default for a key — the .env fallback baked into
     * config/tts.php, BEFORE any user override was layered on. This is what a
     * "Restore default" control resets a field to. The baseline is snapshotted
     * once per process by {@see applyForUser()} (which the admin middleware runs
     * before the page renders); without it — console, or a manager built outside
     * the request cycle — fall back to a live config read, which is still pristine
     * because no overlay has happened.
     */
    public function defaultFor(string $configPath): mixed
    {
        if ($this->baseline !== null && array_key_exists($configPath, $this->baseline)) {
            return $this->baseline[$configPath];
        }

        return config($configPath);
    }

    /**
     * Persist submitted values for the UNLOCKED managed keys only, scoped to one
     * user. Locked keys are silently skipped — defensive, since the form renders
     * them read-only anyway.
     *
     * @param  array<string, mixed>  $values  keyed by config path
     */
    public function saveFor(int $userId, array $values): void
    {
        $managed = $this->managed();

        foreach ($values as $configPath => $value) {
            $entry = $managed[$configPath] ?? null;
            if ($entry === null || ($entry['locked'] ?? false)) {
                continue;
            }

            UserSetting::query()->updateOrCreate(
                ['user_id' => $userId, 'key' => $configPath],
                ['value' => $this->coerce((string) $entry['type'], $value)],
            );
        }

        unset($this->overrides[$userId]); // bust the cache so a later read sees the writes
    }

    /** Cast a submitted form value to its registry type before storage. */
    private function coerce(string $type, mixed $value): mixed
    {
        return match ($type) {
            'bool' => (bool) $value,
            'int' => (int) $value,
            'float' => (float) $value,
            default => (string) $value,
        };
    }

    /**
     * The registry grouped for the view, each field decorated with its HTML field
     * name, current value, pristine default and lock state. Values reflect the
     * user applied by {@see applyForUser()}.
     *
     * SuperAdmin-only keys (`super_admin => true`) are omitted unless the viewer
     * is a SuperAdmin — a regular user never sees, and (mirrored in the
     * controller's validation) never saves, an instance-wide switch.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function groupedForView(bool $isSuperAdmin = true): array
    {
        $groups = [];

        foreach ($this->managed() as $configPath => $entry) {
            if (($entry['super_admin'] ?? false) && ! $isSuperAdmin) {
                continue;
            }

            $entry['path'] = $configPath;
            $entry['field'] = str_replace('.', '_', $configPath);
            $entry['value'] = $this->displayValue($configPath);
            $entry['default'] = $this->defaultFor($configPath);
            $groups[$entry['group']][] = $entry;
        }

        return $groups;
    }
}
