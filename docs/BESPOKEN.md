# Connecting the Bespoken Craft plugin

[Bespoken](https://github.com/johnfmorton/craft-bespoken) generates audio for
Craft entries by calling an ElevenLabs-compatible API. Because this service
speaks the same protocol, you can point Bespoken at your own server and narrate
in your self-hosted cloned voice instead of using ElevenLabs.

> **Status:** this integration requires **Bespoken 5.4.0 or later**, available
> from the [Craft Plugin Store](https://plugins.craftcms.com/bespoken) and
> [Packagist](https://packagist.org/packages/johnfmorton/craft-bespoken).
> Earlier plugin versions target ElevenLabs only.

## Prerequisites

- **alias-tts deployed** (see [DEPLOYMENT.md](DEPLOYMENT.md)) with an
  **API key** (Dashboard → API Keys). Two built-in voices (`default`,
  `default-female`) ship out of the box, so no voice setup is required to get
  started — add your own cloned voice (Dashboard → Voices) when you want the
  narration in a specific voice.
- **Bespoken 5.4.0 or later**, which adds the TTS provider + endpoint
  settings this integration needs — see
  [Plugin status](#plugin-status-craft-bespoken) below for what it supports.

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
| TTS provider | **Alias TTS service** — the settings screen then shows only the fields that apply to this service |
| API endpoint URL | the **Base URL** from the dashboard (origin, e.g. `https://your-domain.com`; environment variables are supported) |
| API key | the **API key** from the dashboard |
| Voice → `voiceId` | a **Voice ID** from the dashboard (e.g. `john`) |

In Alias TTS service mode the plugin hides the ElevenLabs-only settings (**Voice
model**, **Similarity boost**, **Speaker boost**) and the ElevenLabs credit
balance / cost estimate on the Bespoken field — none of them apply to this
service, which picks the engine per voice.

Generate audio for an entry as usual; it now renders in your self-hosted voice.

### Voice configuration

The plugin's **Voices** table (Settings → Voice configuration) maps to this
service as follows:

| Column | With this service |
|---|---|
| **Voice name** | A label for your CMS users — anything you like. |
| **Voice ID** | The voice **slug** from your dashboard (e.g. `john`) — *not* an ElevenLabs ID. It's used directly in `…/v1/text-to-speech/{voice_id}`. |
| **Voice model** | Hidden in Alias TTS service mode — the engine (Chatterbox, Chatterbox Turbo, or Qwen3 TTS) is chosen per voice on the service's own voice page, not by the plugin. |
| **Pronunciation rule set** | Plugin-side text processing — unaffected by the backend. |

> **The endpoint is plugin-wide.** The "API endpoint URL" is a single setting for
> the whole plugin, so **every** voice in the table uses that endpoint. You can't
> mix ElevenLabs voices and self-hosted voices in one install: when the endpoint
> points at this service, every Voice ID must be a slug that exists in your
> dashboard, and ElevenLabs library IDs (e.g. `nPczCjzI2devNBz1zQrb`) won't
> resolve. Switch the endpoint back to ElevenLabs to use ElevenLabs voice IDs.

### How the voice settings map

The service maps the ElevenLabs voice settings onto the voice's engine:

- **Stability** → pacing/steadiness (classic Chatterbox `cfg_weight`; on a
  Chatterbox Turbo voice it maps inversely onto `temperature` — higher
  stability = steadier there too)
- **Style** → expressiveness (classic Chatterbox `exaggeration`; accepted and
  ignored on a Turbo voice, which has no equivalent knob)
- **Similarity boost** and **Speaker boost** are accepted but unused — cloning
  fidelity comes from the reference clip itself.

## 3. Behavior notes

- **The service does the chunking.** In Alias TTS service mode the plugin sends
  the whole article in one request, always through the async jobs endpoint
  (see [Alias extensions](#4-alias-extensions) below) regardless of length;
  the service splits, generates, and stitches the audio server-side with
  seam-clean concatenation. The plugin only falls back to a single synchronous
  `POST /v1/text-to-speech/{voice_id}` when the jobs endpoint is missing
  (see the 404 note below).
- **Output** is a mono MP3 (44.1 kHz / 128 kbps) by default; ElevenLabs
  `output_format` tokens (including the `pcm_*` variants) are honored.
- **Errors** surface with a readable message: the service returns ElevenLabs-shaped
  `{"detail":{"message":…}}` (e.g. an out-of-credit message from the backend).
- **Request stitching** (`previous_request_ids`) is ElevenLabs-only; the service
  ignores it and generates each chunk independently.

## 4. Alias extensions

Beyond the ElevenLabs-compatible surface, the service adds endpoints the plugin
uses when talking to a Alias TTS server (same `xi-api-key` auth):

- **Async generation** — `POST /v1/text-to-speech/{voice_id}/jobs`,
  then poll `GET /v1/text-to-speech/jobs/{id}` and fetch the result from
  `GET /v1/text-to-speech/jobs/{id}/audio`. Lifts the synchronous ceiling for
  long articles; requires a queue worker on the server (see
  [DEPLOYMENT.md](DEPLOYMENT.md)). Bespoken ≥ 5.4.0 uses this endpoint for
  **every** generation, short entries included, so the per-clip progress shows
  on all of them. It polls every 3 seconds and gives up after 30 minutes.

  **404 contract the plugin depends on:** a `404` from the jobs `POST` with a
  bare `{"message": "…route…could not be found."}` body (no `detail` key)
  means "older server without the endpoint" and makes the plugin fall back to
  the synchronous path. A `404` carrying an ElevenLabs-shaped
  `{"detail":{"message":…}}` body (e.g. unknown `voice_id`) is treated as a
  real error and surfaced to the editor. Keep those two shapes distinct.

  While a job is `processing`, the status response carries a live `progress`
  snapshot the client can render directly:

  ```json
  {
    "id": "9d1f6a2e-…",
    "status": "processing",
    "progress": {
      "stage": "generating",
      "chunks_total": 50,
      "chunks_done": 24,
      "percent": 48,
      "message": "Creating clip 25 of 50 · about 12 min left",
      "eta_seconds": 720,
      "eta_human": "about 12 min"
    }
  }
  ```

  `eta_seconds` / `eta_human` are `null` while stitching (and whenever no
  estimate is available), and the "· … left" suffix is then omitted from
  `message`.

  `progress` is optional and nullable: render `message` (plus `percent`) when
  it's present, and fall back to an indeterminate "Processing…" when it's
  `null` (job not started yet, an older server, or a server restart).
  `"stage": "stitching"` means every clip is rendered and the final file is
  being assembled. Only `status` is authoritative for completion — never gate
  on `progress`.

  Bespoken renders `message` and `percent`, but refreshes its status line only
  when `chunks_done` or `stage` changes: when `message` carries a live ETA
  ("· about 12 min left") the estimate wobbles between polls, and keying the
  line on it would make the editor jitter. Its progress ring still advances on
  every poll. With `progress: null` it shows "Generating audio on your Alias
  TTS service… (Ns elapsed)".
- **`POST /v1/projects`** — powers the plugin's "Create Alias TTS project"
  button: opens the entry's text as an editable Studio project on the server.
  The plugin sends `title`, `voice_id`, `text`, `model_id`, and
  `voice_settings`, and reads back `id`, `title`, `url` (falling back to the
  older `edit_url`), `chunk_count`, and `characters`; it shows `url` to the
  editor as an "Open project in Alias TTS →" link.
- **`GET /v1/pronunciations`** — read-only sync of the per-user pronunciation
  dictionary (see
  [ALIAS-PRONUNCIATION-PREPROCESSOR.md](ALIAS-PRONUNCIATION-PREPROCESSOR.md)).

## 5. Switching back to ElevenLabs

Set the plugin's **TTS provider** back to **ElevenLabs** and use your
ElevenLabs key + voice IDs. The API endpoint URL field is hidden and ignored
in ElevenLabs mode, so there is nothing to reset there.

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
| The plugin reports an **ElevenLabs** error (e.g. "Invalid API key") right after switching to Alias TTS | a long-running Craft `queue/listen` worker cached the plugin's old settings at boot — restart it (Craft's `clear-caches` doesn't help) |

---

## Plugin status (craft-bespoken)

Plugin-side support shipped in **Bespoken 5.4.0** (see the plugin's
[changelog](https://github.com/johnfmorton/craft-bespoken/blob/main/CHANGELOG.md)):

- **TTS provider setting** with an Alias TTS service mode and a configurable
  **API endpoint URL** (environment-variable capable, required in that mode);
  the settings screen is tailored per provider. Defaults to ElevenLabs for
  backward compatibility.
- **Whole-article send** (no plugin-side chunking against this service) and
  **async-job polling** for every generation, with the synchronous endpoint
  as a fallback for servers without `/jobs`.
- **Live progress** from the jobs endpoint's `progress` snapshot ("Creating
  clip 25 of 50", "Stitching 50 clips together") rendered in the entry editor,
  one status line per clip. Needs service v0.57.0 or later; older servers get
  an elapsed-time fallback.
- **Create Alias TTS project** button on the Bespoken field, backed by
  `POST /v1/projects`.
- **ElevenLabs-only UI hidden** in Alias mode: voice model, similarity boost,
  speaker boost, and the credit balance / cost estimate.
- **Request stitching skipped** (`previous_request_ids` / `previous_text` /
  `next_text`) for non-ElevenLabs endpoints.

Still open:

- A **"Test connection"** button in the plugin settings.
- An optional **voice picker** fetching from `GET /v1/voices` — that endpoint
  isn't implemented service-side yet.
