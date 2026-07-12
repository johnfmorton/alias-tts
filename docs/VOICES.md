# Voices

A voice is a cloned speaker: a display name, a public **`voice_id`** (its
`slug` — the identifier API clients put in `/v1/text-to-speech/{voice_id}`),
an **engine**, an optional **reference clip** for zero-shot cloning, and
optional **tuning defaults**. Cloning is instant — a clean 15–30 second clip
is the entire setup, no training job.

> Tip: set a voice's `voice_id` to your existing ElevenLabs voice ID for a
> drop-in swap — clients keep their configured ID and simply point at this
> service instead.

## Ownership

Voices are **personal**. Every user sees the shared built-ins plus their own
voices; an API key resolves `voice_id`s inside exactly that set, so one user's
key can never generate with another's voice. SuperAdmins additionally see
every user's voices, owner-labeled, on the Voices page.

- **Shared built-ins** — two bundled voices (`default` male,
  `default-female`) ship with public-domain reference clips. They can't be
  deleted, and only SuperAdmins can retune them; anyone can **Duplicate** one
  into a personal, freely tunable copy.
- **Drag order** — the Voices page order is per-user and drives every voice
  dropdown; the first voice is what the New Project form preselects.
- `voice_id`s are unique within a user's reachable set (their own + the
  shared built-ins), so two users can each own a `john` voice.

## Engines

Each voice generates with one of the two catalog engines
(`config('tts.models')`), chosen in the **Engine** section of the voice form:

| | **Chatterbox** (classic, the default) | **Chatterbox Turbo** |
|---|---|---|
| character | the expressive original | faster and cheaper per run |
| reference clip | optional — without one it speaks in the model's own voice | optional, but must be **longer than 5 seconds** (validated at save) |
| built-in voices | — | 20 presets (Andy, Laura, …) a clip-less voice can speak through |
| sound tags | stripped from payloads (they'd be read aloud) | renders `[laugh]`-style tags as actual sounds |
| per-call cap | none | 500 characters per chunk (the chunker respects it automatically) |
| tuning knobs | exaggeration · CFG/pace · temperature | top-p · top-k · repetition penalty · temperature |

Everything downstream follows the voice's engine automatically: projects
inherit it, a per-chunk voice override switches engines mid-project, spend is
metered per engine at its own rate (`TTS_COST_PER_1K_CHARS` /
`TTS_COST_PER_1K_CHARS_TURBO`), and every tuning surface shows that engine's
knobs (see [STUDIO-TUNING.md](STUDIO-TUNING.md)). Switching an existing
voice's engine re-validates its clip against the new engine's rules.

### Sound tags (Chatterbox Turbo)

Turbo renders these tags inside the text as actual sounds:

`[clear throat] [sigh] [sush] [cough] [groan] [sniff] [gasp] [chuckle] [laugh]`

Studio chunk cards on a turbo voice show them as clickable chips that insert
at the cursor. They work best mid-sentence; a chunk *ending* in a tag is fine
too — the audio cleanup and QA know to leave the rendered sound alone
(see [AUDIO-CLEANUP.md](AUDIO-CLEANUP.md) and the tag note in
[ASR-SETUP.md](ASR-SETUP.md)). Classic voices never speak the brackets: known
tags are stripped from their payloads.

## Creating a voice

**Add a voice** (`admin/voices/create`) takes a name, an optional `voice_id`,
the engine, and the reference clip from one of three sources:

1. **Record with mic** — read one of the built-in teleprompter scripts
   (~20–30 s each) right in the browser; review the take before using it.
2. **Upload a file** — WAV/MP3/M4A/AAC/OGG/FLAC up to 20 MB. A clean, quiet
   ~15–30 s sample works best.
3. **Built-in voice** (turbo only) — pick one of the 20 presets instead of
   providing a clip.

**Clip cleanup** (on by default, `TTS_ENHANCE_ENABLED`): the clip is denoised
and de-reverbed via resemble-enhance on Replicate, and you A/B the *Cleaned
up* result against the *Original* before saving — the pick is what becomes
the reference. Degrades safely: if enhancement fails, the original is kept.
Either way the stored clip is loudness-normalized to mono WAV unless **Store
raw** is checked.

Saving lands on the voice's edit page, where the tuning dials and the
Tune-by-ear bench live — the natural next step is to hear it and dial in the
defaults ([STUDIO-TUNING.md](STUDIO-TUNING.md#where-you-tune)).

From the CLI: `voice:create {name} {audio?} {--slug=} {--raw} {--seed=}
{--stability=} {--style=}`, plus `voice:list`.

## Reference clips

- Stored on the app disk (`tts.storage_disk`) under the reference path —
  owned voices namespaced per owner (`…/u{id}/{slug}.wav`), shared built-ins
  at the flat canonical path.
- The built-ins **self-heal**: if a bundled clip goes missing from storage
  (a bucket move, a fresh disk), it is restored from the seed assets on first
  use.
- A voice that *should* have a clip but whose file is unreadable **fails
  loudly** rather than silently generating without the reference — a warm
  Chatterbox worker given no clip would otherwise reuse the previous
  request's voice.
- Renaming a `voice_id` moves the stored clip to match. Replacing the clip
  goes through the same record/upload/cleanup flow as creation.

## Everyday actions (Voices page)

- **▶ Test** — a short live render through the real backend (spends provider
  credit; never served from cache, so it always reflects the current clip and
  tuning).
- **Duplicate** — clone clip + tuning into a voice you own (`{slug}-copy`).
  Also how anyone gets a tunable copy of a shared built-in. Separately, when a
  SuperAdmin duplicates another user's *project*, any voices they can't reach
  are cloned into their account automatically so the copy stays regenerable.
- **Export / Import** — a portable `{slug}.alias-voice.zip` (manifest +
  reference clip) that round-trips a voice between instances, keeping its
  engine, preset, seed, and clip. Import is content-based.
- **Edit** — rename, change the `voice_id`, switch engines, replace the clip,
  set tuning defaults, tune by ear.
- **Delete** — removes the voice, its reference clip, and its cached
  generations. Built-ins can't be deleted. Lifetime spend counters are never
  lowered by deletions.
