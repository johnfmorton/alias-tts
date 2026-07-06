# Bundled default voice reference clips

These two clips are shipped with the app so a fresh install has a consistent,
distinct built-in voice for each gender — instead of relying on Chatterbox's
unconditioned native voice, which is not anchored and drifts between runs.

They are copied into the configured storage disk (`tts.storage_disk`, path
`tts.reference_path`) by `database/migrations/2026_06_30_000002_seed_bundled_default_voices.php`
(and rolled out to existing installs by
`2026_07_06_000001_replace_bundled_default_voice_clips.php`), attached to the
built-in `default` (male) and `default-female` voices. If a stored clip goes
missing (for example after a `TTS_STORAGE_ROOT` change), `VoiceReference`
restores it from these assets automatically.

| File                 | Voice slug       | Source (LibriVox recording)                                  |
|----------------------|------------------|--------------------------------------------------------------|
| `default-male.wav`   | `default`        | *The Three Musketeers* (Alexandre Dumas), chapter 22         |
| `default-female.wav` | `default-female` | *The Count of Monte Cristo* (Alexandre Dumas), chapter 9     |

## Source & license

Derived from **LibriVox** audiobook recordings (<https://librivox.org/>).
LibriVox volunteers dedicate all of their recordings to the **public domain**
(<https://librivox.org/pages/public-domain/>), so these clips carry no license
obligations.

Each clip is a short excerpt (~15 s) of clean single-speaker speech, processed
with the app's own reference normalization (`AudioConverter::normalizeReference`):
downmixed to mono, silence-trimmed, loudness-normalized to −20 LUFS (true peak
−1.5 dBTP), and resampled to 44.1 kHz 16-bit PCM. See the top-level `CREDITS.md`
for the project-wide attribution notice.
