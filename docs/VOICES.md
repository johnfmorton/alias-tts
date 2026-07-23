# Voices

A voice is a cloned speaker: a display name, a public **`voice_id`** (its
`slug` — the identifier API clients put in `/v1/text-to-speech/{voice_id}`),
an **engine**, an optional **reference clip** for zero-shot cloning, and
optional **tuning defaults**. Cloning is instant — a clean 15–20 second clip
is the entire setup, no training job.

> Tip: set a voice's `voice_id` to your existing ElevenLabs voice ID for a
> drop-in swap — clients keep their configured ID and simply point at this
> service instead. Or map IDs to voices in config with
> `elevenlabs_voice_aliases` — see [How a voice ID resolves](#how-a-voice-id-resolves).

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

## How a voice ID resolves

Every `/v1` endpoint that takes a voice identifier — both dialects — runs the
same four-step procedure. Only step 1 (the alias map) differs per dialect.

| | ElevenLabs dialect | OpenAI dialect |
|---|---|---|
| endpoints | `POST /v1/text-to-speech/{voice_id}` (+ `/stream`, `/jobs`), `POST /v1/projects` | `POST /v1/audio/speech` |
| incoming field | `{voice_id}` path segment / `voice_id` body field | `voice` body field |
| alias map (step 1) | `tts.elevenlabs_voice_aliases` | `tts.openai_voice_aliases` |
| alias key matching | **exact** (ElevenLabs IDs are case-sensitive) | **case-insensitive** (fixed lowercase preset names) |
| 404 shape | `{"detail":{"message":…,"status":404}}` | `{"error":{…,"code":"voice_not_found","param":"voice"}}` |

### The procedure

1. **Alias map** (optional, empty by default, `config/tts.php`). If the
   incoming value is a key in the dialect's map, the mapped value replaces it
   for the remaining steps; otherwise it passes through unchanged. One pass
   only — an alias's output is never looked up in the map again.
2. **Slug match, owner-scoped.** The value is matched against `slug` among
   the voices *visible to the API key's owner*: their own voices plus the
   shared built-ins (see [Ownership](#ownership)).
3. **UUID match, owner-scoped.** No slug hit → the value is tried as a
   voice's internal UUID, same visibility scope.
4. **404.** Nothing matched → a dialect-shaped 404 (table above). The message
   always echoes the *original* client-supplied identifier, never the alias
   target — operator config is not leaked to clients.

Example ElevenLabs map (`config/tts.php`), letting a client that still sends
real ElevenLabs voice IDs work with zero client-side changes:

```php
'elevenlabs_voice_aliases' => [
    '21m00Tcm4TlvDq8ikWAM' => 'default-female', // Rachel -> bundled female
    'pNInz6obpgDQGcFmaJgB' => 'default',        // Adam   -> bundled male
],
```

### Consequences worth knowing

- **An alias key shadows a real `voice_id`.** If the map contains `x` and a
  visible voice's slug is also `x`, the alias wins.
- **Aliasing never widens visibility.** An alias pointing at another user's
  voice slug still 404s — steps 2–3 stay scoped to the key's owner.
- **Only the `/v1` API dialects consult the maps.** Studio/admin pages and
  the internal pipeline resolve voices directly, with no aliasing.
- **Two ways to drop-in-replace ElevenLabs**, and they compose: set a voice's
  `voice_id` to your real ElevenLabs ID (per-voice, data-level), or map IDs
  in `elevenlabs_voice_aliases` (config-level; survives voice renames, and
  several client IDs can point at one voice).

## Engines

Each voice generates with one of the three catalog engines
(`config('tts.models')`), chosen in the **Engine** section of the voice form:

| | **Chatterbox** (classic, the default) | **Chatterbox Turbo** | **Qwen3 TTS** |
|---|---|---|---|
| character | the expressive original | faster and cheaper per run | ten languages, style steered in plain words |
| reference clip | optional — without one it speaks in the model's own voice | optional, but must be **longer than 5 seconds** (validated at save) | optional, at least **3 seconds** — and it can read along with a clip transcript for a closer clone |
| built-in voices | — | 20 presets (Andy, Laura, …) a clip-less voice can speak through | 9 presets (Serena, Aiden, …) |
| sound tags | stripped from payloads (they'd be read aloud) | renders `[laugh]`-style tags as actual sounds | stripped from payloads |
| per-call cap | none | 500 characters per chunk (the chunker respects it automatically) | none |
| tuning knobs | exaggeration · CFG/pace · temperature | top-p · top-k · repetition penalty · temperature | language · free-text style note (no numeric knobs, no seed pin) |

Everything downstream follows the voice's engine automatically: projects
inherit it, a per-chunk voice override switches engines mid-project, spend is
metered per engine at its own rate (`TTS_COST_PER_1K_CHARS` /
`TTS_COST_PER_1K_CHARS_TURBO` / `TTS_COST_PER_1K_CHARS_QWEN3`), and every
tuning surface shows that engine's knobs (see
[STUDIO-TUNING.md](STUDIO-TUNING.md)). Switching an existing voice's engine
re-validates its clip against the new engine's rules.

A Qwen3 TTS voice with a clip can also carry a **clip transcript** (the voice
edit page's "Clip transcript" section): qwen's clone mode reads along with the
clip for better fidelity. With the ASR sidecar enabled, a newly saved clip is
transcribed automatically when the field is empty; anything you type wins.
Keep the two in step: a transcript that claims words the clip doesn't contain
asks the model to hear something that isn't there. `voices:trim-references`
re-reads the transcript of any clip it trims for exactly that reason, but
replacing a clip by hand leaves an existing transcript untouched — update it
yourself when the new take says something different.

All engines normally run on Replicate; developers can serve the Chatterbox
pair from their own machine with `TTS_PROVIDER=local` — qwen voices then
route to Replicate per call (see [CHATTERBOX-LOCAL.md](CHATTERBOX-LOCAL.md)).

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
   (~20–25 s each) right in the browser; review the take before using it.
2. **Upload a file** — WAV/MP3/M4A/AAC/OGG/FLAC up to 20 MB. A clean, quiet
   ~15–20 s sample works best. Longer clips are trimmed to
   `TTS_REFERENCE_MAX_SECONDS` (default 25 s) once at save — at a natural
   pause with a short fade, never mid-word — because the whole stored clip
   ships with every chunk render while the engines only read its head
   (~15 s for Turbo, less for classic, ~3 s for Qwen).
3. **Built-in voice** (Turbo and Qwen3 TTS) — pick one of the engine's
   presets instead of providing a clip.

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
