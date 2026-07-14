# Local Chatterbox — run the TTS engines on your own machine (development)

Development-only. With `TTS_PROVIDER=local`, every generation call (Studio,
the `/v1` APIs, Genblaze renders) runs against a small FastAPI sidecar on your
own machine serving the open-source Chatterbox models, instead of Replicate.
You get real Chatterbox output — same engines, same knobs — with no API
credits, no rate limits, and no network round-trip; it works offline once the
models are downloaded. Unset the variable and you're back on Replicate,
untouched.

Both engines are served: classic `chatterbox` and `chatterbox-turbo`, chosen
per voice exactly as on Replicate (see [VOICES.md](VOICES.md)). Each model is
lazy-loaded on first use and stays warm in memory afterwards.

> This is a developer convenience, not a production deployment path. The
> sidecar has no auth, serves one request at a time, and is meant to listen on
> localhost only.

## Architecture

```
Laravel app (DDEV web container)                      your machine
  TtsProvider seam                                    chatterbox-sidecar (FastAPI)
  LocalChatterboxProvider ──HTTP multipart──►  POST /synthesize → WAV bytes
  tts:chatterbox:health   ──────────────────►  GET  /health     → engine states
```

`TTS_PROVIDER=local` swaps the provider behind the app-wide `TtsProvider`
seam; everything downstream — chunking, tail-trim, seam joins, ASR-QA,
sealing — is provider-agnostic and behaves identically. The contract is
deliberately synchronous: one POST, WAV bytes back, no prediction/polling
dance and no retry logic (a local failure is deterministic and should surface,
not be re-rolled).

The sidecar source ships in `chatterbox-sidecar/` (mirroring the Whisper
sidecar in `asr-sidecar/`, see [ASR-SETUP.md](ASR-SETUP.md)).

## Machine requirements

| Resource | Minimum | Comfortable | Why |
|---|---|---|---|
| RAM | 16 GB | 32 GB | each engine holds ~4 GB resident once warm; using both ≈ 8 GB + PyTorch overhead, on top of DDEV |
| Disk | ~8 GB free | ~12 GB free | one-time model downloads (~3.8 GB per engine, into the Hugging Face cache) + a ~3 GB venv (PyTorch) |
| CPU | Apple Silicon or a modern x86-64 | — | inference is CPU-bound; reference point below |
| GPU | none | NVIDIA (Linux/WSL2) | optional — set `CHATTERBOX_DEVICE=cuda`; **not** useful on Macs (see the MPS warning) |
| Python | 3.10 | — | 3.10 is the tested path; the Docker image uses 3.11 |

Reference point (Apple M4 Max, CPU): **Chatterbox Turbo generates at ~2×
real-time** — a full ~300-character chunk takes ~9 s for ~18 s of audio.
Classic Chatterbox is a noticeably heavier model on CPU; prefer turbo voices
for local iteration.

## Prerequisites

- Python 3.10 with `venv` — [`uv`](https://docs.astral.sh/uv/) is the easy way
  to get one on any OS (`brew install uv` on macOS); it fetches the right
  interpreter for you.
- The repo checked out (the sidecar ships in `chatterbox-sidecar/`).
- Nothing else: ffmpeg lives on the app side and is already part of a working
  DDEV setup.

## macOS setup (run natively on the host — recommended)

Run the sidecar as a plain host process, not in Docker: containers on macOS
get no GPU/Metal and their virtualized CPU is slower than the host's.

```bash
cd chatterbox-sidecar
uv venv --python 3.10
source .venv/bin/activate
uv pip install -r requirements.txt
uvicorn app:app --host 127.0.0.1 --port 8766
```

(Without `uv`: `brew install python@3.10`, then `python3.10 -m venv .venv`,
`.venv/bin/pip install -r requirements.txt`, same `uvicorn` line.)

First start is quick — models download lazily on first *use*, not at boot.
Leave this terminal running; the sidecar logs each request.

Once the venv exists you can skip the manual `uvicorn` line and manage the
sidecar as a background process instead:

```bash
ddev chatterbox start     # also: stop | status | logs
```

— and have DDEV do even that for you (next section).

> **Keep `CHATTERBOX_DEVICE=cpu` on Apple Silicon (the default).** Counter to
> intuition, MPS/Metal is *slower* than CPU here: chatterbox-tts falls back to
> CPU for unsupported ops, and the device↔device tensor bouncing plus
> precision conversions dominate the runtime. CPU is both the stable and the
> fast path on a Mac.

## Linux setup

Native venv, same shape:

```bash
cd chatterbox-sidecar
uv venv --python 3.10       # or: python3.10 -m venv .venv
source .venv/bin/activate
uv pip install -r requirements.txt
uvicorn app:app --host 127.0.0.1 --port 8766
```

With an NVIDIA GPU, the stock PyTorch wheels already carry CUDA on Linux —
just select the device:

```bash
CHATTERBOX_DEVICE=cuda uvicorn app:app --host 127.0.0.1 --port 8766
```

Alternatively, use the containerized DDEV add-on below (CPU-only) and skip the
venv entirely.

## Windows setup

Two workable paths, in order of simplicity:

1. **The containerized DDEV add-on** (below) — no Python setup on Windows at
   all; the sidecar builds and runs inside Docker Desktop next to your DDEV
   containers. CPU-only.
2. **Native in WSL2** — follow the Linux setup inside your WSL2 distro, but
   bind to all interfaces so Docker containers can reach it:
   `uvicorn app:app --host 0.0.0.0 --port 8766`. CUDA works in WSL2 with
   NVIDIA's WSL driver. Depending on your Docker Desktop networking mode,
   `host.docker.internal` may or may not resolve to the WSL2 VM — if the
   health check can't connect, fall back to path 1.

## Point the app at it

Add to your `.env` (not to `.ddev/config.yaml` — a `web_environment` entry
would override `.env` for every developer on the project):

```dotenv
TTS_PROVIDER=local
# host-run sidecar, reached from inside the DDEV web container:
TTS_LOCAL_CHATTERBOX_URL=http://host.docker.internal:8766
```

`host.docker.internal` is DDEV's name for "the machine the containers run on";
DDEV provides it on macOS, Windows, and Linux. Then:

```bash
ddev artisan config:clear   # only needed if config is cached
```

To switch back to Replicate, set `TTS_PROVIDER=replicate` (or remove the line)
and `config:clear` again. Nothing else changes — voices, projects, and
settings are provider-independent.

## Start it automatically with DDEV (optional)

`ddev chatterbox start|stop|status|logs` (a repo-shipped host command) runs
the sidecar as a background process — pidfile and log live in
`chatterbox-sidecar/`. To have `ddev start` bring the sidecar up and
`ddev stop` take it down automatically, activate the per-developer hook file:

```bash
cp .ddev/config.local.yaml.dist .ddev/config.local.yaml
ddev restart
```

The live copy is git-ignored (by DDEV's own `.ddev/.gitignore`), so this is
strictly per-developer opt-in — deliberately not a prompt or a shared-config
entry, because DDEV hooks also run non-interactively and most developers on
the project won't have the sidecar installed. The hook is safe either way:
without a venv, `ddev chatterbox start` prints a one-line hint and exits
cleanly instead of failing your `ddev start`.

If you already keep your own `.ddev/config.local.yaml`, merge the `hooks:`
block from the `.dist` file into it instead of copying over it.

## Containerized alternative (DDEV add-on, CPU-only)

For Linux/Windows, or if you'd rather not manage a Python venv. The compose
file ships with a `.dist` suffix so it's opt-in — DDEV auto-loads every
`docker-compose.*.yaml`, and this image build pulls multi-GB PyTorch wheels
that most developers shouldn't pay for on `ddev start`:

```bash
cp .ddev/docker-compose.chatterbox.yaml.dist .ddev/docker-compose.chatterbox.yaml
ddev restart
```

```dotenv
TTS_PROVIDER=local
TTS_LOCAL_CHATTERBOX_URL=http://chatterbox:8766
```

Model downloads persist in a named volume (`chatterbox-models`), so rebuilds
don't re-download. The copied file is git-ignored (the `.dist` original stays
tracked), so activating the add-on never dirties `git status`.

> On Apple Silicon prefer the native host process above: the container gets
> no Metal and a slower virtualized CPU.

## Verify

```bash
# 1. The sidecar itself (from the host):
curl -s http://127.0.0.1:8766/health | python3 -m json.tool

# 2. From inside the app (reachability + engine states):
ddev artisan tts:chatterbox:health

# 3. Full round-trip — synthesizes a phrase through the sidecar. The FIRST
#    run triggers the one-time ~3.8GB classic-model download, then loads it:
ddev artisan tts:chatterbox:health --deep

# 4. The general health page / doctor now checks the sidecar as the provider:
ddev artisan tts:doctor
```

Then generate something real in the Studio: a chunk on a classic voice with a
reference clip, and one on a turbo voice with a `[laugh]` tag. Everything
downstream (tail-trim, ASR-QA badges, seal/receipt) should behave exactly as
on Replicate.

## Configuration reference

Sidecar process (environment variables on the `uvicorn` command):

| Env var | Default | Meaning |
|---|---|---|
| `CHATTERBOX_DEVICE` | `cpu` | `cpu` \| `cuda` \| `mps` — keep `cpu` on Macs (see warning above) |
| `HF_HOME` | `~/.cache/huggingface` | where the ~3.8 GB/engine model downloads live (`/models` volume in the container) |

Laravel app (`.env`):

| Env var | Default | Meaning |
|---|---|---|
| `TTS_PROVIDER` | `replicate` | set `local` to route all generation to the sidecar |
| `TTS_LOCAL_CHATTERBOX_URL` | `http://127.0.0.1:8766` | sidecar base URL; from DDEV use `http://host.docker.internal:8766` (host-run) or `http://chatterbox:8766` (containerized) |
| `TTS_LOCAL_CHATTERBOX_TIMEOUT` | `300` | whole-call budget in seconds — the sidecar generates one request at a time, so this covers queue wait too |

## Performance expectations

Benchmarked on an Apple M4 Max (64 GB), CPU device, warm model — Chatterbox
Turbo:

| Input | Generation time | Audio produced | Speed |
|---|---|---|---|
| ~55 chars | ~2 s | ~4 s | 1.9× real-time |
| ~150 chars | — | — | 2.2× real-time |
| ~300 chars (the app's chunk size) | ~9 s | ~18 s | ~2.0× real-time |

Model load adds ~9 s once per sidecar start (per engine, on first use); the
very first use ever also downloads ~3.8 GB. Classic Chatterbox is markedly
slower per character on CPU — usable, but iterate on turbo voices when you
can.

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `TypeError: 'NoneType' object is not callable` at model load | `setuptools` ≥ 81 removed `pkg_resources`, which the `resemble-perth` watermarker still imports — its import fails *silently*. The pin `setuptools<81` is in `requirements.txt`; if you installed by hand, `pip install 'setuptools<81'` and retry. |
| `pip` can't resolve `chatterbox-tts` / no matching wheels | Use Python 3.10 (`uv venv --python 3.10`). The package declares 3.10+, but newer interpreters have hit missing wheels for its pinned dependencies. |
| First generation "hangs" for minutes | It's the one-time ~3.8 GB model download — watch the sidecar terminal. Pre-warm with `ddev artisan tts:chatterbox:health --deep`. |
| Generation is slower with `CHATTERBOX_DEVICE=mps` | Expected on Apple Silicon — MPS fallback bounces tensors between devices. Use `cpu`. |
| `tts:chatterbox:health` unreachable but `curl localhost:8766/health` works | The app runs *inside* a container: use `http://host.docker.internal:8766` in `.env`, not `127.0.0.1`. Then `ddev artisan config:clear`. |
| Port 8766 already taken | Start uvicorn with another `--port` and change `TTS_LOCAL_CHATTERBOX_URL` to match. |
| A clip-less turbo voice sounds different than on Replicate | Expected — see Limitations: named preset voices don't exist locally. |

## Limitations & differences vs Replicate

- **Turbo's named preset voices (Aaron…Walter) are a Replicate deployment
  feature.** Locally, a turbo voice without a reference clip speaks in the
  model's single built-in voice. Voices *with* clips clone identically.
- **One generation at a time.** The sidecar serializes requests; parallel
  chunk jobs queue up. `TTS_LOCAL_CHATTERBOX_TIMEOUT` covers that wait.
- **Spend counters still tick.** Est.-spend readouts use the catalog's per-1k
  rates regardless of provider. Locally the real cost is zero; set
  `TTS_COST_PER_1K_CHARS=0` and `TTS_COST_PER_1K_CHARS_TURBO=0` in `.env` if
  the numbers annoy you.
- **Seed pins are *more* honest locally.** A pinned seed reproduces the same
  take — identical token sequence, length, and phrasing — though the waveform
  bytes can still differ at floating-point-noise level (multi-threaded CPU
  math isn't bit-exact). Replicate's shared GPUs can't even promise the same
  take (see [STUDIO-TUNING.md](STUDIO-TUNING.md)).
- **The Perth audio watermark is still applied** — the open-source package
  watermarks output just like the hosted deployment.
