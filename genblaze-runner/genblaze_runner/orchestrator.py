"""The Genblaze-owned TTS orchestrator (Posture B).

This is the piece that makes Genblaze *orchestrate* rather than merely store:
it drives the chunk -> generate -> ASR-score -> (re-roll | trim) -> stitch flow
that Bespoken's ``ChunkRemediator`` does internally, but expressed as Genblaze
pipelines so every take + verdict + manifest is provenance-tracked to B2.

Two phases (forced by Genblaze's static DAG: ``input_from`` is same-run only,
and there is no conditional/loop node):

* **Phase 1 — per chunk.** A ``gen -> QA`` pipeline per attempt, wrapped in a
  best-of-N re-roll loop that mirrors ``ChunkRemediator::remediate`` — re-roll
  on TRUNC/PAUSE/NOSPEECH/BNDNOISE, lossless-trim on TAIL/TAILNOISE. Re-roll
  attempts thread ``Pipeline.from_result`` for manifest lineage.
* **Phase 2 — stitch.** One pipeline whose stitch step consumes the chosen chunk
  assets as ``external_inputs`` (cross-run fan-in), carrying ``break_after`` in
  each ``Asset.metadata``.

With no sink configured the whole flow still runs locally (assets stay
``file://``), which is how the unit/integration tests exercise it.
"""

from __future__ import annotations

import json
import tempfile
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass, replace
from pathlib import Path
from urllib.parse import unquote, urlparse

from genblaze_core import Pipeline
from genblaze_core.media import get_handler
from genblaze_core.models.asset import Asset
from genblaze_core.models.enums import Modality, StepType

from genblaze_bespoken import (
    BespokenChunkProvider,
    BespokenQAProvider,
    BespokenStitchProvider,
)
from genblaze_bespoken._assets import read_asset_bytes, write_audio_asset
from genblaze_bespoken._client import BespokenClient

# Problem classes (from Bespoken's ChunkQualityVerdict).
REROLL_PROBLEMS = frozenset({"TRUNC", "PAUSE", "NOSPEECH", "BNDNOISE"})
TRIM_PROBLEMS = frozenset({"TAIL", "TAILNOISE"})


@dataclass
class ChunkTake:
    """One synthesis attempt of a chunk and its ASR verdict."""

    audio: Asset
    verdict: dict
    available: bool
    problems: set[str]
    score: float
    trim_at_ms: int | None
    seed: int | None = None
    manifest_hash: str | None = None
    trim_applied: bool = False


@dataclass
class ChunkResult:
    """The chosen take for a chunk, ready to stitch."""

    position: int
    text: str
    break_after: str
    audio: Asset
    verdict: dict
    attempts: int
    seed: int | None
    manifest_hash: str | None
    trim_applied: bool


@dataclass
class ProjectResult:
    chunks: list[ChunkResult]
    final: Asset
    manifest: object
    final_manifest_hash: str | None = None

    @property
    def reroll_count(self) -> int:
        return sum(max(0, c.attempts - 1) for c in self.chunks)


@dataclass
class _StitchResult:
    asset: Asset
    manifest: object


class Orchestrator:
    """Drives the Genblaze pipelines for one project's synthesis."""

    def __init__(
        self,
        *,
        client: BespokenClient | None = None,
        sink=None,
        output_dir: str | Path | None = None,
        max_rerolls: int = 3,
        tenant: str | None = None,
        chunk_provider: BespokenChunkProvider | None = None,
        qa_provider: BespokenQAProvider | None = None,
        stitch_provider: BespokenStitchProvider | None = None,
    ) -> None:
        self.client = client
        self.sink = sink
        self.output_dir = Path(output_dir or tempfile.gettempdir())
        self.max_rerolls = max_rerolls
        self.tenant = tenant
        self.reroll_problems = REROLL_PROBLEMS
        self.trim_problems = TRIM_PROBLEMS
        self.chunk_provider = chunk_provider or BespokenChunkProvider(client=client, output_dir=self.output_dir)
        self.qa_provider = qa_provider or BespokenQAProvider(client=client, output_dir=self.output_dir)
        self.stitch_provider = stitch_provider or BespokenStitchProvider(client=client, output_dir=self.output_dir)

    @classmethod
    def from_config(cls, config, sink=None) -> "Orchestrator":
        client = BespokenClient(
            base_url=config.bespoken_base_url,
            internal_secret=config.bespoken_internal_secret,
        )
        return cls(client=client, sink=sink, output_dir=config.output_dir,
                   max_rerolls=config.max_rerolls)

    # -- public entry -------------------------------------------------------

    def synthesize_project(
        self,
        *,
        text: str,
        voice: str,
        settings: dict | None = None,
        output_format: str = "mp3_44100_128",
        base_seed: int | None = None,
        max_concurrency: int = 2,
    ) -> ProjectResult:
        segments = self.client.chunk(text)
        if not segments:
            raise ValueError("No chunks were produced from the input text.")

        def run_one(seg: dict) -> ChunkResult:
            return self.synth_chunk(seg, voice, settings, base_seed)

        if max_concurrency <= 1 or len(segments) == 1:
            chunk_results = [run_one(s) for s in segments]
        else:
            with ThreadPoolExecutor(max_workers=max_concurrency) as pool:
                chunk_results = list(pool.map(run_one, segments))

        stitched = self.stitch(chunk_results, output_format)
        return ProjectResult(
            chunks=chunk_results,
            final=stitched.asset,
            manifest=stitched.manifest,
            final_manifest_hash=getattr(stitched.manifest, "canonical_hash", None),
        )

    # -- per-chunk re-roll loop (mirror of ChunkRemediator::remediate) ------

    def synth_chunk(self, seg: dict, voice: str, settings: dict | None, base_seed: int | None) -> ChunkResult:
        best: ChunkTake | None = None
        prev = None
        attempts = 0

        for attempt in range(self.max_rerolls + 1):
            attempts += 1
            seed = (base_seed + attempt) if base_seed is not None else None
            take, prev = self._run_attempt(seg, voice, settings, seed, prev)

            # ASR off/unreachable, or a clean take -> accept as-is.
            if not take.available or not take.problems:
                return self._finalize(seg, take, attempts)

            # Only junk-tail problems -> lossless trim, no re-roll needed.
            if take.problems <= self.trim_problems:
                return self._finalize(seg, self._apply_trim(take), attempts)

            # A re-roll-class problem is present -> keep the best take and retry.
            best = take if best is None or take.score > best.score else best

        # Re-rolls exhausted: ship the best-effort take, trimming a junk tail if
        # it also carries one.
        if best.problems & self.trim_problems:
            best = self._apply_trim(best)
        return self._finalize(seg, best, attempts)

    def _run_attempt(self, seg, voice, settings, seed, prev):
        pipe = Pipeline(f"chunk-{seg['position']}", tenant_id=self.tenant)
        if prev is not None:
            pipe = pipe.from_result(prev)  # parent_run_id lineage (does not carry steps)
        pipe = pipe.step(
            self.chunk_provider, model=voice, prompt=seg["text"], modality=Modality.AUDIO,
            step_type=StepType.GENERATE, seed=seed, settings=settings or {},
        ).step(
            self.qa_provider, model="asr", modality=Modality.AUDIO, step_type=StepType.CUSTOM,
            input_from=[0], source_text=seg["text"],
        )
        result = pipe.run(sink=self.sink, raise_on_failure=False)
        steps = result.manifest.run.steps

        if not steps[0].assets:
            raise RuntimeError(f"Chunk {seg['position']} generation produced no audio.")
        gen_asset = steps[0].assets[0]
        verdict = self._extract_verdict(steps[1]) if len(steps) > 1 else {}
        take = self._take_from(gen_asset, verdict, seed, getattr(result.manifest, "canonical_hash", None))
        return take, result

    def _extract_verdict(self, qa_step) -> dict:
        verdict = (qa_step.metadata or {}).get("verdict")
        if verdict:
            return verdict
        # Fallback: read the verdict JSON asset the QA provider attaches.
        for asset in qa_step.assets:
            if asset.media_type == "application/json":
                try:
                    return json.loads(read_asset_bytes(asset.url))
                except Exception:  # noqa: BLE001
                    return {}
        return {}

    def _take_from(self, asset: Asset, verdict: dict, seed, manifest_hash) -> ChunkTake:
        available = bool(verdict.get("available", False))
        problems = set(verdict.get("problems", [])) if available else set()
        return ChunkTake(
            audio=asset, verdict=verdict, available=available, problems=problems,
            score=float(verdict.get("score", 0.0)), trim_at_ms=verdict.get("trim_at_ms"),
            seed=seed, manifest_hash=manifest_hash,
        )

    def _apply_trim(self, take: ChunkTake) -> ChunkTake:
        if not take.trim_at_ms:
            return take
        audio = read_asset_bytes(take.audio.url)
        result = self.client.trim(audio, int(take.trim_at_ms))
        asset = write_audio_asset(self.output_dir, None, result.audio,
                                  ext="wav", mime="audio/wav", codec="pcm_s16le")
        if self.sink is not None:
            try:
                asset = self.sink.put_asset(asset)
            except Exception:  # noqa: BLE001 — keep the local asset on upload failure
                pass
        return replace(take, audio=asset, problems=take.problems - self.trim_problems, trim_applied=True)

    def _finalize(self, seg: dict, take: ChunkTake, attempts: int) -> ChunkResult:
        return ChunkResult(
            position=seg["position"], text=seg["text"],
            break_after=seg.get("break_after", "sentence"),
            audio=take.audio, verdict=take.verdict, attempts=attempts,
            seed=take.seed, manifest_hash=take.manifest_hash, trim_applied=take.trim_applied,
        )

    # -- stitch (cross-run fan-in) ------------------------------------------

    def stitch(self, chunk_results: list[ChunkResult], output_format: str) -> _StitchResult:
        inputs = [
            cr.audio.model_copy(update={"metadata": {"break_after": cr.break_after}})
            for cr in chunk_results
        ]
        result = (
            Pipeline("stitch", tenant_id=self.tenant)
            .step(self.stitch_provider, model="concat", modality=Modality.AUDIO,
                  step_type=StepType.MIX, external_inputs=inputs, output_format=output_format)
            .run(sink=self.sink, raise_on_failure=False)
        )
        step = result.manifest.run.steps[0]
        if not step.assets:
            raise RuntimeError("Stitch produced no audio.")
        asset = step.assets[0]
        self._embed_manifest(asset, result.manifest)
        return _StitchResult(asset=asset, manifest=result.manifest)

    def _embed_manifest(self, asset: Asset, manifest) -> None:
        """Best-effort: embed the provenance manifest into a local final file.

        (Embedding into the already-uploaded B2 copy is a follow-up.)
        """
        parsed = urlparse(asset.url)
        if parsed.scheme != "file":
            return
        handler = get_handler(asset.media_type)
        if handler is None:
            return
        path = Path(unquote(parsed.path))
        try:
            handler.embed(path, manifest, path)
        except Exception:  # noqa: BLE001 — embedding is a nicety, never fatal
            pass
