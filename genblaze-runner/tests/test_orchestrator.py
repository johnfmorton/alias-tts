"""Tests for the Genblaze-owned orchestrator.

Two layers:

* **Decision-loop unit tests** — a ``ScriptedOrchestrator`` stubs ``_run_attempt``
  so the re-roll/trim tree is tested in isolation (no Genblaze, no network).
* **Integration tests** — a fake client drives the *real* Genblaze pipelines
  (chunk -> gen -> QA -> stitch) with ``sink=None`` (assets stay ``file://``),
  exercising real ``Pipeline.run``, ``from_result`` lineage, and manifests.
"""

from __future__ import annotations

from dataclasses import replace

from genblaze_core.models.asset import Asset

from genblaze_mimic._client import TtsResult
from genblaze_runner.orchestrator import Orchestrator

_FAKE_WAV = b"RIFF\x24\x00\x00\x00WAVEfmt " + b"\x00" * 32
_FAKE_MP3 = b"ID3\x03\x00\x00\x00\x00\x00\x00" + b"\xff\xfb\x90\x00" + b"\x00" * 64


def V(*, problems, score=1.0, trim_at_ms=None, available=True) -> dict:
    return {
        "available": available,
        "ok": not problems,
        "problems": list(problems),
        "score": score,
        "trim_at_ms": trim_at_ms,
    }


# --------------------------------------------------------------------------
# Decision-loop unit tests
# --------------------------------------------------------------------------


class ScriptedOrchestrator(Orchestrator):
    """Returns canned takes from a script; counts trims. No pipelines run."""

    def __init__(self, script, **kw):
        super().__init__(client=None, sink=None, **kw)
        self._script = list(script)
        self._idx = 0
        self.trim_calls = 0

    def _run_attempt(self, seg, voice, settings, seed, prev):
        verdict = self._script[self._idx]
        self._idx += 1
        asset = Asset(url="file:///tmp/fake.wav", media_type="audio/wav")
        return self._take_from(asset, verdict, seed, f"hash-{self._idx}"), None

    def _apply_trim(self, take):
        self.trim_calls += 1
        return replace(take, problems=take.problems - self.trim_problems, trim_applied=True)


def _loop(script, max_rerolls=2):
    orch = ScriptedOrchestrator(script, max_rerolls=max_rerolls)
    seg = {"position": 0, "text": "x", "break_after": "sentence"}
    return orch, orch.synth_chunk(seg, "voice", None, base_seed=0)


def test_clean_take_accepts_first():
    orch, res = _loop([V(problems=[])])
    assert res.attempts == 1
    assert orch.trim_calls == 0
    assert res.trim_applied is False


def test_trim_only_take_trims_without_reroll():
    orch, res = _loop([V(problems=["TAIL"], trim_at_ms=500)])
    assert res.attempts == 1
    assert orch.trim_calls == 1
    assert res.trim_applied is True


def test_reroll_then_clean():
    orch, res = _loop([V(problems=["TRUNC"], score=0.5), V(problems=[], score=0.9)])
    assert res.attempts == 2
    assert orch.trim_calls == 0


def test_reroll_exhausted_picks_best_score():
    orch, res = _loop(
        [V(problems=["TRUNC"], score=0.5), V(problems=["TRUNC"], score=0.9), V(problems=["TRUNC"], score=0.3)],
        max_rerolls=2,
    )
    assert res.attempts == 3
    assert res.verdict["score"] == 0.9
    assert orch.trim_calls == 0


def test_reroll_exhausted_trims_best_effort_tail():
    orch, res = _loop(
        [
            V(problems=["TRUNC", "TAIL"], score=0.4, trim_at_ms=400),
            V(problems=["TRUNC", "TAIL"], score=0.8, trim_at_ms=400),
            V(problems=["TRUNC", "TAIL"], score=0.2, trim_at_ms=400),
        ],
        max_rerolls=2,
    )
    assert res.attempts == 3
    assert res.verdict["score"] == 0.8  # best take chosen
    assert orch.trim_calls == 1 and res.trim_applied is True  # junk tail trimmed


def test_asr_unavailable_accepts_first():
    orch, res = _loop([{"available": False}])
    assert res.attempts == 1
    assert orch.trim_calls == 0


# --------------------------------------------------------------------------
# Integration tests (real Genblaze pipelines, fake client, no sink)
# --------------------------------------------------------------------------


class FakeClient:
    def __init__(self, *, chunks, verdict=None, verdict_queue=None):
        self._chunks = chunks
        self._verdict = verdict or V(problems=[])
        self._verdict_queue = list(verdict_queue) if verdict_queue else None
        self.calls: list[tuple] = []

    def chunk(self, text):
        self.calls.append(("chunk", text))
        return self._chunks

    def generate_chunk(self, voice_id, text, *, settings=None, seed=None):
        self.calls.append(("generate", text, seed))
        return TtsResult(audio=_FAKE_WAV, content_type="audio/wav")

    def score(self, text, audio, *, filename="chunk.wav"):
        self.calls.append(("score", text))
        if self._verdict_queue:
            return self._verdict_queue.pop(0)
        return dict(self._verdict)

    def stitch(self, chunks, *, output_format, break_after=None):
        self.calls.append(("stitch", len(chunks), break_after))
        return TtsResult(audio=_FAKE_MP3, content_type="audio/mpeg")

    def trim(self, audio, trim_at_ms, *, filename="chunk.wav"):
        self.calls.append(("trim", trim_at_ms))
        return TtsResult(audio=_FAKE_WAV, content_type="audio/wav")


def test_synthesize_project_happy_path(tmp_path):
    client = FakeClient(
        chunks=[
            {"position": 0, "text": "First.", "break_after": "sentence"},
            {"position": 1, "text": "Second.", "break_after": "paragraph"},
        ],
        verdict=V(problems=[]),
    )
    orch = Orchestrator(client=client, sink=None, output_dir=tmp_path, max_rerolls=3)
    result = orch.synthesize_project(text="First. Second.", voice="default", max_concurrency=1)

    assert len(result.chunks) == 2
    assert all(c.attempts == 1 for c in result.chunks)
    assert result.final.media_type == "audio/mpeg"
    assert result.reroll_count == 0
    assert result.manifest.verify() is True
    # the verify() result is surfaced on the project result (→ /run → Studio panel)
    assert result.final_manifest_verified is True
    # verdict flowed through Pipeline.run (metadata or JSON-asset fallback)
    assert result.chunks[0].verdict.get("available") is True
    # the stitch saw the per-chunk break_after tags
    stitch_call = [c for c in client.calls if c[0] == "stitch"][0]
    assert stitch_call[2] == ["sentence", "paragraph"]


def test_synthesize_project_rerolls_then_succeeds(tmp_path):
    client = FakeClient(
        chunks=[{"position": 0, "text": "Hello.", "break_after": "sentence"}],
        verdict_queue=[
            V(problems=["TRUNC"], score=0.5),  # first take flagged -> re-roll
            V(problems=[], score=0.95),         # second take clean
        ],
    )
    orch = Orchestrator(client=client, sink=None, output_dir=tmp_path, max_rerolls=3)
    result = orch.synthesize_project(text="Hello.", voice="default", base_seed=100, max_concurrency=1)

    assert result.chunks[0].attempts == 2  # real from_result lineage path exercised
    assert result.reroll_count == 1
    assert result.manifest.verify() is True
