# ASR transcript QA — Whisper sidecar setup (Laravel Forge)

Optional. When enabled, every generated chunk is transcribed by a small local
Whisper service and the transcript is compared to the source text. This catches
Chatterbox failure modes the DSP tail-trim **cannot**:

| Failure | Example | Why DSP misses it | ASR signal |
|---|---|---|---|
| **Truncation** | model stops before finishing the sentence | there is no artifact — the audio just ends short | `tail_cov` (transcript never reaches the end of the script) |
| **Speech-like / "ghostly singing" tail** | a sung/babbled tail after the words end | it looks like speech (loud, high/variable ZCR) | `trail_s` (audio continues past the last recognized word) |
| **Mid-stream pause** | a long silent gap mid-chunk | not at the tail | `max_gap_s` (large gap between two words) |

The feature is **off by default** and degrades safely: if the sidecar is
disabled or unreachable, generation is completely unaffected.

## Architecture

```
Laravel app  ──HTTP (localhost)──►  tts-asr sidecar (FastAPI + faster-whisper)
  AsrClient                            GET  /health      → model/status
  ChunkQualityScorer  ◄── words ───    POST /transcribe  → words + timestamps
```

The sidecar is a Python process kept running by a **Forge Daemon** (Supervisor).
It loads the Whisper model once at start-up and keeps it warm in memory, so each
transcription avoids the ~2.5s model-load cost. It binds to `127.0.0.1` only — it
is never exposed to the internet.

`tiny` (the default) needs no GPU, ~150 MB RAM, and transcribes a ~15s chunk in a
few seconds on a typical Forge VPS. `base` is more accurate for ~2× the cost.

## Prerequisites

- Python 3.10+ with `venv` (`sudo apt install python3-venv` if missing)
- The repo deployed on the server (the sidecar lives in `asr-sidecar/`)

## Install (run as the `forge` user, on the server)

Paths below assume the site is at `/home/forge/tts.example.com` — adjust to yours.

```bash
cd /home/forge/tts.example.com/asr-sidecar

# 1. Create an isolated virtualenv and install dependencies.
python3 -m venv .venv
.venv/bin/pip install --upgrade pip
.venv/bin/pip install -r requirements.txt

# 2. Pre-download the model so the daemon comes up warm (and the first request
#    isn't slow). Downloads to ~/.cache/huggingface by default.
.venv/bin/python -c "from faster_whisper import WhisperModel; WhisperModel('tiny', device='cpu', compute_type='int8')"

# 3. Smoke-test it by hand (Ctrl-C to stop once you see "Application startup complete").
.venv/bin/uvicorn app:app --host 127.0.0.1 --port 8765
```

In another shell, confirm it answers:

```bash
curl -s http://127.0.0.1:8765/health
# {"status":"ok","model":"tiny","device":"cpu","compute_type":"int8",...}
```

## Run it as a Forge Daemon

In the Forge panel → your server → **Daemons** → New Daemon:

- **Command:** `/home/forge/tts.example.com/asr-sidecar/.venv/bin/uvicorn app:app --host 127.0.0.1 --port 8765`
- **Directory:** `/home/forge/tts.example.com/asr-sidecar`
- **User:** `forge`
- **Processes:** `1` (one warm model is enough; raise only if transcription becomes a bottleneck)

The defaults (`tiny` / `cpu` / `int8` / English) need no environment variables. To
override, prepend them to the command, e.g. to use the more accurate model:

```
sh -c "ASR_MODEL=base exec /home/forge/tts.example.com/asr-sidecar/.venv/bin/uvicorn app:app --host 127.0.0.1 --port 8765"
```

> After each deploy that changes `asr-sidecar/`, restart the daemon (Forge → Daemons → Restart)
> so it picks up new code or dependencies.

## Enable it in the Laravel app

Add to the site's `.env` (port must match the daemon), then `php artisan config:cache`:

```dotenv
TTS_ASR_ENABLED=true
TTS_ASR_URL=http://127.0.0.1:8765
# Start by only recording verdicts (no auto re-roll/trim) so you can watch it
# against real traffic before it spends Replicate credits regenerating chunks:
TTS_ASR_ACTION=log
```

## Verify the installation

```bash
php artisan tts:asr:health --deep
```

A healthy install prints the loaded model + version, transcribes the bundled
fixture, and reports `Self-test PASSED`. It exits non-zero on failure, so you can
gate a deploy on it. The same status (without the transcription self-test) also
appears on the admin **Health** page and in `php artisan tts:doctor`.

## Automatic remediation (`TTS_ASR_ACTION=auto`)

Once you trust the verdicts, switch from `log` to `auto` and the service acts on a
flagged chunk/segment during generation — on **both** the editable-project path
(Studio) and the synchronous / queued `/v1` path (the API + Bespoken plugin):

- **TRUNC / PAUSE / NOSPEECH** (missing content) → **re-roll** the chunk with a fresh
  random seed, up to `TTS_ASR_MAX_REROLLS` times, keeping the take with the best
  transcript coverage and stopping early on the first clean one. Each re-roll is a
  real Replicate call. Chatterbox is non-deterministic, so a re-roll usually recovers it.
- **TAIL only** (junk/"singing" tail, full coverage) → **precise-trim** at the ASR
  speech end. No Replicate call, and it catches the speech-like tails the DSP trim
  can't. (If a re-rolled take ends up TAIL-only, it's trimmed too.)

On the project path the action taken is recorded in the chunk's `asr_report`
(`action` = `rerolled` / `rerolled_unrecovered` / `trimmed` / `trim_failed`); the
`/v1` path has no per-segment record, so flagged segments are written to the log
instead. A **manual** re-roll (the Studio re-roll button) is never auto-remediated —
it always produces exactly one new take. If the sidecar goes down mid-loop, the
latest take is kept and generation continues.

> **Latency on the synchronous endpoint.** On `POST /v1/text-to-speech/{voice}` the
> QA + any re-rolls run inline before the response, so they add to the request time
> (bounded by `max_text_length`). For long text prefer the async jobs endpoint
> (`.../jobs`) — it runs the *same* QA in the queue worker, off the request budget.

## Configuration reference (`config/tts.php` → `asr`)

| Env var | Default | Meaning |
|---|---|---|
| `TTS_ASR_ENABLED` | `false` | Master switch for the whole feature |
| `TTS_ASR_URL` | `http://127.0.0.1:8765` | Sidecar base URL |
| `TTS_ASR_TIMEOUT` | `30` | Per-call timeout (seconds) |
| `TTS_ASR_LANGUAGE` | `en` | Forced language, or `auto` |
| `TTS_ASR_ACTION` | `log` | `log` = record verdict only; `auto` = also remediate (see below) |
| `TTS_ASR_MAX_REROLLS` | `2` | Max re-rolls per chunk when `action=auto` |
| `TTS_ASR_TRAIL_S_MAX` | `1.2` | Audio after the last word above this ⇒ **TAIL** |
| `TTS_ASR_GAP_S_MAX` | `1.5` | Largest inter-word gap above this ⇒ **PAUSE** |
| `TTS_ASR_TAIL_COV_MIN` | `0.93` | Transcript must reach this far into the script, else **TRUNC** |
| `TTS_ASR_TRIM_GUARD_MS` | `80` | Audio kept after the last word when computing a TAIL trim point |

Sidecar-side env vars (set on the daemon command, not in `.env`):
`ASR_MODEL` (`tiny`), `ASR_DEVICE` (`cpu`), `ASR_COMPUTE_TYPE` (`int8`),
`ASR_LANGUAGE` (`en`), `ASR_DOWNLOAD_ROOT` (model cache dir).

## Troubleshooting

- **`tts:asr:health` says UNREACHABLE** — the daemon isn't running or the port
  differs. Check Forge → Daemons (status + log), and that `TTS_ASR_URL`'s port
  matches the daemon command.
- **"sidecar up but model not loaded"** — the model download failed or the box is
  out of RAM. Re-run the pre-download step (step 2) as `forge` and check free
  memory; try `tiny` if you tried `base`.
- **Slow transcription** — expected on a small VPS for `base`; use `tiny`. ASR
  runs after a chunk is generated and never blocks the API response.
- **Verdicts look wrong** — tune the thresholds above. They were validated on
  labeled samples but your voice/pacing may differ; watch `asr_report` on chunks
  (Studio / DB) with `TTS_ASR_ACTION=log` before enabling any automatic action.
