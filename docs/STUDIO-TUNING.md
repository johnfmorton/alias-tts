# Studio voice tuning — design plan

> **Status:** Design only — not yet implemented. Drafted 2026-06-19.
> No feature code has been written; this captures the plan so it survives the
> conversation it came from. The four [Open decisions](#open-decisions) should be
> answered before any phase begins.

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

Settings are resolved three different ways right now, and they disagree on whether
a voice's own `stability`/`style` defaults apply:

| Path | Resolution order | Voice's stability/style applied? |
|---|---|---|
| **Public API** `/v1` | request → config defaults | ❌ (only `seed`) |
| **Studio Inspector** | request → voice → config | ✅ |
| **Studio Projects** | `project.settings` + `project.seed` | partial |

Evidence:

- **API path** — `TextToSpeechRequest::voiceSettings()`
  (`app/Http/Requests/TextToSpeechRequest.php:54`) merges the request over
  `config('tts.default_voice_settings')` and never consults `voice->settings`.
  Only `seed` pulls from the voice, via `SpeechService::resolveSeed()`
  (`app/Services/SpeechService.php:175`).
- **Inspector** — `StudioController::settings()`
  (`app/Http/Controllers/Admin/StudioController.php:271`) explicitly overlays
  `voice->settings` on top of config defaults, then per-request knobs.
- **Projects** — `ProjectService::providerSettings()`
  (`app/Services/ProjectService.php:398`) uses the project's stored `settings`
  snapshot plus its `seed` column.

This divergence is *the* reason the full vision needs a foundation step. If
"save to voice defaults" is to mean anything, all three paths must resolve through
**one** chain — otherwise tuning a voice in Studio would not change what the plugin
hears.

## The unified resolution chain

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
- **Shared** — extract a `SettingsResolver` used by `SpeechService`,
  `ProjectService`, and `StudioController`, deleting the three divergent copies.

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

- **Phase 0 — Unify resolution** *(no visible feature)*. One `SettingsResolver`;
  voice defaults apply everywhere; precedence tests. The spine. Low risk (inert
  for existing data).
- **Phase 1 — Voice tuning + Inspector A/B bench** behind the Advanced toggle,
  with "save to voice defaults." Delivers the README promise and closes the loop
  to the plugin.
- **Phase 2 — Per-chunk overrides** (`tts_chunks.settings`, per-chunk tune UI,
  staleness/regenerate, chunk-scope preview).
- **Phase 3 (optional)** — presets/named profiles; show the resolved
  `cfg_weight`/`exaggeration` in Studio's debug view.

## Open decisions

1. **Confirm the loop is intended**: saving voice defaults *should* change the
   public API output for that voice (that's the whole point) — yes?
2. **Per-chunk `seed`**: include it? It lets you re-roll one chunk's "take"
   without touching the text — handy for fixing a single bad generation.
3. **Toggle home**: per-user UI preference (lean) vs install-wide config flag.
4. **`similarity_boost` / `use_speaker_boost`**: in Studio, hide/disable them with
   a note (they're inert with Chatterbox) rather than showing knobs that do
   nothing? Friendlier and more honest.

## Reference: how a knob reaches the model

For context when designing labels and ranges — the Replicate/Chatterbox provider
maps the ElevenLabs-style `0..1` values onto Chatterbox's own knobs
(`app/Services/Tts/ReplicateChatterboxProvider.php:63`):

- `stability` → `cfg_weight`, clamped `[0.2, 1.0]` — higher = steadier pacing.
- `style` → `exaggeration = 0.5 + style × 1.5`, clamped `[0.25, 2.0]` — higher =
  more animated delivery.
- `similarity_boost` / `use_speaker_boost` are accepted and cached but **not**
  consumed by the provider.
