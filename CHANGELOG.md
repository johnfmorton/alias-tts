# Changelog

All notable changes to this project are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.0] - 2026-06-18

### Added
- `TTS_MAX_ASYNC_TEXT_LENGTH` (default **40000**) caps text on the async jobs
  endpoint independently of the synchronous `TTS_MAX_TEXT_LENGTH` (5000). ~40k
  characters is roughly 6,000–7,000 words, or about 40–50 minutes of narration —
  comfortably more than any single article. Raise it only alongside
  `TTS_ASYNC_TIMEOUT` and provider throughput (see the README's async section).

### Fixed
- The async jobs endpoint (`POST .../jobs`) no longer rejects text longer than
  the synchronous `max_text_length` (5000). That cap is bounded by the ~300s
  synchronous request budget, but async generation runs in a background worker
  (bounded instead by `TTS_ASYNC_TIMEOUT`) and chunks server-side — so long
  articles, the whole reason the async path exists, were being rejected with
  "The text field must not be greater than 5000 characters." before any chunking
  ran. The endpoint now validates against `max_async_text_length`.

## [0.4.1] - 2026-06-18

### Fixed
- The database queue's `retry_after` now defaults to `TTS_ASYNC_TIMEOUT + 60`
  (1860s) instead of Laravel's 90s, so a long async job can no longer be released
  back to the queue mid-generation and run twice. The health page's **Queue timing**
  check now passes out of the box; set `DB_QUEUE_RETRY_AFTER` only to override.

### Changed
- Clarified that the queue worker's `--timeout` flag is **optional**:
  `GenerateSpeechJob` pins its own timeout (`TTS_ASYNC_TIMEOUT`), which Laravel uses
  in preference to the worker flag, so a long job is never killed early regardless.
  Updated the deployment guide, `.env.example`, and config comments to match.

## [0.4.0] - 2026-06-18

### Added
- **Admin health page (`/admin/health`)** — a web view over the same checks as
  `tts:doctor`, rendering PASS/WARN/FAIL badges for PHP, database, migrations,
  cache, ffmpeg, storage, disk space, provider, queue, queue timing, failed jobs,
  scheduler, expired-audio cleanup, voices, API keys, app key/debug, and APP_URL.
  Includes a **Run live checks** (`--deep`) action and **interactive live provider
  tests** — short (synchronous) and long (async chunking + queue worker +
  concatenation) — that generate real audio and play it inline; the long test
  fails fast when no worker is running.
- **Liveness checks, not just configuration.** `tts:doctor` and the health page now
  detect whether the **scheduler cron** and a **`queue:work` worker** are actually
  running — via heartbeats (a per-minute scheduled task, and a key the worker
  stamps on each loop) — instead of only confirming they're configured. `--deep`
  also validates the Replicate token live and dispatches a queue probe job.
- **Additional checks**: pending migrations, a persistent (non-`array`) cache store,
  disk free space, `failed_jobs` count, `DB_QUEUE_RETRY_AFTER` vs `TTS_ASYNC_TIMEOUT`,
  expired-audio backlog, at least one active voice and API key, and production
  `APP_URL` sanity.
- **Version footer** in the admin panel showing the app name and version
  (`config('app.version')`, read from `composer.json`).

### Changed
- Refactored `tts:doctor`'s checks into a shared `HealthReport` service, so the CLI,
  the web page, and future surfaces (e.g. a status endpoint) render the same results
  from one source instead of drifting.

## [0.3.0] - 2026-06-18

### Added
- **`speech:cleanup` command + daily schedule** — deletes expired generated audio
  (database rows **and** the files on the configured disk, including **S3**) once
  past its `TTS_TTL_HOURS` TTL. Scheduled to run daily, so production only needs a
  cron calling `php artisan schedule:run`. Supports `--dry-run` and `--before`.
  (Previously, expired audio and rows accumulated forever.)
- **`tts:doctor` command** — verifies the install (PHP/extensions, database,
  ffmpeg, the storage disk, the provider + Replicate token, the queue, and the
  cleanup schedule) with PASS/WARN/FAIL output; exits non-zero on any failure so
  it's usable in CI / a deploy script. `--deep` validates the Replicate token live.

### Changed
- Rewrote the README to be value-first for Bespoken plugin users, made the
  **Replicate account + credit** requirement explicit, documented every artisan
  command, and corrected the stale roadmap (async shipped in 0.2.0).
- Documented **S3 storage** and the **scheduler / queue-worker** requirements in
  the deployment guide, and replaced the two `NEXT_STEPS_*.md` working notes with a
  concise `ROADMAP.md`.

## [0.2.0] - 2026-06-18

### Added
- **Async generation for long text.** A Bespoken extension that lifts the ~300s
  synchronous ceiling: `POST /v1/text-to-speech/{voice_id}/jobs` returns **202**
  with a job `id` + `status_url`/`audio_url` and runs generation in a queued
  `GenerateSpeechJob`; `GET /v1/text-to-speech/jobs/{id}` polls status and
  `GET /v1/text-to-speech/jobs/{id}/audio` streams the MP3 once complete. Poll
  and audio reads are authenticated but **not** rate-limited (only the generating
  POST is) and are scoped to the calling API key. Identical requests already
  cached return **200** immediately; an identical request already in flight joins
  the running job instead of starting a duplicate. Needs a running queue worker
  (`queue:work`, timeout ≥ `TTS_ASYNC_TIMEOUT`, default 1800s); with
  `QUEUE_CONNECTION=sync` it degrades to synchronous. The synchronous
  `POST /v1/text-to-speech/{voice_id}` is unchanged. (Phase 4 of
  `NEXT_STEPS_17JUN2026.md`.)
- **Survive Replicate's burst rate limit.** Replicate throttles prediction
  creation (e.g. "6/min, burst 1") with an HTTP **429** + `retry_after` hint;
  previously a single throttled chunk failed the whole article with a 502. Every
  Replicate request (create, poll, audio download) now retries on 429 — honoring
  `retry_after` from the body or `Retry-After` header, falling back to
  exponential backoff, and bounded by `request_timeout` so it can't outlive the
  synchronous budget. Tunable via `REPLICATE_MAX_RETRIES`,
  `REPLICATE_RETRY_BASE_MS`, and `REPLICATE_RETRY_MAX_MS`. An optional
  `REPLICATE_MIN_REQUEST_GAP_MS` spaces calls out proactively to avoid 429s
  up front. (Phase 1 of `NEXT_STEPS_17JUN2026.md`.)
- `NEXT_STEPS_17JUN2026.md` — the implementation plan for the service-owned
  chunking, 429 retry, and async generation work delivered in this release.
- Deployment guide (`docs/DEPLOYMENT.md`) covering Laravel Forge (step by step)
  and generic hosts, including the ffmpeg requirement and the dashboard asset build.
- Bespoken plugin integration guide (`docs/BESPOKEN.md`).
- Voice **export / import** — move a voice (manifest + reference clip) between
  installs as a portable `.zip`, from the dashboard's Voices page or via the
  `voice:export` / `voice:import` commands.
- Automatic **text chunking** for long input — text is split into short,
  sentence-aware chunks (Chatterbox is short-form), each generated separately and
  the audio concatenated into one file. Tunable via `TTS_CHUNK_CHARS` (default 280).
- **Edit voices** from the dashboard — rename the `voice_id` (slug), change the
  default seed, or replace the reference clip (renaming moves the stored clip to
  match).
- **Regenerate (rotate) an API key** from the dashboard — issues a new secret
  while keeping the key's name, rate limit, and usage history; the old value
  stops working immediately.
- **Crossfade between generated chunks** (~25 ms, `TTS_CHUNK_CROSSFADE_MS`; set 0
  for a hard join) for click-free seams when long text is split and concatenated.

### Fixed
- Logged-in users now land on the dashboard from the landing page and `/login`
  instead of bouncing back to the homepage (the "Open dashboard" link points
  authenticated users to `/admin`, and `/login` redirects them there too).

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

[Unreleased]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.5.0...HEAD
[0.5.0]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.4.1...v0.5.0
[0.4.1]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/johnfmorton/bespoken-tts-service/releases/tag/v0.1.0
