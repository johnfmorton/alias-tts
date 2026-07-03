# Genblaze runner + Backblaze B2 — provenance pipeline setup

The **Genblaze runner** is a companion service that every full install should
run. It is a small Python (FastAPI) sidecar that owns whole-render
orchestration — chunk → generate → ASR-score → re-roll → stitch — by calling
back into the app's internal pipeline API, and archives **every take plus a
verifiable provenance manifest** to a **Backblaze B2** bucket. It powers:

- **Generate via Genblaze** — the Studio page that is the app's primary
  generation workflow (and the post-login landing page once
  `TTS_GENBLAZE_RUNNER_URL` is set), and
- the **pronunciation pre-processor**'s LLM step (`POST /pronounce`) — see
  [MIMIC-PRONUNCIATION-PREPROCESSOR.md](MIMIC-PRONUNCIATION-PREPROCESSOR.md).

The bare `/v1` API runs without it, but the app as designed —
provenance-tracked renders, QA-gated re-rolls, pronunciation review — expects
the runner. Treat this as part of a standard install, not an add-on.

## How it fits together

```
        ┌────────────────────────── one server ──────────────────────────────┐
        │                                                                    │
        │   Mimic (Laravel) ───────────────► /v1/internal/* (chunk/generate/ │
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
TTS_GENBLAZE_RUNNER_URL=http://127.0.0.1:8800   # empty hides the Studio page
TTS_GENBLAZE_TIMEOUT=600                   # seconds the app waits on the runner (default 600)
TTS_ASR_ENABLED=true                       # optional: enables the re-roll quality gate
# AWS_* → the B2 bucket, for the Studio audio proxy (see above)
```

**Runner (its own process environment — daemons don't read the site's `.env`):**

| Variable | Meaning |
|---|---|
| `MIMIC_BASE_URL` | The app's URL (`https://tts.example.com`, or `https://tts.ddev.site` locally) |
| `MIMIC_INTERNAL_SECRET` | Must equal the app's `TTS_INTERNAL_SECRET` |
| `MIMIC_API_KEY` | A dashboard API key (sent as the `xi-api-key` header) |
| `B2_KEY_ID` / `B2_APP_KEY` | The application key pair from the B2 console |
| `B2_BUCKET` / `B2_REGION` | Bucket name + region from the endpoint (e.g. `us-west-004`) |
| `B2_PUBLIC_URL_BASE` | Optional: base URL for object links if you front the bucket |
| `GENBLAZE_MAX_CONCURRENCY` | Parallel chunk generations (default `2`; use `1` if Replicate throttles) |
| `GENBLAZE_MAX_REROLLS` | Re-roll budget per chunk (default `3`) |
| `GENBLAZE_OUTPUT_DIR` | Local temp dir for audio before upload (default: system temp) |

> **Renamed from `BESPOKEN_*`.** The runner still reads the old
> `BESPOKEN_BASE_URL` / `BESPOKEN_INTERNAL_SECRET` / `BESPOKEN_API_KEY` names as a
> fallback, so an existing daemon keeps working — but set the `MIMIC_*` names and
> drop the old ones when convenient.

## Run it locally

You need **Python 3.11+**. Both Python packages install editable into one
virtualenv (from the repo root):

```bash
python3 -m venv .venv-genblaze
source .venv-genblaze/bin/activate
pip install -e connectors/genblaze-mimic -e genblaze-runner
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
runner-venv/bin/pip install ./current/connectors/genblaze-mimic ./current/genblaze-runner
```

This pulls the Python dependencies into `runner-venv`. The wrapper below puts
the *live* package code on `PYTHONPATH` through the `current` symlink, so a
normal deploy updates the runner's code without reinstalling — re-run this
`pip install` only when the Python dependencies change.

**2. Wrapper script** — daemons don't read the site's `.env`, so the runner
gets its env from a small launcher, e.g. `/home/forge/your-site/run-genblaze.sh`:

```bash
#!/usr/bin/env bash
set -e
SITE=/home/forge/your-site
export PYTHONPATH="$SITE/current/genblaze-runner:$SITE/current/connectors/genblaze-mimic"
export MIMIC_BASE_URL="https://your-domain.com"      # public HTTPS URL (real cert)
export MIMIC_INTERNAL_SECRET="<same as TTS_INTERNAL_SECRET>"
export MIMIC_API_KEY="<a dashboard API key>"
export B2_BUCKET="..." B2_KEY_ID="..." B2_APP_KEY="..." B2_REGION="us-west-004"
export GENBLAZE_MAX_CONCURRENCY=1
# Pronunciation pre-processor only — key(s) for the runner's LLM provider:
# export ANTHROPIC_API_KEY=...   (or REPLICATE_API_TOKEN, GEMINI_API_KEY, OPENAI_API_KEY)
exec "$SITE/runner-venv/bin/uvicorn" genblaze_runner.app:app \
    --host 127.0.0.1 --port 8800
```

`chmod +x` it, then add the daemon (command = the script, directory = the site
root). The runner binds to localhost only; its `MIMIC_BASE_URL` loops out
through the public HTTPS URL, which is fine.

**3. Restart on deploy** — uvicorn loads code at start, so restart the runner
daemon after any deploy that touches its Python code (Forge's daemon panel,
or a restart line in the deploy script).

**4. Verify:** `curl http://127.0.0.1:8800/health` on the server, then run the
smoke (`source runner-venv/bin/activate`, export the same env, and
`python -m genblaze_runner.smoke`) and confirm new objects under
`genblaze/runs/...` in the B2 console. In Studio, **Generate via Genblaze**
appears once `TTS_GENBLAZE_RUNNER_URL` is set.

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `No module named genblaze_runner` | Venv not activated, or the editable installs were skipped. |
| SSL error against `tts.ddev.site` | DDEV cert not trusted by Python — use the `SSL_CERT_FILE` bundle above, or run against a deployed site. |
| `/v1/internal/...` → 503 "disabled" | `TTS_INTERNAL_SECRET` is empty in the app's env. Set it and restart/redeploy. |
| `/v1/internal/...` → 403 | Runner's `MIMIC_INTERNAL_SECRET` ≠ app's `TTS_INTERNAL_SECRET`. |
| Every chunk scores `{"available": false}` | ASR is off or the Whisper sidecar isn't running — see [ASR-SETUP.md](ASR-SETUP.md). |
| B2 auth error | `B2_REGION` doesn't match the bucket's endpoint, or the app key isn't scoped to that bucket. |
| Provenance audio won't play in Studio | The app's `s3` disk isn't pointed at the B2 bucket (the proxy streams through it). |
| Replicate rate-limits / slow runs | Lower `GENBLAZE_MAX_CONCURRENCY` to `1`; Replicate throttles bursty traffic. |
| `preflight.unknown ... permissive fallback` log lines | Benign — the providers don't register a Genblaze model "family". |
