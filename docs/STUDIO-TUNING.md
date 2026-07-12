# Studio voice tuning — design plan

> **Status:** Drafted 2026-06-19. The four decisions below are resolved.
> **Phases 0–3 are all implemented and test-verified 2026-06-19** (Phases 0–2
> also had a live UI walkthrough). The plan is fully built.
>
> **Update (post-v0.21.0):** The tuning surfaces were reorganized around the
> voice. The **A/B bench and named presets moved from the Studio Inspector to
> the voice EDIT page** ("Tune by ear" — `admin/voices/{voice}/edit`), which is
> also where creation now lands; the Add-a-voice form carries no tuning fields
> at all, and the voice forms speak the native knobs. The Inspector keeps only
> the transient per-preview knobs behind the Advanced toggle. Presets became
> **per-user** (`tuning_presets.user_id`) and gained two apply points: a
> "Delivery" pick on the New Project form (resolved server-side into the
> project's settings snapshot) and an "Apply preset" pick in each chunk's
> Takes & tuning panel (fills the knobs client-side). Tuning a SHARED voice
> (the built-ins) is SuperAdmin-only everywhere, including the bench's
> "save to voice defaults"; regular users get a **Duplicate** action on the
> Voices page that clones clip + tuning into a voice they own.
>
> **Update (v0.15.0):** The Studio surfaces (per-chunk panel, single-shot
> inspector, A/B bench, named presets) now speak Chatterbox's **native** knobs —
> **Exaggeration** (0.25–2.0) and **CFG/Pace** (0.2–1.0) — as slider + number +
> reset, not the abstract 0–1 Stability/Style fields below. The Phase-3a derived
> readout is gone with them — the knobs *are* `cfg_weight`/`exaggeration` now, so
> there is nothing to derive. The mapping is unchanged and lives in exactly one
> place, `App\Services\Tts\ChatterboxTuning`: `cfg_weight = clamp(stability,
> 0.2, 1.0)`, `exaggeration = clamp(0.5 + style·1.5, 0.25, 2.0)`. (No JS mirror of
> the formula exists anymore; what the JS/Blade knob widgets duplicate is the
> knob **ranges/defaults** — the app.js bench rows, the `x-tuning-knob`
> component, and `saveVoiceDefaults` validation — keep those in sync.) The resolver and
> provider accept BOTH key forms — **native wins**, EL is the fallback — so the
> public `/v1` API stays ElevenLabs-compatible (it still sends stability/style;
> the provider derives native). When the Studio writes a native knob it drops the
> stale EL twin so a settings map never carries both. Separately, every render is
> now persisted as a selectable **take** (see CHANGELOG / `tts.takes`).

## Why

The README invites you to "tune the voice with stability and style." The knobs
already exist in the code, but there's no *tuning experience*: you can't hear the
same line at two settings side by side, you can't dial one sentence differently
from the rest, the values are bare `0.0–1.0` fields with no feedback on what they
do, and anything you discover in Studio can't be saved back as a voice's defaults
so the plugin/API would actually use it.

The goal is a **three-scope tuning model with one resolution chain** — tune a
**voice** (its defaults, reused everywhere), a **project** (this one piece), or a
**chunk** (one sentence) — exposed through a friendly, opt-in Studio workbench.

## The crux: three divergent resolution paths today

*(Historical design context — this describes the pre-Phase-0 code, kept for the
rationale. All three paths now resolve through `VoiceSettingsResolver`; see the
updates above and the current citations in the bullets below.)*

Settings were resolved three different ways, and they disagreed on whether
a voice's own `stability`/`style` defaults apply:

| Path | Resolution order | Voice's stability/style applied? |
|---|---|---|
| **Public API** `/v1` | request → config defaults | ❌ (only `seed`) |
| **Studio Inspector** | request → voice → config | ✅ |
| **Studio Projects** | `project.settings` + `project.seed` | partial |

Evidence (pre-Phase-0 behavior; citations updated to where the code lives now):

- **API path** — `TextToSpeechRequest::voiceSettings()` merged the request over
  `config('tts.default_voice_settings')` and never consulted `voice->settings`.
  Only `seed` pulled from the voice, via `SpeechService::resolveSeed()` (now
  `app/Services/SpeechService.php:336`). Since Phase 0 the method is
  `voiceSettingOverrides()` (`app/Http/Requests/TextToSpeechRequest.php:60`),
  returns only the keys the client explicitly sent, and delegates layering to
  `VoiceSettingsResolver`.
- **Inspector** — `StudioController::settings()` explicitly overlaid
  `voice->settings` on top of config defaults, then per-request knobs. Now
  (`app/Http/Controllers/Admin/StudioController.php:389`) it delegates to the
  shared `VoiceSettingsResolver`.
- **Projects** — `ProjectService::providerSettings()` (now
  `app/Services/ProjectService.php:977`) uses the project's stored `settings`
  snapshot plus its `seed` column.

This divergence is *the* reason the full vision needs a foundation step. If
"save to voice defaults" is to mean anything, all three paths must resolve through
**one** chain — otherwise tuning a voice in Studio would not change what the plugin
hears.

## The unified resolution chain

*(Design as written 2026-06-19 — implemented in Phase 0 as
`App\Services\Tts\VoiceSettingsResolver`.)*

One resolver, highest-precedence-defined value wins:

```
1. Per-request override   (API voice_settings / Inspector knobs)
2. Per-chunk override      (projects only)      ← NEW: tts_chunks.settings
3. Project setting          (projects only)      ← exists
4. Voice default            (voice.settings)     ← exists for seed; EXTEND to stability/style
5. System default           (config)             ← exists
```

- Sync API & Inspector use layers **1 · 4 · 5** (and now *agree*).
- Projects use **1 · 2 · 3 · 4 · 5**.

The only behavior change is layer 4 contributing `stability`/`style` to the API.
**It is inert for every existing install** — no voice carries stability/style
defaults today (there's no UI to set them), so nothing changes until someone uses
the new tuning feature. The cache hash already includes the resolved settings
(`SpeechService::cacheHash()`), so re-resolution invalidates the cache
automatically.

## Data-model deltas

- **Voice** — no migration; `settings` JSON already exists. Add a UI (and
  optionally `voice:create --stability= --style=`) to set defaults. This is the
  target of "save to voice defaults."
- **Project** — no migration; `settings` + `seed` already exist
  (`tts_projects`). Add the ability to edit settings *after* creation (today
  they're set once, at create time).
- **Chunk** — one migration: nullable `settings` JSON on `tts_chunks`. `null`
  means inherit. Editing it marks the chunk `stale` and the final audio outdated
  (reuse `ProjectService::markFinalOutdated()`).
- **Shared** — `App\Services\Tts\VoiceSettingsResolver` is the one chain, called
  by the public API, the project API, and both Studio controllers (done in
  Phase 0), replacing the three divergent copies.

## UX: progressive disclosure, not a feature flag

Stability/style are genuinely confusing numbers, so Studio should look simple by
default and *reveal* the tuning surface on demand:

- An **"Advanced tuning" toggle** (default **off**) gates the visible controls:
  the Inspector's knobs, the A/B bench, and the per-chunk "tune" affordances.
  Off → just text, voice, chunks. On → the full workbench.
- The toggle gates **only the UI**, never the resolution chain. The plumbing
  (Phase 0) is always correct and invisible; the toggle only decides whether knobs
  are *shown*. "Friendly by default, powerful when asked" — without two code
  paths.

### The Inspector tuning bench (Phase 1)

Add 2–N setting "candidates"; generate the same short text under each; play them
side by side. Then either **save the winner to the voice's defaults** or **carry
the settings into a new project**.

```
INSPECTOR — tune by ear            [ Advanced tuning ●on ]

Text: "Welcome back to the show."
Voice: [ John ▼ ]

  A  stability 0.5  style 0.0   ▶ ───  (current default)
  B  stability 0.8  style 0.3   ▶ ───
  C  stability 0.3  style 0.7   ▶ ───

        [ + add setting ]   [ Generate all ]

  ◉ B sounds best  →  [ Save as John's defaults ]
```

### Per-chunk overrides (Phase 2)

Each chunk gets an optional override; `null` inherits from project → voice →
config. Changing it marks the chunk stale.

```
PROJECT: "Episode 12 intro"

  #1  "Welcome back to the show."
      (project default)          [✎ tune] ▶
  #2  "And THIS week — everything changes!"
      stability 0.4  style 0.8   [✎ tune] ▶  ● stale
  #3  "Let's get into it."
      (project default)          [✎ tune] ▶
```

## Suggested build order

- **Phase 0 — Unify resolution** *(no visible feature)* — **DONE, test-verified
  2026-06-19.** Added `App\Services\Tts\VoiceSettingsResolver` (config → voice →
  overrides) and routed the public `/v1` API, the project API, the Studio
  inspector, and Studio projects all through it, deleting three divergent copies.
  Voice `stability`/`style` defaults now reach the API (previously only `seed`
  did). Covered by `tests/Unit/VoiceSettingsResolverTest.php` and new
  `TextToSpeechTest` cases.
- **Phase 1 — Voice tuning + Inspector A/B bench** — **DONE, test-verified
  2026-06-19** (live UI walkthrough pending). stability/style are first-class
  voice defaults (edit/create forms + `voice:create --stability/--style`); the
  inspector gained a per-user **Advanced tuning** toggle (`users.studio_advanced`,
  default off) that reveals the knobs and an **A/B bench** — generate a line at
  several settings, pick the best, and **save to voice defaults**
  (`StudioController::saveVoiceDefaults` → `VoiceService::saveTuning`). Tests:
  `VoiceTuningTest`, new `StudioTest` cases.
- **Phase 2 — Per-chunk overrides** — **DONE, test-verified 2026-06-19** (live UI
  walkthrough pending). Added `tts_chunks.settings` (nullable; null = inherit);
  `ProjectService::providerSettings` overlays the chunk override on the project's
  resolved settings, and `updateChunkTuning` marks a generated chunk stale + the
  final outdated. Each chunk in the project editor gained a "Tune this chunk" panel
  (stability/style + Save tuning) and a **Re-roll** action
  (`generateChunk(reroll: true)` → fresh random seed, no persisted per-chunk seed).
  Endpoints `chunks.tuning` / `chunks.reroll`; tests in `StudioProjectTest`. (A
  dedicated per-chunk A/B preview was not built — tune → regenerate → listen, or
  re-roll, covers it; could revisit in Phase 3.)
- **Phase 3 (optional)** — **DONE, test-verified 2026-06-19.** (3a) live
  `cfg_weight`/`exaggeration` readout next to the knobs and each bench row
  *(retired in v0.15.0 — the knobs went native, so there's nothing to derive)*;
  (3b) named tuning presets (`tuning_presets` table) — apply one to add a
  pre-filled bench row, save the picked row as a preset, delete *(global at
  first; **per-user** since migration `2026_07_02_000003`)*; (3c) per-chunk
  **A/B preview** — a "Preview" button auditions the typed stability/style
  transiently (no persist) so you can compare against the chunk's current audio
  before committing. Tests in `StudioTest` / `StudioProjectTest`. (The "preset
  apply is bench-only" caveat that used to sit here is superseded — see the
  post-v0.21.0 update at the top: presets now also apply from the New Project
  form's "Delivery" pick and each chunk's Takes & tuning panel.)

## Decisions — resolved 2026-06-19

1. **Voice defaults propagate to the public `/v1` API — YES.** Tuning a voice
   changes what the plugin hears when a request omits `voice_settings`; an explicit
   request value still overrides. This mirrors how `seed` already works. Implies
   the Phase 0 refactor of `TextToSpeechRequest::voiceSettings()` so it reports
   only the keys the client *explicitly* sent (today it coalesces all four from
   request-or-config, which hides "omitted" from "sent the default").
2. **No persisted per-chunk `seed`.** Projects rebuild from *stored* WAV bytes, so
   a saved per-chunk seed buys little. Instead add a **"re-roll" action** —
   regenerate one chunk with a fresh random seed to escape a bad take. Per-chunk
   *overrides* stay `stability`/`style` only.
3. **Toggle = per-user preference, default off.** Friendly default for casual use,
   sticky for power users. (An ephemeral in-page disclosure is an acceptable
   lighter v1 if we want to ship Phase 1 without a preference store.)
4. **Hide `similarity_boost` / `use_speaker_boost` in Studio.** They're inert with
   Chatterbox, and silent knobs are confusing in a tune-by-ear tool. Keep them in
   the API for ElevenLabs drop-in compatibility; optionally show a one-line note.

## Reference: how a knob reaches the model

For context when designing labels and ranges — the mapping from the
ElevenLabs-style `0..1` values onto Chatterbox's own knobs lives in
`App\Services\Tts\ChatterboxTuning`; the provider calls
`ChatterboxTuning::resolveNative()`
(`app/Services/Tts/ReplicateChatterboxProvider.php:69`), and explicit native
keys win over the EL-derived values:

- `stability` → `cfg_weight`, clamped `[0.2, 1.0]` — higher = steadier pacing.
- `style` → `exaggeration = 0.5 + style × 1.5`, clamped `[0.25, 2.0]` — higher =
  more animated delivery.
- an explicit `cfg_weight` / `exaggeration` in the settings map is used as-is
  (clamped), and the EL twin is ignored.
- `similarity_boost` / `use_speaker_boost` are accepted and cached but **not**
  consumed by the provider.

## Addendum (2026-07-09): temperature knob + seed pin

Two native controls that the original plan left off are now first-class in the
Studio, since the Studio speaks native Chatterbox (the ElevenLabs `/v1` API — the
reason temperature was omitted — has no equivalent, so it's unaffected).

**Temperature** — Chatterbox's sampling randomness, a third native knob alongside
exaggeration/cfg_weight. Native-only (no EL twin). Practical UI band **0.5–1.5**,
default **0.8**; `ChatterboxTuning::clampTemperature()` and `TEMPERATURE_*`
constants are the single source of truth for the range. It flows through the whole
tuning chain: `default_voice_settings`, `VoiceSettingsResolver` (KEYS + cast), the
provider (`synthesize()` sends it verbatim, defaulting to 0.8 = a no-op for
EL-only callers), voice defaults (`VoiceService::update`/`saveTuning`), the A/B
bench, named presets (`tuning_presets.temperature` — migration
`2026_07_09_000001`), project creation, and the per-chunk override
(`chunk.settings['temperature']`).

**Seed** — re-added to the UI after being pulled (decision #2 above still holds in
spirit: **a pinned seed is not bit-reproducible on Replicate's shared GPUs**, it
only biases the draw). It's back as an honest tool, not a reproduce button:
- The per-chunk tuning row has a **Seed pin** (blank = random). It persists to
  `chunk.settings['seed']`; precedence in `ProjectService::providerSettings()` is
  chunk-pinned › project seed › random, and a **Re-roll** still drops every pin
  for a fresh random take.
- Every take now records the seed it rendered at (`recordTake(..., seed:)` →
  `tts_chunk_takes.seed`) — a positive integer when pinned, `null` when it rolled
  random (Replicate doesn't report the seed it chose). The take list shows
  `seed 4242` / `seed random` so a good pinned render can be spotted and re-pinned.

UI copy deliberately avoids implying reproducibility — see the memory note
`feedback-no-seed-in-ui`.

## Addendum (2026-07-12): Chatterbox Turbo — a second engine, per-voice

The model catalog (`config/tts.php` → `tts.models`) now carries TWO engines,
chosen **per voice** (`voices.model`, `null` = classic chatterbox):

| | classic `chatterbox` | `chatterbox-turbo` |
|---|---|---|
| knobs | `exaggeration` 0.25–2 · `cfg_weight` 0.2–1 | `top_p` 0.5–1 · `top_k` 1–2000 · `repetition_penalty` 1–2 |
| shared | `temperature` 0.5–1.5 (band deliberately narrower than turbo's native 0.05–2, for one consistent dial) + the seed pin |
| EL mapping | `stability`→`cfg_weight`, `style`→`exaggeration` (`ChatterboxTuning`) | `stability`→`temperature` **inversely**: `clamp(1.3 − stability)` — 0.5→0.8 (defaults align), 1→0.5, 0→1.3; `style`/`similarity_boost` accepted-and-ignored (`ChatterboxTurboTuning`) |
| extras | — | 500-char per-call cap · `[laugh]`-style sound tags · 20 built-in preset voices (clip-less voices) · reference clips must be **>5 s** |

How it flows: the voice's engine is stamped into the resolved settings as the
reserved `model` key (`ModelCatalog::stamp()`, applied AFTER
`VoiceSettingsResolver` at every chokepoint — SpeechService, ProjectService,
StudioController, the internal pipeline). The stamp is OMITTED for classic
voices so pre-existing cache hashes and settings JSON stay byte-identical.
Every knob surface (voice edit dials, the bench, the Studio inspector, per-chunk
rows) shows exactly the effective voice's knob set — a per-chunk voice override
swaps the row live. Presets belong to the engine they were authored on
(`tuning_presets.model`) and only appear where they apply. Spend is metered
per engine (`tts_spend_counters`) at per-model rates
(`TTS_COST_PER_1K_CHARS` / `TTS_COST_PER_1K_CHARS_TURBO`).
