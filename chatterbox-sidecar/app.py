"""
chatterbox-sidecar — local Chatterbox TTS inference for development.

A small FastAPI app that wraps the open-source chatterbox-tts package so the
Laravel app (App\\Services\\Tts\\LocalChatterboxProvider, selected with
TTS_PROVIDER=local) can synthesize speech on the developer's own machine
instead of Replicate. Both engines are served — classic `chatterbox` and
`chatterbox-turbo` — each lazy-loaded on first use and kept warm after that.

Run (see docs/CHATTERBOX-LOCAL.md for the full setup):

    uvicorn app:app --host 127.0.0.1 --port 8766

Configuration (environment variables):

    CHATTERBOX_DEVICE  cpu | cuda | mps   (default: cpu — on Apple Silicon
                       CPU is FASTER than MPS; see docs/CHATTERBOX-LOCAL.md)
    HF_HOME            Hugging Face cache dir for the ~3.8GB model downloads
                       (default: ~/.cache/huggingface)
"""

from __future__ import annotations

import io
import os
import platform
import random
import tempfile
import threading
import time
from importlib.metadata import version as pkg_version

from fastapi import FastAPI, File, Form, UploadFile
from fastapi.responses import JSONResponse, Response

DEVICE = os.environ.get("CHATTERBOX_DEVICE", "cpu")

app = FastAPI(title="chatterbox-sidecar", version="1.0.0")

# Heavy imports happen once here. If they fail (broken venv, missing wheels),
# /health reports the error instead of the worker crashing on every request.
_import_error: str | None = None
try:
    import numpy as np
    import torch
    import torchaudio
except Exception as exc:  # pragma: no cover - defensive
    _import_error = f"{type(exc).__name__}: {exc}"

MODEL_KEYS = ("chatterbox", "chatterbox-turbo")

# Knob ranges mirror the Laravel-side tuning clamps (ChatterboxTuning /
# ChatterboxTurboTuning) — defense in depth, the app clamps before sending.
CLAMPS = {
    "cfg_weight": (0.2, 1.0),
    "exaggeration": (0.25, 2.0),
    "temperature": (0.5, 1.5),
    "top_p": (0.5, 1.0),
    "top_k": (1, 2000),
    "repetition_penalty": (1.0, 2.0),
}

_models: dict[str, object] = {}
# The built-in voice (conds.pt) captured at load time. generate() with a
# reference clip PERMANENTLY swaps the model's conditionals to that clip, so a
# later clip-less request would silently speak in the previous request's
# voice. Restoring this snapshot before every clip-less generate keeps the
# built-in voice honest.
_builtin_conds: dict[str, object] = {}
_load_errors: dict[str, str] = {}
_load_seconds: dict[str, float] = {}

# One generation at a time: endpoints are sync `def`s (FastAPI runs them in
# its threadpool), so concurrent requests simply queue on this lock.
_lock = threading.Lock()


def _clamp(name: str, value: float | int) -> float | int:
    lo, hi = CLAMPS[name]
    return max(lo, min(hi, value))


def _get_model(key: str):
    """Load a model on first use (caller must hold _lock)."""
    if key in _models:
        return _models[key]

    t0 = time.perf_counter()
    if key == "chatterbox":
        from chatterbox.tts import ChatterboxTTS

        model = ChatterboxTTS.from_pretrained(device=DEVICE)
    else:
        from chatterbox.tts_turbo import ChatterboxTurboTTS

        model = ChatterboxTurboTTS.from_pretrained(device=DEVICE)

    _load_seconds[key] = round(time.perf_counter() - t0, 1)
    _models[key] = model
    _builtin_conds[key] = model.conds
    _load_errors.pop(key, None)
    return model


@app.get("/health")
def health() -> JSONResponse:
    """Liveness + readiness. Cheap: never runs inference or loads a model.

    Lazy loading means "not loaded yet" is the normal idle state — this
    returns 200 as long as the service and its imports are healthy.
    """
    ok = _import_error is None
    return JSONResponse(
        status_code=200 if ok else 503,
        content={
            "status": "ok" if ok else "error",
            "device": DEVICE,
            "python": platform.python_version(),
            "torch": torch.__version__ if ok else None,
            "chatterbox_tts": pkg_version("chatterbox-tts"),
            "busy": _lock.locked(),
            "error": _import_error,
            "models": {
                key: {
                    "loaded": key in _models,
                    "error": _load_errors.get(key),
                    "load_seconds": _load_seconds.get(key),
                }
                for key in MODEL_KEYS
            },
        },
    )


@app.post("/synthesize")
def synthesize(
    text: str = Form(...),
    model: str = Form("chatterbox"),
    reference: UploadFile | None = File(None),
    cfg_weight: float | None = Form(None),
    exaggeration: float | None = Form(None),
    temperature: float | None = Form(None),
    top_p: float | None = Form(None),
    top_k: int | None = Form(None),
    repetition_penalty: float | None = Form(None),
    seed: int | None = Form(None),
) -> Response:
    """
    Synthesize speech and return raw WAV bytes (24kHz mono).

    Synchronous by design — no prediction/polling dance. The caller waits;
    a 300-char chunk takes ~9s on an M4 Max CPU (turbo), longer on classic.
    Diagnostic headers: X-Model, X-Sample-Rate, X-Generation-Seconds.
    """
    if _import_error is not None:
        return JSONResponse(status_code=503, content={"error": _import_error})

    text = text.strip()
    if not text:
        return JSONResponse(status_code=400, content={"error": "text is required"})
    if model not in MODEL_KEYS:
        return JSONResponse(
            status_code=400,
            content={"error": f"unknown model '{model}' (expected one of: {', '.join(MODEL_KEYS)})"},
        )

    # Persist the uploaded clip before entering the lock; generate() reads it
    # from disk via audio_prompt_path.
    ref_path: str | None = None
    if reference is not None:
        suffix = os.path.splitext(reference.filename or "")[1] or ".wav"
        tmp = tempfile.NamedTemporaryFile(suffix=suffix, delete=False)
        tmp.write(reference.file.read())
        tmp.flush()
        tmp.close()
        ref_path = tmp.name

    try:
        with _lock:
            try:
                engine = _get_model(model)
            except Exception as exc:
                _load_errors[model] = f"{type(exc).__name__}: {exc}"
                return JSONResponse(
                    status_code=503,
                    content={"error": _load_errors[model], "model": model},
                )

            # Honest seed pin: locally a pinned seed reproduces the take
            # (same sampled tokens/length; waveform floats can still wiggle
            # at numerical-noise level — CPU threading isn't bit-exact).
            if seed is not None:
                torch.manual_seed(seed)
                random.seed(seed)
                np.random.seed(seed % 2**32)

            kwargs: dict[str, float | int | str] = {}
            if model == "chatterbox":
                if cfg_weight is not None:
                    kwargs["cfg_weight"] = _clamp("cfg_weight", cfg_weight)
                if exaggeration is not None:
                    kwargs["exaggeration"] = _clamp("exaggeration", exaggeration)
                if temperature is not None:
                    kwargs["temperature"] = _clamp("temperature", temperature)
            else:
                if temperature is not None:
                    kwargs["temperature"] = _clamp("temperature", temperature)
                if top_p is not None:
                    kwargs["top_p"] = _clamp("top_p", top_p)
                if top_k is not None:
                    kwargs["top_k"] = int(_clamp("top_k", top_k))
                if repetition_penalty is not None:
                    kwargs["repetition_penalty"] = _clamp(
                        "repetition_penalty", repetition_penalty
                    )

            if ref_path is not None:
                kwargs["audio_prompt_path"] = ref_path
            else:
                # Restore the built-in voice a previous clip may have replaced.
                engine.conds = _builtin_conds[model]
                if engine.conds is None:
                    return JSONResponse(
                        status_code=400,
                        content={
                            "error": "no reference clip given and the model has no built-in voice"
                        },
                    )

            t0 = time.perf_counter()
            try:
                wav = engine.generate(text, **kwargs)
            except Exception as exc:
                return JSONResponse(
                    status_code=500,
                    content={"error": f"{type(exc).__name__}: {exc}", "model": model},
                )
            elapsed = time.perf_counter() - t0

            buf = io.BytesIO()
            torchaudio.save(buf, wav, engine.sr, format="wav")
            return Response(
                content=buf.getvalue(),
                media_type="audio/wav",
                headers={
                    "X-Model": model,
                    "X-Sample-Rate": str(engine.sr),
                    "X-Generation-Seconds": f"{elapsed:.2f}",
                },
            )
    finally:
        if ref_path is not None:
            try:
                os.unlink(ref_path)
            except OSError:
                pass
