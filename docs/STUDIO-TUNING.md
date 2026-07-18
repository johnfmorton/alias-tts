# Voice tuning

How a generation's tuning settings are chosen, what each knob does, and where
the tuning surfaces live in the admin panel. For creating and managing the
voices themselves, see [VOICES.md](VOICES.md).

## The model: three scopes, one resolution chain

Tuning lives at three scopes — a **voice** (its defaults, reused everywhere), a
**project** (this one piece), and a **chunk** (one sentence) — and every
generation path resolves them through the same chain,
`App\Services\Tts\VoiceSettingsResolver`. Highest-precedence-defined value wins:

```
1. Per-request override    (API voice_settings / Inspector knobs)
2. Per-chunk override      (projects only — tts_chunks.settings)
3. Project setting         (projects only — tts_projects.settings)
4. Voice default           (voices.settings)
5. System default          (config tts.default_voice_settings)
```

The public `/v1` API and the Studio Inspector use layers 1 · 4 · 5; Studio
projects use all five. Because the API resolves through the same chain, tuning
a voice in the panel changes what an API client (e.g. the Bespoken plugin)
hears when its request doesn't set its own values — an explicit request value
still wins. The cache hash includes the resolved settings
(`SpeechService::cacheHash()`), so a tuning change never replays stale audio.

`seed` is deliberately outside the resolver — it has its own slot (a project
column, a per-chunk pin, a voice-default fallback). See [Seed](#seed) below.

## The knobs

Each voice runs one of two engines (`voices.model` — see
[VOICES.md](VOICES.md#engines)), and each engine has its own knob dialect.
Every tuning surface shows exactly the effective voice's set; a per-chunk
voice override swaps the visible knobs (and their help text) live.

| | classic `chatterbox` | `chatterbox-turbo` |
|---|---|---|
| knobs | `exaggeration` 0.25–2 (neutral 0.5) — higher = more animated delivery · `cfg_weight` 0.2–1 (neutral 0.5) — higher = steadier, more deliberate pacing | `top_p` 0.5–1 (neutral 0.95) and `top_k` 1–2000 (neutral 1000) — lower = more focused, consistent delivery · `repetition_penalty` 1–2 (neutral 1.2) — higher = fewer repeated sounds |
| shared | `temperature` 0.5–1.5 (neutral 0.8) — sampling randomness: lower is flatter and steadier, higher is livelier but less predictable. The band is deliberately narrower than turbo's native 0.05–2 so both engines share one dial. Plus the [seed pin](#seed). |
| extras | — | 500-char per-call cap · `[laugh]`-style sound tags · built-in preset voices · clips must be >5 s |

Single sources of truth:

- **Clamps and formulas** — `App\Services\Tts\ChatterboxTuning` (classic) and
  `App\Services\Tts\ChatterboxTurboTuning` (turbo). The formulas exist ONLY in
  PHP; there is no JS mirror.
- **Which engine gets which knobs** — the `knobs` entry per model in
  `config('tts.models')`, surfaced to JS via `KNOB_ENGINES` in `app.js`
  (visibility only, no math).
- **What IS duplicated** (keep in sync when a range changes): the knob
  ranges/defaults in the `x-tuning-knob` / `x-voice.tuning-dial` Blade
  components, the `app.js` bench rows (`BENCH_KNOBS`), and the `between:`
  validation in `StudioController`, `StudioProjectController`, and the voice
  form requests.

## ElevenLabs compatibility

The public `/v1` ElevenLabs dialect speaks `voice_settings` (`stability`,
`style`, `similarity_boost`, `use_speaker_boost`). Those map onto the effective
engine at the provider; requests never error over a knob mismatch:

- **Classic chatterbox** (`ChatterboxTuning::resolveNative`):
  `cfg_weight = clamp(stability, 0.2, 1.0)` and
  `exaggeration = clamp(0.5 + style × 1.5, 0.25, 2.0)`.
- **Turbo** (`ChatterboxTurboTuning::resolveNative`): `stability` maps
  **inversely** onto temperature — `clamp(1.3 − stability, 0.5, 1.5)`, so the
  EL default 0.5 lands exactly on the 0.8 temperature default, 1 = steadiest,
  0 = most varied. `style` is accepted and ignored (turbo has no
  expressiveness knob).
- **Both engines**: an explicit native key always wins over its EL twin, and
  when the Studio writes a native knob it drops the stale EL twin so a
  settings map never carries both (`ProjectService::EL_TWIN`).
  `similarity_boost` / `use_speaker_boost` are accepted for drop-in
  compatibility but not consumed — cloning fidelity comes from the reference
  clip itself. They're hidden in the Studio for the same reason.

## Where you tune

### The voice edit page — defaults and the "Tune by ear" bench

`admin/voices/{voice}/edit` is where a voice's own sound is set (creating a
voice lands here). The **Default tuning** dials write `voices.settings` —
used whenever a request doesn't set its own. Below them, the **Tune by ear**
bench renders the same sample line at several candidate settings side by side:
add rows, **Generate all**, pick the winner, and either **save it as the
voice's defaults** or bookmark it as a named **preset**. Each ▶ spends a real
generation. The bench speaks the voice's saved engine.

**Presets** are per-user named knob combos (`tuning_presets`), tagged with the
engine they were authored on — pickers only offer them where their knobs
apply. They surface in three places: bench chips (apply = a pre-filled row,
✕ = delete), the **Delivery** pick on the New Project form (resolved
server-side into the project's settings snapshot), and the **Apply preset**
pick in each chunk's Takes & tuning panel (fills the knobs client-side; the
next **Regenerate** saves and renders them).

Tuning a SHARED voice (the built-ins) is SuperAdmin-only everywhere, including
the bench's save-to-defaults; regular users get a **Duplicate** action on the
Voices page that clones clip + tuning into a voice they own.

### The Studio Inspector — transient per-preview knobs

The Inspector (`admin/studio`, Inspector tab) auditions chunking and single
renders without a project. Its knobs sit behind a per-user **Advanced tuning**
toggle (`users.studio_advanced`, default off) and shape only the current
preview — nothing is saved. The toggle gates the UI only; the resolution chain
always runs.

### Studio projects — per-chunk Takes & tuning

Each chunk card's **Takes & tuning** panel carries the knobs for that chunk's
effective voice, the seed pin, and the take history:

- **Regenerate is the one render action.** The click submits the whole panel
  (Delivery/fine-tune knobs + seed) and a pending text edit, persists them
  (`chunk.settings`, null = inherit), then renders — what's on screen is
  always exactly what renders, and the stored tuning always matches the
  latest take. Want another take of the same settings? Leave the seed blank
  and Regenerate again.
- **Every render is kept as a take** — Generate and QA auto-fixes all land in
  the list (`tts_chunk_takes`), each recording the text it read, its settings,
  its seed, and its duration. Play any take or **Delete** the duds; older
  takes are pruned automatically (`tts.takes.keep` / `keep_preview` for
  legacy preview rows), the selected take never. The kept audio is
  byte-for-byte the take you heard — selecting never re-renders, the only
  reliable contract given the provider's non-determinism.
- **Select restores the whole snapshot.** Picking an older take repoints the
  chunk's audio AND brings back the text, knobs, and seed it was rendered
  from — the panel (and a sealed receipt) always tells the truth about the
  audio you're hearing. Selecting warns first if it would replace an unsaved
  text edit.
- Blank knobs inherit the project's resolved value — shown as each field's
  placeholder.
- On a turbo chunk, a **Sound tags** chip row inserts `[laugh]`-style tags at
  the cursor (see [VOICES.md](VOICES.md#sound-tags-chatterbox-turbo)).
- **Skip (🔊/🔇, next to the trash can)** leaves the chunk out of the final
  assembly without deleting it — the reversible alternative to the trash can.
  A skipped chunk keeps its text, tuning, and takes (dimmed in the editor, an
  amber *skipped* pill), stays playable and regenerable by hand, but is
  excluded from **Build final** and stitch previews and ignored by
  **Generate remaining** and the Build-final readiness check — an ungenerated
  skipped chunk doesn't block the build. Toggling it marks a built final
  stale (and clears its seal) so the next build reflects the change; a sealed
  receipt lists skipped chunks labeled "skipped — not in final audio" rather
  than omitting them. The Genblaze pipeline is unaffected (it re-chunks the
  raw source text).

## Seed

A seed **pins the random draw, not the result** — Chatterbox is not
bit-for-bit reproducible on Replicate's shared GPUs even with a fixed seed, so
a pin only gets you close. The UI copy deliberately never implies
reproducibility.

- Blank = random. On the `/v1` API: request seed › the voice's default seed ›
  random. In projects: chunk pin (`chunk.settings['seed']`) › the project seed
  (`tts_projects.seed`, filled at creation from the form or the voice's
  default) › random.
- The 🎲 button rolls a visible random seed into the field; on a project with
  no pinned seed, a blank-seed Regenerate is the fresh-take "re-roll". (QA
  auto-fix re-rolls always draw fresh seeds internally.)
- Every take records the seed it rendered at (`tts_chunk_takes.seed`; null =
  rolled random — Replicate doesn't report the seed it chose), shown in the
  take list as `seed 4242` / `seed random` so a good pinned render can be
  spotted and re-pinned.
