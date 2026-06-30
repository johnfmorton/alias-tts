# Bundled default voice reference clips

These two clips are shipped with the app so a fresh install has a consistent,
distinct built-in voice for each gender — instead of relying on Chatterbox's
unconditioned native voice, which is not anchored and drifts between runs.

They are copied into the configured storage disk (`tts.storage_disk`, path
`tts.reference_path`) by `database/migrations/2026_06_30_000002_seed_bundled_default_voices.php`
and attached to the built-in `default` (male) and `default-female` voices.

| File                 | Voice slug       | Speaker | Accent / region          |
|----------------------|------------------|---------|--------------------------|
| `default-male.wav`   | `default`        | p311    | American — Iowa (General American) |
| `default-female.wav` | `default-female` | p333    | American — Indiana (Midwest)       |

## Source & license

Derived from the **CSTR VCTK Corpus (version 0.92)** — *English Multi-speaker
Corpus for CSTR Voice Cloning Toolkit*, by Junichi Yamagishi, Christophe Veaux,
and Kirsten MacDonald, The Centre for Speech Technology Research (CSTR),
University of Edinburgh.

Licensed under **Creative Commons Attribution 4.0 International (CC BY 4.0)**.

- Dataset: https://datashare.ed.ac.uk/handle/10283/3443
- License: https://creativecommons.org/licenses/by/4.0/

Each clip is two of the standard VCTK elicitation sentences for the chosen
speaker (~10 s), resampled to mono 24 kHz, loudness-normalized, and cleaned with
background-noise reduction / voice isolation. See the top-level `CREDITS.md` for
the project-wide attribution notice.
