# tts — self-hosted, ElevenLabs-compatible TTS API

A personal text-to-speech service that mimics the ElevenLabs HTTP API so existing
clients (e.g. the Bespoken Craft plugin) work by changing only the base URL and
key. A Laravel app on your Forge server handles auth, caching, storage, and the
EL-compatible API; the actual voice generation is delegated to a pay-per-use
serverless GPU (Replicate, running **Chatterbox** — MIT-licensed, zero-shot voice
cloning from a short reference clip).

```
client ──POST /v1/text-to-speech/{voice_id}──▶ Laravel (auth, cache, ffmpeg)
        xi-api-key: sk_...                          │ miss → Replicate/Chatterbox → WAV
        { text, model_id, voice_settings }          ▼ ffmpeg → MP3
                                              ◀── audio/mpeg bytes
```

The inference backend is a pluggable driver (`config('tts.provider')`):
`replicate` (default) or `fake` (silent audio, for local/tests). Modal/Fal/local
drivers can be added later without touching the rest of the app.

## Local development (DDEV)

```bash
ddev start
ddev artisan migrate
ddev artisan apikey:create "me"             # prints sk_... (use in xi-api-key header)
ddev artisan voice:create "John" ./sample.wav --slug=john   # register a voice
```

The local `.env` defaults to `TTS_PROVIDER=fake`, so the app runs without a
Replicate token (it returns silent MP3s). Switch to real generation by setting:

```env
TTS_PROVIDER=replicate
REPLICATE_API_TOKEN=r8_...
```

### Try it

```bash
curl -k -X POST https://tts.ddev.site/v1/text-to-speech/john \
  -H "xi-api-key: sk_..." -H "Content-Type: application/json" \
  -d '{"text":"Hello, this is my own voice.","model_id":"eleven_v3"}' \
  --output out.mp3
```

## API

`POST /v1/text-to-speech/{voice_id}` (and `/stream`)

- **Auth:** `xi-api-key: <key>` header (also accepts `X-API-Key` / Bearer).
- **Body:** `text` (required), `model_id` (accepted; the configured provider
  decides the actual model), `voice_settings { stability, similarity_boost,
  style, use_speaker_boost }`, optional `output_format` (defaults to a fixed
  mono `mp3_44100_128`), optional `seed` (pin an integer for reproducible
  output — by default the backend uses a random seed each call), optional
  `force_refresh`.
- **Success:** raw audio bytes (`audio/mpeg` by default) + `request-id` header.
- **Error:** JSON `{"detail":{"message": "..."}}` (ElevenLabs shape).
- Identical requests are cached (keyed on voice+text+settings+format).

`{voice_id}` matches a Voice's `slug` first, then its UUID — set a slug equal to
your existing ElevenLabs `voice_id` for a true drop-in swap.

## Management commands

```bash
ddev artisan apikey:create "name" [--rate-limit=N]      # N = requests/hour
ddev artisan apikey:list
ddev artisan voice:create "Name" [audio] [--slug=] [--raw]
ddev artisan voice:list
```

### Reference audio normalization

By default, `voice:create` auto-cleans the reference clip on registration —
**downmix to mono, trim leading/trailing silence, loudness-normalize, and cap
the true peak so it can never clip**. This makes clone quality consistent
regardless of how the clip was recorded. The ideal source is a clean ~15–30s
sample of natural speech in a quiet room.

- Pass **`--raw`** to skip normalization and store the clip exactly as provided
  (e.g. if you've already mastered it yourself).
- Disable it globally with `TTS_NORMALIZE_REFERENCE=false`; tune the target with
  `TTS_REFERENCE_LOUDNESS` (LUFS) and `TTS_REFERENCE_TRUE_PEAK` (dBTP).

## Bespoken Craft plugin

Point the plugin's (upcoming) configurable ElevenLabs base URL at this service,
set the `xi-api-key` to an `apikey:create` key, and use a `voice_id` that matches
a registered voice slug. The plugin sends no `output_format` and concatenates
`.mp3` chunks, which is why this service always emits a fixed mono MP3 profile.

## Production (Laravel Forge)

- New PHP 8.3 site; `composer install`, `php artisan migrate`.
- **Install `ffmpeg`** on the server (`apt install ffmpeg`).
- Set `TTS_PROVIDER=replicate`, `REPLICATE_API_TOKEN`, and storage
  (`TTS_STORAGE_DISK=local|s3`).
- Raise the site's PHP `max_execution_time` and nginx `fastcgi_read_timeout`
  (generation holds the request up to ~60s on a cold start; the provider polls
  as a fallback).
- Confirm the exact Replicate Chatterbox model slug + input field names
  (`REPLICATE_TEXT_FIELD` / `REPLICATE_REFERENCE_FIELD`) from the model's schema
  page, and tune the `voice_settings → Chatterbox` mapping by ear.

## Tests

```bash
ddev artisan test           # uses the fake provider + real ffmpeg
```

## Roadmap (not yet built)

- Phase 2: `/v1/voices` management API, TTL cleanup command + scheduler, optional
  async/webhook path for long text.
- Phase 3: admin panel, additional provider drivers (Modal/Fal/local + an
  ElevenLabs pass-through), real streaming.
