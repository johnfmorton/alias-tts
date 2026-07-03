# Changelog

All notable changes to this project are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed
- **`tts:doctor` / Health treat the Genblaze runner as integral, with louder,
  actionable guidance.** An unconfigured runner is now a **WARN** (was a silent
  green PASS) explaining that it powers the pronunciation pre-processor and the
  QA-gated "Generate via Genblaze" pipeline, with the exact steps to enable it.
  The pronunciation warning is likewise stronger — it states detection is
  silently skipped and gives both the fix and the off-switch
  (`TTS_PRONUNCIATION_ENABLED=false`). Every Genblaze/pronunciation row now
  links a **Setup guide** (new `TTS_GENBLAZE_DOCS_URL`). DEPLOYMENT.md gains a
  "Background processes at a glance" table mapping each daemon (scheduler, queue
  worker, Genblaze runner, Whisper ASR sidecar) to the Health check that reports
  it. `tts:doctor` still exits non-zero only on a hard FAIL.

## [0.27.0] - 2026-07-03

### Added
- **Trust configurable reverse proxies (`TRUSTED_PROXIES`).** When the app runs
  behind a TLS-terminating proxy or CDN (Cloudflare, a load balancer, nginx),
  set `TRUSTED_PROXIES` so it reads the real visitor IP — used by the per-IP
  login rate-limiter and logs — and the true https scheme from the proxy's
  `X-Forwarded-*` headers instead of the proxy's own. Opt-in and safe by
  default: unset trusts nothing (correct for a directly-exposed install); `*`
  trusts any upstream (lock the origin firewall to the proxy's IPs), or give a
  comma-separated IP/CIDR list. The trusted list is read at request time, so it
  resolves correctly under a cached production config. `.env.example` and
  DEPLOYMENT.md also now document `SESSION_SECURE_COOKIE` and the Cloudflare
  "Full (strict)" origin-certificate setup.

## [0.26.0] - 2026-07-03

### Fixed
- **Bare `php artisan admin:create` no longer dead-ends a fresh install.**
  Following `.env.example` (set `ADMIN_EMAIL` / `ADMIN_PASSWORD`, run the
  command with no arguments) used to fail with `Not enough arguments (missing:
  "email")`. The command now falls back to those variables — read through
  config, so they still resolve after the deploy's `artisan optimize` caches
  config (the same trap that silently broke the `AdminSeeder` path on
  production) — prompts interactively for anything still missing, and fails
  with the exact remedy in the message when run non-interactively. The
  `.env.example` also now notes that `FILESYSTEM_DISK` is unused by this app —
  storage is selected with `TTS_STORAGE_DISK`.

### Added
- **The sign-in page now tells a locked-out user how to recover.** Password
  recovery is admin-mediated by design (a SuperAdmin issues a signed reset link
  from the Users page; the app sends no email). The login card now says so —
  and when the new `TTS_SUPPORT_EMAIL` is set, it links a pre-drafted "send me
  a reset link" email to that address, carrying the instance URL and the
  account email from the failed attempt. A full self-service (mail-based)
  reset is specced in ROADMAP.md's new Accounts section; `.env.example` now
  notes the `MAIL_*` block is unused today.
- **`tts:doctor` now checks the Genblaze runner.** A dedicated "Genblaze
  runner" health check (CLI and the dashboard Health page): unset
  `TTS_GENBLAZE_RUNNER_URL` passes as "not configured", but a configured runner
  that is down, missing `TTS_INTERNAL_SECRET`, or disagreeing with the app's
  `TTS_STORAGE_ROOT` fails loudly with the fix in the message — previously a
  dead runner only surfaced as a broken Studio page after doctor reported
  green. Softer misconfigurations (callback URL not matching the app, no B2
  sink) warn. The runner's `/health` now reports its `storage_root` so the two
  sides can be compared.

## [0.25.0] - 2026-07-03

### Added
- **Shared-bucket storage root (`TTS_STORAGE_ROOT`).** Optionally scope
  everything an install stores (`speech/`, `voices/`, `avatars/`, `genblaze/`)
  to a subfolder of the storage disk, so several apps can safely share one S3/B2
  bucket. The prefix is applied at the disk layer — database rows keep their
  relative paths — so adopting it on an existing install only means moving the
  objects in the bucket and setting the variable. The Genblaze runner honors the
  same root (uploading provenance under `<root>/genblaze/`), reading
  `TTS_STORAGE_ROOT` directly from its own environment; the app's provenance
  proxy understands the prefixed runner URLs. A tracked
  `genblaze-runner/run-genblaze.sh.example` wrapper prototype sources these
  shared values straight from the site's `.env`, so secrets and the storage root
  are defined once, and `MIMIC_API_KEY` is documented as optional (orchestrated
  runs authenticate through the internal secret).
- **Orphan-file sweep for `speech:cleanup` (`--orphans`).** Removes files under
  the speech storage path that no database row references — leftovers from
  crashed jobs or rows deleted outside the app. The sweep never leaves that
  path (voices, avatars, Genblaze provenance, and other apps on a shared disk
  are untouched) and spares files younger than `--orphan-age` hours (default
  24) so an in-flight generation is never caught. Preview with `--dry-run`;
  it is not scheduled by default.
- **Per-user "Final audio format" setting (MP3 or uncompressed WAV).** A new
  "Audio output" section on the Settings page lets each user choose the format
  their projects build to: the default MP3 (44.1 kHz, 128 kbps) or uncompressed
  WAV (44.1 kHz, 16-bit) for editing and archival. The choice applies to
  projects created from then on — panel "New project" and `POST /v1/projects`
  without an explicit `output_format` — and is stamped at creation, so existing
  projects keep their format. Direct `/v1/text-to-speech` calls are deliberately
  unaffected (clients like the Bespoken plugin rely on the fixed MP3 default);
  an explicit `output_format` on any API request still wins. Managed-settings
  enum options can now carry human-readable labels in the registry
  (`option_labels`), so the dropdown reads "WAV — 44.1 kHz, 16-bit
  (uncompressed)" rather than `wav_44100`.

## [0.24.0] - 2026-07-02

### Added
- **OpenAI-compatible text-to-speech endpoint (`POST /v1/audio/speech`).** Apps
  that speak OpenAI's TTS API now work against Bespoken by swapping only the base
  URL and key — a thin adapter over the same engine, voices, cache, and audio
  pipeline the ElevenLabs surface uses. Authenticate with a Bespoken key via
  `Authorization: Bearer`; `voice` is a Bespoken voice slug (with an optional
  `openai_voice_aliases` config map for OpenAI's fixed preset names). Errors use
  the OpenAI `{"error":{…}}` shape. Supported formats are `mp3` and `wav` (`pcm`
  returns a WAV container); streaming, `opus`/`aac`/`flac`, and `speed`/
  `instructions` are not supported yet. Full support contract in
  [docs/OPENAI-COMPAT.md](docs/OPENAI-COMPAT.md).

## [0.23.1] - 2026-07-02

### Changed
- **The per-chunk quality-check badges now read "QA" instead of "ASR."** The
  acronym was jargon; "QA" reads as what it does — a per-chunk quality check.
  The badge text, the Studio explainer (now expanded as "quality assurance"
  while still noting it works by transcribing with speech recognition), and the
  Settings and Health labels all use the new wording. Nothing functional
  changed — the underlying transcript-QA behavior and its configuration are the
  same.
- **The dashboard's connection panel is no longer plugin-specific.** It now
  leads with "Connect your app" and explains that Bespoken speaks the
  ElevenLabs v1 API, so any ElevenLabs-compatible app works — with the Bespoken
  Craft CMS plugin named as one example rather than the whole audience.

### Fixed
- **Renaming a Studio project works again.** After the project header was
  reworked to drop its `<h1>`, the Rename button threw a JavaScript error and
  did nothing; it now resolves the title element correctly and renames in place.

## [0.23.0] - 2026-07-02

### Added
- **Unapprove.** An approval made by accident can be undone: the project's ⋯
  menu gains an "Unapprove" item (shown only while approved) that clears the
  approval and returns the project to its editable, re-approvable state. The
  final audio is untouched, so you can re-approve the same cut in one click.

### Changed
- **The project action bar reads as a draft→approved progression.** The
  top-of-project buttons were reordered and renamed: *Generate remaining* (was
  "Generate all remaining"), *Build final* (was "Rebuild final" — nothing to
  rebuild on the first stitch), *Download draft version* (was "Download"), and
  *Approve as final* (was "Seal as final"). Approving now swaps that button in
  place for a primary *Download approved version* — the full package (audio +
  provenance + offline verify page), promoted out of the ⋯ overflow menu — while
  the draft download hides, so there's one clear download whose label always
  says which version you're getting. The sealed badge and its copy now read
  "Approved" to match (routes and stored fields are unchanged).
- **The approved package names its audio for the project.** The audio inside the
  receipt `.zip` is now `‹project›-sealed-‹fingerprint›.mp3` (matching the `.zip`
  and the folder it unzips to) instead of a bare `final.mp3`; the receipt page
  and the manifest reference the same name.
- **The receipt reads "Approved."** The provenance receipt page — title, banner,
  and labels — now says "Approved final" instead of "Sealed," matching the rest
  of the app.
- **The receipt's provenance table was rebuilt.** It dropped the Seed and Source
  columns (seed is no longer surfaced in the app; both remain in
  `manifest.json`) and switched to a fixed layout, so the long source hash and QA
  notes wrap inside their columns instead of overflowing the page.

### Fixed
- **"Generate remaining" hides once every chunk is generated.** A CSS
  `hidden`/`inline-flex` conflict had left it visible on fully-generated
  projects; it now disappears when nothing is pending and reappears if you
  insert a new chunk.
- **No duplicate approval confirmation.** Approving a project no longer prints an
  "Approved as the final" status line beneath the badge that already says so.

## [0.22.0] - 2026-07-02

### Changed
- **Voice tuning now lives with the voice.** The A/B tuning bench and named
  presets moved from the Studio Inspector to the voice edit page as a "Tune by
  ear" section: hear the voice read a sample line at different settings (row
  one is the voice's current defaults), pick the winner, and save it as the
  voice's defaults — the preview gap the old edit form never closed. Creating
  a voice now lands on that page, the Add-a-voice form dropped its tuning
  fields entirely, and both voice forms now speak Chatterbox's native knobs
  (Exaggeration, CFG/Pace) instead of the abstract Stability/Style pair, so
  every tuning surface uses one vocabulary. The Inspector keeps its transient
  per-preview knobs, now labeled as such, with a pointer to the voice page.
- **Presets are per-user and actually applicable.** Tuning presets belong to
  the user who saved them (existing ones go to the first SuperAdmin), preset
  names only need to be unique within your own set, and nobody can delete
  yours. They also gained the two places you'd want to use them: a "Delivery"
  pick on the New Project form that seeds the project's tuning, and an "Apply
  preset" pick in each chunk's Takes & tuning panel that fills the knobs for
  that one line — an "excited" or "languid" read per piece without minting
  voice variants.

### Added
- **Duplicate a voice.** Every voice row on the Voices page gained a
  Duplicate action that clones the reference clip and tuning into a voice you
  own — the sanctioned way to get a tunable personal copy of a shared
  built-in.
- **The real Bespoken mark, everywhere.** The waveform icon replaces the
  placeholder "B" tile in the panel header, the auth pages, and the landing
  page, and it's now the favicon too — a crisp SVG for modern browsers, a
  real multi-size `favicon.ico` (the shipped one was an empty file), and an
  `apple-touch-icon` for home screens. In-page logos use a lightened
  on-dark variant of the mark so the deep-blue bars stay legible on the
  black UI; the favicon keeps the original brand colors.

### Changed
- **Panel test generations run as the user who clicks.** The Voices "Test"
  button and the health page's provider tests used one shared API key named
  "dashboard" (created unowned), so every user's test ran under — and was
  attributed to — whoever happened to own that key, and any signed-in user
  could poll another user's test audio by id. Each user now gets their own
  dashboard key on first use, and the health-test status/audio endpoints only
  serve the initiating user's tests. An old shared "dashboard" key is left
  untouched; deactivate it from the API keys page if one lingers.

### Security
- **The tuning bench can no longer retune a shared voice for everyone.** The
  bench's "save to voice defaults" endpoint skipped the shared-voice rule the
  edit form enforces, so any user could write tuning onto a built-in voice and
  change what every user (and their API calls) hears. It now requires the same
  ownership as the edit form; regular users are pointed at Duplicate instead.

### Deploy
- Run `php artisan migrate` — adds `tuning_presets.user_id` (existing presets
  are assigned to the first SuperAdmin) and makes preset names unique per user.

## [0.21.0] - 2026-07-02

### Added
- **Voices can be reordered — and the order is yours.** Drag the handle on the
  Voices page to arrange your list (built-ins included). The order drives every
  voice dropdown in the panel, and the first voice is what New Project
  pre-selects, so dragging is how you choose your effective default voice. Each
  user's order is their own.
- **New Project links to the Voices panel** ("Manage voices →" beside the
  voice picker).

### Security
- **Voices are now per-user.** Custom voices used to be visible to every
  signed-in user, who could also edit, delete, or export them — and any API
  key could generate with any voice. A custom voice now belongs to the user
  who created it: others never see it, the panel guards edit/update/delete
  behind the owner (or a SuperAdmin), and an API key resolves only its
  owner's voices plus the shared built-ins. Registering a voice_id that
  belongs to someone else is refused, so a re-register can no longer replace
  another user's reference clip. Existing custom voices are assigned to the
  first SuperAdmin by the migration; a SuperAdmin's Voices page still lists
  everyone's voices, labeled by owner.
- **Settings are now per-user.** The Settings page used to write one global
  set of values, so any signed-in user could change ASR QA behavior, API
  project mode, and pronunciation options for the SuperAdmin and every other
  user. Each user now has their own settings, applied only to their panel
  session, their API keys' requests, and their queued generations; a queue
  worker resets between jobs so one user's values never bleed into another's.
  Values pinned in `.env` remain instance-wide and read-only. The migration
  hands the old global values to SuperAdmins; regular users start from the
  server defaults, the same as any new user. ASR QA's "defaults on if
  available" also became per-user: the health page enables it for the visitor,
  `tts:doctor` for every user who hasn't chosen.

### Changed
- **Clearer Settings help text.** The Detection LLM provider help now names
  all four options — "anthropic" was a valid (and prod-deployed) choice the
  text didn't mention — and says which API key each one needs. The
  pronunciation pre-processor help now mentions that approved respellings
  persist to your pronunciation dictionary, the ASR QA master switch describes
  what turning it on actually does, and the PAUSE/TRUNC threshold wording was
  tightened.
- **The sealed receipt now verifies itself.** The receipt .zip's
  `receipt.html` embeds the drop-to-verify widget directly — the sealed
  SHA-256 is baked into the page, so double-clicking the receipt and dropping
  the audio on it confirms the file offline, with nothing to configure. The
  zip no longer ships a separate `verify.html`, which invited opening the
  verifier without the expected fingerprint and answered "nothing to compare
  against." The hosted `/verify` page (used by "Copy verify link") is
  unchanged.
- **Genblaze demo: the final audio plays in the Studio hero player.** The
  demo's bare `<audio>` element is reskinned with the same transport used on
  the Studio project page.

### Fixed
- **Genblaze demo: the Chunk step no longer goes silent for short text.** When
  the input fits in a single segment (no splitting needed), the pipeline's Chunk
  step now says so explicitly ("no chunking needed — short enough for a single
  segment") instead of leaving the step blank. Fixed at the source (the runner's
  live progress ping) and in the panel's final provenance render, with matching
  wording so the detail doesn't flicker on completion.

## [0.20.0] - 2026-07-01

### Security
- **Studio projects are now private to their owner.** Opening the control
  panel to every active user (0.19.0) had left Studio unscoped: any signed-in
  user could list, open, edit, regenerate, seal, or delete anyone's projects.
  Every project now records an owner — panel projects get the signed-in user,
  API-created projects the API key's owner, and existing rows are backfilled
  by the migration — and every project route requires the owner (or a
  SuperAdmin). The Studio list shows only your own projects; a SuperAdmin
  still sees everyone's, labeled by owner.
- **Removed the auto-login ("magic") project link.** `POST /v1/projects` used
  to return an `edit_url` that logged the visitor straight into the panel — but
  always as the SuperAdmin, so once the panel opened to regular users, any of
  them could mint their own API key, call the endpoint, and open the link to
  gain a SuperAdmin session. The whole feature is gone: no token is minted, the
  `edit_url`/`edit_url_expires_at` response fields and the `/projects/open/{token}`
  route are removed, and the `magic_login_tokens` table is dropped. The response
  now returns only a plain `url` the project's owner opens after a normal login.

### Removed
- The `TTS_MAGIC_LOGIN_TTL_MINUTES` setting (the feature it configured is gone).

## [0.19.0] - 2026-07-01

### Added
- **Account screen.** A self-service `/admin/account` where any signed-in user
  manages their profile (display name, email, avatar), changes their password,
  and can delete their own account. Avatars live on the private object-storage
  disk (B2 in prod) and stream through an authenticated proxy — the same pattern
  as generated audio.
- **Two-factor authentication (TOTP).** Set up an authenticator app from the
  Account screen (the QR is rendered locally, so the secret never leaves the
  server), confirm a code, and save one-time recovery codes. Login gains a
  second step for protected accounts. No configuration required.
- **Single sign-on (Google & GitHub).** Connect a provider from the Account
  screen and sign in with it thereafter. Invite-only — SSO links and
  authenticates existing accounts, it never creates them — and each provider
  stays dormant (buttons read "Not configured") until its credentials are set.
  See `docs/SSO-SETUP.md`.
- **Users admin (SuperAdmin only).** A `/admin/users` screen — a table of
  everyone with access plus a click-in detail drawer — to create users (with a
  one-time temporary password), invite by email (a signed set-password link),
  change a user's role, suspend/reactivate, force a password reset, sign in as a
  user (impersonate, with a persistent banner to return), and delete. Guarded so
  the last SuperAdmin can't be demoted, suspended, or deleted into a lockout.
- **Role-gated navigation.** The flat top bar collapses to three primary items
  (Genblaze Demo, Dashboard, Studio) plus an account menu grouped into Manage /
  System, with an Admin → Users section shown only to SuperAdmins.
- **Studio: one pinned command bar.** The project page's action toolbar and the
  separate "Final audio" card merge into a single sticky, two-row header, so the
  final audio (now a custom player) is always one tap away as you scroll the
  chunk list. The action set is state-aware — the lit primary always names the
  next step (Generate → Rebuild → Download) and Seal stays visibly disabled
  until a current final exists — and rare/destructive actions (Start over,
  Delete) move into an overflow menu.

### Changed
- **The control panel is open to any signed-in user, not just the admin.**
  Previously every `/admin` page required the super-admin; now any active user
  can use the panel, only the Users screen is SuperAdmin-gated, and suspended
  accounts are signed out on their next request. This is the multi-user access
  model the Users screen manages — the two roles (User and SuperAdmin) map onto
  the existing `is_super_admin` flag, so there is no separate roles table.
- **API keys are strictly per-user.** On the dashboard and the API Keys page each
  user sees, resets, and manages only their own connection key — a keyless user no
  longer inherits a shared or legacy key, and nobody can rotate or delete someone
  else's. Any pre-existing unowned keys are reassigned to the primary admin on
  upgrade.

## [0.18.0] - 2026-07-01

### Added
- **The Genblaze page is now a self-contained live demo.** "Generate via
  Genblaze" shows a pipeline checklist that lights up **as the run happens** —
  Pronunciation → Chunk → Generate & QA (re-rolls surfaced live) → Stitch →
  Seal + verify → Upload to B2 — driven by real per-stage progress the runner
  reports while it works, with a **Replay walkthrough** to re-watch it at any
  time at no cost.
- **Automatic pronunciation on the Genblaze page.** Every run now begins with
  the Genblaze CHAT pronunciation pass (always on here, regardless of the global
  toggle), respelling tricky terms before synthesis and showing exactly what it
  changed as the first step.
- **Download the sealed final audio.** A one-click download of the verified
  final deliverable — the way a real client receives it — streamed through the
  app's authenticated proxy so it works even from a private bucket, with a
  hash-stamped filename.

### Changed
- **Genblaze is the headline landing.** It's now the first nav item
  ("Genblaze Demo") and the page you land on after logging in when the runner is
  configured (falling back to the dashboard otherwise), so the flagship flow is
  front-and-center for evaluators.
- Added a storage note on the page explaining that the demo bucket is public for
  convenience, while the app is private-bucket-ready via the authenticated proxy.

### Fixed
- The nav no longer highlights both "Studio" and "Genblaze Demo" when viewing the
  Genblaze page — its route lives under `admin.studio.*` but now only its own tab
  lights up.

## [0.17.1] - 2026-06-30

### Fixed
- **Manually re-rolled chunks now get a junk tail auto-trimmed.** With ASR
  auto-remediation on, a manual re-roll still won't auto-re-roll (you asked for
  exactly one new take, so it never spends extra generation behind your back) —
  but a flagged `TAIL`/`TAILNOISE` (junk strictly after the speech) is now
  precise-trimmed on that take, the same lossless cut a first-generation chunk
  gets. Previously a manual re-roll skipped remediation entirely, so a long
  trailing artifact could survive.

## [0.17.0] - 2026-06-30

### Added
- **Seal a project's final and prove it later.** A ready project now has a
  **🔒 Seal as final** button that freezes the current stitched audio as the
  approved cut: it records who approved it, when, and the SHA-256 of the exact
  bytes, and snapshots those bytes to an immutable copy (so a later rebuild can't
  silently change what "approved" pointed at). A **✓ Sealed final** badge shows
  the approver, date, and short fingerprint, with a "Copy verify link". Any edit,
  rebuild, voice change, or reset automatically clears the seal so an "approved"
  claim can never outlive the audio it described.
- **Downloadable provenance receipt (.zip).** A sealed project can download a
  self-verifying receipt: the final audio, a human-readable `receipt.html`
  (per-chunk script text, **per-chunk voice**, seed, takes, QA, and source-audio
  hashes), a machine-readable `manifest.json`, and an offline verify page.
- **Drag-to-verify page** at `/verify` (and bundled in the receipt): drop the
  audio file and it confirms — entirely on your device, no upload, works offline
  from `file://` — whether it's the untouched approved final (✅) or has been
  edited (❌). The expected fingerprint travels in the link, never the request.
- **A built-in female default voice** (`voice_id` = `default-female`) ships
  alongside the existing default, so a fresh install offers both a neutral US
  male and female voice out of the box. Both are protected from deletion.

### Changed
- **Audio downloads are named by content fingerprint.** The final-audio and
  receipt downloads now include the first 8 characters of the audio's SHA-256 in
  the filename (e.g. `my-project-3f9a1c08.mp3`), so each distinct build downloads
  under its own name instead of the operating system appending ` (1) (2) …`. The
  tag is the same short code shown by the seal badge and verify page.
- **The default voice now ships with a real reference clip** instead of falling
  back to Chatterbox's native voice. The unconditioned native voice isn't anchored
  to a speaker, so it drifted between runs and could resemble a cloned voice. The
  built-in `default` is now a neutral US male voice with a bundled reference clip
  (from the VCTK corpus, CC BY 4.0 — see `CREDITS.md`), giving a consistent,
  distinct result. Existing audio generated under the old default isn't
  auto-updated — re-roll a chunk to adopt the new voice.

## [0.16.0] - 2026-06-29

### Added
- **Reset a leaked API key from the Dashboard.** The "Connect Bespoken" panel now
  has a **Reset** button beside the API key that issues a new secret and revokes
  the old one immediately (with a confirmation, since it breaks any client still
  using the old value). The rotation resolves the current user's key server-side,
  so it's per-user-safe — one user can't reset another's — and a legacy unowned
  key is claimed by the user when reset so future rotations stay owner-scoped.

### Fixed
- **Studio "Create project" no longer looks frozen.** Clicking **Create project**
  kicks off a synchronous request that normalizes the text and runs the LLM
  pronunciation check (up to ~a minute for a long article) before the review
  screen can render — with no feedback, the page read as an error. The button now
  disables and shows a spinner with honest step labels ("Normalizing text…" →
  "Checking pronunciations…") plus a "this can take up to a minute for long
  articles" note, so it's clear the app is working. Resets cleanly on back/forward
  navigation, and an empty form still falls through to native validation.

## [0.15.0] - 2026-06-28

### Added
- **Studio keeps every take.** Every render of a chunk — Generate, Re-roll,
  Preview, "Use this take", and ASR auto-remediation — is now saved as its own
  immutable clip in a new **"Takes & tuning"** panel below each chunk (renamed from
  "Tune this chunk"). Audition any prior take, **Select** the one that sounded best
  to make it the chunk's audio, or **Delete** the duds. Older takes are auto-pruned
  per chunk (config `tts.takes.keep` / `keep_preview`); the selected take is always
  kept, and previews are pruned harder than committed takes. Previously each render
  overwrote the single stored clip, so a better earlier take was lost forever.
  Existing projects backfill one "legacy" take per generated chunk on migrate.
- **Native Chatterbox knobs across the Studio.** The tuning controls (per-chunk
  panel, single-shot inspector, and A/B bench) now expose Chatterbox's own
  **Exaggeration** (0.25–2.0, neutral 0.5) and **CFG/Pace** (0.2–1.0) as
  slider + number box + reset (↺), matching the Hugging Face Chatterbox demo,
  instead of the abstract 0–1 Stability/Style fields and a derived readout.

### Changed
- The shared `VoiceSettingsResolver` and the Replicate provider now accept the
  native `exaggeration`/`cfg_weight` keys directly (native wins), falling back to
  deriving them from `stability`/`style`. The public `/v1` API is unchanged — it
  still speaks ElevenLabs-style `stability`/`style`, which the provider maps. Named
  tuning presets and saved voice defaults were migrated to the native knobs.

## [0.14.7] - 2026-06-28

### Added
- **Studio: "Use this take" keeps the exact clip you just previewed.** Auditioning
  a per-chunk tuning with **Preview** generated a clip that was then discarded —
  and because the voice model is non-deterministic even with a fixed seed,
  regenerating could never reproduce a good take. A new **✓ Use this take** button
  (next to Preview) saves the exact previewed audio as the chunk's audio, along
  with the stability/style it was previewed at, with no re-generation. It appears
  once you preview and retires itself the moment you change the text, tuning, or
  voice, so a kept clip always matches its settings.

### Changed
- **Per-chunk tuning fields now show what "inherit" resolves to.** The Stability
  and Style overrides displayed a bare `inherit` placeholder with no hint of the
  underlying value; they now show the inherited project setting (e.g.
  `inherit (0.50)`) and were widened to fit it. The Generate/Re-roll tooltips and
  the tuning help text were reworded to describe **Generate** as a render and
  **Re-roll** as simply "another take."

### Removed
- **"Seed" is no longer surfaced anywhere in the admin UI.** Because a fixed seed
  doesn't guarantee an identical take, exposing it promised a reproducibility the
  model can't deliver and added needless complexity. The seed inputs were removed
  from the Studio create form, the Studio inspector, and the voice create/edit
  forms, and the Seed column was dropped from the voices list. The underlying seed
  plumbing is unchanged (database, API, voice-manifest import); the voice edit form
  preserves any existing seed in a hidden field so saving doesn't wipe it.

### Fixed
- **A word ending in a soft "n"/"m"/"ng" no longer gets its last sound clipped.**
  When a chunk ended on a voiced nasal (e.g. "…with proof built in"), the tail
  cleanup mistook the quiet, low-pitched nasal release for trailing noise and
  trimmed into the real word — so the final word sounded cut off, most visibly
  where chunks were joined. The cleanup now recognises a short voiced word-ending
  as part of speech and keeps it, while still trimming genuine hums, drones, and
  hiss after the voice stops. The check is purely acoustic (pitch + loudness +
  length), so it isn't tied to any one language. Tunable via
  `TTS_CHUNK_TAIL_VOICED_CODA_MAX_MS` (default 300 ms). Affects newly generated
  audio; clips already saved before this fix need re-generating.

## [0.14.6] - 2026-06-26

### Fixed
- **Generated clips no longer lose their last word.** A single-chunk Studio or
  "Generate via Genblaze" run could ship a final MP3 with the last word chopped
  off mid-syllable at full volume (reported: a 2.52s clip cut to 1.59s, a 2.96s
  clip to 2.25s). The tail-artifact detector's voicing path decided where speech
  ended using a pitch check, then hard-cut any unvoiced run longer than ~400ms
  after it — but a genuine word-final unvoiced sound (a sustained *s*/*f*/*sh* or
  a soft, devoiced ending) has no pitch and routinely runs 600–900ms, so it was
  mistaken for an appended hiss tail and removed. Duration alone can't tell the
  two apart; loudness can — a real word ending tapers *off* the word (no louder
  than the speech before it), whereas a hiss/swoosh artifact is markedly *louder*.
  The voicing cut now only fires when the trailing run is at least 6 dB louder
  than the speech body (new `TTS_CHUNK_TAIL_VOICING_OVER_SPEECH_DB`, default 6.0),
  the same over-speech test the ASR tail-noise check already used. Genuine hiss
  tails are still trimmed; ordinary clips keep every word.

## [0.14.5] - 2026-06-26

### Added
- **The Genblaze panel now shows whether the provenance manifest verifies.** Every
  "Generate via Genblaze" run already wrote a SHA-256 provenance manifest to
  Backblaze B2; the runner now also calls Genblaze's `manifest.verify()` and
  returns the result, so the Studio panel renders a green **✓ verified · SHA-256**
  badge next to the manifest hash (red if it fails to verify, hidden if the runner
  can't compute it). The run's verifiability is now visible at a glance instead of
  implied by the hash alone.

## [0.14.4] - 2026-06-26

### Changed
- **`api_project_mode=always` now keeps the audio a successful `/v1` call already
  generated.** Previously every Studio project auto-created from a successful API
  generation opened empty — all chunks `pending`, the final marked `draft` —
  forcing a full regeneration of work the API had just done. A successful call now
  hands its result across intact: each synthesized segment is persisted as a
  `completed` chunk holding its raw audio (so per-chunk playback, editing, and
  re-roll work immediately), and the API's concatenated final file is carried over
  so the project opens **Ready** with the same audio the call returned. The chunks
  are the exact segments `/v1` read, so a later edit still re-rolls only that one
  chunk. The failure-recovery path (`api_project_mode=on_error`) is unchanged — a
  failed generation has no usable audio, so it still seeds a bare project to repair.

## [0.14.3] - 2026-06-26

### Added
- **Dismiss the "API failure" flag on a recovered project.** Projects auto-created
  from a failed `/v1` generation (`api_project_mode=on_error`) were permanently
  marked as failures — a red *"Recovered from a failed API generation"* banner on
  the project page and a red **API failure** badge on the Studio index — with no
  way to clear it once you'd dealt with the failure. The banner now has a
  **Dismiss** button that converts the project into a regular entry: the banner
  and badge disappear, the auto-pruning TTL is cleared (so it's no longer reaped),
  and the auto-generated `API failure: ` title prefix is stripped (a title you've
  renamed yourself is left untouched).

## [0.14.2] - 2026-06-26

### Changed
- **"Generate via Genblaze" now runs asynchronously.** The Studio button dispatches
  a queued job and the panel polls for the result, so a long multi-attempt run no
  longer holds an HTTP request open past the web server's read timeout (was
  surfacing as HTTP 502). Its provenance audio is also streamed back through the
  app (authenticated `s3` read), so takes play in-browser even when the Backblaze
  B2 bucket is **private**.

### Fixed
- **Cloned voices work again under S3/B2 storage.** Generating with a voice that
  has a reference clip (preview, `/v1`, Studio, *and* Genblaze) failed when
  `TTS_STORAGE_DISK=s3` — the clip was resolved with `Storage::disk('s3')->path()`,
  which isn't a real file, surfacing as "Preview failed" / "the runner returned
  HTTP 500". A voice's reference is now resolved to a real local path regardless
  of disk (a local clip is used in place; an S3 clip is cached down first), so
  clips uploaded before a switch to S3 keep working.

## [0.14.1] - 2026-06-26

### Fixed
- **S3-compatible object storage now works as the audio disk.** `TTS_STORAGE_DISK=s3`
  previously failed at runtime because the Flysystem S3 adapter wasn't bundled;
  `league/flysystem-aws-s3-v3` is now a dependency, so generated audio + voice
  clips can live in AWS S3, **Backblaze B2**, Cloudflare R2, MinIO, Wasabi, etc.
- **Backblaze B2 (and other ACL-less providers) accepted as the storage disk.**
  Laravel's S3 driver stamped a canned object ACL on every upload, which B2
  rejects ("Unsupported value for canned acl 'private'") — silently failing every
  write. The `s3` driver is re-registered to strip the ACL parameter. Also
  documents the previously-undocumented `AWS_ENDPOINT` (+ path-style) needed for
  non-AWS S3 providers, in `.env.example` and the deployment guide.

## [0.14.0] - 2026-06-26

### Added
- **Genblaze on Backblaze B2 — provenance-tracked, QA-gated audio generation.** A
  new Studio action, **"Generate via Genblaze"** (`/admin/studio/genblaze`), runs
  the whole pipeline through a Genblaze orchestrator: generate each chunk →
  **Whisper ASR scores it → re-roll a flagged chunk → stitch**, writing **every
  take (including rejected re-rolls) and a verifiable provenance manifest to B2**.
  The panel shows per-chunk attempts/scores, the B2 take URLs, the final MP3, and
  the manifest hash.
  - Ships the Genblaze-owned orchestrator (a Python "runner"), a
    `genblaze-bespoken` provider connector, and stateless `/v1/internal/*`
    pipeline primitives the runner calls.
  - The provenance bucket can stay **private** — reads are authenticated (SigV4).
  - New config: `TTS_GENBLAZE_RUNNER_URL`, `B2_*` (key/region/endpoint/bucket),
    `TTS_INTERNAL_SECRET`.
- **Pronunciation pre-processor — respell mispronounced terms before they reach
  the voice.** Before chunking, an LLM proposes plain-spelling respellings for
  likely-mispronounced terms (`DDEV` → "dee dev", `nginx` → "engine ex") on a
  review screen; approved terms are applied to the project text and saved to a
  per-writer dictionary that accumulates over time.
  - Detection runs as a **provider-agnostic Genblaze chat step** — swap the LLM
    between **Replicate** (default, Llama 4 Scout), **Anthropic** (Claude Haiku),
    **Gemini**, or **OpenAI** from the **Settings** page.
  - A new **Pronunciations** admin screen manages each writer's dictionary
    (add / edit / approve / delete). Dictionaries are **strictly per-user**.
  - New read API **`GET /v1/pronunciations`** (scoped to the calling key's owner)
    so the Bespoken Craft plugin can sync the lexicon and apply it upstream of any
    TTS backend.
  - New config: `TTS_PRONUNCIATION_ENABLED` (default **off**),
    `TTS_PRONUNCIATION_LLM_PROVIDER`, `TTS_PRONUNCIATION_MODEL`,
    `TTS_PRONUNCIATION_TEMPERATURE`, `TTS_PRONUNCIATION_TIMEOUT`, and
    `ANTHROPIC_API_KEY` / `GEMINI_API_KEY` / `OPENAI_API_KEY`. The whole feature
    degrades safely — a missing runner or LLM just skips straight to chunking and
    never blocks a generation.
- **API keys now have an owner.** A nullable `user_id` ties a key to a user and
  scopes the pronunciation dictionary it syncs — groundwork for multiple writers,
  each with their own lexicon and keys.

### Changed
- **Dev environment:** the Genblaze runner and Whisper ASR sidecars now run as
  **DDEV add-on services**, with auto-started `queue:listen` + `schedule:work`
  daemons — `ddev restart` builds and wires them.

### Fixed
- **Studio "Start over" restores your original text.** It previously re-opened the
  *respelled* text (e.g. "dee dev") instead of what you typed; the original
  submission is now preserved as the project source, with respellings applied only
  to the spoken/chunked text (and re-applied consistently on reset).
- **Genblaze/B2 robustness:** normalize `B2_REGION` when given in the
  `s3.<region>` endpoint-host form; authenticate B2 reads so the provenance bucket
  can be private; correct the off-the-shelf Genblaze `chat()` argument order and
  bundle the Gemini/OpenAI chat adapters.

## [0.13.0] - 2026-06-24

### Added
- **A failed (and, optionally, every) `/v1` generation can hand off to an editable
  Studio project.** A new setting — `tts.api_project_mode` (`never` | `on_error` |
  `always`, default `never`, on the admin **Settings** page) — controls it:
  - `on_error` turns a failed generation into a **recovery project**: the source
    text seeded as an editable project, badged "API failure" in the Studio list,
    with the provider's actual error (e.g. a Replicate CUDA assert) and the chunk
    that failed shown on the project page so an admin can fix that sentence and
    rebuild. The failure response also carries a `recovery_url` pointing at the
    project — a plain panel URL the admin opens after a normal login, never a
    credential.
  - `always` creates a project on every call; `never` keeps the API stateless.
  - Auto-created recovery projects carry a TTL and are pruned by a new scheduled
    command, `projects:prune-recovery` (daily), when never opened or worked on;
    `always`-mode and panel-made projects are kept.
- New config: `TTS_API_PROJECT_MODE` (default `never`) and `TTS_API_PROJECT_TTL_HOURS`
  (default `168` — 7 days).

## [0.12.3] - 2026-06-24

### Security
- **Hardened the ffmpeg/audio pipeline against the FFmpeg "PixelSmash" class of
  decoder flaws (CVE-2026-8461).** Three defense-in-depth changes:
  - **Uploaded reference clips are screened for video.** A new `ffprobe`-backed
    validation rule rejects a voice-reference upload that carries a real
    (non-cover-art) video stream — closing the container-overlap seam (m4a/mov are
    both ISO-BMFF; Ogg can carry Theora) that the `mimes:` rule alone can't.
    Ordinary embedded cover art still passes.
  - **Every ffmpeg call that opens an input file now passes `-vn`** (drop video),
    so a smuggled video stream can never be decoded to output — most importantly
    on the only untrusted path, reference-clip normalization.
  - **The health check enforces a minimum ffmpeg version.** `php artisan tts:doctor`
    and the admin **Health** page now **fail** when ffmpeg is older than **8.1.2**,
    the release that fixes the MagicYUV "PixelSmash" decoder bug. On a distro that
    backported the fix without bumping the version number, set
    `TTS_FFMPEG_MIN_VERSION` to the installed version to acknowledge it and clear
    the check.

### Added
- New config: `TTS_FFPROBE_PATH` (default `ffprobe`) and `TTS_FFMPEG_MIN_VERSION`
  (default `8.1.2`). `docs/DEPLOYMENT.md` and the DDEV ffmpeg config now document
  the ffmpeg ≥ 8.1.2 requirement.

### Fixed
- **Studio audio players now work in iOS Safari.** The per-chunk and final players
  (and the admin Health test player) serve audio with HTTP range support
  (`206 Partial Content` + `Accept-Ranges`), so iOS can determine the duration and
  seek — instead of showing "Live Broadcast" with a dead scrubber on the final MP3
  or failing to play a WAV chunk. Desktop playback and downloads are unchanged, and
  the `/v1` API path is untouched.
- **The admin navigation no longer overflows on phone-width screens.** The header
  stacks and the nav wraps below the `sm` breakpoint instead of clipping the
  right-hand links.

## [0.12.2] - 2026-06-22

### Added
- **ASR transcript QA enables itself when the sidecar is available.** The master
  switch still ships off, but the admin **Health** page and `php artisan tts:doctor`
  now turn it on automatically the first time they find the Whisper sidecar
  reachable, persisting the choice as an editable setting (the Health page shows a
  notice; `tts:doctor` prints a line). It is a one-shot that never overrides a switch
  pinned in `.env` or a choice saved in **Settings**, and it probes only on those
  admin surfaces — never on the generation path.

### Changed
- **The unattended API path now self-heals by default.** `TTS_ASR_API_ACTION`
  defaults to `auto` (previously it inherited the shared `TTS_ASR_ACTION`), so a
  flagged segment on the `/v1` + queued path is re-rolled/trimmed with no human in
  the loop. The interactive Studio still defaults to `log` — it inherits the shared
  action — so an admin triages flagged chunks by hand from the per-chunk ASR badge.
  Set `TTS_ASR_API_ACTION=log` to opt the API back into ship-as-is.
- **Default maximum automatic re-rolls raised from 2 to 3** (`TTS_ASR_MAX_REROLLS`),
  biasing toward re-rolling a suspect take rather than shipping it.

## [0.12.1] - 2026-06-22

### Added
- **In-Studio explanation of the ASR badges.** The Studio project page now explains
  the per-chunk ASR badges — expanding "ASR (Automatic Speech Recognition)" and
  describing each state (`TRUNC` / `TAIL` / `TAILNOISE` / `PAUSE` / `BNDNOISE`) — so the
  feature isn't unexplained on the page. Shown only when ASR is enabled.

### Changed
- **README + package metadata.** Documented the opt-in ASR quality check and the
  energy-aware `TAILNOISE` / `BNDNOISE` signals in the README, and replaced the
  Laravel-skeleton `composer.json` name/description/keywords with this project's own.

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

[Unreleased]: https://github.com/johnfmorton/mimic-tts/compare/v0.23.0...HEAD
[0.23.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.22.0...v0.23.0
[0.22.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.21.0...v0.22.0
[0.21.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.20.0...v0.21.0
[0.20.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.19.0...v0.20.0
[0.19.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.18.0...v0.19.0
[0.18.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.17.1...v0.18.0
[0.17.1]: https://github.com/johnfmorton/mimic-tts/compare/v0.17.0...v0.17.1
[0.17.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.16.0...v0.17.0
[0.16.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.15.0...v0.16.0
[0.15.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.14.7...v0.15.0
[0.14.7]: https://github.com/johnfmorton/mimic-tts/compare/v0.14.6...v0.14.7
[0.14.6]: https://github.com/johnfmorton/mimic-tts/compare/v0.14.5...v0.14.6
[0.14.5]: https://github.com/johnfmorton/mimic-tts/compare/v0.14.4...v0.14.5
[0.14.4]: https://github.com/johnfmorton/mimic-tts/compare/v0.14.3...v0.14.4
[0.14.3]: https://github.com/johnfmorton/mimic-tts/compare/v0.14.2...v0.14.3
[0.14.2]: https://github.com/johnfmorton/mimic-tts/compare/v0.14.1...v0.14.2
[0.14.1]: https://github.com/johnfmorton/mimic-tts/compare/v0.14.0...v0.14.1
[0.14.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.13.0...v0.14.0
[0.13.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.12.3...v0.13.0
[0.12.3]: https://github.com/johnfmorton/mimic-tts/compare/v0.12.2...v0.12.3
[0.12.2]: https://github.com/johnfmorton/mimic-tts/compare/v0.12.1...v0.12.2
[0.12.1]: https://github.com/johnfmorton/mimic-tts/compare/v0.12.0...v0.12.1
[0.12.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.11.0...v0.12.0
[0.11.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.10.0...v0.11.0
[0.10.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.9.3...v0.10.0
[0.9.3]: https://github.com/johnfmorton/mimic-tts/compare/v0.9.2...v0.9.3
[0.9.2]: https://github.com/johnfmorton/mimic-tts/compare/v0.9.1...v0.9.2
[0.9.1]: https://github.com/johnfmorton/mimic-tts/compare/v0.9.0...v0.9.1
[0.9.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.8.2...v0.9.0
[0.8.2]: https://github.com/johnfmorton/mimic-tts/compare/v0.8.1...v0.8.2
[0.8.1]: https://github.com/johnfmorton/mimic-tts/compare/v0.8.0...v0.8.1
[0.8.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.7.0...v0.8.0
[0.7.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.6.1...v0.7.0
[0.6.1]: https://github.com/johnfmorton/mimic-tts/compare/v0.6.0...v0.6.1
[0.6.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.4.1...v0.5.0
[0.4.1]: https://github.com/johnfmorton/mimic-tts/compare/v0.4.0...v0.4.1
[0.4.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/johnfmorton/mimic-tts/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/johnfmorton/mimic-tts/releases/tag/v0.1.0
