# Connecting the Bespoken Craft plugin

[Bespoken](https://github.com/johnfmorton/craft-bespoken) generates audio for
Craft entries by calling an ElevenLabs-compatible API. Because this service
speaks the same protocol, you can point Bespoken at your own server and narrate
in your self-hosted cloned voice instead of using ElevenLabs.

## Prerequisites

- **bespoken-tts-service deployed** (see [DEPLOYMENT.md](DEPLOYMENT.md)) with:
  - at least one **API key** (Dashboard → API Keys), and
  - at least one **voice** (Dashboard → Voices).
- A version of the **Bespoken plugin that supports a custom ElevenLabs-compatible
  endpoint URL**. The published plugin currently targets ElevenLabs directly; the
  configurable-endpoint setting is required for this integration — see
  [Plugin requirements](#plugin-requirements-work-needed-in-craft-bespoken) below.

## 1. Get the connection details

Open the service **Dashboard**. The home page's **Connect Bespoken** panel shows
everything you need, each with a copy button:

- **Base URL** — your server origin, e.g. `https://your-domain.com`
- **API key** — your `xi-api-key`
- **Voice IDs** — the `voice_id` slugs you've registered

## 2. Configure the plugin

In the Bespoken plugin settings:

| Plugin setting | Value |
|---|---|
| API base URL (custom endpoint) | the **Base URL** from the dashboard (origin, e.g. `https://your-domain.com`) |
| API key | the **API key** from the dashboard |
| Voice → `voiceId` | a **Voice ID** from the dashboard (e.g. `john`) |
| Voice model | leave as-is (e.g. `eleven_v3`) — the service accepts any `model_id` and uses its configured backend (Chatterbox) |

Generate audio for an entry as usual; it now renders in your self-hosted voice.

### Voice configuration

The plugin's **Voices** table (Settings → Voice configuration) maps to this
service as follows:

| Column | With this service |
|---|---|
| **Voice name** | A label for your CMS users — anything you like. |
| **Voice ID** | The voice **slug** from your dashboard (e.g. `john`) — *not* an ElevenLabs ID. It's used directly in `…/v1/text-to-speech/{voice_id}`. |
| **Voice model** | Ignored by the backend (Chatterbox is always used). It only affects plugin-side chunking/stitching; `Eleven v3` is fine. |
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

Set a **default seed** on a voice in the dashboard and every render of the same
text becomes reproducible.

## 3. Behavior notes

- **Chunking + concatenation** work unchanged — the service always returns a
  fixed mono MP3 (44.1 kHz / 128 kbps), so the plugin's chunk stitching stays clean.
- **Errors** surface with a readable message: the service returns ElevenLabs-shaped
  `{"detail":{"message":…}}` (e.g. an out-of-credit message from the backend).
- **Request stitching** (`previous_request_ids`) is ElevenLabs-only; the service
  ignores it and generates each chunk independently.

## 4. Switching back to ElevenLabs

Set the plugin's API base URL back to ElevenLabs' default
(`https://api.elevenlabs.io`) and use your ElevenLabs key + voice IDs.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| `401` "API key is required/invalid" | wrong/missing `xi-api-key`, or the key is disabled in the dashboard |
| `404` "voice_id … not found" | the plugin's `voiceId` doesn't match a registered voice slug |
| `502` "…Insufficient credit…" / "generation failed" | Replicate out of credit, or a backend error |
| Audio is silent or fails to save | ffmpeg not installed on the server |
| Dashboard looks unstyled | the asset build (`npm run build`) didn't run on deploy |

---

## Plugin requirements (work needed in craft-bespoken)

This integration needs a few additions on the **plugin** side, tracked separately:

- **Configurable endpoint URL** *(required)* — replace the hardcoded
  `https://api.elevenlabs.io/v1/text-to-speech/` with a setting that stores the
  **origin** (e.g. `https://your-domain.com`); the plugin then appends
  `/v1/text-to-speech/{voiceId}`. Default to ElevenLabs for backward compatibility.
- **"Test connection"** button in settings to verify the endpoint + key.
- **Skip request stitching** (`previous_request_ids` / `previous_text` /
  `next_text`) when a non-ElevenLabs endpoint is configured.
- **Optional voice picker** that fetches voices from `GET /v1/voices` (needs that
  endpoint implemented service-side — Phase 2).
- **Docs / settings copy** pointing at bespoken-tts-service as a self-hosted backend.
