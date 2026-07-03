# genblaze-runner

The Genblaze-owned orchestrator for the Mimic TTS pipeline. Drives
**chunk → generate → ASR score → (re-roll | trim) → stitch** as Genblaze
pipelines, persisting every take + manifest to Backblaze B2 as provenance.

Built for the **Backblaze "Build with Genblaze on B2"** hackathon.

## Install (dev)

```bash
pip install -e ../connectors/genblaze-mimic   # sibling connector (not on PyPI)
pip install -e ".[dev]"
```

## Configuration (env)

| Var | Purpose |
|---|---|
| `MIMIC_BASE_URL` | Base URL of the running Mimic service |
| `MIMIC_INTERNAL_SECRET` | Shared secret for `/v1/internal/*` (`X-Internal-Secret`) |
| `B2_BUCKET` / `B2_KEY_ID` / `B2_APP_KEY` / `B2_REGION` | Backblaze B2 provenance store (omit `B2_BUCKET` to run sink-less) |
| `TTS_STORAGE_ROOT` | Optional shared-bucket subfolder — uploads go under `<root>/genblaze/` to match the app (read directly from the app's env name; `MIMIC_STORAGE_ROOT` overrides) |
| `GENBLAZE_OUTPUT_DIR` | Local temp dir for audio before B2 upload |
| `GENBLAZE_MAX_REROLLS` / `GENBLAZE_MAX_CONCURRENCY` | Orchestration knobs |

## Smoke test

```bash
# Offline self-test (no creds) — validates the wiring with a fake client:
python -m genblaze_runner.smoke --offline
python -m genblaze_runner.smoke --offline --simulate-reroll

# Real proof-of-life (B2 + Mimic env set) — writes takes + manifests to B2:
python -m genblaze_runner.smoke
```

Exit code is `0` on PASS, `1` on FAIL. The report prints a per-chunk provenance
table (attempts, score, problems, B2 URLs) plus the final manifest hash and
`verify()` result.

## Serve

```bash
uvicorn genblaze_runner.app:app --port 8800   # POST /run, GET /health
```

## Test

```bash
pytest
```
