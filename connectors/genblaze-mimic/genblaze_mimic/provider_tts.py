"""``MimicTTSProvider`` — Posture A: one Genblaze step == one full Mimic
synthesis via ``POST /v1/text-to-speech/{voice_id}``.

Mimic chunks, ASR-QA-remediates and stitches internally; this provider
returns the final audio as a ``file://`` :class:`Asset`. The
:class:`ObjectStorageSink` then uploads that local file to Backblaze B2 and
rewrites the asset URL to a durable B2 URL.
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
from genblaze_mimic._formats import format_meta


class MimicTTSProvider(SyncProvider):
    """Synthesize speech via a self-hosted Mimic TTS service (whole pipeline)."""

    name = "mimic-tts"

    def __init__(
        self,
        *,
        client: MimicClient | None = None,
        base_url: str | None = None,
        api_key: str | None = None,
        output_dir: str | os.PathLike[str] | None = None,
        timeout: float = 300.0,
        **kwargs,
    ) -> None:
        super().__init__(**kwargs)
        self._client = client or MimicClient(base_url=base_url, api_key=api_key, timeout=timeout)
        self._output_dir = Path(output_dir or os.getenv("GENBLAZE_OUTPUT_DIR") or tempfile.gettempdir())

    def get_capabilities(self) -> ProviderCapabilities:
        return ProviderCapabilities(
            supported_modalities=[Modality.AUDIO],
            supported_inputs=["text"],
            accepts_chain_input=False,
            output_formats=["audio/mpeg", "audio/wav"],
        )

    def normalize_params(self, params: dict, modality: Modality | None = None) -> dict:
        p = dict(params)
        if "format" in p and "output_format" not in p:
            p["output_format"] = p.pop("format")
        if "voice" in p and "voice_id" not in p:
            p["voice_id"] = p.pop("voice")
        return p

    def generate(self, step: Step, config: RunnableConfig | None = None) -> Step:
        text = step.prompt or ""
        if not text.strip():
            raise ProviderError(
                "Mimic TTS requires non-empty prompt text",
                error_code=ProviderErrorCode.INVALID_INPUT,
            )
        voice_id = step.params.get("voice_id") or step.model
        output_format = step.params.get("output_format")
        seed = step.seed if step.seed is not None else step.params.get("seed")

        try:
            result = self._client.text_to_speech(
                voice_id,
                text,
                model_id=step.params.get("model_id"),
                output_format=output_format,
                seed=seed,
                voice_settings=step.params.get("voice_settings"),
            )
        except ProviderError:
            raise
        except Exception as exc:  # noqa: BLE001
            raise ProviderError(
                f"Mimic TTS request failed: {exc}",
                error_code=classify_exception(exc),
            ) from exc

        ext, mime, codec, sample_rate = format_meta(output_format, result.content_type)
        step.assets.append(
            write_audio_asset(
                self._output_dir, step.step_id, result.audio,
                ext=ext, mime=mime, codec=codec, sample_rate=sample_rate,
            )
        )
        return step
