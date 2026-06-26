# Genblaze + Backblaze B2 — Runbook for a DDEV / Laravel Forge Developer

This guide assumes you know **DDEV** (local) and **Laravel Forge** (hosting) well,
and that the rest of this stack — Python, the Genblaze SDK, Backblaze B2 — is new.
It translates each new concept into something you already know, then gives exact,
copy-pasteable steps for **(1) running locally** and **(2) deploying for the
hackathon submission**.

> **The one-sentence reframe:** the two new services (the Whisper ASR sidecar and
> the Genblaze runner) are *Python sidecars* — persistent background processes,
> exactly like the Whisper daemon you already run on Forge. There is **no Docker
> required**. Your Forge + DDEV workflow still applies; you're adding one more
> daemon and one external bucket.

---

## 0. What you'll end up with

A normal Laravel app (Bespoken) that, when it generates speech, hands the work to
a small Python program (the **Genblaze runner**) which orchestrates the
chunk → generate → ASR-check → re-roll → stitch pipeline and writes every audio
take plus a tamper-evident "provenance manifest" to **Backblaze B2** (an
S3-compatible bucket). The hackathon judges open your normal Laravel URL; behind
it, Genblaze + B2 are doing the work they want to see.

---

## 1. Mental model — translate what you already know

| You know (Laravel / DDEV / Forge) | New-stack equivalent | What's actually different |
|---|---|---|
| `composer.json` + `composer install` → `vendor/` | `pyproject.toml` + `pip install` → `.venv/` | Same idea, Python flavor. Deps live in a per-project folder. |
| `composer require ./path/pkg` (local path, dev) | `pip install -e ./connectors/genblaze-bespoken` | `-e` = "editable"; your edits take effect immediately, no reinstall. |
| `php artisan <command>` | `python -m genblaze_runner.<module>` | How you run a Python "command". |
| A **Forge daemon** (persistent process) | How the Python sidecars run | You already do this for the Whisper sidecar. The Genblaze runner is one more. |
| Laravel **queue worker** | unchanged | Still a Forge daemon. |
| `config/filesystems.php` **S3 disk** | **Backblaze B2** (S3-compatible) | A bucket you rent from Backblaze. You don't host it. |
| `.env` (local) / Forge env panel (prod) | same file, **plus** new keys | `TTS_INTERNAL_SECRET`, `B2_*` — that's it. |
| **DDEV** (multi-container, managed for you) | **Docker Compose** (multi-container, you author) | Only relevant if you pick the *optional* VPS path. With Forge you skip Docker entirely. |
| **Nginx + Let's Encrypt** (Forge sets up) | **Caddy** (only in the Docker path) | With Forge, you keep nginx + Forge's HTTPS. Caddy never enters the picture. |

### The component map

```
        ┌───────────────── one server (Forge site) — or DDEV locally ─────────────────┐
        │                                                                              │
 you /  │   Bespoken (Laravel)  ──────────────► /v1/internal/* (chunk/generate/        │
 judge ─┼─► nginx + PHP, HTTPS                       score/trim/stitch)                │
        │     │                                         ▲                              │
        │     ├─ queue worker        (daemon)           │ orchestrated by              │
        │     ├─ Whisper ASR sidecar (daemon :8765) ◄───┤                              │
        │     └─ Genblaze runner     (daemon :8800) ────┘                              │
        │                 │                                                            │
        └─────────────────┼─────────────────────────────┬────────────────────────────┘
                          ▼                              ▼
                Replicate (Chatterbox TTS)      Backblaze B2 (provenance store)
                   [external service]              [external service]
```

Read it as: the **runner** calls back into Bespoken's `/v1/internal/*` endpoints
(which themselves call Replicate to generate and Whisper to score), and the runner
uploads results to **B2**. The runner and Whisper are both "localhost" sidecars,
just like your current setup.

---

## 2. The five moving parts (and which are new)

| Part | What it is | New to you? | How it runs |
|---|---|---|---|
| **Bespoken** | Your Laravel app | No | DDEV locally / Forge site in prod |
| **Queue worker** | Laravel queue | No | `php artisan queue:work` (Forge daemon) |
| **Whisper ASR sidecar** | Python FastAPI in `asr-sidecar/` | You've set it up (see `docs/ASR-SETUP.md`) | `uvicorn app:app --port 8765` (Forge daemon) |
| **Genblaze runner** | Python FastAPI/CLI in `genblaze-runner/` | **Yes** | `uvicorn genblaze_runner.app:app --port 8800` (Forge daemon) — same shape as Whisper |
| **Backblaze B2** | S3-compatible object storage | **Yes** | External bucket; configured via env vars |

Everything else (Replicate, your voices, the Studio UI) you already know.

---

## 3. One-time setup of the external accounts

### 3a. Backblaze B2 (this is the only genuinely new service)

Think of B2 as "S3, cheaper, from Backblaze." You'll create a bucket and an
access key, then drop four values into `.env`.

1. Sign up / log in at **backblaze.com** → left nav **B2 Cloud Storage**.
2. **Buckets → Create a Bucket.** Give it a name (globally unique-ish, e.g.
   `bespoken-genblaze-yourname`), keep it **Private**. Create it.
3. On the bucket list, note the **Endpoint**, which looks like
   `s3.us-west-004.backblazeb2.com`. The middle bit (`us-west-004`) is your
   **region** — you'll need it.
4. **Application Keys → Add a New Application Key.**
   - Name: `genblaze-runner`
   - Allow access to: **your bucket only** (not "All")
   - Create. Backblaze shows **keyID** and **applicationKey** **once** — copy both now.
5. You now have the four values:

   | Backblaze thing | Env var |
   |---|---|
   | keyID | `B2_KEY_ID` |
   | applicationKey | `B2_APP_KEY` |
   | bucket name | `B2_BUCKET` |
   | region (from the endpoint, e.g. `us-west-004`) | `B2_REGION` |

> Mental model: `B2_KEY_ID` + `B2_APP_KEY` are like `AWS_ACCESS_KEY_ID` +
> `AWS_SECRET_ACCESS_KEY`; `B2_BUCKET` is the bucket; `B2_REGION` picks the S3
> endpoint. The Genblaze SDK reads these env vars directly.

### 3b. Replicate

You already use Replicate for Chatterbox — reuse that token (`REPLICATE_API_TOKEN`).
The runner never talks to Replicate directly; Bespoken does, as it does today.

### 3c. GMI Cloud — intentionally *not* used for generation (and why)

The hackathon hands out optional GMI Cloud credits, and GMI's catalog even has a
model called **Chatterbox-tts** — so the obvious question is "shouldn't we use
that instead of Replicate?" **We evaluated it and deliberately did not.** Here's
the reasoning, so nobody re-opens it later:

- GMI hosts **Resemble AI's *commercial* Chatterbox**, which is a different
  product from the open-source `resemble-ai/chatterbox` we run on Replicate.
- Its API selects a voice by **`voice_uuid`** — a *pre-registered* voice (presets
  like Lucy / Nova / Titan, or a voice you set up inside Resemble's own platform).
  There is **no `audio_prompt` / reference-audio input.**
- Bespoken's whole premise is **zero-shot voice cloning from a user-uploaded
  clip** (we send that clip to Replicate's Chatterbox as `audio_prompt`). GMI's
  endpoint has nowhere to put that clip, so switching would **delete custom voices**
  and leave only the presets.
- GMI is **optional** and is **not part of the scored criteria** (those are B2
  integration, Genblaze orchestration, real-world utility, and production
  readiness). The free credits don't justify losing the headline feature.

> **Possible future add-on (non-scoring, end-of-line only):** expose GMI's *preset*
> voices as extra *built-in* voices via a `GmiResembleProvider` behind the existing
> `tts.provider` switch — cloning stays on Replicate. Skip it unless everything
> else is done with time to spare.

---

## 4. Python in five minutes (for a PHP developer)

You need Python **3.11 or newer**. Check:

```bash
python3 --version       # need >= 3.11; macOS via Homebrew/miniconda is fine
```

Three concepts and you're done:

- **virtualenv (`.venv/`)** = `vendor/`, but for Python and per-project. You
  "activate" it so `python`/`pip` use that folder's packages.
- **`pip install -e <dir>`** = `composer require` of a *local path package in dev*.
  The `-e` ("editable") means it's symlinked — edit the source, no reinstall.
- **`python -m some.module`** = `php artisan something` — run a module.

You'll create **one** virtualenv that contains both of our Python packages
(`genblaze-bespoken` = the Genblaze connector, `genblaze-runner` = the orchestrator).

---

## 5. Run it locally

Goal: prove the whole thing works on your machine before paying for a server.
There are **two levels**:

- **5C — Offline self-test:** zero external accounts, proves the wiring. Do this first.
- **5D — Real run:** hits Replicate + B2 for real. Do this once 5C passes.

### 5A. Bespoken (Laravel) via DDEV — the part you know

Add two keys to your `.env` (the internal API turns *on* only when the secret is set):

```dotenv
# pick any long random string; the runner must send the same value
TTS_INTERNAL_SECRET=please-change-me-to-a-long-random-string
# you already have this:
REPLICATE_API_TOKEN=r8_xxx
# optional but recommended (the ASR-gated re-roll is the differentiator):
TTS_ASR_ENABLED=true
```

Then:

```bash
ddev restart
# sanity check the internal API is alive (403 = secret required = good, it's mounted):
curl -i https://tts.ddev.site/v1/internal/chunk -X POST -d '{}' -H 'Content-Type: application/json'
```

### 5B. The Whisper ASR sidecar (only if `TTS_ASR_ENABLED=true`)

You've likely done this already — see **`docs/ASR-SETUP.md`**. Locally it's just:

```bash
cd asr-sidecar
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
.venv/bin/uvicorn app:app --host 127.0.0.1 --port 8765
# leave this running in its own terminal; check: curl http://127.0.0.1:8765/health
```

> Skip this section if you set `TTS_ASR_ENABLED=false` — the pipeline still runs,
> it just won't re-roll on quality problems (and `/internal/score` returns
> `{"available": false}`).

### 5C. Create the runner virtualenv and run the offline self-test

In a fresh terminal, from the repo root (`/Users/john/git/tts`):

```bash
python3 -m venv .venv-genblaze
source .venv-genblaze/bin/activate           # like "use this vendor folder"

pip install -e connectors/genblaze-bespoken  # the Genblaze connector
pip install -e genblaze-runner               # the orchestrator + smoke test

# Prove the wiring with NO accounts and NO Bespoken running:
python -m genblaze_runner.smoke --offline
python -m genblaze_runner.smoke --offline --simulate-reroll
```

Expected: a provenance table and `RESULT: PASS` (exit code 0). The
`--simulate-reroll` run shows a chunk marked `(re-rolled x1)`. If these pass, the
Python side is correctly installed.

### 5D. The real local run (Replicate + B2 + your DDEV Bespoken)

Still in the activated `.venv-genblaze`:

```bash
export BESPOKEN_BASE_URL="https://tts.ddev.site"
export BESPOKEN_INTERNAL_SECRET="<same value you put in .env as TTS_INTERNAL_SECRET>"
export B2_BUCKET="bespoken-genblaze-yourname"
export B2_KEY_ID="<keyID>"
export B2_APP_KEY="<applicationKey>"
export B2_REGION="us-west-004"

python -m genblaze_runner.smoke
```

A `RESULT: PASS` means: it chunked your text, generated each chunk on Replicate,
(optionally) ASR-scored and re-rolled, stitched the final MP3, and uploaded every
take + a verified manifest to B2. **Open the Backblaze web console → your bucket →
`genblaze/runs/...`** and you'll see `manifest.json` files and the audio assets.
That's the hackathon's core claim, demonstrated.

> **DDEV HTTPS gotcha (Python ↔ ddev cert).** Python doesn't trust DDEV's local
> certificate by default, so the real run above may fail with an SSL error. Two
> easy fixes:
> 1. **Simplest — do the real run against the deployed Forge site** (Section 6),
>    which has a real certificate. Use the offline self-test (5C) for local work.
> 2. **Stay local** by giving Python a CA bundle that trusts *both* public CAs
>    (for B2) and DDEV's local CA:
>    ```bash
>    cat "$(python -c 'import certifi; print(certifi.where())')" \
>        "$(mkcert -CAROOT)/rootCA.pem" > /tmp/genblaze-ca.pem
>    export SSL_CERT_FILE=/tmp/genblaze-ca.pem
>    ```
>    (DDEV installs its CA via `mkcert`; this appends it to the public bundle.)

### 5E. (Optional) Run the runner as a service locally

The Studio "Generate via Genblaze" button is **not built yet** (it's the next
task). Until it is, you exercise the pipeline via the smoke script above, or by
running the runner as an HTTP service and calling it yourself:

```bash
uvicorn genblaze_runner.app:app --host 127.0.0.1 --port 8800
# in another terminal:
curl -s http://127.0.0.1:8800/run -H 'Content-Type: application/json' \
  -d '{"text":"Welcome to media provenance. Every word is verifiable.","voice":"default"}' | jq
```

---

## 6. Deploy for the hackathon submission

You have **two paths**. The plan originally picked Docker Compose, but given your
Forge fluency, **Path A (Forge) is the lower-friction choice and reuses your
existing Whisper-as-daemon setup.** Pick A unless you have a specific reason for B.

### Path A — Laravel Forge (recommended; matches your skills)

The shape: one Forge server, one site (Bespoken), **three daemons** (queue,
Whisper, runner). B2 + Replicate are external. The judge-facing URL is your
normal Forge site URL.

**Step 1 — Deploy Bespoken like any Forge site.**
Provision a server, create the site, connect the `feat/genblaze-b2` branch (or
merge it to `main` first), deploy. This is your normal workflow.

**Step 2 — Set the environment** (Forge → Site → Environment). Add to your usual keys:

```dotenv
TTS_INTERNAL_SECRET=<long random string>
TTS_ASR_ENABLED=true
REPLICATE_API_TOKEN=r8_xxx
# B2 (the runner reads these; harmless to also have here for reference)
B2_BUCKET=...
B2_KEY_ID=...
B2_APP_KEY=...
B2_REGION=us-west-004
```

**Step 3 — Whisper sidecar daemon.** Exactly as in `docs/ASR-SETUP.md` (you've done
this): build the venv under `asr-sidecar/`, then add a **Forge Daemon**:

- Command: `/home/forge/<your-site>/asr-sidecar/.venv/bin/uvicorn app:app --host 127.0.0.1 --port 8765`
- Directory: `/home/forge/<your-site>/asr-sidecar`

**Step 4 — Genblaze runner daemon (the one new thing).** SSH into the Forge server
and build a venv for the runner (one-time, plus after dependency changes):

```bash
cd /home/forge/<your-site>
python3 -m venv runner-venv
runner-venv/bin/pip install -e connectors/genblaze-bespoken -e genblaze-runner
```

The runner needs its env vars at runtime. Forge daemons don't read the site's
`.env`, so use a tiny wrapper script (this mirrors a Forge deploy script):

`/home/forge/<your-site>/run-genblaze.sh`
```bash
#!/usr/bin/env bash
set -e
export BESPOKEN_BASE_URL="https://<your-site-domain>"        # the public HTTPS URL (real cert)
export BESPOKEN_INTERNAL_SECRET="<same as TTS_INTERNAL_SECRET>"
export B2_BUCKET="..." B2_KEY_ID="..." B2_APP_KEY="..." B2_REGION="us-west-004"
export GENBLAZE_MAX_CONCURRENCY=1                            # start conservative (Replicate throttling)
exec /home/forge/<your-site>/runner-venv/bin/uvicorn genblaze_runner.app:app --host 127.0.0.1 --port 8800
```
```bash
chmod +x /home/forge/<your-site>/run-genblaze.sh
```

Then add a **Forge Daemon**:
- Command: `/home/forge/<your-site>/run-genblaze.sh`
- Directory: `/home/forge/<your-site>`

> Note the runner's `BESPOKEN_BASE_URL` is the site's **public HTTPS URL** (real
> certificate → no SSL workaround needed in prod). It loops out and back through
> nginx; that's fine.

**Step 5 — Add the runner venv rebuild to your deploy script** so deploys stay
one-click (so a `git pull` that changes Python deps doesn't break the daemon):

```bash
# in the Forge deploy script, after composer/npm steps:
/home/forge/<your-site>/runner-venv/bin/pip install -q -e connectors/genblaze-bespoken -e genblaze-runner
# (Forge restarts daemons automatically after deploy)
```

**Step 6 — Verify on the server.** SSH in and run the smoke against production:

```bash
cd /home/forge/<your-site>
source runner-venv/bin/activate
# reuse the same env as run-genblaze.sh, then:
python -m genblaze_runner.smoke
```

`RESULT: PASS` + new objects in the B2 console = you're submission-ready on the
backend. (The judge-facing "Generate via Genblaze" Studio button is the remaining
build task; until it ships, the demo is driven via this runner.)

### Path B — Docker Compose on a plain VPS (the plan's original pick)

Choose this only if you'd rather not run Forge daemons. It's a single
`docker-compose.yml` with five services — app (php-fpm+nginx), queue worker,
whisper, runner, and **Caddy** (which replaces Forge's nginx+Let's Encrypt and
gives you automatic HTTPS). It's more new ground (you author the Dockerfiles and
Compose file) for the same result. **The `docker/` files for this path are not
written yet** — say the word and I'll generate them. Most people in your shoes
should use Path A.

---

## 7. How this maps to the hackathon submission requirements

| Requirement | Where it comes from |
|---|---|
| Functional, accessible application **URL** | Your Forge site URL (the Studio) |
| Public/private **GitHub repo** with setup | This repo (`feat/genblaze-b2` → merge to `main`); this file is the setup doc |
| **B2 used meaningfully** | Console screenshot of `genblaze/runs/...` (takes + manifests) |
| **Genblaze used meaningfully** | The runner orchestrates gen→QA→re-roll→stitch; the manifest proves it |
| List of **AI providers/models** | Replicate Chatterbox (TTS) + faster-whisper (ASR) |
| ~3-min **demo video** | Show a generation + the B2 provenance + `manifest.verify()` |

---

## 8. Troubleshooting cheat-sheet

| Symptom | Cause / fix |
|---|---|
| `python -m genblaze_runner...` → `No module named genblaze_runner` | The venv isn't activated, or you skipped `pip install -e genblaze-runner`. Re-run Section 5C. |
| Real smoke → SSL/certificate error against `tts.ddev.site` | DDEV cert not trusted by Python. Use the deployed site, or the `SSL_CERT_FILE` combined-bundle trick in 5D. |
| `/v1/internal/...` → 503 "internal pipeline API is disabled" | `TTS_INTERNAL_SECRET` is empty in the app's env. Set it, `ddev restart` / Forge redeploy. |
| `/v1/internal/...` → 403 | The runner's `BESPOKEN_INTERNAL_SECRET` doesn't match the app's `TTS_INTERNAL_SECRET`. |
| Smoke → `{"available": false}` on every chunk | ASR is off or the Whisper sidecar isn't running. Start it (5B) and set `TTS_ASR_ENABLED=true`. |
| B2 auth error in the real run | Wrong `B2_REGION` (must match the bucket's endpoint), or the app key isn't scoped to that bucket. |
| Replicate rate-limit / slow | Keep `GENBLAZE_MAX_CONCURRENCY=1` and short demo text; Replicate throttles bursty traffic. |
| Lots of `preflight.unknown ... permissive fallback` lines | Benign (our providers don't register a model "family"). The smoke script already silences them. |
