"""FastAPI surface for the Genblaze runner.

``POST /run`` synthesizes a project through the Genblaze-owned pipeline and
returns the final asset URL plus a per-chunk provenance summary (Studio renders
this). The Studio wiring lives behind this endpoint; see task A2.
"""

from __future__ import annotations

from fastapi import FastAPI
from fastapi.concurrency import run_in_threadpool
from pydantic import BaseModel

from genblaze_runner import pronounce
from genblaze_runner.config import RunnerConfig
from genblaze_runner.orchestrator import Orchestrator
from genblaze_runner.sink import build_sink

app = FastAPI(title="Genblaze Runner")
_config = RunnerConfig.from_env()


class RunRequest(BaseModel):
    text: str
    voice: str = "default"
    output_format: str = "mp3_44100_128"
    seed: int | None = None
    settings: dict | None = None
    run_id: str | None = None  # opaque Studio run id; echoed back on progress pings
    chunk_mode: str | None = None  # the dispatching user's chunking mode ('packed'/'sentence')


class PronounceRequest(BaseModel):
    text: str
    known_terms: list[str] = []
    provider: str = "replicate"  # admin-selectable; Replicate-via-Genblaze by default
    model: str | None = None
    temperature: float = 0.2


@app.get("/health")
def health() -> dict:
    return {
        "status": "ok",
        "alias": _config.alias_base_url,
        # "is a provenance sink configured?" — true for either the AWS_* (any S3
        # provider) or the legacy B2_* path. Named "b2" for back-compat with the
        # app's health check.
        "b2": bool(_config.s3_bucket or _config.b2_bucket),
        # Reported so the app's tts:doctor can flag a shared-bucket subfolder
        # disagreement (uploads and the provenance proxy must use the same root).
        "storage_root": _config.storage_root,
        "pronounce": pronounce.available_providers(),
    }


@app.post("/run")
async def run(req: RunRequest) -> dict:
    orchestrator = Orchestrator.from_config(_config, sink=build_sink(_config))
    result = await run_in_threadpool(
        orchestrator.synthesize_project,
        text=req.text,
        voice=req.voice,
        settings=req.settings,
        output_format=req.output_format,
        base_seed=req.seed,
        max_concurrency=_config.max_concurrency,
        run_id=req.run_id,
        chunk_mode=req.chunk_mode,
    )
    return {
        "final_url": result.final.url,
        "final_manifest_hash": result.final_manifest_hash,
        "final_manifest_verified": result.final_manifest_verified,
        "reroll_count": result.reroll_count,
        "chunks": [
            {
                "position": c.position,
                "attempts": c.attempts,
                "trim_applied": c.trim_applied,
                "audio_url": c.audio.url,
                "manifest_hash": c.manifest_hash,
                "verdict": c.verdict,
            }
            for c in result.chunks
        ],
    }


@app.post("/pronounce")
async def pronounce_text(req: PronounceRequest) -> dict:
    return await run_in_threadpool(
        pronounce.detect_substitutions,
        text=req.text,
        known_terms=req.known_terms,
        provider=req.provider,
        model=req.model,
        temperature=req.temperature,
        config=_config,
    )
