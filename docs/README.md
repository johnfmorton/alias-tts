# Alias TTS documentation

Start with the group that matches what you're doing.

## Running the service

- **[DOCKER.md](DOCKER.md)** — the simplest install: one Docker image carrying
  the whole service (app, queue worker, scheduler, ASR sidecar, Genblaze
  runner) with all state on a single `/data` volume.
- **[DEPLOYMENT.md](DEPLOYMENT.md)** — full install guide: Laravel Forge and
  generic hosts, storage (local vs S3/B2), scheduler + queue worker, `tts:doctor`.
- **[GENBLAZE-SETUP.md](GENBLAZE-SETUP.md)** — the Genblaze runner sidecar and
  Backblaze B2 provenance store. Part of a standard install — it drives the
  whole-render generate → QA → re-roll → stitch pipeline.
- **[ASR-SETUP.md](ASR-SETUP.md)** — the Whisper ASR sidecar: per-chunk quality
  scoring and automatic re-roll/trim of flawed takes.
- **[CHATTERBOX-LOCAL.md](CHATTERBOX-LOCAL.md)** — dev-only: run the Chatterbox
  engines on your own machine (`TTS_PROVIDER=local`) instead of Replicate — no
  credits, works offline; macOS/Linux/Windows setup.
- **[SSO-SETUP.md](SSO-SETUP.md)** — optional Google / GitHub sign-in for the
  dashboard (invite-only account linking).

## Using the app

- **[VOICES.md](VOICES.md)** — voices: creating one (record / upload /
  built-in), the two engines (Chatterbox and Chatterbox Turbo, chosen per
  voice), sound tags, reference clips, ownership, duplicate/export/import.
- **[STUDIO-TUNING.md](STUDIO-TUNING.md)** — voice tuning: the one
  settings-resolution chain, each engine's knobs, the ElevenLabs mapping, and
  the tuning surfaces (voice dials, the Tune-by-ear bench, presets, per-chunk
  Takes & tuning, the seed pin).
- **[SPOKEN-QUOTES.md](SPOKEN-QUOTES.md)** — the opt-in "spoken quote marks"
  setting: voicing paired double quotes as "open quote … close quote" (news
  narration style), the pairing rules, and multi-paragraph quote handling.

## Integrating

- **[BESPOKEN.md](BESPOKEN.md)** — connecting the Bespoken Craft plugin: the
  ElevenLabs-compatible API plus the Alias extensions (async jobs, projects,
  pronunciations).
- **[OPENAI-COMPAT.md](OPENAI-COMPAT.md)** — the OpenAI-compatible endpoint
  (`POST /v1/audio/speech`): the same engines spoken in OpenAI's dialect, so
  any OpenAI-TTS client works by swapping the base URL.
- **[ALIAS-PRONUNCIATION-PREPROCESSOR.md](ALIAS-PRONUNCIATION-PREPROCESSOR.md)** —
  the LLM pronunciation pre-processor: detection, review screen, per-user
  dictionary, and the `/v1/pronunciations` read API.

## Internals & design notes

- **[AUDIO-CLEANUP.md](AUDIO-CLEANUP.md)** — how chunk seams and Chatterbox tail
  artifacts are detected and trimmed (the DSP pipeline in `AudioConverter`),
  and why a chunk ending in a rendered sound tag is spared the tail cut.
- **[GENBLAZE-BACKEND-SWAP.md](GENBLAZE-BACKEND-SWAP.md)** — *future feature,
  not built yet:* swap the whole TTS engine per render at the Genblaze provider
  layer — cheap to add because published adapters share one API; parked on
  priority, not merit. (The per-voice Chatterbox/Turbo model catalog already
  covers Replicate-level model choice; this note is about entirely different
  engines, e.g. LMNT.)
- **[GENERATION-CONCURRENCY.md](GENERATION-CONCURRENCY.md)** — *future design
  note, not built yet:* chunk generation is serial within each operation; this
  sketches a safe, bounded way to keep a few chunks in flight (per-chunk queued
  jobs, capped by worker count) to cut wall-clock on multi-chunk renders —
  grounded in primary-source provider facts, with a default-off flag and a
  measured rollout.
