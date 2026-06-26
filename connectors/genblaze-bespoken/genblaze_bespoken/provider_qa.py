"""``BespokenQAProvider`` — Posture B: ASR-score one chunk via
``POST /v1/internal/score``.

Reads the chunk audio from the chained input asset, then records the quality
verdict both on ``step.metadata['verdict']`` (so the orchestrator's re-roll loop
can read it in-process) and as a JSON ``file://`` asset (so it persists to B2 as
provenance). A degraded ASR sidecar returns ``{"available": false}``, which the
orchestrator treats as "skip the QA gate".
"""

from __future__ import annotations

import json
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

from genblaze_bespoken._assets import read_asset_bytes, write_json_asset
from genblaze_bespoken._client import BespokenClient
from genblaze_bespoken._errors import classify_exception


class BespokenQAProvider(SyncProvider):
    """Score one generated chunk against its source text via Whisper ASR."""

    name = "bespoken-qa"

    def __init__(
        self,
        *,
        client: BespokenClient | None = None,
        base_url: str | None = None,
        api_key: str | None = None,
        internal_secret: str | None = None,
        output_dir: str | os.PathLike[str] | None = None,
        timeout: float = 120.0,
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
            output_formats=["application/json"],
        )

    def generate(self, step: Step, config: RunnableConfig | None = None) -> Step:
        if not step.inputs:
            raise ProviderError(
                "ASR QA requires one chained audio input",
                error_code=ProviderErrorCode.INVALID_INPUT,
            )
        for inp in step.inputs:
            validate_chain_input_url(inp.url)  # rejects http:// (SSRF guard)

        source_text = step.params.get("source_text") or step.prompt or ""
        audio = read_asset_bytes(step.inputs[0].url)

        try:
            verdict = self._client.score(source_text, audio)
        except ProviderError:
            raise
        except Exception as exc:  # noqa: BLE001
            raise ProviderError(
                f"ASR score failed: {exc}",
                error_code=classify_exception(exc),
            ) from exc

        step.metadata = {**(step.metadata or {}), "verdict": verdict}
        blob = json.dumps(verdict, sort_keys=True).encode("utf-8")
        step.assets.append(write_json_asset(self._output_dir, step.step_id, blob))
        return step
