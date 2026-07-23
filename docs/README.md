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
  credits, works offline; macOS/Linux/Windows setup. Qwen3 TTS voices still
  route to Replicate under `local` (the sidecar can't run qwen).
- **[SSO-SETUP.md](SSO-SETUP.md)** — optional Google / GitHub sign-in for the
  dashboard (invite-only account linking).

## Using the app

- **[VOICES.md](VOICES.md)** — voices: creating one (record / upload /
  built-in), the three engines (Chatterbox, Chatterbox Turbo, and Qwen3 TTS,
  chosen per voice), sound tags, reference clips, ownership,
  duplicate/export/import.
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
  priority, not merit. (The per-voice model catalog — Chatterbox, Turbo,
  Qwen3 TTS — already covers Replicate-level model choice; this note is about
  swapping providers entirely, e.g. LMNT.)
- **[GENERATION-CONCURRENCY.md](GENERATION-CONCURRENCY.md)** — bounded
  concurrency for multi-chunk renders: a few chunks in flight (claim-based
  per-chunk queued jobs, capped by worker count) to cut wall-clock. Implemented
  behind a default-off flag; the note holds the design rationale,
  primary-source provider facts, and the measured rollout plan.
- **[GENERATION-CONCURRENCY-OPS.md](GENERATION-CONCURRENCY-OPS.md)** — the
  operations side of the above: what deploying changes (nothing, until an env
  var is set), how to enable and tune `K` on Forge/Docker/DDEV, why flag flips
  are safe mid-flight, and the one-variable kill switch.
- **[DEDICATED-GENERATION-QUEUE.md](DEDICATED-GENERATION-QUEUE.md)** — Forge
  walkthrough for giving chunk fan-out its own `generation` queue and worker
  pool: exact worker settings, routing table, verification, the
  health-probe caveat, and rollback in both directions.
