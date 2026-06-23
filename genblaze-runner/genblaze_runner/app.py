"""FastAPI surface for the Genblaze runner.

``POST /run`` synthesizes a project through the Genblaze-owned pipeline and
returns the final asset URL plus a per-chunk provenance summary (Studio renders
this). The Studio wiring lives behind this endpoint; see task A2.
"""

from __future__ import annotations

from fastapi import FastAPI
from fastapi.concurrency import run_in_threadpool
from pydantic import BaseModel

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


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "bespoken": _config.bespoken_base_url, "b2": bool(_config.b2_bucket)}


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
    )
    return {
        "final_url": result.final.url,
        "final_manifest_hash": result.final_manifest_hash,
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
