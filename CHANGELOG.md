# Changelog

All notable changes to this project are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.12.0] - 2026-06-22

### Added
- **Energy-aware ASR scrutiny at the tail and at sentence/comma boundaries
  (`TAILNOISE` / `BNDNOISE`).** The existing ASR signals are duration-based
  (`trail_s` / `max_gap_s`), so a *short but loud* defect sails through — a brief
  tail "swoosh" under the TAIL time threshold, or a tonal hum filling a
  punctuation-boundary gap under the PAUSE threshold. (A bad take with both passed
  QA in 0.11.0 when it shouldn't have.) Two new signals measure the actual energy
  in those zones, aligned to the Whisper word timings: **TAILNOISE** flags a tail
  whose peak loudness — measured past the final word's natural release — exceeds
  `TTS_ASR_TAIL_ENERGY_DBFS_MAX` **and is louder than the chunk's own speech by
  `TTS_ASR_TAIL_OVER_SPEECH_DB`** (the relative gate keeps a soft final word-coda
  that Whisper under-times — e.g. the "n" in "2019"→"nineteen" — from being clipped),
  and lossless-trims it; **BNDNOISE** flags a
  punctuation-boundary gap that is both not-silent (`TTS_ASR_BOUNDARY_ENERGY_DBFS_MAX`)
  and tonal/low-frequency (`TTS_ASR_BOUNDARY_ZCR_MAX_HZ`) — a hum, distinct from a
  clean breath or normal speech residue — and re-rolls it (the defect is mid-speech,
  so it can't be trimmed). Both are off unless ASR QA is enabled and degrade safely;
  thresholds are configurable in `.env` and the admin Settings page. Validated on the
  labeled corpus; the boundary thresholds are conservative and should be re-checked
  against the production sidecar. See docs/ASR-SETUP.md.

  The tail trim is verified not to clip soft word-endings: validated end-to-end against
  recovered untrimmed Replicate originals (takes ending in "2019"→"nineteen", whose
  voiced nasal Whisper under-times), the relative gate spares the real word while still
  catching a genuine swoosh.

### Changed
- **Clearer `AdminSeeder` guidance when `ADMIN_EMAIL` / `ADMIN_PASSWORD` aren't
  readable.** On production the config is usually cached (e.g. `artisan optimize` on
  deploy), so `env()` returns null outside config files and the seeder silently
  skipped. The skip message now explains this and points to `php artisan admin:create`,
  which doesn't depend on `env()` / cached config and works on production.

## [0.11.0] - 2026-06-21

### Added
- **Independent ASR remediation policy for the Studio vs. the API.** `TTS_ASR_ACTION`
  was a single switch shared by both generation paths, so you couldn't keep manual
  triage in the Studio while the API self-healed. It can now be scoped per path with
  `TTS_ASR_STUDIO_ACTION` and `TTS_ASR_API_ACTION` — each inherits `TTS_ASR_ACTION`
  when unset, so existing single-value setups are unchanged. The intended split: the
  interactive Studio stays `log` (an admin sees the per-chunk badge and re-rolls by
  hand) while the unattended API / full-MP3 path runs `auto` to self-heal, since no
  human can intervene there.
- **Admin settings page (`/admin/settings`).** UI-editable service configuration for
  the ASR feature — the master switch, the Studio/API remediation split, max
  re-rolls, and (under "Advanced") the detection thresholds — backed by a new generic
  settings store. Precedence is **`.env` (locked) → saved value → config default**: a
  value pinned in `.env` is shown read-only with a "Set in .env" note, while every
  other key is editable in the panel and layered onto config at boot, so `.env`
  always wins and `config:cache` is respected. Built to extend — future setting
  groups just register more keys.

### Removed
- **"Sentence seam" / "paragraph seam" chunk badges in Studio.** These per-chunk
  markers exposed an internal chunking detail that wasn't actionable to the user.
  Removed from both the project editor and the chunk inspector. The "Preview
  stitch" feature (which actually tests the trim + seam join) is unchanged.

## [0.10.0] - 2026-06-21

### Added
- **ASR transcript quality checking (opt-in).** A local Whisper sidecar transcribes
  each generated chunk and compares the transcript to the source text to catch the
  failure modes the DSP tail-trim cannot: **truncation** (Chatterbox stops before
  finishing the line — no acoustic artifact to detect), **speech-like / "ghostly
  singing" tails**, and **mid-stream pauses**. Off by default (`TTS_ASR_ENABLED`).
  The sidecar (`asr-sidecar/`, FastAPI + faster-whisper, run as a Forge Daemon)
  exposes `/health` + `/transcribe`; the app talks to it over localhost and never
  blocks generation if it's down. Two modes via `TTS_ASR_ACTION`: **`log`** records
  a per-chunk verdict (surfaced as a badge in Studio), **`auto`** also remediates —
  re-rolling truncated/paused/empty takes with a fresh seed (best-of-N, up to
  `TTS_ASR_MAX_REROLLS`, default **2**) and precise-trimming junk tails at the ASR
  speech end. Runs on both the Studio/project path and the synchronous + queued
  `/v1` path. Verify with `php artisan tts:asr:health [--deep]`, or the ASR row on
  the admin Health page. Setup: `docs/ASR-SETUP.md`.
- **Pure-PHP voicing gate for the tail detector.** Closes a known blind spot — a
  loud, high-ZCR but *aperiodic* tail (broadband hiss/noise with no fundamental)
  cleared the speech gate and was not a low-ZCR/tonal artifact either. A peak
  normalized autocorrelation in 75–600 Hz now finds the last loud **voiced** window
  and, when a trailing unvoiced run of ≥ `chunk_tail_unvoiced_min_ms` (default
  **400**) follows, cuts back to it plus `chunk_tail_fricative_allowance_ms`
  (default **250**, so a genuine word-final fricative survives). It combines with
  the existing ZCR/tonal cut (takes the earlier), so it only ever trims more and
  never clips a voiced word. No Python/Praat dependency. `chunk_tail_voicing_*`,
  default on.

### Changed
- **Clearer ASR health diagnostics.** Health-check results can now carry a help
  link. The ASR sidecar's "unreachable" message drops the raw cURL noise for an
  actionable one-liner and links the setup guide (`TTS_ASR_DOCS_URL`) — rendered as
  a clickable "Setup guide" on the admin Health page and shown in `tts:doctor`.

## [0.9.3] - 2026-06-20

### Fixed
- **Long tonal "swell" at the end of a chunk survived trimming.** Chatterbox
  sometimes appends a sustained tone that ramps up for over a second after the
  speech ends (separated by a brief quiet gap). It clears the speech gates (loud,
  mid-ZCR) and is too long for the re-swell "blip" peel, so it remained in the
  stitched audio. The tail detector now also peels a longer isolated run when its
  per-window ZCR is near-constant (`chunk_tail_tonal_cv_max`, default **0.35**) —
  real speech swings between voiced and unvoiced, a tone does not — so the swell is
  cut at the speech end while genuine speech is untouched. `0` disables this path.

## [0.9.2] - 2026-06-20

### Added
- **Retry transient Chatterbox prediction failures.** Replicate occasionally fails
  a prediction with a transient GPU fault (observed: `CUDA error: device-side assert
  triggered` while embedding the reference clip, and CUDA OOM) on a flaky/cold
  worker — re-running the same request usually succeeds. The provider now re-rolls
  such a failure up to `REPLICATE_PREDICT_MAX_RETRIES` times (default **2**, same
  backoff as the 429 path, bounded by the request timeout). Deterministic failures
  (bad input) still fail fast. Set `REPLICATE_PREDICT_MAX_RETRIES=0` to disable.
- **Pace Studio "Generate all remaining".** Generation was already sequential, but
  gapless — a chunk that failed fast (~300 ms) fired the next prediction almost
  immediately, and a burst of back-to-back predictions can spin up cold GPU replicas
  on Replicate (which is what throws the transient CUDA asserts above). The loop now
  waits a short gap between chunks (`TTS_STUDIO_GENERATE_PACE_MS`, default **800**;
  0 = back-to-back).

## [0.9.1] - 2026-06-20

### Added
- **Per-chunk voice override in Studio projects.** Each chunk now has its own voice
  picker. By default a chunk inherits the project voice (the picker mirrors it, and
  follows when you change the project voice), but you can pin an individual chunk to
  a different voice and generate just that chunk with it. Changing the project voice
  only stales chunks that inherit it; explicitly-voiced chunks keep their audio.
  Backed by a nullable `tts_chunks.voice_id` (`PATCH …/chunks/{chunk}/voice`).

### Fixed
- **New Studio project bound to the wrong voice.** The "New project" form only
  marked a `<select>` option selected on validation-error repopulation, so a fresh
  page let the browser auto-select the first voice by name — binding new projects to
  an arbitrary cloning voice instead of the built-in default. The form now
  pre-selects the configured default voice.
- **Voices "Test" button replayed a stale preview.** The preview reused a cached
  result, so a default-voice test could keep playing audio captured while the voice
  briefly had a different reference. The Test button now always regenerates live.
- **Tail-end "decay-then-blip" artifact that slipped past 0.9.0.** Chatterbox
  sometimes follows the quiet decay tail with a brief, loud, mid-band "re-swell"
  that is neither quiet nor tonal, so it cleared both of the long-tail detector's
  gates. Sitting at the very end of the chunk, it reset the detected speech end to
  ~EOF, the trailing-silence run collapsed to near zero, and the whole multi-second
  tail survived. The detector now peels an isolated short trailing run (shorter than
  `TTS_CHUNK_TAIL_BLIP_MAX_MS`, default 400 ms) when a long **quiet** (sub-floor) gap
  isolates it from earlier audio, then measures the speech end and hard-cuts as
  before. The gap must be genuinely silent, not merely low-ZCR — so a quiet final
  word like "will be" (loud but low-ZCR voiced sound) is never mistaken for an
  artifact and trimmed. Set `TTS_CHUNK_TAIL_BLIP_MAX_MS=0` to disable. See
  `docs/AUDIO-CLEANUP.md`.

## [0.9.0] - 2026-06-20

### Added
- **Built-in default voice.** A "Default voice" (`voice_id` = `default`) is now
  seeded on install and uses Chatterbox's native voice (no reference clip), so a
  fresh install can generate audio without uploading a custom voice. It shows up in
  Studio and the `/v1` API, is protected from deletion, and its `voice_id` is
  configurable via `TTS_DEFAULT_VOICE_SLUG`. The **Add voice** form's reference clip
  is now optional, so you can also create additional reference-less voices.
- **Change a project's voice.** The Studio project editor has a voice picker in its
  toolbar (`PATCH /admin/studio/projects/{project}/voice`). Switching the voice
  marks already-generated chunks stale so you can regenerate them with the new
  voice; tuning, seed, and per-chunk overrides are preserved.

### Fixed
- **Chatterbox's long tail-end distortion is now removed.** Some generations append
  a multi-second, speech-level, low-frequency drone after the speech ends — too loud
  for the silence trim and too long for the bounded tail window, so it survived into
  production audio. A new detector flags it by zero-crossing rate (the drone is
  tonal/low-frequency) gated by an RMS floor and hard-cuts at the speech end, while
  normal clips and soft final words keep the existing bounded trim. All thresholds
  are env-tunable (`TTS_CHUNK_TAIL_*`). See `docs/AUDIO-CLEANUP.md`.

## [0.8.2] - 2026-06-19

### Added
- The version number in the admin footer now links to that version's GitHub
  release (`{APP_SOURCE_URL}/releases/tag/v{version}`), opening in a new tab. The
  base URL is configurable via `APP_SOURCE_URL`; set it empty to drop the link.

### Security
- Bumped `guzzlehttp/guzzle` (7.11.2 → 7.12.1) and `guzzlehttp/psr7`
  (2.11.1 → 2.12.1) to patched releases, resolving three moderate Dependabot
  advisories (HTTPS-proxy cleartext downgrade, dot-only cookie domain matching,
  and CRLF injection in HTTP start-line serialization).

## [0.8.1] - 2026-06-19

### Added
- **Unsaved-edit safeguards in the Studio editor.** A chunk whose text has been
  edited but not saved now shows an amber "● unsaved" badge and an amber textarea
  border, and reveals a **Revert** button that restores the last-saved text. The
  editor also warns before you navigate away from (or reload) the page with any
  unsaved chunk edits, so they can't be lost silently — and inserting a chunk
  (which reloads the list) confirms first if there are unsaved edits.

## [0.8.0] - 2026-06-19

### Added
- **Insert a chunk into a Studio project.** A subtle "+ insert chunk" control at
  every gap (and at the top/bottom) inserts a new empty, ungenerated chunk at that
  position, shifting the rest down (`POST /admin/studio/projects/{project}/chunks`).
  Type into it, Save, and Generate like any other chunk.
- **Auto re-chunk an over-long edit.** Saving a chunk whose text now exceeds the
  standard chunk budget re-splits it the same way new projects are chunked: the
  edited chunk keeps the first segment and the remainder become new pending chunks
  inserted right after it. The surrounding chunks' generated audio is preserved
  (audio is keyed by chunk id, not position); a trailing paragraph seam is kept on
  the last new piece.
- **Start over from the original text.** A **Start over** action opens an editable
  editor pre-filled with the project's original text; on confirm it re-chunks the
  (possibly edited) text and **permanently deletes all generated audio**, returning
  the project to a fresh draft with the same voice/settings
  (`GET/POST /admin/studio/projects/{project}/edit` + `/reset`).
- **Rename a Studio project.** A **Rename** control next to the project title in
  the editor edits the title inline and saves it without a full page reload
  (`PATCH /admin/studio/projects/{project}`). Useful for telling apart projects
  that were created with the same default title.
- **Create-project API.** `POST /v1/projects` creates an editable Studio project
  from text instead of generating audio — the non-generating sibling of
  `POST /v1/text-to-speech/{voice_id}`. It takes `voice_id` and `text` in the body
  (plus optional `title`, `model_id`, `voice_settings`, `seed`, `output_format`),
  normalizes and chunks the text into a project (no provider calls), and returns
  the project's id, an auto-generated title when none is given (e.g.
  `Audio project #47 - June 19, 2026`), and a single-use `edit_url` that logs the
  user into the control panel and lands them on the project. Like the generation
  endpoints, it requires a valid API key (`xi-api-key`); it is not counted against
  the per-key generation rate limit, since it does no generation. Projects created
  this way record the calling API key (new nullable `tts_projects.api_key_id`).
- Single-use, expiring auto-login links (`GET /projects/open/{token}`), backed by
  a new `magic_login_tokens` table. The raw token lives only in the link; its
  sha256 hash is stored, redemption is atomic (single use), and the link expires
  after `TTS_MAGIC_LOGIN_TTL_MINUTES` (default **60**).

## [0.7.0] - 2026-06-19

### Added
- **Studio** — a new admin area (under **Studio** in the nav) for inspecting and
  editing text-to-speech output:
  - **Inspector.** Paste a block of text to see it normalized and split into
    chunks using the same normalization and chunking the generation pipeline
    uses, then hear it three ways: the whole text as a single Chatterbox call,
    each chunk on its own (raw provider output, so seam artifacts are audible),
    or the full production stitch. A concatenation preview re-stitches chunks you
    have already generated to audition a seam without re-synthesizing (Chatterbox
    is non-deterministic, so re-synthesizing would not reproduce the same audio).
  - **Editable projects.** Save a project, generate or regenerate individual
    chunks, edit a single sentence and re-synthesize only that chunk, then
    rebuild the stitched file — no full regeneration and no re-billing the whole
    file. An inline "Preview stitch" control between two adjacent generated chunks
    auditions their join, and the final file can be downloaded.
- `TTS_CHUNK_TRIM_TAIL_WINDOW_MS` (default **300**) bounds the trailing-silence
  trim to the last N ms of a chunk, so the trim removes Chatterbox's swoosh tail
  without reaching back and swallowing a quiet final word (see Fixed).
- `TTS_SHORT_TRAILER_WORDS` (default **3**) moves a sentence of this many words
  or fewer off the end of a chunk and onto the start of the next one (see Fixed).
  Set **0** to disable.

### Changed
- Studio audio players now play one at a time — starting any player pauses the
  others — and the actively-playing player stays highlighted while the rest dim
  to gray, so it is clear which clip is sounding. The redundant "playing now"
  status text was removed in favor of this visual cue.

### Fixed
- A short word at the very end of a chunk — most visibly a one-word "Why?" — is
  no longer dropped from the generated audio. Two independent causes were fixed:
  - The per-chunk edge-trim that removes Chatterbox's trailing "swoosh" was far
    more aggressive than its nominal threshold and could remove a quiet final
    word along with the trailing silence. It is now bounded to the last
    `TTS_CHUNK_TRIM_TAIL_WINDOW_MS` of each chunk — the swoosh sits after the
    word, so a short end-window strips the noise while the word is preserved.
  - Chatterbox itself tends to drop a short trailing utterance by ending
    generation early, yet renders a short *leading* phrase reliably, so the
    chunker now relocates a short sentence (≤ `TTS_SHORT_TRAILER_WORDS` words)
    that would end a chunk to the start of the next chunk. This applies only
    within a paragraph; a paragraph- or document-final short sentence is left in
    place (regenerate it from the Studio editor if it drops).

## [0.6.1] - 2026-06-18

### Fixed
- Very short standalone chunks — a one-word paragraph like "Why?", or a short
  heading like "The to-do list." — are no longer dropped from the generated
  audio. Headings and short paragraphs each become their own block, and thus
  their own Chatterbox generation; Chatterbox returns silence or garbage for
  inputs under ~25 characters, so those words went missing (heard as an extra
  pause). Such chunks are now merged into a neighbor before synthesis so nothing
  too short is ever sent on its own.

### Added
- `TTS_MIN_CHUNK_CHARS` (default **30**) sets the length below which a chunk is
  merged into an adjacent chunk — forward into the following chunk when it fits
  (the short line leads its content and the pause before it is preserved),
  otherwise back into the previous one. Set **0** to disable merging.

## [0.6.0] - 2026-06-18

### Added
- **Paragraph-aware pacing.** Long text is split into blocks on blank lines
  **or** runs of `TTS_BLOCK_SPACE_RUN` (default **4**) spaces, and a longer
  silence is inserted at block/paragraph seams (`TTS_PARAGRAPH_GAP_MS`, default
  **400**ms) than at sentence seams (`TTS_CHUNK_GAP_MS`, default **120**ms). The
  space-run rule recovers paragraph structure from clients that flatten a post to
  a single line and mark blocks with runs of spaces.
- **Configurable chunk edge-trimming.** `TTS_CHUNK_TRIM_THRESHOLD` (default
  **-40dB**; raise toward -30dB to cut a louder tail) and `TTS_CHUNK_FADE_MS`
  (default **8**ms) control the per-chunk trim + fade applied before joining.

### Changed
- Chunk concatenation now trims each chunk and rejoins the pieces with true
  digital silence instead of a fixed crossfade, producing clean, click-free seams
  and natural pacing.

### Removed
- `TTS_CHUNK_CROSSFADE_MS` / `chunk_crossfade_ms`, superseded by the trim +
  silence-gap join above.

### Fixed
- Long-form articles no longer have noisy "swooshy" pauses at paragraph and
  sentence breaks. Chatterbox appends a low-level noise/hiss tail to nearly every
  generation; because long text is synthesized as many short chunks and the tail
  was never trimmed, it landed at every concatenation seam — which fall exactly at
  sentence and paragraph boundaries. Each chunk is now edge-trimmed before it is
  joined, and the seams carry clean silence.
- Messy upstream text is normalized before synthesis: a space before terminal
  punctuation (`word .`) and spaced double-terminators (`word. .`) — which reach
  Chatterbox as awkward pauses or near-empty fragments and amplify its noise
  artifacts — are cleaned up, while real ellipses (`...`) are preserved.

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

[Unreleased]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.11.0...HEAD
[0.11.0]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.9.3...v0.10.0
[0.9.3]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.9.2...v0.9.3
[0.9.2]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.9.1...v0.9.2
[0.9.1]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.8.2...v0.9.0
[0.8.2]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.8.1...v0.8.2
[0.8.1]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.8.0...v0.8.1
[0.8.0]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.6.1...v0.7.0
[0.6.1]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.6.0...v0.6.1
[0.6.0]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.4.1...v0.5.0
[0.4.1]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/johnfmorton/bespoken-tts-service/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/johnfmorton/bespoken-tts-service/releases/tag/v0.1.0
