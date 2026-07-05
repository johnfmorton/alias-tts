"""The Genblaze-owned TTS orchestrator (Posture B).

This is the piece that makes Genblaze *orchestrate* rather than merely store:
it drives the chunk -> generate -> ASR-score -> (re-roll | trim) -> stitch flow
that Alias's ``ChunkRemediator`` does internally, but expressed as Genblaze
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

from genblaze_alias import (
    AliasChunkProvider,
    AliasQAProvider,
    AliasStitchProvider,
)
from genblaze_alias._assets import read_asset_bytes, write_audio_asset
from genblaze_alias._client import AliasClient

# Problem classes (from Alias's ChunkQualityVerdict).
REROLL_PROBLEMS = frozenset({"TRUNC", "PAUSE", "NOSPEECH", "BNDNOISE"})
TRIM_PROBLEMS = frozenset({"TAIL", "TAILNOISE"})


def _basename(url: str) -> str:
    """Filename tail of an asset URL (for the panel's 'Upload → B2' step)."""
    return unquote(urlparse(url).path).rstrip("/").split("/")[-1] or url


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
    final_manifest_verified: bool | None = None

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
        client: AliasClient | None = None,
        sink=None,
        output_dir: str | Path | None = None,
        max_rerolls: int = 3,
        tenant: str | None = None,
        chunk_provider: AliasChunkProvider | None = None,
        qa_provider: AliasQAProvider | None = None,
        stitch_provider: AliasStitchProvider | None = None,
    ) -> None:
        self.client = client
        self.sink = sink
        self.output_dir = Path(output_dir or tempfile.gettempdir())
        self.max_rerolls = max_rerolls
        self.tenant = tenant
        self.reroll_problems = REROLL_PROBLEMS
        self.trim_problems = TRIM_PROBLEMS
        self.chunk_provider = chunk_provider or AliasChunkProvider(client=client, output_dir=self.output_dir)
        self.qa_provider = qa_provider or AliasQAProvider(client=client, output_dir=self.output_dir)
        self.stitch_provider = stitch_provider or AliasStitchProvider(client=client, output_dir=self.output_dir)

    @classmethod
    def from_config(cls, config, sink=None) -> "Orchestrator":
        client = AliasClient(
            base_url=config.alias_base_url,
            internal_secret=config.alias_internal_secret,
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
        run_id: str | None = None,
    ) -> ProjectResult:
        # Report each stage as it happens so the Studio panel can light up its
        # pipeline checklist LIVE (the /run call itself is blocking). Best-effort
        # and a no-op when no run_id is threaded (tests, smoke).
        def report(step: str, detail: str = "") -> None:
            if run_id and self.client is not None:
                self.client.report_progress(run_id, step, detail)

        segments = self.client.chunk(text)
        if not segments:
            raise ValueError("No chunks were produced from the input text.")
        report(
            "chunk",
            "no chunking needed — short enough for a single segment"
            if len(segments) == 1
            else f"split into {len(segments)} segments",
        )

        total = len(segments)

        def run_one(seg: dict) -> ChunkResult:
            return self.synth_chunk(seg, voice, settings, base_seed, report=report, total=total)

        if max_concurrency <= 1 or len(segments) == 1:
            chunk_results = []
            for seg in segments:
                report("generate", f"chunk {seg['position'] + 1}/{total}")
                chunk_results.append(run_one(seg))
        else:
            report("generate", f"{total} chunk(s)")
            with ThreadPoolExecutor(max_workers=max_concurrency) as pool:
                chunk_results = list(pool.map(run_one, segments))

        report("stitch", f"{len(chunk_results)} chunk(s)")
        stitched = self.stitch(chunk_results, output_format)
        verified = self._safe_verify(stitched.manifest)
        report("seal", "SHA-256 provenance manifest")
        report("upload", _basename(stitched.asset.url))
        return ProjectResult(
            chunks=chunk_results,
            final=stitched.asset,
            manifest=stitched.manifest,
            final_manifest_hash=getattr(stitched.manifest, "canonical_hash", None),
            final_manifest_verified=verified,
        )

    # -- per-chunk re-roll loop (mirror of ChunkRemediator::remediate) ------

    def synth_chunk(
        self,
        seg: dict,
        voice: str,
        settings: dict | None,
        base_seed: int | None,
        *,
        report=None,
        total: int | None = None,
    ) -> ChunkResult:
        best: ChunkTake | None = None
        prev = None
        attempts = 0

        for attempt in range(self.max_rerolls + 1):
            # A re-roll is the QA gate rejecting the prior take — surface it live.
            if report and attempt > 0:
                report("generate", f"chunk {seg['position'] + 1}/{total or '?'} · re-roll #{attempt}")
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

    @staticmethod
    def _safe_verify(manifest) -> bool | None:
        """Genblaze's SHA-256 provenance check, surfaced for the Studio panel.

        Returns the ``manifest.verify()`` bool, or ``None`` if the SDK build has
        no ``verify()`` or it raises — so a missing/erroring check degrades to
        "unknown" in the UI rather than failing the run.
        """
        verify = getattr(manifest, "verify", None)
        if not callable(verify):
            return None
        try:
            return bool(verify())
        except Exception:  # noqa: BLE001 — provenance check is informational, never fatal
            return None

    def _embed_manifest(self, asset: Asset, manifest) -> None:
        """Best-effort: embed the provenance manifest into the final deliverable.

        The final then carries its own provenance and can be verified offline —
        the handler stores the manifest in a metadata region (e.g. an ID3v2 TXXX
        frame for MP3), leaving the audio stream intact. Two cases:

        * **Local** (dev / tests, no sink): the final is still a ``file://``
          asset — embed in place.
        * **Uploaded** (prod, sink configured): the sink already transferred the
          final to object storage, so download the just-uploaded bytes, embed,
          and overwrite the SAME key. This makes the B2 deliverable
          self-describing rather than merely accompanied by a sidecar manifest.

        Embedding is a nicety layered on top of the sidecar manifest, so every
        failure path degrades to "sidecar only" and never fails the run.
        """
        handler = get_handler(asset.media_type)
        if handler is None:
            return
        parsed = urlparse(asset.url)
        if parsed.scheme == "file":
            path = Path(unquote(parsed.path))
            try:
                handler.embed(path, manifest, path)
            except Exception:  # noqa: BLE001 — embedding is a nicety, never fatal
                pass
            return
        self._embed_manifest_remote(asset, manifest, handler)

    def _embed_manifest_remote(self, asset: Asset, manifest, handler) -> None:
        """Embed the manifest into the copy already uploaded to the sink, in place.

        Downloads the just-uploaded final through the sink's own backend (the
        same authenticated client that wrote it — so a private bucket works),
        embeds the manifest, and re-``put``s the SAME key. Best-effort: a missing
        backend, an unresolvable key, or any transfer error leaves the sidecar
        manifest as the sole provenance record.
        """
        backend = getattr(self.sink, "_backend", None)
        if backend is None:
            return
        try:
            key = backend.key_from_url(asset.url)
        except Exception:  # noqa: BLE001 — foreign/unswappable URL → sidecar only
            key = None
        if not key:
            return
        suffix = Path(unquote(urlparse(asset.url).path)).suffix or ".bin"
        try:
            data = backend.get(key)
            with tempfile.TemporaryDirectory() as tmp:
                local = Path(tmp) / f"final{suffix}"
                local.write_bytes(data)
                handler.embed(local, manifest, local)
                embedded = local.read_bytes()
            backend.put(key, embedded, content_type=asset.media_type)
        except Exception:  # noqa: BLE001 — sidecar manifest remains the record
            pass
