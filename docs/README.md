# Bespoken TTS documentation

Start with the group that matches what you're doing.

## Running the service

- **[DEPLOYMENT.md](DEPLOYMENT.md)** — full install guide: Laravel Forge and
  generic hosts, storage (local vs S3/B2), scheduler + queue worker, `tts:doctor`.
- **[GENBLAZE-SETUP.md](GENBLAZE-SETUP.md)** — the Genblaze runner sidecar and
  Backblaze B2 provenance store. Part of a standard install — it drives the
  whole-render generate → QA → re-roll → stitch pipeline.
- **[ASR-SETUP.md](ASR-SETUP.md)** — the Whisper ASR sidecar: per-chunk quality
  scoring and automatic re-roll/trim of flawed takes.
- **[SSO-SETUP.md](SSO-SETUP.md)** — optional Google / GitHub sign-in for the
  dashboard (invite-only account linking).

## Integrating

- **[BESPOKEN.md](BESPOKEN.md)** — connecting the Bespoken Craft plugin: the
  ElevenLabs-compatible API plus the Bespoken extensions (async jobs, projects,
  pronunciations).
- **[OPENAI-COMPAT.md](OPENAI-COMPAT.md)** — the OpenAI-compatible endpoint
  (`POST /v1/audio/speech`): the same engine spoken in OpenAI's dialect, so any
  OpenAI-TTS client works by swapping the base URL.
- **[BESPOKEN-PRONUNCIATION-PREPROCESSOR.md](BESPOKEN-PRONUNCIATION-PREPROCESSOR.md)** —
  the LLM pronunciation pre-processor: detection, review screen, per-user
  dictionary, and the `/v1/pronunciations` read API.

## Internals & design notes

- **[AUDIO-CLEANUP.md](AUDIO-CLEANUP.md)** — how chunk seams and Chatterbox tail
  artifacts are detected and trimmed (the DSP pipeline in `AudioConverter`).
- **[STUDIO-TUNING.md](STUDIO-TUNING.md)** — the voice-tuning design and its
  implementation history; the current settings-resolution chain lives in
  `VoiceSettingsResolver` / `ChatterboxTuning`.
- **[GENBLAZE-BACKEND-SWAP.md](GENBLAZE-BACKEND-SWAP.md)** — *future feature,
  not built yet:* swap the whole TTS engine per render at the Genblaze provider
  layer — cheap to add because published adapters share one API; parked on
  priority, not merit.
