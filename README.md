# Bespoken TTS Service

**Your own self-hosted, ElevenLabs-compatible text-to-speech server — a drop-in
voice backend for the [Bespoken Craft CMS plugin](https://github.com/johnfmorton/craft-bespoken).**

![CI](https://github.com/johnfmorton/bespoken-tts-service/actions/workflows/ci.yml/badge.svg)

This is a small Laravel app that speaks the ElevenLabs HTTP API. Point the
Bespoken plugin (or any ElevenLabs client) at it by changing only the **base URL**
and **API key**, and it returns MP3 audio in your own cloned voice. It handles
auth, caching, chunking, storage, and an admin dashboard; the actual voice
generation is delegated to a pay-per-use GPU on [Replicate](https://replicate.com)
running **[Chatterbox](https://replicate.com/resemble-ai/chatterbox)** (MIT
licensed, zero-shot voice cloning from a short reference clip).

## Why run your own?

If you use the Bespoken plugin with ElevenLabs today, this gives you an
alternative where:

- **You own your voice and your data.** Reference clips and generated audio live
  on infrastructure you control (your server, or your own S3 bucket).
- **It's a true drop-in.** Same ElevenLabs request/response shape, `xi-api-key`
  auth, and error format — the plugin just needs a different base URL and key.
- **You pay per use, not per month.** Replicate bills per second of GPU time
  instead of a monthly character subscription. For low or bursty volumes that's
  often dramatically cheaper.
- **You're in control.** Pin a seed for reproducible output, choose your storage,
  set per-key rate limits, and tune the voice to taste.

## How it works

```
client ──POST /v1/text-to-speech/{voice_id}──▶ Laravel (auth, cache, chunk, ffmpeg)
        xi-api-key: sk_...                          │ cache miss → Replicate/Chatterbox → WAV
        { text, model_id, voice_settings }          ▼ ffmpeg → MP3 (mono 44.1 kHz / 128 kbps)
                                              ◀── audio/mpeg bytes
```

Long text is split into short, sentence-aware chunks (Chatterbox is short-form),
generated in sequence, and crossfade-concatenated into one clean MP3. For very
long articles there's also an **async job** API so generation isn't bound by the
HTTP request timeout (see [The API](#the-api)).

## Requirements

> [!IMPORTANT]
> **You need your own Replicate account with credit.** Generation runs on
> Replicate's GPUs, billed per use. Create an account and an API token at
> <https://replicate.com/account/api-tokens>, then **add credit / a payment
> method**. Note: while a Replicate account has **less than $5.00 in credit**, it
> is rate-limited to **6 predictions/minute, burst 1**, which makes long articles
> slow — keep the balance funded for full speed.

- **Replicate** account + API token + credit (above).
- **ffmpeg** on the host (audio conversion + concatenation).
- **PHP 8.3+** and a database (SQLite/MySQL/Postgres).
- **Node 20+** to build the dashboard assets.
- For production: a **queue worker** (for async generation) and a **cron** running
  the scheduler (for automatic cleanup) — see [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

Run **`php artisan tts:doctor`** at any time to verify all of the above are set up
correctly.

### A note on providers

Today the only real inference backend is **Replicate / Chatterbox** (plus a
`fake` driver that returns silent audio for local development and tests). The
backend is a pluggable driver (`config('tts.provider')`), so other providers
(Modal, Fal, a local GPU, or an ElevenLabs pass-through) *could* be added without
touching the rest of the app — but **none are implemented yet**, and whether to
support them is still an open question. For now, plan on Replicate.

## Quick start (local, DDEV)

```bash
ddev start
ddev artisan migrate
ddev npm install && ddev npm run build         # build the dashboard assets
ddev artisan admin:create admin@example.com    # dashboard login (prompts for a password)
ddev artisan apikey:create "me"                # or generate keys from the dashboard
ddev artisan voice:create "John" ./sample.wav --slug=john   # or add voices from the dashboard
```

The local `.env` defaults to `TTS_PROVIDER=fake`, so the app runs with **zero
setup** and returns silent MP3s. Switch to real generation by setting:

```env
TTS_PROVIDER=replicate
REPLICATE_API_TOKEN=r8_...
```

Then verify everything is wired up:

```bash
ddev artisan tts:doctor --deep      # --deep also validates your Replicate token live
```

### Try it

```bash
curl -k -X POST https://tts.ddev.site/v1/text-to-speech/john \
  -H "xi-api-key: sk_..." -H "Content-Type: application/json" \
  -d '{"text":"Hello, this is my own voice.","model_id":"eleven_v3"}' \
  --output out.mp3
```

## Production

See **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** for the full step-by-step guide
(Laravel Forge + generic hosts), including ffmpeg, building assets, **S3 storage**,
the **queue worker** for async generation, and the **scheduler/cron** for cleanup.

## The API

The service is ElevenLabs-compatible, so existing clients work by swapping the
base URL and key. `{voice_id}` matches a Voice's **slug** first, then its UUID —
set a slug equal to your existing ElevenLabs `voice_id` for a true drop-in swap.

**Auth:** `xi-api-key: <key>` header (also accepts `X-API-Key` / Bearer).
**Errors:** JSON `{"detail":{"message":"..."}}` (ElevenLabs shape).
**Caching:** identical requests (voice + text + settings + format + seed) are
served from cache; the response carries an `x-cache: HIT|MISS` header.

### Synchronous

`POST /v1/text-to-speech/{voice_id}` (and `/stream`)

- **Body:** `text` (required); `model_id` (accepted — the configured provider
  decides the actual model); `voice_settings { stability, similarity_boost, style,
  use_speaker_boost }`; optional `output_format` (defaults to mono `mp3_44100_128`);
  optional `seed` (pin an integer for reproducible output); optional `force_refresh`.
- **Success:** raw `audio/mpeg` bytes + a `request-id` header.

### Asynchronous (long text)

For articles that would exceed the synchronous request timeout, submit a job and
poll. Requires a running queue worker.

- `POST /v1/text-to-speech/{voice_id}/jobs` → **202** `{ id, status, status_url, audio_url }`
  (or **200** if the result is already cached).
- `GET /v1/text-to-speech/jobs/{id}` → `{ id, status, ... }` (`processing` →
  `completed` / `failed`). Scoped to the calling key; not rate-limited.
- `GET /v1/text-to-speech/jobs/{id}/audio` → the MP3 once `completed` (409 while
  still processing).

**Text length.** The async endpoint accepts up to `TTS_MAX_ASYNC_TEXT_LENGTH`
characters (default **40,000** — roughly 6,000–7,000 words, or about **40–50
minutes of spoken audio**), independent of the synchronous `TTS_MAX_TEXT_LENGTH`
(5,000). The async cap is higher because generation runs in a background worker
bounded by `TTS_ASYNC_TIMEOUT` (default 1,800s), not the ~300s synchronous
request budget. Text is split at `TTS_CHUNK_CHARS` and **each chunk is a separate,
rate-limited provider call**, so ~40,000 characters is ~140 sequential
predictions — which fits comfortably inside the 30-minute job timeout. You can
raise the cap for longer single submissions (e.g. whole chapters), but only
**together with `TTS_ASYNC_TIMEOUT`** (and your provider's throughput): push the
character limit up without giving the job more time and long jobs will hit the
worker timeout and fail. Lower it to fail fast and bound the cost of any one
request. Over the limit, the endpoint returns an ElevenLabs-shaped 422.

## Dashboard

A password-protected control panel (at `/`) lets you **generate API keys**,
**manage and "train" voices** (upload a reference clip — it's normalized and
registered instantly; Chatterbox is zero-shot, so there's no training job), and
**test a voice**. Its home page shows copy-paste connection details (base URL,
key, voice IDs) for the Bespoken plugin.

## Commands

```bash
# Dashboard admin
php artisan admin:create <email> [--password=] [--name=Admin]

# API keys
php artisan apikey:create "<name>" [--rate-limit=N]    # N = requests/hour
php artisan apikey:list

# Voices
php artisan voice:create "<Name>" [audio] [--slug=] [--raw] [--seed=]
php artisan voice:list
php artisan voice:export <slug> [--output=path]        # -> <slug>.bespoken-voice.zip
php artisan voice:import <file.zip>

# Maintenance
php artisan speech:cleanup [--dry-run] [--before=<date>]   # delete expired audio (local or S3)
php artisan tts:doctor [--deep]                            # verify the install is set up correctly
```

(Prefix with `ddev artisan` for local DDEV development.)

### Reference audio normalization

By default `voice:create` auto-cleans the reference clip on registration —
**downmix to mono, trim leading/trailing silence, loudness-normalize, and cap the
true peak so it can never clip** — for consistent clone quality. The ideal source
is a clean ~15–30s sample of natural speech in a quiet room.

- Pass **`--raw`** to skip normalization and store the clip exactly as provided.
- Disable globally with `TTS_NORMALIZE_REFERENCE=false`; tune with
  `TTS_REFERENCE_LOUDNESS` (LUFS) and `TTS_REFERENCE_TRUE_PEAK` (dBTP).

### Moving voices between environments

A voice is a database row plus its reference clip — neither is in git. **Export**
it to a portable `.zip` and **import** it on another install (e.g. local →
production), from the dashboard's Voices page or via `voice:export` /
`voice:import`.

## Maintenance

Generated audio expires after `TTS_TTL_HOURS` (default 30 days). `speech:cleanup`
deletes expired rows and their files from the configured disk (local **or S3**),
and is scheduled to run daily — so in production you only need the OS **cron**
calling `schedule:run`. Use **`tts:doctor`** to confirm ffmpeg, storage, the
provider/token, the queue, and the scheduler are all healthy. See
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for the details.

## Bespoken Craft plugin

Point the plugin at this service: set its **Bespoken TTS service** base URL to
your domain, the `xi-api-key` to an `apikey:create` key, and a `voice_id` that
matches a registered voice slug. See **[docs/BESPOKEN.md](docs/BESPOKEN.md)** for
the full step-by-step integration guide.

## Tests

```bash
ddev artisan test           # uses the fake provider + real ffmpeg
```

## Roadmap

See **[ROADMAP.md](ROADMAP.md)** for what's not yet built (e.g. a `/v1/voices`
management API, real streaming, additional provider drivers, a Docker image).

## License

[MIT](LICENSE) © John F. Morton
