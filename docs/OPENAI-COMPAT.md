# OpenAI-compatible TTS endpoint

Alias exposes one endpoint that speaks OpenAI's text-to-speech API,
`POST /v1/audio/speech`, adapting it onto the same synthesis engine, voices,
cache, and audio pipeline the [ElevenLabs surface](BESPOKEN.md) uses. An app
written against OpenAI's TTS API can point at Alias by changing only the base
URL and the API key.

This document is the **support contract**: exactly what is honored, what is
accepted-but-ignored, and what is not supported at all. It describes text-to-
speech **only** — none of OpenAI's other APIs are implemented (see
[What is NOT supported](#what-is-not-supported)).

## At a glance

| Area | Status |
|---|---|
| `POST /v1/audio/speech` | ✅ Supported |
| `Authorization: Bearer <key>` | ✅ Supported (key is a **Alias** key) |
| `input` | ✅ Supported |
| `voice` | ⚠️ Supported, but it is a **Alias voice slug**, not an OpenAI preset |
| `model` | 🟡 Accepted, **ignored** (the configured provider picks the model) |
| `response_format`: `mp3`, `wav` | ✅ Supported |
| `response_format`: `pcm` | ⚠️ Returns a **WAV container**, not raw PCM |
| `response_format`: `opus`, `aac`, `flac` | ❌ Rejected (`400`) |
| `speed` | 🟡 Accepted, **not applied** |
| `instructions` | 🟡 Accepted, **not applied** |
| `stream_format` / streaming | ❌ Not supported (always full, buffered audio) |
| Error envelope `{ "error": {…} }` | ✅ Supported |
| Every other OpenAI endpoint (STT, chat, models, realtime, …) | ❌ Not implemented |

Legend: ✅ honored · ⚠️ honored with a caveat · 🟡 accepted so clients don't
break, but has no effect · ❌ not available.

## The one supported endpoint

```
POST {base_url}/v1/audio/speech
```

That is the **entire** OpenAI-compatible surface. There is no
`/v1/audio/transcriptions`, `/v1/models`, `/v1/chat/completions`, realtime
socket, or any other OpenAI route — requests to those get a `404`.

## Authentication

- **Supported:** `Authorization: Bearer <key>`, where `<key>` is a **Alias
  API key** (`sk_…`) from the dashboard — *not* an OpenAI key. `xi-api-key` and
  `X-API-Key` headers also work.
- **Ignored:** `OpenAI-Organization` / `OpenAI-Project` headers are accepted and
  have no effect (Alias has no org/project concept).
- Errors are `401` (missing/invalid key) and `403` (deactivated key), in the
  OpenAI error shape.

## Request parameters

| Field | Required | Behavior |
|---|---|---|
| `input` | yes | The text to synthesize. Capped at `tts.max_text_length` (default **5000**; note OpenAI's own cap is 4096). Over the cap → `400`. |
| `voice` | yes | Resolved as a **Alias voice slug** — see [Voices](#voices). Unknown → `404`. |
| `model` | no | **Accepted and ignored.** OpenAI requires it (`tts-1`, `tts-1-hd`, `gpt-4o-mini-tts`); Alias's configured provider (Chatterbox via Replicate by default) decides the actual model. |
| `response_format` | no | `mp3` (default), `wav`, `pcm` — see [Audio formats](#audio-formats). `opus`/`aac`/`flac` → `400`. |
| `speed` | no | **Accepted (0.25–4.0), not applied.** Out-of-range → `400`. |
| `instructions` | no | **Accepted, not applied** — the Chatterbox provider has no prose-steering input. |
| `stream_format` | no | **Accepted and ignored** — the response is never streamed. |

Anything not listed (e.g. arbitrary extra fields) is ignored.

## Voices

This is the biggest semantic difference and the thing most likely to surprise a
stock OpenAI client.

- **OpenAI:** `voice` is one of a **fixed preset list** (`alloy`, `ash`, `coral`,
  `echo`, `fable`, `onyx`, `nova`, `sage`, `shimmer`, plus a few on
  `gpt-4o-mini-tts`). There is **no cloning and no custom voices**.
- **Alias:** voices are **arbitrary, per-user, and often cloned**, addressed
  by a **slug** — the same value the ElevenLabs `{voice_id}` path segment takes.

So the `voice` field is treated as **one of your Alias slugs**, not an OpenAI
preset. Two ways to make a client work:

1. **Send a real slug** — set the client's `voice` to a Alias voice slug
   (e.g. `my-voice`). Simplest.
2. **Alias the preset names** — map OpenAI's fixed names to your voices in
   `config/tts.php` so a stock client that only knows `alloy`/`nova` resolves:

   ```php
   'openai_voice_aliases' => [
       'alloy' => 'default',
       'nova'  => 'default-female',
   ],
   ```

   Unlisted names pass through unchanged (treated as slugs). Resolution is scoped
   to the API key's owner, so one key can never generate with another user's
   voice.

**Not supported:** the OpenAI preset voices as *actual distinct voices*
(`alloy` does not sound like OpenAI's alloy), and creating/cloning a voice
through this endpoint. Manage voices in the Alias dashboard.

## Audio formats

| `response_format` | Supported | Result |
|---|---|---|
| `mp3` (default) | ✅ | MP3, `Content-Type: audio/mpeg` (44.1 kHz, 128 kbps) |
| `wav` | ✅ | 16-bit PCM WAV, `Content-Type: audio/wav` |
| `pcm` | ⚠️ | **A WAV container** (`audio/wav`), **not** OpenAI's raw headerless 24 kHz little-endian PCM. Use `wav` if you expected a header; do **not** feed this straight into a raw-PCM sink. |
| `opus` | ❌ | Rejected with `400` |
| `aac` | ❌ | Rejected with `400` |
| `flac` | ❌ | Rejected with `400` |

## Responses and headers

- **Success:** `200` with the raw audio bytes as the body.
- **Headers:** `Content-Type` (per format above), `Content-Disposition: inline`,
  `x-request-id` (the generation's id), `x-cache` (`HIT`/`MISS`).
- **Caching:** identical requests are de-duplicated and served from cache
  (`x-cache: HIT`), shared with the ElevenLabs surface.
- **Rate limiting:** the same per-key hourly limit as the rest of `/v1` applies;
  exceeding it returns `429` with `X-RateLimit-*` / `Retry-After` headers.
- **Not supported:** streaming of any kind — no `stream_format: "sse"` events and
  no chunked audio deltas. The full clip is buffered and returned at once.

## Errors

All errors use the OpenAI envelope:

```json
{ "error": { "message": "…", "type": "invalid_request_error", "param": "input", "code": null } }
```

| Status | When | Notable fields |
|---|---|---|
| `400` | Validation (missing `input`, bad `response_format`, out-of-range `speed`) | `param` names the field |
| `401` | Missing or invalid API key | `code: invalid_api_key` |
| `403` | Deactivated API key | — |
| `404` | Unknown `voice` | `code: voice_not_found`, `param: voice` |
| `429` | Rate limit exceeded | `code: rate_limit_exceeded` |
| `502` | Generation failed | — |

`type` is a best-effort match of OpenAI's categories (`invalid_request_error`,
`rate_limit_error`, `api_error`); clients should key on the **HTTP status**, not
the `type` string.

## What is NOT supported

A single, explicit list:

- **Any endpoint other than `POST /v1/audio/speech`.** No speech-to-text
  (`/v1/audio/transcriptions`, `/v1/audio/translations`), no chat/completions/
  responses, no `/v1/models`, no embeddings/images, no realtime WebSocket API.
- **Streaming** — `stream_format: "sse"` and chunked audio deltas. Always a full
  buffered response.
- **`opus`, `aac`, `flac`** response formats (→ `400`).
- **Raw (headerless) `pcm`** — `pcm` yields a WAV container instead.
- **`speed`** and **`instructions`** — accepted for compatibility but have no
  effect on the output.
- **OpenAI's preset voices as real voices**, and **voice cloning/creation** via
  the API. `voice` is a Alias slug (optionally aliased).
- **OpenAI's `model` selection** — the value is ignored; Alias's configured
  provider decides the model.
- **OpenAI API keys** — authenticate with a Alias `sk_…` key.

## Quickstart

### Official OpenAI SDK (Python)

```python
from openai import OpenAI

client = OpenAI(base_url="https://your-host/v1", api_key="<alias sk_ key>")

client.audio.speech.create(
    model="tts-1",       # accepted, ignored
    voice="my-voice",    # a Alias slug (or an aliased preset name)
    input="Hello from Alias.",
).stream_to_file("out.mp3")
```

### curl

```bash
curl -X POST https://your-host/v1/audio/speech \
  -H "Authorization: Bearer <alias sk_ key>" \
  -H "Content-Type: application/json" \
  -d '{"model":"tts-1","voice":"my-voice","input":"Hello from Alias.","response_format":"mp3"}' \
  --output out.mp3
```
