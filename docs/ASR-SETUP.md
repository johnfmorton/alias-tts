# ASR transcript QA — Whisper sidecar setup (Laravel Forge)

Optional. When enabled, every generated chunk is transcribed by a small local
Whisper service and the transcript is compared to the source text. This catches
Chatterbox failure modes the DSP tail-trim **cannot**:

| Failure | Example | Why DSP misses it | ASR signal |
|---|---|---|---|
| **Truncation** | model stops before finishing the sentence | there is no artifact — the audio just ends short | `tail_cov` (transcript never reaches the end of the script) |
| **Speech-like / "ghostly singing" tail** | a sung/babbled tail after the words end | it looks like speech (loud, high/variable ZCR) | `trail_s` (audio continues past the last recognized word) |
| **Mid-stream pause** | a long silent gap mid-chunk | not at the tail | `max_gap_s` (large gap between two words) |
| **No speech at all** (`NOSPEECH`) | the take is noise or near-silence — no recognizable words | the audio can still carry energy, so it doesn't read as silence | empty transcript |
| **Loud short tail** (`TAILNOISE`) | a brief but loud "swoosh" right after the last word | too **short** to trip `trail_s`, too loud/aperiodic for the DSP tail detector | tail **energy** — peak dBFS past the word's natural release, **and louder than the chunk's own speech** |
| **Boundary hum** (`BNDNOISE`) | a tonal low-frequency hum filling a sentence/comma gap | too **short** to trip `max_gap_s`; genuinely quiet, so energy alone can't see it | boundary-gap **energy + ZCR** — a punctuation-boundary gap that is not-silent **and** low-frequency |

The last two are **energy-aware** signals (added 2026-06-22): the duration-based
signals above measure *how long* the dead zones are, but Chatterbox anomalies
cluster at the **tail** and at **sentence/comma boundaries** as *short-but-loud*
junk that sails through a time threshold. These measure the actual dBFS in those
zones, aligned to the Whisper word timings. Validated on a labeled corpus; the
boundary thresholds are deliberately conservative (re-check on the prod sidecar).

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

- Python 3.10+ with `venv` (`sudo apt-get install -y python3-venv` if missing)
- The repo deployed on the server (the sidecar source ships in `asr-sidecar/`)

## Where the sidecar lives (read this first — Forge zero-downtime)

Forge zero-downtime deploys check the repo out into `releases/<id>/` and repoint
a `current` symlink at the newest one, **pruning old releases**. So the repo's
`asr-sidecar/` only ever exists inside an ephemeral release dir — and `.venv/` is
git-ignored, so a fresh release never contains it. **Do not build the venv inside
the release tree; it would be wiped on the next deploy.**

Instead, install into a **stable directory that sits beside `current`/`releases`/
`storage`** — i.e. directly under the site root. That level persists across
deploys (it's where Forge keeps `.env` and `storage/`), it's scoped to this site
(removed if you delete the site), and it's never web-served (the web root is
`current/public`). A typical Forge site root looks like:

```
/home/forge/<your-site>/
├── current -> releases/<id>/   # symlink, repointed each deploy
├── releases/                   # per-deploy checkouts, PRUNED
├── storage/                    # shared
├── .env
└── asr-sidecar/                # ← the STABLE runtime dir you create below
```

> Throughout, replace `<your-site>` with your real site directory, e.g.
> `alias-tts-xxxx.on-forge.com`.

## Install (run as the `forge` user, over SSH)

A fresh SSH session starts in `/home/forge`, so set `SITE` and use absolute paths
(every command below is self-contained):

```bash
SITE=/home/forge/<your-site>
mkdir -p "$SITE/asr-sidecar"
cd "$SITE/asr-sidecar"
```

Copy the sidecar code out of the current release into this stable dir (one file
per line — long combined `cp` commands can wrap and break when pasted):

```bash
cp "$SITE/current/asr-sidecar/app.py" .
```
```bash
cp "$SITE/current/asr-sidecar/requirements.txt" .
```

Create the virtualenv, install dependencies, and pre-download the model so the
daemon comes up warm (downloads to `~/.cache/huggingface`, which is outside the
release tree and persists):

```bash
python3 -m venv .venv
.venv/bin/pip install --upgrade pip
.venv/bin/pip install -r requirements.txt
.venv/bin/python -c "from faster_whisper import WhisperModel; WhisperModel('tiny', device='cpu', compute_type='int8')"
```

Confirm the dir now has `app.py`, `requirements.txt`, and `.venv/`:

```bash
ls -l "$SITE/asr-sidecar"
```

Optional smoke test (self-cleaning — `timeout` stops uvicorn after 6s):

```bash
cd "$SITE/asr-sidecar"
timeout 6 .venv/bin/uvicorn app:app --host 127.0.0.1 --port 8765 &
sleep 4
curl -s http://127.0.0.1:8765/health; echo
```
Expect `{"status":"ok","model":"tiny",...}`.

## Run it as a Forge Daemon

Forge panel → your server → **Daemons** (a.k.a. background processes) → New:

- **Command** — must be the **ABSOLUTE** path to the venv's uvicorn (see the note below):
  ```
  /home/forge/<your-site>/asr-sidecar/.venv/bin/uvicorn app:app --host 127.0.0.1 --port 8765
  ```
- **Directory / Working Directory:** `/home/forge/<your-site>/asr-sidecar`
  — under **Custom → Supervisor configuration**. **⚠️ Change this: Forge
  pre-fills it with the release path `/home/forge/<your-site>/current`**, but the
  sidecar's `app.py` and `.venv/` live in the stable `asr-sidecar/` dir *beside*
  `current`, not inside it. Left at the default, the daemon starts from `current/`
  and fails to import `app.py`. Click **Edit** on the Supervisor configuration and
  set the working directory to `.../asr-sidecar`.
- **User:** `forge`
- **Processes:** `1` (one warm model is enough; raise only if transcription becomes a bottleneck)

Leave the stop signal/seconds at their defaults.

> **The command MUST be absolute.** Supervisor resolves the program against the
> system `PATH` (effectively from `/`) *before* it `chdir`s into the working
> directory, so a relative `.venv/bin/uvicorn` fails with
> `FATAL can't find command '.venv/bin/uvicorn'`. The working directory is still
> required separately — `app:app` tells uvicorn to import `app.py` from the
> current directory, so you need **both** the absolute binary path **and** the
> working directory set.

The defaults (`tiny` / `cpu` / `int8` / English) need no environment variables.
To use the more accurate `base` model, wrap the command so the env var is set:

```
sh -c 'ASR_MODEL=base exec /home/forge/<your-site>/asr-sidecar/.venv/bin/uvicorn app:app --host 127.0.0.1 --port 8765'
```

## Enable it in the Laravel app

The easy path: the admin **health page** (and `php artisan tts:doctor`) turn
transcript QA on automatically the first time they find the sidecar reachable,
saving it as an editable setting. So once the daemon is up, open **Admin → Health**
once and ASR flips on. Pin or save a choice yourself and the auto-enable steps aside.

Two things to know about the auto-enable:

- **It's a per-user setting, not `.env`.** The Health page enables ASR only for
  the user who visited it (`tts:doctor` sweeps all users). Settings on `/v1`
  calls resolve from the **API-key owner**, so ASR can be on for the admin who
  opened Health while another user's API calls still run with it off. Pin
  `TTS_ASR_ENABLED=true` in `.env` to make it instance-wide.
- **Console commands never load per-user settings**, so the DB auto-enable is
  invisible to `tts:asr:health` — see "Verify the installation" below.

To pin it explicitly instead (port must match the daemon), add to the site's `.env`
and `php artisan config:cache`:

```dotenv
TTS_ASR_ENABLED=true
TTS_ASR_URL=http://127.0.0.1:8765
# Start by only recording verdicts (no auto re-roll/trim) so you can watch it
# against real traffic before it spends Replicate credits regenerating chunks.
# (The unattended /v1 path defaults to auto; set it back to log here too if you
# want to watch first — see "Per-path policy" below.)
TTS_ASR_ACTION=log
TTS_ASR_API_ACTION=log
```

Confirm the migration that adds `tts_chunks.asr_score` / `asr_report` has run
(the admin Studio path persists verdicts to these columns; the `/v1` path never
writes them — its flagged segments go to the app log — so it's safe regardless):

```bash
cd /home/forge/<your-site>/current
php artisan migrate:status   # 2026_06_20_000003_add_asr_to_tts_chunks_table → Ran
php artisan migrate --force  # only if it shows Pending
```

## Verify the installation

```bash
cd /home/forge/<your-site>/current
php artisan tts:asr:health --deep
```

A healthy install prints the loaded model + version, transcribes the bundled
fixture, and reports `Self-test PASSED`. It exits non-zero on failure, so you can
gate a deploy on it. The same status (without the transcription self-test) also
appears on the admin **Health** page and in `php artisan tts:doctor`.

> **If you relied on the Health-page auto-enable, pin `TTS_ASR_ENABLED=true` in
> `.env` (then `php artisan config:cache`) before running this.** The
> auto-enable stores a per-user setting that console commands never load, so
> without the pin `tts:asr:health` reports "ASR is disabled
> (TTS_ASR_ENABLED=false)" and exits **without testing anything**. If you'd
> rather not pin it, verify on the admin **Health** page instead.

## Updating the sidecar after a deploy

The stable dir is decoupled from deploys, so normal deploys don't touch it — and
don't need to. Only when `asr-sidecar/app.py` or `requirements.txt` actually
changes in a release, refresh the runtime copy and restart the daemon:

```bash
SITE=/home/forge/<your-site>
cp "$SITE/current/asr-sidecar/app.py" "$SITE/asr-sidecar/"
cp "$SITE/current/asr-sidecar/requirements.txt" "$SITE/asr-sidecar/"
"$SITE/asr-sidecar/.venv/bin/pip" install -r "$SITE/asr-sidecar/requirements.txt"  # only if deps changed
```
Then restart the daemon in Forge → Daemons.

## Automatic remediation (`TTS_ASR_ACTION=auto`)

Once you trust the verdicts, switch from `log` to `auto` and the service acts on a
flagged chunk/segment during generation — on **both** the editable-project path
(Studio) and the synchronous / queued `/v1` path (the API + Bespoken plugin):

- **TRUNC / PAUSE / NOSPEECH / BNDNOISE** (missing/garbled content, or a mid-stream
  boundary hum) → **re-roll** the chunk with a fresh random seed, up to
  `TTS_ASR_MAX_REROLLS` times, keeping the take with the best transcript coverage and
  stopping early on the first clean one. Each re-roll is a real Replicate call.
  `BNDNOISE` is re-rolled (not trimmed) because the hum sits *inside* the speech.
- **TAIL / TAILNOISE only** (junk/"singing"/loud tail, full coverage) → **precise-trim**
  at the ASR speech end. No Replicate call, and it catches the speech-like and
  short-but-loud tails the DSP trim can't. (If a re-rolled take ends up tail-only,
  it's trimmed too.)

On the project path the action taken is recorded in the chunk's `asr_report`
(`action` = `rerolled` / `rerolled_unrecovered` / `trimmed` / `trim_failed`); the
`/v1` path has no per-segment record, so flagged segments are written to the log
instead. A **manual** re-roll (the Studio re-roll button) is never auto-**re-rolled** —
you asked for exactly one new take, so it won't spend more Replicate calls behind your
back. A junk **TAIL / TAILNOISE** on that take *is* still precise-trimmed, though,
since that's a lossless cut strictly after the speech (no extra generation). If the
sidecar goes down mid-loop, the latest take is kept and generation continues.

### Per-path policy: manual in the Studio, automatic on the API

The two paths can be set independently with `TTS_ASR_STUDIO_ACTION` (editable-project
/ Studio) and `TTS_ASR_API_ACTION` (synchronous + queued `/v1`), and they **default
differently** to match who is watching. The Studio is interactive — an admin sees the
per-chunk ASR badge and re-rolls by hand — so it defaults to `log` (it inherits the
shared `TTS_ASR_ACTION`, which itself defaults to `log`). The unattended API / full-MP3
path can't prompt anyone, so it defaults to `auto` and self-heals. The block below just
spells out that out-of-the-box split; set `TTS_ASR_API_ACTION=log` to make the API ship
flagged takes as-is instead.

```dotenv
TTS_ASR_STUDIO_ACTION=log   # default (inherits TTS_ASR_ACTION)
TTS_ASR_API_ACTION=auto     # default
```

> **Latency on the synchronous endpoint.** On `POST /v1/text-to-speech/{voice_id}` the
> QA + any re-rolls run inline before the response, so they add to the request time
> (bounded by `max_text_length`). For long text prefer the async jobs endpoint
> (`.../jobs`) — it runs the *same* QA in the queue worker, off the request budget.

## Configuration reference (`config/tts.php` → `asr`)

| Env var | Default | Meaning |
|---|---|---|
| `TTS_ASR_ENABLED` | `false` | Master switch. Ships off, but the health page / `tts:doctor` flip it on automatically the first time they find the sidecar reachable — unless you pin it in `.env` or save a choice in Settings |
| `TTS_ASR_URL` | `http://127.0.0.1:8765` | Sidecar base URL |
| `TTS_ASR_TIMEOUT` | `30` | Per-call timeout (seconds) |
| `TTS_ASR_LANGUAGE` | `en` | Forced language, or `auto` |
| `TTS_ASR_DOCS_URL` | *(this guide on GitHub)* | "Setup guide" link shown by the health page / `tts:doctor` |
| `TTS_ASR_ACTION` | `log` | Shared default: `log` = record verdict only; `auto` = also remediate (see above) |
| `TTS_ASR_STUDIO_ACTION` | *(inherits `TTS_ASR_ACTION` ⇒ `log`)* | Per-path override for the Studio / editable-project path |
| `TTS_ASR_API_ACTION` | `auto` | Per-path override for the `/v1` API + queued path; unattended, so it self-heals by default |
| `TTS_ASR_MAX_REROLLS` | `3` | Max re-rolls per chunk when the effective action is `auto` |
| `TTS_ASR_TRAIL_S_MAX` | `1.2` | Audio after the last word above this ⇒ **TAIL** |
| `TTS_ASR_GAP_S_MAX` | `1.5` | Largest inter-word gap above this ⇒ **PAUSE** |
| `TTS_ASR_TAIL_COV_MIN` | `0.93` | Transcript must reach this far into the script, else **TRUNC** |
| `TTS_ASR_TRIM_GUARD_MS` | `80` | Audio kept after the last word when computing a TAIL/TAILNOISE trim point |
| `TTS_ASR_TAIL_ENERGY_DBFS_MAX` | `-38` | Tail peak energy (dBFS) above this — **and** louder than speech (below) — ⇒ **TAILNOISE** (short-but-loud swoosh) |
| `TTS_ASR_TAIL_OVER_SPEECH_DB` | `6` | TAILNOISE also requires the tail to be this many dB louder than the chunk's own speech, so a Whisper-under-timed soft word-coda (e.g. the "n" in "2019") isn't clipped. Higher = stricter |
| `TTS_ASR_TAIL_RELEASE_MS` | `200` | Tail audio skipped before measuring loudness, so a normal word-release isn't flagged |
| `TTS_ASR_BOUNDARY_GAP_MIN_MS` | `500` | Only inter-word gaps at a sentence/comma boundary at least this long are scrutinized for a hum |
| `TTS_ASR_BOUNDARY_ENERGY_DBFS_MAX` | `-55` | A boundary gap whose mean energy (dBFS) exceeds this — i.e. not clean silence — is a **BNDNOISE** candidate |
| `TTS_ASR_BOUNDARY_ZCR_MAX_HZ` | `1500` | …and whose ZCR is below this (tonal/low-frequency, a hum, not broadband speech) ⇒ **BNDNOISE** |
| `TTS_ASR_BOUNDARY_GAP_INSET_MS` | `100` | Trim each gap end before measuring (drops the adjacent words' onset/decay) |
| `TTS_ASR_ENERGY_WINDOW_MS` | `50` | Analysis window for the tail-peak measurement |

Sidecar-side env vars (set on the daemon command, not in `.env`):
`ASR_MODEL` (`tiny`), `ASR_DEVICE` (`cpu`), `ASR_COMPUTE_TYPE` (`int8`),
`ASR_LANGUAGE` (`en`), `ASR_DOWNLOAD_ROOT` (model cache dir).

### Paralinguistic sound tags (Chatterbox Turbo)

Turbo renders `[laugh]`-style tags as actual sounds, which never appear as
words in a transcript — so QA scores every chunk against its **tag-stripped**
expected text (`ChunkRemediator::score` → `ParalinguisticTags::strip`), and a
tagged chunk no longer false-flags as truncated. Residual limitation: the
rendered laugh/sigh itself is nonspeech audio that can still inflate the
duration/energy signals (`trail_s`, gap length, TAILNOISE) — especially a tag
at the very END of a chunk, which lands exactly where the tail detectors hunt.
If tag-heavy chunks over-flag in practice, prefer mid-sentence tags, or watch
with `TTS_ASR_ACTION=log` before trusting `auto` on tagged material.

## Troubleshooting

- **Daemon log shows `FATAL can't find command '.venv/bin/uvicorn'`** — the daemon
  Command is a relative path. Set it to the **absolute** uvicorn path
  (`/home/forge/<your-site>/asr-sidecar/.venv/bin/uvicorn …`). See the daemon note above.
- **`.venv/bin/uvicorn: No such file or directory` in an interactive shell** — a
  fresh SSH session starts in `/home/forge`; `cd /home/forge/<your-site>/asr-sidecar`
  first (or use absolute paths).
- **`tts:asr:health` says UNREACHABLE / `curl` returns nothing** — the daemon isn't
  running or the port differs. Check Forge → Daemons (status + log), and that
  `TTS_ASR_URL`'s port matches the daemon command.
- **"sidecar up but model did not load"** — the model download failed or the box is
  out of RAM. Re-run the pre-download step as `forge` and check free memory; try
  `tiny` if you tried `base`.
- **A long command broke when pasted** — your terminal wrapped it and ran a fragment
  as its own command. Run the boxed commands one line at a time.
- **Verdicts look wrong** — tune the thresholds above. They were validated on
  labeled samples but your voice/pacing may differ; watch `asr_report` on chunks
  (Studio / DB) with `TTS_ASR_ACTION=log` before enabling any automatic action.
- **A chunk's last word sounds clipped / `TAILNOISE · trimmed` on a clean take** —
  Whisper-tiny under-times soft final codas (a voiced nasal like the "n" in
  "2019"→"nineteen" can even return a zero-duration word), so the still-sounding word
  reads as a loud tail. The fix is the `TTS_ASR_TAIL_OVER_SPEECH_DB` gate (TAILNOISE
  only fires when the tail is *louder than the chunk's own speech*); if you still see
  it, **raise** that margin. The `asr_report` records `tail_peak_dbfs` and `speech_dbfs`
  so you can read the actual gap. (The badge also shows `tail_peak … vs speech …`.)
