"""``BespokenStitchProvider`` — Posture B: concatenate ordered chunk audio into
the final output via ``POST /v1/internal/stitch``.

Genblaze has no built-in audio concat (``FFmpegCompositor`` muxes video), so
this provider is the one with real fan-in logic. Inputs arrive as
``external_inputs`` (cross-run, since the per-chunk re-roll loop produces
separate runs), each carrying its ``break_after`` tag in ``Asset.metadata`` so
the stitch inserts the right sentence/paragraph silence at each seam.
"""

from __future__ import annotations

import os
import tempfile
from pathlib import Path

from genblaze_core.exceptions import ProviderError
from genblaze_core.models.enums import Modality, ProviderErrorCode
from genblaze_core.models.step import Step
from genblaze_core.providers.base import (
    ProviderCapabilities,
    SyncProvider,
    validate_chain_input_url,
)
from genblaze_core.runnable.config import RunnableConfig

from genblaze_bespoken._assets import read_asset_bytes, write_audio_asset
from genblaze_bespoken._client import BespokenClient
from genblaze_bespoken._errors import classify_exception
from genblaze_bespoken._formats import format_meta


class BespokenStitchProvider(SyncProvider):
    """Concatenate chunk audio into a single final track via Bespoken."""

    name = "bespoken-stitch"

    def __init__(
        self,
        *,
        client: BespokenClient | None = None,
        base_url: str | None = None,
        api_key: str | None = None,
        internal_secret: str | None = None,
        output_dir: str | os.PathLike[str] | None = None,
        timeout: float = 300.0,
        **kwargs,
    ) -> None:
        super().__init__(**kwargs)
        self._client = client or BespokenClient(
            base_url=base_url, api_key=api_key, internal_secret=internal_secret, timeout=timeout
        )
        self._output_dir = Path(output_dir or os.getenv("GENBLAZE_OUTPUT_DIR") or tempfile.gettempdir())

    def get_capabilities(self) -> ProviderCapabilities:
        return ProviderCapabilities(
            supported_modalities=[Modality.AUDIO],
            supported_inputs=["audio"],
            accepts_chain_input=True,
            output_formats=["audio/mpeg", "audio/wav"],
        )

    def generate(self, step: Step, config: RunnableConfig | None = None) -> Step:
        if not step.inputs:
            raise ProviderError(
                "Stitch requires at least one chained audio input",
                error_code=ProviderErrorCode.INVALID_INPUT,
            )
        for inp in step.inputs:
            validate_chain_input_url(inp.url)  # rejects http:// (SSRF guard)

        output_format = step.params.get("output_format", "mp3_44100_128")
        chunks = [read_asset_bytes(inp.url) for inp in step.inputs]
        break_after = [(inp.metadata or {}).get("break_after", "sentence") for inp in step.inputs]

        try:
            result = self._client.stitch(chunks, output_format=output_format, break_after=break_after)
        except ProviderError:
            raise
        except Exception as exc:  # noqa: BLE001
            raise ProviderError(
                f"Stitch failed: {exc}",
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
