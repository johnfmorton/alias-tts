"""HTTP client for the Alias TTS service.

Two surfaces:

* **Posture A** — the public ElevenLabs-compatible endpoint
  ``POST /v1/text-to-speech/{voice_id}`` (Alias runs the whole pipeline
  internally). Authenticated with the ``xi-api-key`` header.
* **Posture B** — the granular ``/v1/internal/{chunk,generate,score,stitch}``
  primitives the Genblaze runner orchestrates. Authenticated with the
  ``X-Internal-Secret`` header.

The client never opens a connection at construction time, so providers can be
instantiated cheaply (the compliance harness relies on this).
"""

from __future__ import annotations

import os
from dataclasses import dataclass

import httpx

DEFAULT_TIMEOUT = 300.0


@dataclass
class TtsResult:
    """Audio bytes + their content-type returned by a synthesis/stitch call."""

    audio: bytes
    content_type: str


class AliasClient:
    """Calls the Alias ``/v1`` and ``/v1/internal`` APIs over HTTP."""

    def __init__(
        self,
        *,
        base_url: str | None = None,
        api_key: str | None = None,
        internal_secret: str | None = None,
        timeout: float = DEFAULT_TIMEOUT,
        transport: httpx.BaseTransport | None = None,
    ) -> None:
        # ALIAS_* are the current env names; BESPOKEN_* remain a fallback so a
        # process whose environment predates the rename keeps working.
        self.base_url = (
            base_url or os.getenv("ALIAS_BASE_URL") or os.getenv("BESPOKEN_BASE_URL", "http://localhost")
        ).rstrip("/")
        self.api_key = api_key if api_key is not None else (os.getenv("ALIAS_API_KEY") or os.getenv("BESPOKEN_API_KEY", ""))
        self.internal_secret = (
            internal_secret
            if internal_secret is not None
            else (os.getenv("ALIAS_INTERNAL_SECRET") or os.getenv("BESPOKEN_INTERNAL_SECRET", ""))
        )
        self.timeout = timeout
        self._transport = transport  # injected in tests

    def _http(self, *, internal: bool = False) -> httpx.Client:
        headers = (
            {"X-Internal-Secret": self.internal_secret}
            if internal
            else {"xi-api-key": self.api_key}
        )
        return httpx.Client(
            base_url=self.base_url,
            timeout=self.timeout,
            headers=headers,
            transport=self._transport,
        )

    # --- Posture A: whole-pipeline synthesis -------------------------------

    def text_to_speech(
        self,
        voice_id: str,
        text: str,
        *,
        model_id: str | None = None,
        output_format: str | None = None,
        seed: int | None = None,
        voice_settings: dict | None = None,
    ) -> TtsResult:
        body: dict = {"text": text}
        if model_id is not None:
            body["model_id"] = model_id
        if output_format is not None:
            body["output_format"] = output_format
        if seed is not None:
            body["seed"] = seed
        if voice_settings:
            body["voice_settings"] = voice_settings
        with self._http() as client:
            resp = client.post(f"/v1/text-to-speech/{voice_id}", json=body)
            resp.raise_for_status()
            return TtsResult(audio=resp.content, content_type=resp.headers.get("content-type", "audio/mpeg"))

    # --- Live progress (best-effort) ---------------------------------------

    def report_progress(self, run_id: str, step: str, detail: str = "") -> None:
        """Ping the Studio panel with the stage the run just entered.

        Fire-and-forget: every failure (panel closed, run expired, network) is
        swallowed and short-timed so progress reporting can never break or
        meaningfully slow a real ``/run``.
        """
        if not run_id:
            return
        try:
            with self._http(internal=True) as client:
                client.post(
                    "/v1/internal/genblaze/progress",
                    json={"run_id": run_id, "step": step, "detail": detail},
                    timeout=5.0,
                )
        except Exception:  # noqa: BLE001 — progress is informational, never fatal
            pass

    # --- Posture B: pipeline primitives ------------------------------------

    def chunk(self, text: str, *, chunk_mode: str | None = None) -> list[dict]:
        """Normalize + segment text into chunk descriptors.

        ``chunk_mode`` ('packed'/'sentence') overrides the app instance's
        default — the internal endpoints run userless, so the dispatching
        user's chunking setting must be forwarded explicitly. ``None`` keeps
        the app's default.
        """
        payload: dict = {"text": text}
        if chunk_mode is not None:
            payload["chunk_mode"] = chunk_mode
        with self._http(internal=True) as client:
            resp = client.post("/v1/internal/chunk", json=payload)
            resp.raise_for_status()
            return resp.json().get("chunks", [])

    def generate_chunk(
        self,
        voice_id: str,
        text: str,
        *,
        settings: dict | None = None,
        seed: int | None = None,
    ) -> TtsResult:
        """Synthesize a single chunk (one seed, raw container bytes)."""
        body: dict = {"voice_id": voice_id, "text": text}
        if settings:
            body["settings"] = settings
        if seed is not None:
            body["seed"] = seed
        with self._http(internal=True) as client:
            resp = client.post("/v1/internal/generate", json=body)
            resp.raise_for_status()
            return TtsResult(audio=resp.content, content_type=resp.headers.get("content-type", "audio/wav"))

    def score(self, text: str, audio: bytes, *, filename: str = "chunk.wav") -> dict:
        """ASR-score a chunk's audio against its source text."""
        with self._http(internal=True) as client:
            resp = client.post(
                "/v1/internal/score",
                data={"text": text},
                files={"audio": (filename, audio, "audio/wav")},
            )
            resp.raise_for_status()
            return resp.json()

    def trim(self, audio: bytes, trim_at_ms: int, *, filename: str = "chunk.wav") -> TtsResult:
        """Hard-cut a raw chunk to its first ``trim_at_ms`` milliseconds."""
        with self._http(internal=True) as client:
            resp = client.post(
                "/v1/internal/trim",
                data={"trim_at_ms": str(trim_at_ms)},
                files={"audio": (filename, audio, "audio/wav")},
            )
            resp.raise_for_status()
            return TtsResult(audio=resp.content, content_type=resp.headers.get("content-type", "audio/wav"))

    def stitch(
        self,
        chunks: list[bytes],
        *,
        output_format: str,
        break_after: list[str] | None = None,
    ) -> TtsResult:
        """Concatenate ordered chunk audio into the final output."""
        files = [("chunks[]", (f"chunk{i}.wav", data, "audio/wav")) for i, data in enumerate(chunks)]
        form: dict = {"output_format": output_format}
        if break_after:
            form["break_after[]"] = list(break_after)
        with self._http(internal=True) as client:
            resp = client.post("/v1/internal/stitch", data=form, files=files)
            resp.raise_for_status()
            return TtsResult(audio=resp.content, content_type=resp.headers.get("content-type", "audio/mpeg"))
