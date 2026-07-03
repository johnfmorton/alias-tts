"""``MimicChunkProvider`` — Posture B: synthesize ONE chunk with a single
seed via ``POST /v1/internal/generate`` (no internal ASR re-roll — the Genblaze
orchestrator owns the QA-gated re-roll). Returns the raw provider container
(WAV) as a ``file://`` asset.
"""

from __future__ import annotations

import os
import tempfile
from pathlib import Path

from genblaze_core.exceptions import ProviderError
from genblaze_core.models.enums import Modality, ProviderErrorCode
from genblaze_core.models.step import Step
from genblaze_core.providers.base import ProviderCapabilities, SyncProvider
from genblaze_core.runnable.config import RunnableConfig

from genblaze_mimic._assets import write_audio_asset
from genblaze_mimic._client import MimicClient
from genblaze_mimic._errors import classify_exception


class MimicChunkProvider(SyncProvider):
    """Synthesize a single chunk via the Mimic internal API."""

    name = "mimic-chunk"

    def __init__(
        self,
        *,
        client: MimicClient | None = None,
        base_url: str | None = None,
        api_key: str | None = None,
        internal_secret: str | None = None,
        output_dir: str | os.PathLike[str] | None = None,
        timeout: float = 300.0,
        **kwargs,
    ) -> None:
        super().__init__(**kwargs)
        self._client = client or MimicClient(
            base_url=base_url, api_key=api_key, internal_secret=internal_secret, timeout=timeout
        )
        self._output_dir = Path(output_dir or os.getenv("GENBLAZE_OUTPUT_DIR") or tempfile.gettempdir())

    def get_capabilities(self) -> ProviderCapabilities:
        return ProviderCapabilities(
            supported_modalities=[Modality.AUDIO],
            supported_inputs=["text"],
            accepts_chain_input=False,
            output_formats=["audio/wav"],
        )

    def normalize_params(self, params: dict, modality: Modality | None = None) -> dict:
        p = dict(params)
        if "voice" in p and "voice_id" not in p:
            p["voice_id"] = p.pop("voice")
        return p

    def generate(self, step: Step, config: RunnableConfig | None = None) -> Step:
        text = step.prompt or ""
        if not text.strip():
            raise ProviderError(
                "Chunk synthesis requires non-empty prompt text",
                error_code=ProviderErrorCode.INVALID_INPUT,
            )
        voice_id = step.params.get("voice_id") or step.model
        seed = step.seed if step.seed is not None else step.params.get("seed")
        settings = step.params.get("settings")

        try:
            result = self._client.generate_chunk(voice_id, text, settings=settings, seed=seed)
        except ProviderError:
            raise
        except Exception as exc:  # noqa: BLE001
            raise ProviderError(
                f"Chunk synthesis failed: {exc}",
                error_code=classify_exception(exc),
            ) from exc

        step.assets.append(
            write_audio_asset(
                self._output_dir, step.step_id, result.audio,
                ext="wav", mime="audio/wav", codec="pcm_s16le",
            )
        )
        return step
