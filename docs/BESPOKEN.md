# Connecting the Bespoken Craft plugin

[Bespoken](https://github.com/johnfmorton/craft-bespoken) generates audio for
Craft entries by calling an ElevenLabs-compatible API. Because this service
speaks the same protocol, you can point Bespoken at your own server and narrate
in your self-hosted cloned voice instead of using ElevenLabs.

> **Status:** this integration requires the **Bespoken plugin ≥ 5.4.0**
> (currently unreleased). Published plugin versions target ElevenLabs only.

## Prerequisites

- **mimic-tts-service deployed** (see [DEPLOYMENT.md](DEPLOYMENT.md)) with an
  **API key** (Dashboard → API Keys). Two built-in voices (`default`,
  `default-female`) ship out of the box, so no voice setup is required to get
  started — add your own cloned voice (Dashboard → Voices) when you want the
  narration in a specific voice.
- The **Bespoken plugin ≥ 5.4.0**, which adds the TTS provider + endpoint
  settings this integration needs — see
  [Plugin status](#plugin-status-craft-bespoken) below.

## 1. Get the connection details

Open the service **Dashboard**. The home page's **Connect your app** panel shows
everything you need, each with a copy button:

- **Base URL** — your server origin, e.g. `https://your-domain.com`
- **API key** — your `xi-api-key`
- **Voice IDs** — the `voice_id` slugs you've registered

## 2. Configure the plugin

In the Bespoken plugin settings:

| Plugin setting | Value |
|---|---|
| TTS provider | **Mimic TTS service** — the settings screen then shows only the fields that apply to this service |
| API endpoint URL | the **Base URL** from the dashboard (origin, e.g. `https://your-domain.com`; environment variables are supported) |
| API key | the **API key** from the dashboard |
| Voice → `voiceId` | a **Voice ID** from the dashboard (e.g. `john`) |

In TTS-service mode the plugin hides the ElevenLabs-only settings (**Voice
model**, **Similarity boost**, **Speaker boost**) — the service's Chatterbox
backend doesn't use them.

Generate audio for an entry as usual; it now renders in your self-hosted voice.

### Voice configuration

The plugin's **Voices** table (Settings → Voice configuration) maps to this
service as follows:

| Column | With this service |
|---|---|
| **Voice name** | A label for your CMS users — anything you like. |
| **Voice ID** | The voice **slug** from your dashboard (e.g. `john`) — *not* an ElevenLabs ID. It's used directly in `…/v1/text-to-speech/{voice_id}`. |
| **Voice model** | Hidden in TTS-service mode — the backend is always Chatterbox. |
| **Pronunciation rule set** | Plugin-side text processing — unaffected by the backend. |

> **The endpoint is plugin-wide.** The "API endpoint URL" is a single setting for
> the whole plugin, so **every** voice in the table uses that endpoint. You can't
> mix ElevenLabs voices and self-hosted voices in one install: when the endpoint
> points at this service, every Voice ID must be a slug that exists in your
> dashboard, and ElevenLabs library IDs (e.g. `nPczCjzI2devNBz1zQrb`) won't
> resolve. Switch the endpoint back to ElevenLabs to use ElevenLabs voice IDs.

### How the voice settings map

The service maps the ElevenLabs voice settings onto its backend:

- **Stability** → pacing/steadiness (Chatterbox `cfg_weight`)
- **Style** → expressiveness (Chatterbox `exaggeration`)
- **Similarity boost** and **Speaker boost** are accepted but unused — cloning
  fidelity comes from the reference clip itself.

## 3. Behavior notes

- **The service does the chunking.** In TTS-service mode the plugin sends the
  whole article in one request (switching to the async jobs endpoint above
  ~4,000 characters — see [Mimic extensions](#4-mimic-extensions) below);
  the service splits, generates, and stitches the audio server-side with
  seam-clean concatenation.
- **Output** is a mono MP3 (44.1 kHz / 128 kbps) by default; ElevenLabs
  `output_format` tokens (including the `pcm_*` variants) are honored.
- **Errors** surface with a readable message: the service returns ElevenLabs-shaped
  `{"detail":{"message":…}}` (e.g. an out-of-credit message from the backend).
- **Request stitching** (`previous_request_ids`) is ElevenLabs-only; the service
  ignores it and generates each chunk independently.

## 4. Mimic extensions

Beyond the ElevenLabs-compatible surface, the service adds endpoints the plugin
uses when talking to a Mimic TTS server (same `xi-api-key` auth):

- **Async long-text generation** — `POST /v1/text-to-speech/{voice_id}/jobs`,
  then poll `GET /v1/text-to-speech/jobs/{id}` and fetch the result from
  `GET /v1/text-to-speech/jobs/{id}/audio`. Lifts the synchronous ceiling for
  long articles; requires a queue worker on the server (see
  [DEPLOYMENT.md](DEPLOYMENT.md)).
- **`POST /v1/projects`** — powers the plugin's "Create Mimic TTS project"
  button: opens the entry's text as an editable Studio project on the server.
- **`GET /v1/pronunciations`** — read-only sync of the per-user pronunciation
  dictionary (see
  [MIMIC-PRONUNCIATION-PREPROCESSOR.md](MIMIC-PRONUNCIATION-PREPROCESSOR.md)).

## 5. Switching back to ElevenLabs

Set the plugin's API base URL back to ElevenLabs' default
(`https://api.elevenlabs.io`) and use your ElevenLabs key + voice IDs.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| `401` "API key is required/invalid" | wrong or missing `xi-api-key` |
| `403` "This API key has been deactivated." | the key was toggled off in the dashboard — re-enable it or use another key |
| `404` "voice_id … not found" | the plugin's `voiceId` doesn't match a registered voice slug |
| `429` "Too many requests" | the per-key rate limit on generation requests — wait and retry |
| `502` "…Insufficient credit…" / "generation failed" | Replicate out of credit, or a backend error |
| `502` "ffmpeg conversion failed" | ffmpeg not installed on the server |
| Dashboard looks unstyled | the asset build (`npm run build`) didn't run on deploy |

---

## Plugin status (craft-bespoken)

Plugin-side support lands in **Bespoken 5.4.0** (unreleased):

- **TTS provider setting** with a Mimic-TTS-service mode and a configurable
  **API endpoint URL** (environment-variable capable); the settings screen is
  tailored per provider. Defaults to ElevenLabs for backward compatibility.
- **Whole-article send** (no plugin-side chunking against this service) and
  **async-job polling** for long articles.
- **Request stitching skipped** (`previous_request_ids` / `previous_text` /
  `next_text`) for non-ElevenLabs endpoints.

Still open:

- A **"Test connection"** button in the plugin settings.
- An optional **voice picker** fetching from `GET /v1/voices` — that endpoint
  isn't implemented service-side yet.
