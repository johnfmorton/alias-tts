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

## Accounts

- **Self-service (email-based) password reset.** Today recovery is
  human-mediated by design: a SuperAdmin issues a signed set-password link from
  the Users page, the CLI `admin:create` covers a locked-out sole admin, and the
  sign-in page points a stranded user at the instance's contact
  (`TTS_SUPPORT_EMAIL`, pre-drafted email). A self-service flow would be the
  app's **first mail dependency**, so it should land as one opt-in package:
  - **Strictly opt-in.** The "Forgot password?" link, routes, and reset mailable
    (Laravel's password broker) appear only when mail is actually configured —
    an unconfigured install keeps today's behavior with zero new setup burden.
  - **Doctor + Health coverage ships with it, not after.** A mail check in
    `tts:doctor` (transport configured/reachable) and a one-click "send a test
    email" probe on the Health page, so a broken SMTP setup is caught before
    the first user needs a reset.
  - **2FA interaction.** A reset must not bypass the second factor: a
    2FA-enabled account still faces the challenge after a reset; invalidate the
    user's other sessions on completion.
  - **No user enumeration.** Identical response whether or not the address has
    an account; throttle requests per email and per IP.
  - **Reuse the transport for invites.** The Users page's invite / force-reset
    actions gain an optional "email this link" alongside copy-to-clipboard.
  - **Docs.** A MAIL-SETUP section in DEPLOYMENT.md; the `.env.example` MAIL_*
    comment flips from "unused" to "optional — enables self-service reset".

## Operations

- **`speech:stats` / `speech:list`** console commands for visibility into usage
  and stored audio.
- ~~**Orphan-file sweep** for `speech:cleanup`~~ — done: `speech:cleanup
  --orphans` removes unreferenced files under the speech storage path, with an
  age guard for in-flight generations (and `TTS_STORAGE_ROOT` scopes an install
  to a bucket subfolder so shared buckets stay safe).
- **Cost guardrails** — optional per-key monthly character caps on top of the
  existing hourly `rate_limit` and `tts.max_text_length`.

## Packaging

- **Docker image** for non-Forge / non-DDEV users (app + ffmpeg), so a fresh
  install is a single `docker run`.
