# Roadmap

The service is fully functional for its purpose — a self-hosted,
ElevenLabs-compatible voice backend for the Bespoken Craft plugin. The items below
are **not yet built**; none are required for current use. They're roughly ordered
by likely usefulness. See [CHANGELOG.md](CHANGELOG.md) for what's already shipped.

## API

- **`/v1/voices` management API** — `GET /v1/voices`, `GET /v1/voices/{id}`,
  `POST /v1/voices/add` (multipart), `DELETE /v1/voices/{id}`. Not needed by the
  Bespoken plugin (voices are managed via the dashboard / console), but it would
  round out ElevenLabs compatibility.
- **Real streaming** — `POST /v1/text-to-speech/{voice_id}/stream` is currently an
  alias that returns the full audio once it's ready, not a streamed response.

## Providers

- **Additional inference backends.** The backend is a pluggable driver, but
  **Replicate / Chatterbox is the only one implemented** (plus the `fake` driver
  for local/tests). Candidates: Modal or Fal (lower latency / self-managed
  container), a local-GPU driver, and an ElevenLabs pass-through for A/B or
  fallback. **Open question:** whether supporting multiple providers is worth the
  added surface area, or whether to stay Replicate-only and keep it simple.

## Operations

- **`speech:stats` / `speech:list`** console commands for visibility into usage
  and stored audio.
- **Orphan-file sweep** for `speech:cleanup` — optionally remove files on the
  storage disk that no longer have a matching database row (today cleanup is keyed
  off expired rows).
- **Cost guardrails** — optional per-key monthly character caps on top of the
  existing hourly `rate_limit` and `tts.max_text_length`.

## Packaging

- **Docker image** for non-Forge / non-DDEV users (app + ffmpeg), so a fresh
  install is a single `docker run`.
