"""Compliance + unit tests for the Alias Genblaze providers.

Each provider gets the full ``ProviderComplianceTests`` suite against a fake
(no-network) client, plus targeted behaviour checks. The QA/Stitch providers
declare ``accepts_chain_input``, so their compliance run actually exercises the
chain-input SSRF check (it is skipped for the text-in providers).
"""

from __future__ import annotations

import tempfile
from pathlib import Path
from urllib.parse import quote

from genblaze_core.models.asset import Asset
from genblaze_core.models.step import Step
from genblaze_core.testing import ProviderComplianceTests

from genblaze_alias import (
    AliasChunkProvider,
    AliasQAProvider,
    AliasStitchProvider,
    AliasTTSProvider,
)
from genblaze_alias._client import TtsResult

# Canned audio blobs — content is irrelevant; only that bytes round-trip.
_FAKE_MP3 = b"ID3\x03\x00\x00\x00\x00\x00\x00" + b"\xff\xfb\x90\x00" + b"\x00" * 64
_FAKE_WAV = b"RIFF\x24\x00\x00\x00WAVEfmt " + b"\x00" * 32

_OK_VERDICT = {
    "available": True,
    "ok": True,
    "problems": [],
    "score": 1.0,
    "trim_at_ms": None,
    "word_count": 3,
}


class FakeAliasClient:
    """Stand-in for :class:`AliasClient` covering every method, no network."""

    def __init__(self, *, content_type: str = "audio/mpeg", audio: bytes = _FAKE_MP3,
                 wav: bytes = _FAKE_WAV, verdict: dict | None = None) -> None:
        self.content_type = content_type
        self.audio = audio
        self.wav = wav
        self.verdict = verdict if verdict is not None else dict(_OK_VERDICT)
        self.calls: list[dict] = []

    def text_to_speech(self, voice_id, text, *, model_id=None, output_format=None,
                       seed=None, voice_settings=None) -> TtsResult:
        self.calls.append({"op": "tts", "voice_id": voice_id, "text": text, "seed": seed,
                           "output_format": output_format})
        return TtsResult(audio=self.audio, content_type=self.content_type)

    def generate_chunk(self, voice_id, text, *, settings=None, seed=None) -> TtsResult:
        self.calls.append({"op": "generate", "voice_id": voice_id, "text": text,
                           "settings": settings, "seed": seed})
        return TtsResult(audio=self.wav, content_type="audio/wav")

    def score(self, text, audio, *, filename="chunk.wav") -> dict:
        self.calls.append({"op": "score", "text": text, "audio_len": len(audio)})
        return dict(self.verdict)

    def stitch(self, chunks, *, output_format, break_after=None) -> TtsResult:
        self.calls.append({"op": "stitch", "n": len(chunks), "output_format": output_format,
                           "break_after": break_after})
        return TtsResult(audio=self.audio, content_type=self.content_type)


def _tmpdir() -> str:
    return tempfile.mkdtemp(prefix="gbz-alias-")


def _wav_input_asset(tmp: str, *, break_after: str = "sentence") -> Asset:
    base = Path(tmp)
    base.mkdir(parents=True, exist_ok=True)
    path = base / "in.wav"
    path.write_bytes(_FAKE_WAV)
    return Asset(
        url=f"file://{quote(str(path.resolve()))}",
        media_type="audio/wav",
        metadata={"break_after": break_after},
    )


# --------------------------------------------------------------------------
# Compliance suites
# --------------------------------------------------------------------------


class TestAliasTTSCompliance(ProviderComplianceTests):
    expects_cost = False

    def make_provider(self):
        return AliasTTSProvider(client=FakeAliasClient(), output_dir=_tmpdir())

    def make_step(self) -> Step:
        return Step(provider="alias-tts", model="default", prompt="Hello from Alias.")

    def constructor_kwargs_for_probe_cache_test(self) -> dict:
        return {"client": FakeAliasClient(), "output_dir": _tmpdir()}


class TestAliasChunkCompliance(ProviderComplianceTests):
    expects_cost = False

    def make_provider(self):
        return AliasChunkProvider(client=FakeAliasClient(), output_dir=_tmpdir())

    def make_step(self) -> Step:
        return Step(provider="alias-chunk", model="default", prompt="One chunk.")

    def constructor_kwargs_for_probe_cache_test(self) -> dict:
        return {"client": FakeAliasClient(), "output_dir": _tmpdir()}


class TestAliasQACompliance(ProviderComplianceTests):
    expects_cost = False

    def make_provider(self):
        return AliasQAProvider(client=FakeAliasClient(), output_dir=_tmpdir())

    def make_step(self) -> Step:
        step = Step(provider="alias-qa", model="qa", prompt="Hello there.")
        step.inputs = [_wav_input_asset(_tmpdir())]
        step.params = {"source_text": "Hello there."}
        return step

    def constructor_kwargs_for_probe_cache_test(self) -> dict:
        return {"client": FakeAliasClient(), "output_dir": _tmpdir()}


class TestAliasStitchCompliance(ProviderComplianceTests):
    expects_cost = False

    def make_provider(self):
        return AliasStitchProvider(client=FakeAliasClient(), output_dir=_tmpdir())

    def make_step(self) -> Step:
        step = Step(provider="alias-stitch", model="concat", prompt=None)
        step.inputs = [_wav_input_asset(_tmpdir())]
        step.params = {"output_format": "mp3_44100_128"}
        return step

    def constructor_kwargs_for_probe_cache_test(self) -> dict:
        return {"client": FakeAliasClient(), "output_dir": _tmpdir()}


# --------------------------------------------------------------------------
# Behaviour unit tests
# --------------------------------------------------------------------------


def test_tts_generate_writes_audio_asset_with_metadata(tmp_path):
    fake = FakeAliasClient()
    provider = AliasTTSProvider(client=fake, output_dir=tmp_path)
    out = provider.invoke(Step(provider="alias-tts", model="v", prompt="Hi.", seed=7))
    asset = out.assets[0]
    assert asset.url.startswith("file://")
    assert asset.media_type == "audio/mpeg"
    assert asset.audio is not None and asset.audio.channels == 1
    assert asset.sha256 and asset.size_bytes == len(fake.audio)
    assert fake.calls[0] == {"op": "tts", "voice_id": "v", "text": "Hi.", "seed": 7,
                             "output_format": None}


def test_chunk_generate_returns_wav_and_passes_seed(tmp_path):
    fake = FakeAliasClient()
    provider = AliasChunkProvider(client=fake, output_dir=tmp_path)
    out = provider.invoke(Step(provider="alias-chunk", model="v", prompt="One.", seed=11))
    asset = out.assets[0]
    assert asset.media_type == "audio/wav"
    assert asset.audio.codec == "pcm_s16le"
    assert fake.calls[0]["op"] == "generate" and fake.calls[0]["seed"] == 11


def test_qa_records_verdict_in_metadata_and_json_asset(tmp_path):
    fake = FakeAliasClient(verdict={"available": True, "ok": False, "problems": ["TRUNC"],
                                       "score": 0.6, "trim_at_ms": None})
    provider = AliasQAProvider(client=fake, output_dir=tmp_path)
    step = Step(provider="alias-qa", model="qa", prompt="x")
    step.inputs = [_wav_input_asset(str(tmp_path))]
    step.params = {"source_text": "the full source"}

    out = provider.invoke(step)
    assert out.metadata["verdict"]["problems"] == ["TRUNC"]
    assert out.assets[0].media_type == "application/json"
    assert fake.calls[0]["op"] == "score" and fake.calls[0]["text"] == "the full source"


def test_stitch_reads_inputs_and_forwards_break_after(tmp_path):
    fake = FakeAliasClient()
    provider = AliasStitchProvider(client=fake, output_dir=tmp_path)
    step = Step(provider="alias-stitch", model="concat")
    step.inputs = [
        _wav_input_asset(str(tmp_path / "a"), break_after="sentence"),
        _wav_input_asset(str(tmp_path / "b"), break_after="paragraph"),
    ]
    step.params = {"output_format": "mp3_44100_128"}

    out = provider.invoke(step)
    assert out.assets[0].media_type == "audio/mpeg"
    call = fake.calls[0]
    assert call["op"] == "stitch" and call["n"] == 2
    assert call["break_after"] == ["sentence", "paragraph"]
