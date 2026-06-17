# Changelog

All notable changes to this project are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Deployment guide (`docs/DEPLOYMENT.md`) covering Laravel Forge (step by step)
  and generic hosts, including the ffmpeg requirement and the dashboard asset build.
- Bespoken plugin integration guide (`docs/BESPOKEN.md`).
- Voice **export / import** — move a voice (manifest + reference clip) between
  installs as a portable `.zip`, from the dashboard's Voices page or via the
  `voice:export` / `voice:import` commands.

## [0.1.0] - 2026-06-17

### Added
- Initial self-hosted, **ElevenLabs-compatible** TTS service (Laravel) — a
  companion server for the Bespoken Craft plugin.
- `POST /v1/text-to-speech/{voice_id}` returning MP3 audio, with `xi-api-key`
  authentication and ElevenLabs-shaped JSON errors (`{"detail":{"message":…}}`).
  `{voice_id}` resolves by slug, then UUID.
- Pluggable inference backend via `config('tts.provider')`: **Replicate /
  Chatterbox** (default, version-pinned) and a `fake` driver for local/tests.
- API-key authentication and per-key hourly rate limiting; management commands
  `apikey:create` and `apikey:list`.
- Voice registry with zero-shot cloning from a short reference clip
  (`voice:create`, `voice:list`).
- Automatic reference-audio normalization on registration (downmix to mono,
  trim silence, loudness-normalize, true-peak limit). Opt out per voice with
  `--raw` or globally with `TTS_NORMALIZE_REFERENCE=false`.
- `seed` support for reproducible output — per request and as a per-voice
  default (`voice:create --seed=`).
- ffmpeg-based output conversion. Defaults to a fixed mono MP3 (44.1 kHz /
  128 kbps) so chunked audio concatenates cleanly; ElevenLabs `output_format`
  tokens are supported.
- Response caching keyed on voice + reference fingerprint + text + settings +
  seed; re-recording a voice automatically invalidates its cached audio.
- Feature and unit tests; Pint code style.
- MIT license.
- GitHub Actions CI running Pint and the test suite (with ffmpeg in the runner;
  builds the dashboard assets).
- **Password-protected web dashboard** (Tailwind): minimal public landing,
  session login (single seeded admin via `ADMIN_EMAIL`/`ADMIN_PASSWORD` or
  `admin:create`), API-key management, voice management ("train" = upload a
  reference clip → instant zero-shot registration + normalization), and a
  Test-voice preview. Home page surfaces copy-paste Bespoken connection details.
  Built on an `is_super_admin` flag + `EnsureUserIsAdmin` middleware as the seam
  for future multi-user roles. Shared `VoiceService` backs both the dashboard and
  the `voice:create` command.

### Notes
- Chatterbox inference on Replicate is not bit-for-bit deterministic even at a
  fixed seed; the response cache guarantees stable output for repeated identical
  requests.

[Unreleased]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/johnfmorton/bespoken-tts-service/releases/tag/v0.1.0
