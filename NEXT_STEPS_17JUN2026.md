# NEXT STEPS — Service-owned chunking + Replicate 429 resilience

_Captured 2026-06-17; planned to start 2026-06-18._

How to make the **Bespoken TTS service own all chunking** so the Bespoken Craft
plugin can send whole articles ("send-whole"), and make Replicate generation
survive the burst rate limit. This is the backend half of the
`feature/configurable-endpoint` work already shipped (unreleased) in the plugin.

---

## The problem

When the Bespoken plugin points at this service and generates a long entry, the
text gets **chunked twice**:

1. **Plugin side** — `GenerateAudio` splits text with its own `TextChunker`
   (~4,500 chars for `eleven_v3`), generates each chunk, and concatenates with
   ffmpeg's concat demuxer — **hard joins, no crossfade**
   (`src/helpers/AudioConcatenator.php` in the plugin repo).
2. **Service side** — `SpeechService::synthesize()` then splits *each* of those
   chunks again into ~280-char sentence-aware pieces for Chatterbox and
   concatenates them with a **25 ms crossfade** (`AudioConverter::concatenate`).

So the service already does finer, higher-quality chunking than the plugin. The
plugin re-chunking on top is **redundant and worse**: it only adds un-crossfaded
seams wherever the plugin splits (every ~4,500 chars).

**Decision (already made):** when the plugin uses a custom endpoint
(`usesCustomEndpoint()`), it should **send the whole text in one request** and
let this service own chunking + crossfade. This doc is the service-side work that
makes that safe.

### What blocks send-whole today

- **Text cap.** `tts.max_text_length = 5000` (`config/tts.php`) → the service
  rejects whole articles with a 422.
- **Replicate burst throttle.** Observed on `johnfmorton`'s account:
  > "Your rate limit for creating predictions is reduced to **6 requests per
  > minute with a burst of 1** … `{"status":429,"retry_after":2}`"
  (seen even with $19.98 credit, so it's a burst/rate limit, not a balance
  problem). Send-whole fires one Replicate prediction **per ~280-char chunk** in
  rapid succession → the 2nd+ prediction in a burst 429s.
- **No retry/backoff.** `ReplicateChatterboxProvider::createPrediction()` throws
  immediately on any non-2xx and does **not** honor `retry_after`, so a single
  throttled chunk fails the **entire** article with a 502.
- **Synchronous ceiling.** The plugin's curl timeout is **300 s**
  (`CURLOPT_TIMEOUT`), and this service's `request_timeout` is 300 s. A long
  article is many sequential Chatterbox calls (each ~3–16 s, per the Replicate
  dashboard) plus rate-limit spacing — it can blow past 300 s.

---

## Goal

A whole-article `POST /v1/text-to-speech/{voice_id}` that:

- accepts large text (raised, bounded `max_text_length`),
- chunks internally (already does — 280-char, sentence-aware),
- **retries on Replicate 429 honoring `retry_after`** so throttling slows it down
  instead of failing it,
- crossfade-concatenates into one MP3 (already does),
- and, for text beyond the synchronous budget, has an async escape hatch.

---

## Current behavior — code map

**This repo (`bespoken-tts-service`):**

- `app/Services/SpeechService.php` → `synthesize()`: `TextChunker::split($text,
  config('tts.chunk_chars', 280))` → loop `provider->synthesize()` per chunk →
  `AudioConverter::concatenate()`.
- `app/Services/Audio/AudioConverter.php` → `concatenate()`: `acrossfade`
  (`tts.chunk_crossfade_ms`, default 25; 0 = hard join).
- `app/Services/Tts/ReplicateChatterboxProvider.php`:
  - `createPrediction()` — POSTs to Replicate; **`if (!$response->successful())
    throw …` with no 429 / `retry_after` handling**. ← the fix goes here.
  - `awaitCompletion()` — polls every 750 ms until `request_timeout`.
  - `synthesize()` — maps `stability → cfg_weight`, `style → exaggeration`.
- `app/Http/Requests/TextToSpeechRequest.php` — `text` rule
  `max:tts.max_text_length`; already accepts (and ignores) `previous_text` /
  `next_text` / `previous_request_ids`.
- `config/tts.php` — `max_text_length=5000`, `chunk_chars=280`,
  `chunk_crossfade_ms=25`, `request_timeout=300`, `provider=replicate`.
- `app/Models/Speech.php` — has `status` (Processing/Completed/Failed) +
  `wasRecentlyCreated` (drives `x-cache`). The status field means an async path
  is already half-modeled.

**Plugin repo (`craftpluingdev/plugins/bespoken`, `feature/configurable-endpoint`):**

- `src/jobs/GenerateAudio.php` — `elevenLabsApiCall()` chunks via `TextChunker`;
  `makeElevenLabsRequest()` builds the call; `CURLOPT_TIMEOUT = 300`; `getTtr()`
  also chunks to estimate the queue TTR.
- `src/models/Settings.php` — `usesCustomEndpoint()`, `getApiBaseUrl()`,
  `getTextToSpeechUrl()`.

### Measurements from 2026-06-17

- Single 58-char request → 200, ~70 KB MP3 (ID3v2.4, 128 kbps mono), ~6.2 s,
  `request-id` header, `x-cache: MISS`; repeat → `HIT`.
- 906-char request (~4 internal chunks) → **502** (Replicate 429 throttle).
- Replicate dashboard: Chatterbox predictions ~3.4–16.2 s each.

---

## Plan

### Phase 1 — Survive Replicate throttling (429 retry/backoff) — do first

The keystone: without this, send-whole fails intermittently.

- [ ] In `ReplicateChatterboxProvider::createPrediction()`, on HTTP **429**:
      read `retry_after` from the JSON body (`{"…","status":429,"retry_after":N}`)
      and/or the `Retry-After` header, sleep that long, and retry. Fall back to
      exponential backoff if no hint is given.
- [ ] Bound it: max attempts + a total-wait cap so a stuck request can't exceed
      `request_timeout`. Make attempts/base-delay config-driven in `config/tts.php`
      (e.g. `tts.replicate.max_retries`, `tts.replicate.retry_base_ms`).
- [ ] Apply the same 429 handling to the polling GET in `awaitCompletion()` and
      the final audio-download GET (less likely, but cheap insurance).
- [ ] (Optional but recommended) **space out** prediction creation within a
      request to respect burst-1 (e.g. enforce a minimum gap between
      `createPrediction()` calls, or a small token bucket) so we proactively avoid
      429s instead of only reacting to them.
- [ ] Tests: `Http::fake()` a `429 (retry_after:1)` → `200` sequence and assert
      `synthesize()` succeeds; assert it gives up after the cap.

Files: `app/Services/Tts/ReplicateChatterboxProvider.php`, `config/tts.php`,
`tests/…`.

### Phase 2 — Accept whole articles (raise + bound `max_text_length`)

- [ ] Raise `TTS_MAX_TEXT_LENGTH`. Pick a value that fits the **synchronous
      budget**: roughly `ceil(chars / chunk_chars) × per_chunk_latency +
      rate_limit_spacing` must finish under the client's ~300 s curl timeout.
      With ~280-char chunks at ~4–8 s each plus burst spacing, the practical sync
      ceiling is on the order of a few thousand to ~10–15k chars — **measure,
      then set** (don't guess a huge number that will time out).
- [ ] Keep the EL-shaped **422** when text exceeds the cap (already produced by
      `TextToSpeechRequest`); confirm the plugin surfaces it (it reads
      `detail.message`).
- [ ] Update `.env` / `.env.example` and note the timeout relationship in
      `config/tts.php` + README.

Files: `config/tts.php`, `.env.example`, docs.

### Phase 3 — Plugin: send-whole in custom-endpoint mode _(plugin repo)_

- [ ] In `GenerateAudio::elevenLabsApiCall()` (and `getTtr()`), when
      `settings->usesCustomEndpoint()`, **skip `TextChunker`** and send the whole
      text as a single request (`$chunks = [trim($text)]`); let the service chunk +
      crossfade. Leave the ElevenLabs path unchanged (it still needs client-side
      chunking for EL's per-model limits).
- [ ] Decide overflow behavior: either still split into **large** chunks just
      under the service's `max_text_length` as a safety net, or surface the
      service's 422 cleanly. (Leaning: surface 422 — simpler, and avoids
      re-introducing seams.)

Files (plugin repo, `feature/configurable-endpoint`): `src/jobs/GenerateAudio.php`.

### Phase 4 — Async generation for very long text _(only if needed)_

The synchronous ceiling is fundamental. For articles beyond it:

- [ ] Use the existing `Speech.status` (Processing/Completed/Failed): return
      **202 + a poll URL / id**, queue a `GenerateSpeech` job, and have the plugin
      poll (its queue job + progress UI can drive this). Mirrors
      `screenshot-service`'s queued job + webhook. Needs a Forge queue worker.
- [ ] Keep the synchronous path as the default for short/medium text.

---

## Constraints & risks

- **Replicate burst throttle dominates.** Even with retry, a long article is slow
  while throttled to 6/min, burst 1 (~10 s between predictions). This eases at a
  higher Replicate usage tier; confirm the exact trigger (lifetime spend vs
  balance). A larger `chunk_chars` reduces prediction count but Chatterbox is
  short-form, so weigh quality vs. fewer calls.
- **300 s synchronous ceiling** (plugin curl + service `request_timeout`). Phase 4
  is the escape hatch; until then `max_text_length` must keep requests under it.
- **Don't regress ElevenLabs mode** — the plugin must keep chunking for real
  ElevenLabs (per-model char limits).

---

## Verification plan

- [ ] **Unit:** `Http::fake` 429→200 retry test (Phase 1).
- [ ] **Integration (no cost):** `TTS_PROVIDER=fake`, POST a long single-request
      text → confirm internal chunking + crossfade → one valid MP3.
- [ ] **Live (real Chatterbox):** medium article via the plugin in custom-endpoint
      mode → one crossfaded file, **no 502s under throttle**, `request-id` present.
- [ ] **Overflow:** text > `max_text_length` → clean 422 surfaced in the plugin UI.

---

## Open questions

- [ ] Final `max_text_length` (needs a real per-chunk latency measurement under
      the current throttle).
- [ ] Proactive prediction spacing (token bucket) vs. purely reactive 429 retry?
- [ ] `retry_after` location — body, `Retry-After` header, or both? (Observed in
      the body; handle both.)
- [ ] Async now (Phase 4) or defer — depends on the real article lengths to support.

---

## Handy commands

```bash
ddev artisan test                       # fake provider + real ffmpeg
ddev artisan config:clear               # after editing config/tts.php or .env
ddev exec ./vendor/bin/pint             # format to Laravel style
# Reproduce the throttle (multi-chunk forces multiple Replicate predictions):
curl -k -X POST https://tts.ddev.site/v1/text-to-speech/<slug> \
  -H "xi-api-key: sk_..." -H "Content-Type: application/json" \
  -d '{"text":"<~1000 chars>","model_id":"eleven_v3","force_refresh":true}' -i
```
