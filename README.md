# Alias TTS

**Your own self-hosted text-to-speech server with zero-shot voice cloning —
API-compatible with both ElevenLabs and OpenAI, so existing clients work by
changing only the base URL and API key.**

![CI](https://github.com/johnfmorton/alias-tts/actions/workflows/ci.yml/badge.svg)

This is a Laravel app that speaks two TTS dialects: the **ElevenLabs HTTP API**
(`POST /v1/text-to-speech/{voice_id}`) and the **OpenAI audio API**
(`POST /v1/audio/speech`). Point any client of either service at it and it
returns audio in your own cloned voice. The app handles auth, caching,
chunking, audio cleanup, storage, and a multi-user dashboard; the actual voice
generation is delegated to a pay-per-use GPU on [Replicate](https://replicate.com)
running **[Chatterbox](https://replicate.com/resemble-ai/chatterbox)** (MIT
licensed, zero-shot voice cloning from a short reference clip),
**[Chatterbox Turbo](https://replicate.com/resemble-ai/chatterbox-turbo)**
(faster, with built-in voices and `[laugh]`-style sound tags), or
**[Qwen3 TTS](https://replicate.com/qwen/qwen3-tts)** (Apache-2.0; ten
languages, its own built-in voices, cloning from ~3s of audio, and free-text
style notes instead of numeric knobs) — each voice picks its engine on its
edit page.

> [!NOTE]
> **New: Qwen3 TTS.** The newest engine in the catalog, and still in testing.
> Qwen3 voices plug into the same workflow as the Chatterbox engines — the
> same dashboard, Studio editing, caching, and quality checks — so trying it
> is a per-voice switch on the voice's edit page, not a different tool.

Full documentation lives in **[docs/](docs/README.md)** — setup, integration,
and internals, grouped by audience.

## Why run your own?

If your app talks to ElevenLabs or OpenAI for text-to-speech today, this gives
you an alternative where:

- **You own your voice and your data.** Reference clips and generated audio live
  on infrastructure you control (your server, or your own S3 bucket).
- **It's a true drop-in — in two dialects.** Same request/response shapes, auth
  headers, and error formats as ElevenLabs and OpenAI TTS; a client just needs a
  different base URL and key.
- **You pay per use, not per month.** Replicate bills per second of GPU time
  instead of a monthly character subscription. For low or bursty volumes that's
  often dramatically cheaper.
- **You're in control.** Choose your storage, set per-key rate limits, tune each
  voice's delivery by ear (e.g. `"voice_settings": { "stability": 0.8, "style":
  0.3 }` for a steadier, slightly more expressive read — higher `stability` =
  steadier pacing, higher `style` = more animated delivery), and opt into
  quality checks that automatically re-roll or trim flawed takes.

And you don't need to be a developer to get value out of it. The APIs are how
apps connect, but everything else lives in the dashboard: record yourself for
~20 seconds right in the browser to clone your voice, paste in a script, and
produce the finished piece in **Studio** — audition it sentence by sentence,
re-roll just the line that reads wrong, then download a polished MP3 along
with a receipt anyone can use to verify it's the approved take. A creator can
do all of that without ever seeing a line of JSON.

## How it works

```
ElevenLabs dialect                            OpenAI dialect
POST /v1/text-to-speech/{voice_id}            POST /v1/audio/speech
xi-api-key: sk_...                            Authorization: Bearer sk_...
{ text, model_id, voice_settings }            { input, voice, response_format }
          └──────────────────────┬──────────────────────┘
                                 ▼
              Laravel (auth, cache, chunk, ffmpeg)
                                 │ cache miss → Replicate/Chatterbox → WAV
                                 ▼ ffmpeg → MP3 (mono 44.1 kHz / 128 kbps)
                       ◀── audio bytes
```

Long text is split into short, sentence-aware chunks (Chatterbox is short-form),
generated in sequence, then joined with brief, controlled digital silence — each
chunk edge-trimmed with a short fade so the seams stay click-free — into one clean
MP3. For very long articles there's also an **async job** API so generation isn't
bound by the HTTP request timeout (see [The API](#the-api)).

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
correctly — or, for the same checks without the command line, open the dashboard's
**Health page** (`/admin/health`). It's a web view over the identical checks (with
a deep mode matching `--deep` for live token and queue probes) plus one-click
short/long test generations to confirm end-to-end audio works.

### A note on providers

Today the only real inference backend is **Replicate** (plus a `fake` driver
that returns silent audio for local development and tests), running the
model catalog (`config('tts.models')`): classic **resemble-ai/chatterbox**,
**resemble-ai/chatterbox-turbo**, and **qwen/qwen3-tts** (a recent addition,
still in testing), chosen per-voice, each with its own pinned version, tuning
knobs, and per-character rate. The
backend is a pluggable driver (`config('tts.provider')`); the dev-only
`local` driver runs the Chatterbox engines on an in-repo sidecar and routes
qwen voices to Replicate (see
[docs/CHATTERBOX-LOCAL.md](docs/CHATTERBOX-LOCAL.md)). Other providers
(Modal, Fal, an ElevenLabs pass-through) *could* be added without touching
the rest of the app — but none are, and whether to support them is still an
open question. For now, plan on Replicate.

## Quick start (local, DDEV)

```bash
ddev start
ddev artisan migrate
ddev npm install && ddev npm run build         # build the dashboard assets
ddev artisan admin:create admin@example.com    # dashboard login (prompts for a password)
ddev artisan apikey:create "me"                # or generate keys from the dashboard
ddev artisan voice:create "John" ./sample.wav --slug=john   # or add voices from the dashboard
```

A fresh install ships with **two bundled voices** — `default` (male) and
`default-female` — built from public-domain reference clips, so you can
generate audio before cloning a voice of your own.

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

In the ElevenLabs dialect:

```bash
curl -k -X POST https://tts.ddev.site/v1/text-to-speech/john \
  -H "xi-api-key: sk_..." -H "Content-Type: application/json" \
  -d '{"text":"Hello, this is my own voice.","model_id":"eleven_v3"}' \
  --output out.mp3
```

Or the OpenAI dialect — same engine, same voices, same key:

```bash
curl -k -X POST https://tts.ddev.site/v1/audio/speech \
  -H "Authorization: Bearer sk_..." -H "Content-Type: application/json" \
  -d '{"model":"tts-1","voice":"john","input":"Hello, this is my own voice."}' \
  --output out.mp3
```

## Production

The simplest install is the **single Docker image** — the whole service (web
app, queue worker, scheduler, ASR quality gate, Genblaze runner) in one
container with all state on one volume:

```bash
docker run -d -p 8080:80 -v alias-data:/data \
  -e REPLICATE_API_TOKEN=r8_xxx \
  -e ADMIN_EMAIL=you@example.com -e ADMIN_PASSWORD=a-strong-password \
  alias-tts
```

See **[docs/DOCKER.md](docs/DOCKER.md)** for the walkthrough (HTTPS, S3,
backups, tuning).

Running on your own host or Laravel Forge instead? See
**[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** for the full step-by-step guide,
including ffmpeg, building assets, **S3 storage**, the **queue worker** for
async generation, and the **scheduler/cron** for cleanup.

## The API

Two dialects, one engine. Both surfaces authenticate with the same Alias API
keys, resolve the same voices, share the same cache and audio pipeline, and
count against the same per-key rate limit.

### ElevenLabs dialect

Existing ElevenLabs clients work by swapping the base URL and key. `{voice_id}`
matches a Voice's **slug** first, then its UUID — set a slug equal to your
existing ElevenLabs `voice_id` for a true drop-in swap, or map real ElevenLabs
IDs to your voices with `elevenlabs_voice_aliases` in `config/tts.php` (the
full resolution procedure is in
[docs/VOICES.md](docs/VOICES.md#how-a-voice-id-resolves)).

**Auth:** `xi-api-key: <key>` header (also accepts `X-API-Key` / Bearer).
**Errors:** JSON `{"detail":{"message":"..."}}` (ElevenLabs shape).
**Caching:** identical requests (voice + text + settings + format + seed) are
served from cache; the response carries an `x-cache: HIT|MISS` header.

#### Synchronous

`POST /v1/text-to-speech/{voice_id}` (and `/stream`)

- **Body:** `text` (required); `model_id` (accepted — the configured provider
  decides the actual model); `voice_settings { stability, similarity_boost, style,
  use_speaker_boost }` (the full ElevenLabs object is accepted for drop-in
  compatibility, but with Chatterbox only `stability` and `style` currently affect
  output — they map to Chatterbox's `cfg_weight` and `exaggeration`; the others —
  and every EL knob on a Qwen3 TTS voice — are
  accepted and cached but inert); optional `output_format` (defaults to mono
  `mp3_44100_128`); optional `seed` (accepted, forwarded to the provider, and part
  of the cache key); optional `force_refresh`.
- **Success:** raw `audio/mpeg` bytes + a `request-id` header.

#### Asynchronous (long text)

For articles that would exceed the synchronous request timeout, submit a job and
poll. Requires a running queue worker.

- `POST /v1/text-to-speech/{voice_id}/jobs` → **202** `{ id, status, status_url, audio_url }`
  (or **200** if the result is already cached).
- `GET /v1/text-to-speech/jobs/{id}` → `{ id, status, progress, ... }`
  (`processing` → `completed` / `failed`). Scoped to the calling key; not
  rate-limited. While `processing`, `progress` reports the chunk loop live —
  `{ stage, chunks_total, chunks_done, percent, message }`, e.g. "Creating
  clip 25 of 50" — and is `null` otherwise.
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

### OpenAI dialect

`POST /v1/audio/speech` — an app written against OpenAI's TTS API works by
changing only the base URL and key. Authenticate with an **Alias** key via
`Authorization: Bearer`; errors use the OpenAI `{"error":{…}}` envelope.

The one semantic difference: `voice` is an **Alias voice slug**, not an OpenAI
preset (OpenAI has no custom voices to map to). For stock clients that only know
`alloy`/`nova`/etc., a config map (`openai_voice_aliases`) can alias the preset
names to your voices (see
[docs/VOICES.md](docs/VOICES.md#how-a-voice-id-resolves) for the full
resolution procedure). `model` is accepted and ignored; `response_format`
supports `mp3` and `wav` (`pcm` returns a WAV container); streaming,
`opus`/`aac`/`flac`, `speed`, and `instructions` are not supported. The full
support contract — exactly what's honored, accepted-but-ignored, and rejected —
is in **[docs/OPENAI-COMPAT.md](docs/OPENAI-COMPAT.md)**.

### Alias extensions

Beyond the two compatibility surfaces (and the async jobs above), two
authenticated endpoints exist for richer clients:

- `POST /v1/projects` — create an **editable Studio project** from text
  (normalize + chunk + persist, no generation), so a human can review and fix
  individual sentences in the dashboard before building the final audio.
- `GET /v1/pronunciations` — read the key owner's pronunciation dictionary
  (see [Pronunciation dictionary](#pronunciation-dictionary)).

## Dashboard

A password-protected control panel (at `/admin`; visiting `/` while signed in
redirects you there). It's **multi-user**: each account has its own **API
keys**, **voices**, **pronunciation dictionary**, and **settings** (like the
final audio format for projects), with the bundled default voices shared by
everyone. Add a voice by uploading a short reference clip **or recording one
right in the browser** (read one of the built-in prompts, review the take, and
submit) — it's normalized and registered instantly; Chatterbox is zero-shot, so
there's no training job. Clips are optionally **cleaned up** (denoise + enhance
via [resemble-enhance](https://github.com/resemble-ai/resemble-enhance) on
Replicate) with an Original-vs-Cleaned-up preview before saving; cleanup is on
by default and degrades safely to the original clip if it can't run (toggle with
`TTS_ENHANCE_ENABLED`). Then dial in its delivery on the voice's edit page
(**"Tune by ear"**: an A/B bench for comparing settings side by side, saveable
as named presets). The
home page shows copy-paste connection details (base URL, key, voice IDs) for
any ElevenLabs- or OpenAI-compatible client. Optional Google/GitHub sign-in is
available — see [docs/SSO-SETUP.md](docs/SSO-SETUP.md).

## Studio

**Studio** (`/admin/studio`) is a workbench for inspecting and tuning
the text-to-speech pipeline — useful for dialing in a voice, debugging chunk
seams, or fixing one sentence in a long piece without regenerating the whole file.

- **Inspector** — paste text and pick a voice to see exactly what a project made
  from it would read: cleaned and normalized, respelled by your approved
  pronunciation dictionary, and split into chunks (the *same* pipeline projects
  use, so this preview costs nothing). The breakdown includes an up-front
  **estimated cost** for rendering every chunk once — quoted at your account's
  rates, next to your remaining credit — and, when the pronunciation
  pre-processor is enabled, new LLM respelling suggestions you can add to your
  dictionary with one click. Then audition it: chunk-by-chunk (raw, untrimmed
  provider audio so any seam artifacts are audible) or **Preview final audio** —
  every chunk rendered and stitched exactly the way production builds the final
  file. Done inspecting? **Create a project** straight from the breakdown —
  chunks you already rendered carry over as real takes, so paid audio is never
  thrown away.
- **Projects** — saved, editable jobs. Create a project from text, edit or insert
  individual chunks, generate or regenerate a single chunk at a time, preview the
  stitch across a seam, then rebuild and download the final MP3 (or WAV, per your
  settings). Edited chunks are marked stale so you can see what needs
  regenerating. A chunk can also be **skipped** (🔇) — left out of the stitched
  final without deleting it; its text and takes stay, and the receipt lists it
  as skipped. (Chunk reordering isn't supported yet.)
  **Generate remaining** renders every outstanding chunk as a **background run**
  on the queue worker — it keeps going if you leave the page, the project page
  follows along live (with a **Stop**), and the **Jobs** page (`/admin/jobs`)
  lists every run with progress, failures, and a Stop of its own.
- **Approve & seal** — mark a build as the approved final and **seal** it: the
  project records a SHA-256 of the exact audio bytes plus a frozen snapshot, and
  you can download a receipt `.zip` (the audio, a human-readable `receipt.html`
  provenance record with the per-chunk script, and a machine-readable
  `manifest.json`). The receipt links to the public **`/verify`** page, where
  anyone can confirm a file is the untouched approved final — the browser
  fingerprints it locally with SHA-256, so **the file itself is never uploaded**;
  only the 64-character hash is sent and matched against the sealed approval. A
  `?sha=…` link opens the record for a known fingerprint directly. (Uploads are
  off by default; enable a server-side hashing fallback for non-secure-context
  browsers with `TTS_VERIFY_ALLOW_UPLOAD=true`.)
- **Generate via Genblaze** — hand a whole render to the Genblaze runner,
  which orchestrates chunk → generate → QA-score → re-roll → stitch and
  archives every take plus a verifiable provenance manifest to a Backblaze B2
  bucket. The runner is a companion sidecar every full install should set
  up — see **[docs/GENBLAZE-SETUP.md](docs/GENBLAZE-SETUP.md)**.

## Pronunciation dictionary

TTS models mangle certain words — initialisms, product names, unusual proper
nouns. An **opt-in, LLM-assisted pre-processor** catches them before
generation: creating a project adds a review step that suggests phonetic
respellings ("DDEV" → "dee dev"), you approve or edit each suggestion, and
approved entries are saved to your per-user dictionary and reapplied
automatically to all future text. Clients can read the dictionary via
`GET /v1/pronunciations`. It's off by default (`TTS_PRONUNCIATION_ENABLED`),
toggleable per user on the Settings page, and degrades safely — if the LLM is
unreachable, generation continues without suggestions. See
**[docs/ALIAS-PRONUNCIATION-PREPROCESSOR.md](docs/ALIAS-PRONUNCIATION-PREPROCESSOR.md)**.

## Audio quality checks (QA)

Chatterbox occasionally produces a flawed take — it stops short of the script,
trails off into a junk/"singing" tail, or hums at a sentence boundary. The DSP
edge-trim handles obvious tails; for the rest there's an **opt-in quality
check** (shown as "QA" in the panel) that transcribes each generated chunk with
a local Whisper sidecar and compares it to the source text, flagging:

- **TRUNC** — the take didn't reach the end of the script (missing content).
- **TAIL** / **TAILNOISE** — audio after the last word: a long junk/"singing" tail,
  or a short-but-**loud** "swoosh" that's louder than the speech itself (the energy
  gate ignores a soft word-ending so it won't clip the last word).
- **PAUSE** / **BNDNOISE** — a gap mid-stream: a long silent pause, or a tonal
  **hum** filling a sentence/comma boundary that's too short to read as a pause.

`TAILNOISE` and `BNDNOISE` are **energy-aware** signals: they measure the actual
loudness (and, for the hum, the low-frequency character) in those zones, catching
*short-but-loud* defects the duration-based checks miss. Flagged chunks are either
**logged** (shown as a QA badge in Studio) or **auto-remediated** — re-rolled with
a fresh take for missing content / boundary hums, or precisely trimmed for a junk
tail. It's **off by default** and degrades safely: if the sidecar is unreachable,
generation is unaffected. See **[docs/ASR-SETUP.md](docs/ASR-SETUP.md)** to set it up.

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
php artisan voice:export <slug> [--output=path]        # -> <slug>.alias-voice.zip
php artisan voice:import <file.zip>

# Maintenance
php artisan speech:cleanup [--dry-run] [--before=<date>]   # delete expired audio (local or S3)
                           [--orphans] [--orphan-age=24]   # also sweep files no DB row references
php artisan tts:doctor [--deep]                            # verify the install is set up correctly
```

(Prefix with `ddev artisan` for local DDEV development.)

### Reference audio normalization

By default `voice:create` auto-cleans the reference clip on registration —
**downmix to mono, trim leading/trailing silence, loudness-normalize, and cap the
true peak so it can never clip** — for consistent clone quality. The ideal source
is a clean ~15–20s sample of natural speech in a quiet room.

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
calling `schedule:run`. Pass **`--orphans`** to also sweep files under the speech
storage path that no database row references (crash leftovers); it never leaves
that path, so voices, avatars, and anything else on the disk are untouched, and
files younger than `--orphan-age` hours (default 24) are spared so an in-flight
generation is safe. Preview any of it with `--dry-run`. Use **`tts:doctor`** to
confirm ffmpeg, storage, the provider/token, the queue, and the scheduler are all
healthy. See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for the details.

## Using it with Craft CMS

The **[Bespoken plugin](https://github.com/johnfmorton/craft-bespoken)** for
Craft CMS speaks the ElevenLabs dialect and understands the Alias extensions
(async jobs, projects, pronunciation sync), so it works with Alias out of the
box: set the plugin's base URL to your domain, the API key to an Alias key, and
a `voice_id` that matches a registered voice slug. See
**[docs/BESPOKEN.md](docs/BESPOKEN.md)** for the full step-by-step guide.

## Tests

```bash
ddev artisan test           # uses the fake provider + real ffmpeg
```

## Roadmap

See **[ROADMAP.md](ROADMAP.md)** for what's not yet built (e.g. a `/v1/voices`
management API, real streaming, additional provider drivers, a Docker image).

## License

Copyright © 2026 John F. Morton. All rights reserved.

This is proprietary software — not open source (for now). See
[LICENSE](LICENSE) for details.
