<?php

/*
|--------------------------------------------------------------------------
| UI-managed settings registry
|--------------------------------------------------------------------------
|
| Each entry maps a runtime config() path to the env var that pins it, plus the
| metadata the admin Settings page needs to render and validate the field.
|
| Resolution precedence (see App\Services\Settings\SettingsManager):
|
|     .env (locked, read-only in UI)  →  DB override (editable)  →  config default
|
| The `locked` flag — "is this pinned in .env?" — is computed HERE, at config
| evaluation time, NOT at runtime. That matters: once `php artisan config:cache`
| runs, env() returns null for .env-only vars, so a runtime check would report
| everything as unlocked. Because this file is evaluated WHILE the cache is built,
| the flag is frozen into the cached config and stays correct.
|
| To expose a new group/key later, add a row below — nothing else is group-aware.
|
*/

$keys = [
    [
        'group' => 'asr',
        'key' => 'enabled',
        'config' => 'tts.asr.enabled',
        'env' => 'TTS_ASR_ENABLED',
        'type' => 'bool',
        'label' => 'ASR transcript QA',
        'help' => 'Master switch. When off, generated audio is never transcribed or scored and no badges appear.',
    ],
    [
        'group' => 'asr',
        'key' => 'studio_action',
        'config' => 'tts.asr.studio_action',
        'env' => 'TTS_ASR_STUDIO_ACTION',
        'type' => 'enum',
        'options' => ['log', 'auto'],
        'inherits' => 'tts.asr.action',
        'label' => 'Studio remediation',
        'help' => 'What the interactive Studio does with a flagged chunk. "log" = show the badge and let an admin re-roll by hand (recommended). "auto" = re-roll/trim automatically.',
    ],
    [
        'group' => 'asr',
        'key' => 'api_action',
        'config' => 'tts.asr.api_action',
        'env' => 'TTS_ASR_API_ACTION',
        'type' => 'enum',
        'options' => ['log', 'auto'],
        'inherits' => 'tts.asr.action',
        'label' => 'API remediation',
        'help' => 'What the unattended API / full-MP3 path does with a flagged segment. "auto" self-heals (no human to read a badge); "log" ships the take as-is.',
    ],
    [
        'group' => 'asr',
        'key' => 'max_rerolls',
        'config' => 'tts.asr.max_rerolls',
        'env' => 'TTS_ASR_MAX_REROLLS',
        'type' => 'int',
        'min' => 0,
        'max' => 5,
        'label' => 'Max re-rolls',
        'help' => 'Maximum automatic re-rolls of a flagged take when the effective action is "auto". Each re-roll is a real Replicate call.',
    ],
    [
        'group' => 'asr',
        'key' => 'trail_s_max',
        'config' => 'tts.asr.trail_s_max',
        'env' => 'TTS_ASR_TRAIL_S_MAX',
        'type' => 'float',
        'min' => 0,
        'max' => 10,
        'advanced' => true,
        'label' => 'TAIL threshold — trailing audio (s)',
        'help' => 'Audio after the last recognized word longer than this is flagged TAIL.',
    ],
    [
        'group' => 'asr',
        'key' => 'gap_s_max',
        'config' => 'tts.asr.gap_s_max',
        'env' => 'TTS_ASR_GAP_S_MAX',
        'type' => 'float',
        'min' => 0,
        'max' => 10,
        'advanced' => true,
        'label' => 'PAUSE threshold — max inter-word gap (s)',
        'help' => 'Largest gap between two recognized words above this is flagged PAUSE.',
    ],
    [
        'group' => 'asr',
        'key' => 'tail_cov_min',
        'config' => 'tts.asr.tail_cov_min',
        'env' => 'TTS_ASR_TAIL_COV_MIN',
        'type' => 'float',
        'min' => 0,
        'max' => 1,
        'advanced' => true,
        'label' => 'TRUNC threshold — min coverage',
        'help' => 'The transcript must reach at least this far into the source text, else the take is flagged TRUNC. 0–1.',
    ],
    [
        'group' => 'asr',
        'key' => 'trim_guard_ms',
        'config' => 'tts.asr.trim_guard_ms',
        'env' => 'TTS_ASR_TRIM_GUARD_MS',
        'type' => 'int',
        'min' => 0,
        'max' => 2000,
        'advanced' => true,
        'label' => 'TAIL trim guard (ms)',
        'help' => 'Audio kept after the last word when auto-trimming a TAIL-only take.',
    ],
];

// Freeze the "pinned in .env" flag now (see header). env($var) !== null is true
// whenever the var is present in the environment / .env at cache-build time.
$managed = [];
foreach ($keys as $entry) {
    $entry['locked'] = env($entry['env']) !== null;
    $managed[$entry['config']] = $entry;
}

return [
    'managed' => $managed,
];
