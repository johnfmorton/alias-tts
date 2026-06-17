# NEXT_STEPS

Where this project stands and exactly what to do next. Phase 1 (the
ElevenLabs-compatible API + caching + ffmpeg + fake provider) is **built and
verified**. Everything below takes it from "works with silent placeholder audio"
to "generates my real cloned voice" and then to production.

---

## Current status ✅

- Laravel 13 app at `https://tts.ddev.site` (DDEV).
- `POST /v1/text-to-speech/{voice_id}` — `xi-api-key` auth, MP3 out, EL-shaped
  JSON errors, `request-id` header, text+voice+settings caching.
- Pluggable backend: `fake` (default locally) and `replicate` (Chatterbox).
- Console: `apikey:create|list`, `voice:create|list`.
- 9 tests passing; live HTTP smoke test confirmed valid MP3 + cache HIT.

---

## 1. Enable real voice generation (Replicate) — do this first

- [ ] **Create a Replicate account + API token:** https://replicate.com/account/api-tokens
- [x] **Schema confirmed + wired in.** Model `resemble-ai/chatterbox`; text field
      `prompt`; reference field `audio_prompt` (sent as a base64 data URI); version
      pinned to `1b8422bc…f94e97`. The `voice_settings → Chatterbox` mapping
      (stability → cfg_weight [0.2–1], style → exaggeration [0.25–2]) already
      respects the model's documented bounds.
- [ ] **Add your token + switch the provider** in `.env`, then `ddev artisan config:clear`:
  ```env
  TTS_PROVIDER=replicate
  REPLICATE_API_TOKEN=r8_...   # the only value you still need to fill in
  ```
- [ ] **Register your voice** from a clean ~10–30s clip (reuse the audio behind
      your ElevenLabs clone). Set the slug to your existing ElevenLabs `voice_id`
      so clients only swap the base URL:
  ```bash
  ddev artisan voice:create "John" ./john-sample.wav --slug=<your-elevenlabs-voice-id>
  ```
- [ ] **Generate for real:**
  ```bash
  ddev artisan apikey:create "me"      # grab sk_...
  curl -k -X POST https://tts.ddev.site/v1/text-to-speech/<slug> \
    -H "xi-api-key: sk_..." -H "Content-Type: application/json" \
    -d '{"text":"Hello, this is my own voice.","model_id":"eleven_v3"}' \
    --output out.mp3 && open out.mp3
  ```
- [ ] **Tune the voice settings → Chatterbox mapping by ear.** In
      `app/Services/Tts/ReplicateChatterboxProvider.php`, `stability` maps to
      `cfg_weight` and `style` to `exaggeration` — adjust until it matches the
      ElevenLabs result you like. Consider tuning `temperature` too.

> Tip: keep `TTS_PROVIDER=fake` while developing unrelated features so you don't
> spend Replicate credits; flip to `replicate` only when testing real audio.

---

## 2. Wire up the Bespoken Craft plugin

- [ ] In the plugin's (upcoming) settings, set the configurable ElevenLabs base
      URL to `https://<your-tts-domain>` (it calls `…/v1/text-to-speech/{voiceId}`).
- [ ] Set the plugin's `xi-api-key` to a key from `apikey:create`.
- [ ] Use a `voiceId` in the plugin that matches a registered voice **slug**.
- [ ] Generate a long entry to exercise the plugin's chunking + `.mp3`
      concatenation against this service (output is fixed mono 44.1 kHz/128 kbps,
      which is what makes concatenation clean).
- [ ] Confirm a deliberately bad request surfaces a readable error (the service
      returns `{"detail":{"message":…}}`, which the plugin reads).

---

## 3. Deploy to production (Laravel Forge)

- [ ] New PHP 8.3 site on the existing server; deploy the repo.
- [ ] `composer install --no-dev`, `php artisan migrate --force`,
      `php artisan config:cache route:cache`.
- [ ] **Install ffmpeg on the server:** `sudo apt install ffmpeg` (or a Forge
      recipe). Verify with `ffmpeg -version`.
- [ ] Production `.env`: `TTS_PROVIDER=replicate`, `REPLICATE_API_TOKEN`,
      `APP_ENV=production`, `APP_DEBUG=false`, real `APP_KEY`, DB, and storage
      (`TTS_STORAGE_DISK=local` or `s3` + AWS creds).
- [ ] **Raise timeouts** for this site so a cold-start generation isn't cut off:
      PHP `max_execution_time` and nginx `fastcgi_read_timeout` to ~120s.
- [ ] Pick a storage decision: `local` disk (simplest) vs `s3` (matches
      `screenshot-service` prod; survives redeploys/multiple servers).
- [ ] Create production API key(s) and register the voice on the server.
- [ ] Smoke test the live domain with the curl above.

---

## 4. Phase 2 — robustness (when needed)

- [ ] `/v1/voices` management API (`GET /v1/voices`, `GET /v1/voices/{id}`,
      `POST /v1/voices/add` multipart, `DELETE`) — not required by the Bespoken
      plugin, but handy and completes EL compatibility.
- [ ] TTL cleanup: a `speech:cleanup` command + a scheduler entry
      (`routes/console.php`, daily) deleting expired audio (mirror
      `screenshot-service`'s `ScreenshotCleanup`). Enable Forge's scheduler.
- [ ] `speech:stats` / `speech:list` console commands.
- [ ] Optional async path for very long text: a queued `GenerateSpeech` job +
      webhook (mirror `CaptureScreenshot`/`SendWebhook`); needs a Forge queue
      worker daemon. The synchronous EL path stays the default.
- [ ] Cost guardrails: confirm per-key `rate_limit` and `tts.max_text_length`
      suit your usage; consider a monthly character cap.

---

## 5. Phase 3 — polish (optional)

- [ ] Slim admin panel (API keys, voices, generation history) from
      `screenshot-service`'s `Admin/*` + `routes/admin.php`.
- [ ] Extra provider drivers: `ModalProvider` / `FalProvider` (lower latency or
      self-managed container), a `LocalProvider`, and an `ElevenLabsProvider`
      pass-through for A/B comparison and fallback.
- [ ] Real streaming for `/v1/text-to-speech/{voice_id}/stream` (currently an
      alias that returns the full audio).

---

## 6. Toward public release (companion to a future Bespoken)

Goal: ship this as an open, self-hostable ElevenLabs-compatible TTS server that
pairs with the Bespoken Craft plugin.

- [ ] **LICENSE** — add one (MIT fits; Chatterbox is MIT and Laravel is too).
- [ ] **Generic onboarding** — don't assume DDEV. Document plain setup
      (`composer install`, `.env`, `php artisan migrate`, ffmpeg) alongside the
      DDEV path; add a one-command bootstrap (Make target or `app:install`).
- [ ] **No personal data committed** — `.env` is gitignored; keep `.env.example`
      generic (no tokens/voices). Ship with `TTS_PROVIDER=fake` so a fresh clone
      runs with zero setup, and document switching to `replicate`.
- [ ] **Bespoken integration doc** — a dedicated page showing exactly how to set
      the plugin's base URL, key, and voice_id against this server.
- [ ] **Provider docs** — explain the pluggable driver, the pinned Chatterbox
      version, and how to swap to Modal/Fal/local or point at real ElevenLabs.
- [ ] **CI** — GitHub Actions running `pint --test` + the test suite (the
      ffmpeg-dependent tests need ffmpeg installed in the runner).
- [ ] **Security defaults** — `APP_DEBUG=false` in prod, rate limiting guidance,
      and optional CORS if anyone calls it from a browser.
- [ ] **Versioned releases** — tag releases; a CHANGELOG; clear PHP/ffmpeg
      requirements in the README.
- [ ] Decide repo/name and whether to publish a Docker image for non-Forge users.

---

## Open questions to resolve

- [ ] Exact Replicate Chatterbox model slug + input field names (see §1).
- [ ] Reference-audio delivery to the model: base64 data URI vs public URL.
- [ ] `voice_settings → Chatterbox` parameter mapping (tune by ear).
- [ ] Storage backend for production: `local` vs `s3`.
- [ ] Is Replicate the right host long-term, or move to Modal/Fal for latency or
      to self-hosted GPU if volume grows? (Driver swap, not a rewrite.)

---

## Handy commands

```bash
ddev artisan test                  # fake provider + real ffmpeg
ddev artisan apikey:create "name"  # --rate-limit=N  (requests/hour)
ddev artisan apikey:list
ddev artisan voice:create "Name" ./clip.wav --slug=my-id
ddev artisan voice:list
ddev exec ./vendor/bin/pint        # format to Laravel style
```
