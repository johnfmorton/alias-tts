<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Resolves and persists UI-managed settings. Precedence:
 *
 *     .env (locked, read-only in UI)  →  DB override (editable)  →  config default
 *
 * The registry + the cache-safe `locked` flags live in config/settings.php. This
 * service layers DB overrides onto runtime config at boot ({@see applyToConfig()})
 * so every existing config() read transparently reflects saved settings, and the
 * admin page reads/writes through {@see groupedForView()} / {@see save()}.
 */
class SettingsManager
{
    /** @var array<string, mixed>|null per-instance cache of DB overrides (path => value) */
    private ?array $overrides = null;

    /**
     * The managed-settings registry, keyed by config path.
     *
     * @return array<string, array<string, mixed>>
     */
    public function managed(): array
    {
        return (array) config('settings.managed', []);
    }

    /** Is this key pinned in .env (and therefore read-only in the UI)? */
    public function isLocked(string $configPath): bool
    {
        return (bool) ($this->managed()[$configPath]['locked'] ?? false);
    }

    /**
     * DB overrides keyed by config path. Loaded once per instance; empty (and
     * never throwing) if the table doesn't exist yet — a fresh install or a boot
     * mid-migration must not break.
     *
     * @return array<string, mixed>
     */
    public function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        try {
            if (! Schema::hasTable('settings')) {
                return $this->overrides = [];
            }

            return $this->overrides = Setting::all()
                ->mapWithKeys(fn (Setting $s): array => [$s->key => $s->value])
                ->all();
        } catch (Throwable) {
            return $this->overrides = [];
        }
    }

    /**
     * Layer DB overrides onto runtime config for every managed key NOT pinned in
     * .env. After this runs, config('tts.asr.*') (and the AsrClient accessors)
     * reflect the saved settings, so no call site needs to know about this layer.
     * Env-locked keys are never touched — .env always wins.
     */
    public function applyToConfig(): void
    {
        $managed = $this->managed();

        foreach ($this->overrides() as $configPath => $value) {
            $entry = $managed[$configPath] ?? null;
            if ($entry === null || ($entry['locked'] ?? false)) {
                continue;
            }

            config([$configPath => $value]);
        }
    }

    /**
     * The value to show (and edit) in the form: the env value when locked, else
     * the saved DB value, else the config default — resolving the `inherits` chain
     * for the per-path action keys (which are null until explicitly set).
     */
    public function displayValue(string $configPath): mixed
    {
        $entry = $this->managed()[$configPath] ?? null;

        // config() already reflects env (locked) and the boot-merged DB value.
        $value = config($configPath);
        if ($value !== null || $entry === null) {
            return $value;
        }

        return isset($entry['inherits']) ? config($entry['inherits']) : null;
    }

    /**
     * Persist submitted values for the UNLOCKED managed keys only. Locked keys are
     * silently skipped — defensive, since the form renders them read-only anyway.
     *
     * @param  array<string, mixed>  $values  keyed by config path
     */
    public function save(array $values): void
    {
        $managed = $this->managed();

        foreach ($values as $configPath => $value) {
            $entry = $managed[$configPath] ?? null;
            if ($entry === null || ($entry['locked'] ?? false)) {
                continue;
            }

            Setting::query()->updateOrCreate(
                ['key' => $configPath],
                ['value' => $this->coerce((string) $entry['type'], $value)],
            );
        }

        $this->overrides = null; // bust the cache so a later read sees the writes
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
     * name, current value and lock state.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function groupedForView(): array
    {
        $groups = [];

        foreach ($this->managed() as $configPath => $entry) {
            $entry['path'] = $configPath;
            $entry['field'] = str_replace('.', '_', $configPath);
            $entry['value'] = $this->displayValue($configPath);
            $groups[$entry['group']][] = $entry;
        }

        return $groups;
    }
}
