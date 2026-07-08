# Genblaze runner + Backblaze B2 — provenance pipeline setup

The **Genblaze runner** is a companion service that every full install should
run. It is a small Python (FastAPI) sidecar that owns whole-render
orchestration — chunk → generate → ASR-score → re-roll → stitch — by calling
back into the app's internal pipeline API, and archives **every take plus a
verifiable provenance manifest** to a **Backblaze B2** bucket. It powers:

- **Generate via Genblaze** — the Studio page that is the app's primary
  generation workflow (surfaced in the nav as "Genblaze Demo" when
  `TTS_GENBLAZE_DEMO=true`), and
- the **pronunciation pre-processor**'s LLM step (`POST /pronounce`) — see
  [ALIAS-PRONUNCIATION-PREPROCESSOR.md](ALIAS-PRONUNCIATION-PREPROCESSOR.md).

The bare `/v1` API runs without it, but the app as designed —
provenance-tracked renders, QA-gated re-rolls, pronunciation review — expects
the runner. Treat this as part of a standard install, not an add-on.

## How it fits together

```
        ┌────────────────────────── one server ──────────────────────────────┐
        │                                                                    │
        │   Alias (Laravel) ───────────────► /v1/internal/* (chunk/generate/ │
        │     │                                   score/trim/stitch)        │
        │     ├─ queue worker         (daemon)          ▲                    │
        │     ├─ Whisper ASR sidecar  (daemon :8765) ◄──┤ orchestrated by    │
        │     └─ Genblaze runner      (daemon :8800) ───┘                    │
        │                  │                                                 │
        └──────────────────┼──────────────────────────┬──────────────────────┘
                           ▼                          ▼
                 Replicate (Chatterbox TTS)   Backblaze B2 (provenance store)
```

The runner owns the *orchestration*; every heavy operation still runs inside
the app, through the same services the public `/v1` and Studio paths use. The
internal API (`/v1/internal/chunk|generate|score|trim|stitch`, plus
`/v1/internal/genblaze/progress` for the Studio page's live checklist) mounts
only when `TTS_INTERNAL_SECRET` is set, and every call must present that
secret. The runner never talks to Replicate directly — the app does, exactly
as it does for normal generation.

The **Whisper ASR sidecar** should run alongside the runner: it is what gates
the re-roll step. Without it (`TTS_ASR_ENABLED=false`) the pipeline still
runs, but flawed takes ship un-re-rolled. Setup: [ASR-SETUP.md](ASR-SETUP.md).

## One-time: create the B2 bucket

Any S3-compatible bucket works, but the runner's env vars are B2-shaped:

1. Backblaze console → **B2 Cloud Storage** → **Buckets → Create a Bucket**.
   Keep it **Private**.
2. Note the bucket's **Endpoint** (e.g. `s3.us-west-004.backblazeb2.com`) —
   the middle segment (`us-west-004`) is your **region**.
3. **Application Keys → Add a New Application Key**, scoped to **this bucket
   only**, with Read + Write capabilities. Backblaze shows the **keyID** and
   **applicationKey** once — copy both.

**Also point the app's `s3` disk at the same bucket.** Studio plays provenance
audio from the private bucket through an authenticated app proxy, and that
proxy reads the standard Laravel `s3` disk — so set the `AWS_*` keys
(bucket, endpoint, path-style: see
[DEPLOYMENT.md → Storage](DEPLOYMENT.md#storage-local-vs-s3-compatible)) to
this bucket even if generated speech itself stays on `TTS_STORAGE_DISK=local`.

## Environment reference

**App (`.env`):**

```env
TTS_INTERNAL_SECRET=<long random string>   # mounts /v1/internal/*; empty = API disabled (503)
TTS_GENBLAZE_RUNNER_URL=http://127.0.0.1:8800   # the default; empty disables the runner
TTS_GENBLAZE_TIMEOUT=600                   # seconds the app waits on the runner (default 600)
TTS_GENBLAZE_DEMO=false                    # true shows the judge-facing "Genblaze Demo" nav page
TTS_ASR_ENABLED=true                       # optional: enables the re-roll quality gate
# AWS_* → the B2 bucket, for the Studio audio proxy (see above)
```

**Runner (its own process environment — daemons don't read the site's `.env`):**

| Variable | Meaning |
|---|---|
| `ALIAS_BASE_URL` | The app's URL (`https://tts.example.com`, or `https://tts.ddev.site` locally) |
| `ALIAS_INTERNAL_SECRET` | Must equal the app's `TTS_INTERNAL_SECRET` |
| `ALIAS_API_KEY` | Optional — only for the standalone TTS provider (Posture A); the orchestrated run authenticates everything with the internal secret |
| `AWS_BUCKET` / `AWS_ENDPOINT` / `AWS_DEFAULT_REGION` | **Provenance storage — the app's own `AWS_*` config.** The runner writes to the SAME bucket the app uses, on **any S3 provider**: leave `AWS_ENDPOINT` blank for AWS S3, or set it for B2 / R2 / MinIO (matches the app's `.env`). |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | Storage credentials (read/write/delete/list on the bucket) |
| `AWS_URL` | Optional: public URL base for object links if you front the bucket |
| `B2_KEY_ID` / `B2_APP_KEY` / `B2_BUCKET` / `B2_REGION` / `B2_PUBLIC_URL_BASE` | **Legacy** Backblaze-only fallback, used only when the `AWS_*` block above is unset. New installs should use `AWS_*`. |
| `TTS_STORAGE_ROOT` | Optional: the app's shared-bucket subfolder — uploads go under `<root>/genblaze/` so both sides agree (read directly; `ALIAS_STORAGE_ROOT` overrides) |
| `GENBLAZE_MAX_CONCURRENCY` | Parallel chunk generations (default `2`; use `1` if Replicate throttles) |
| `GENBLAZE_MAX_REROLLS` | Re-roll budget per chunk (default `3`) |
| `GENBLAZE_OUTPUT_DIR` | Local temp dir for audio before upload (default: system temp) |

> **Renamed from `BESPOKEN_*`.** The runner still reads the old
> `BESPOKEN_BASE_URL` / `BESPOKEN_INTERNAL_SECRET` / `BESPOKEN_API_KEY` names as a
> fallback, so an existing daemon keeps working — but set the `ALIAS_*` names and
> drop the old ones when convenient.

## Run it locally

You need **Python 3.11+**. Both Python packages install editable into one
virtualenv (from the repo root):

```bash
python3 -m venv .venv-genblaze
source .venv-genblaze/bin/activate
pip install -e connectors/genblaze-alias -e genblaze-runner
```

**Offline self-test first** — no accounts, no app, proves the install:

```bash
python -m genblaze_runner.smoke --offline
python -m genblaze_runner.smoke --offline --simulate-reroll   # shows a re-rolled chunk
```

Expect a provenance table and `RESULT: PASS` (exit 0).

**Real run** — set `TTS_INTERNAL_SECRET` in the app's `.env` (and restart the
app), export the runner env vars above, then:

```bash
python -m genblaze_runner.smoke    # real when env is set, offline otherwise
```

`PASS` means it chunked, generated on Replicate, scored/re-rolled (if ASR is
on), stitched, and uploaded takes + manifest to `genblaze/runs/...` in your
bucket. To drive it over HTTP instead (what the Studio page does):

```bash
uvicorn genblaze_runner.app:app --host 127.0.0.1 --port 8800
curl http://127.0.0.1:8800/health
```

> **DDEV HTTPS gotcha:** Python doesn't trust DDEV's local certificate, so a
> real run against `https://tts.ddev.site` can fail with an SSL error. Either
> run against a deployed site (real certificate), or give Python a CA bundle
> that trusts both public CAs (for B2) and DDEV's `mkcert` CA:
>
> ```bash
> cat "$(python -c 'import certifi; print(certifi.where())')" \
>     "$(mkcert -CAROOT)/rootCA.pem" > /tmp/genblaze-ca.pem
> export SSL_CERT_FILE=/tmp/genblaze-ca.pem
> ```

## Production

> **This is a long-running daemon, NOT a cron job.** The script starts a
> `uvicorn` server that must stay up continuously, so run it as a **Forge
> Background Process** (site → **Processes → Background processes → Add**) — or
> any supervisor-managed program — with autostart + autorestart. Do **not** put
> it in the Scheduler / crontab: that would spawn a new server every minute.
> (The scheduler cron is a separate thing, for `speech:cleanup`; see
> [DEPLOYMENT.md §8](DEPLOYMENT.md).)

The runner is one more persistent process next to the queue worker and the
Whisper sidecar — a Forge **daemon**, or anything supervisor-shaped. The
recipe below assumes Forge's atomic (zero-downtime) releases, where the live
code sits behind a `current` symlink; on a non-atomic site, drop the
`current/` segment from the paths.

**1. Build the venv at the site root** — *outside* the release directories,
so it survives deploys:

```bash
cd /home/forge/your-site
python3 -m venv runner-venv
runner-venv/bin/pip install ./current/connectors/genblaze-alias ./current/genblaze-runner
```

This pulls the Python dependencies into `runner-venv`. The wrapper below puts
the *live* package code on `PYTHONPATH` through the `current` symlink, so a
normal deploy updates the runner's code without reinstalling — re-run this
`pip install` only when the Python dependencies change.

**2. Wrapper script** — daemons don't read the site's `.env`, so the runner
gets its env from a small launcher at `/home/forge/your-site/run-genblaze.sh`.
Copy the ready-made prototype from the repo and edit one line (`SITE=`):

```bash
cp $SITE/current/genblaze-runner/run-genblaze.sh.example /home/forge/your-site/run-genblaze.sh
```

Rather than duplicating secrets, the prototype **sources the shared values
straight from the site's `.env`** (the app's `AWS_*` storage config for the
provenance sink, `TTS_INTERNAL_SECRET`, `APP_URL`, the pronunciation LLM key,
and `TTS_STORAGE_ROOT` if you scope the bucket) — so each value is defined
exactly once and panel edits to the `.env` carry over on the next daemon
restart:

```bash
#!/usr/bin/env bash
set -e

SITE=/home/forge/your-site
ENV_FILE="$SITE/current/.env"

export PYTHONPATH="$SITE/current/genblaze-runner:$SITE/current/connectors/genblaze-alias"

set -a
. <(grep -E '^(REPLICATE_API_TOKEN|ANTHROPIC_API_KEY|GEMINI_API_KEY|OPENAI_API_KEY|B2_KEY_ID|B2_APP_KEY|B2_BUCKET|B2_REGION|B2_PUBLIC_URL_BASE|AWS_ACCESS_KEY_ID|AWS_SECRET_ACCESS_KEY|AWS_BUCKET|AWS_DEFAULT_REGION|AWS_ENDPOINT|AWS_URL|TTS_INTERNAL_SECRET|TTS_STORAGE_ROOT|APP_URL)=' "$ENV_FILE")
set +a

export ALIAS_INTERNAL_SECRET="${TTS_INTERNAL_SECRET:-}"
export ALIAS_BASE_URL="${APP_URL:-}"   # must be the public HTTPS URL (real cert)
export GENBLAZE_MAX_CONCURRENCY=1      # conservative — avoids Replicate burst limits

exec "$SITE/runner-venv/bin/uvicorn" genblaze_runner.app:app --host 127.0.0.1 --port 8800
```

The runner reads the app's `AWS_*` storage config directly, so it writes
provenance to the **same bucket the app uses, on any S3 provider** — no separate
storage setup. (The legacy `B2_*` names are still sourced as a fallback for an
older install.) `chmod +x` it, then add the daemon (command = the script,
directory = the site root). The runner binds to localhost only; its
`ALIAS_BASE_URL` loops out through the public HTTPS URL, which is fine.

**3. Restart on deploy** — uvicorn loads code at start, so restart the runner
daemon after any deploy that touches its Python code (Forge's daemon panel,
or a restart line in the deploy script).

**4. Verify:** run `php artisan tts:doctor` — its **Genblaze runner** check
confirms the runner responds, the internal secret is set, the storage roots
agree, the callback URL matches the app, and the storage sink is on (same checks
on the dashboard's Health page). For a deeper probe, `curl
http://127.0.0.1:8800/health` on the server, then run the smoke
(`source runner-venv/bin/activate`, export the same env, and
`python -m genblaze_runner.smoke`) and confirm new objects under
`genblaze/runs/...` in your bucket. The **Genblaze Demo** nav page appears
once `TTS_GENBLAZE_DEMO=true` (the page needs the runner reachable at
`TTS_GENBLAZE_RUNNER_URL` to actually run).

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `No module named genblaze_runner` | Venv not activated, or the editable installs were skipped. |
| SSL error against `tts.ddev.site` | DDEV cert not trusted by Python — use the `SSL_CERT_FILE` bundle above, or run against a deployed site. |
| `/v1/internal/...` → 503 "disabled" | `TTS_INTERNAL_SECRET` is empty in the app's env. Set it and restart/redeploy. |
| `/v1/internal/...` → 403 | Runner's `ALIAS_INTERNAL_SECRET` ≠ app's `TTS_INTERNAL_SECRET`. |
| Every chunk scores `{"available": false}` | ASR is off or the Whisper sidecar isn't running — see [ASR-SETUP.md](ASR-SETUP.md). |
| B2 auth error | `B2_REGION` doesn't match the bucket's endpoint, or the app key isn't scoped to that bucket. |
| Provenance audio won't play in Studio | The app's `s3` disk isn't pointed at the B2 bucket (the proxy streams through it). |
| Replicate rate-limits / slow runs | Lower `GENBLAZE_MAX_CONCURRENCY` to `1`; Replicate throttles bursty traffic. |
| `preflight.unknown ... permissive fallback` log lines | Benign — the providers don't register a Genblaze model "family". |
