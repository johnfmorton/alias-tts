"""
tts-asr — local Whisper sidecar for the TTS service.

A tiny FastAPI app that wraps faster-whisper. It loads the model ONCE at
start-up and keeps it warm in memory, so each /transcribe call avoids the
~2.5s model-load cost. The Laravel app (App\\Services\\Asr\\AsrClient) calls
it over localhost to transcribe generated audio chunks and check them for
truncation / tail artifacts / mid-stream pauses.

Run (see docs/ASR-SETUP.md for the Forge Daemon setup):

    uvicorn app:app --host 127.0.0.1 --port 8765

Configuration (environment variables):

    ASR_MODEL          faster-whisper model size      (default: tiny)
    ASR_DEVICE         cpu | cuda                      (default: cpu)
    ASR_COMPUTE_TYPE   int8 | int8_float16 | float16   (default: int8)
    ASR_LANGUAGE       forced language, or "auto"      (default: en)
    ASR_DOWNLOAD_ROOT  model cache dir (optional)
"""

from __future__ import annotations

import os
import tempfile
import time

from fastapi import FastAPI, File, Form, UploadFile
from fastapi.responses import JSONResponse

import faster_whisper
from faster_whisper import WhisperModel

MODEL_SIZE = os.environ.get("ASR_MODEL", "tiny")
DEVICE = os.environ.get("ASR_DEVICE", "cpu")
COMPUTE_TYPE = os.environ.get("ASR_COMPUTE_TYPE", "int8")
DEFAULT_LANGUAGE = os.environ.get("ASR_LANGUAGE", "en")
DOWNLOAD_ROOT = os.environ.get("ASR_DOWNLOAD_ROOT") or None

app = FastAPI(title="tts-asr", version="1.0.0")

# Loaded once at import time so the daemon comes up warm. If the model can't
# load, _model stays None and /health reports the failure instead of crashing
# the worker on every request.
_model: WhisperModel | None = None
_load_error: str | None = None
_loaded_at: float | None = None


def _load_model() -> None:
    global _model, _load_error, _loaded_at
    try:
        _model = WhisperModel(
            MODEL_SIZE,
            device=DEVICE,
            compute_type=COMPUTE_TYPE,
            download_root=DOWNLOAD_ROOT,
        )
        _loaded_at = time.time()
        _load_error = None
    except Exception as exc:  # pragma: no cover - defensive
        _model = None
        _load_error = f"{type(exc).__name__}: {exc}"


_load_model()


@app.get("/health")
def health() -> JSONResponse:
    """Liveness + readiness. Cheap: does not run inference."""
    ok = _model is not None
    return JSONResponse(
        status_code=200 if ok else 503,
        content={
            "status": "ok" if ok else "error",
            "model": MODEL_SIZE,
            "device": DEVICE,
            "compute_type": COMPUTE_TYPE,
            "language": DEFAULT_LANGUAGE,
            "faster_whisper_version": faster_whisper.__version__,
            "error": _load_error,
        },
    )


@app.post("/transcribe")
async def transcribe(
    audio: UploadFile = File(...),
    language: str | None = Form(None),
) -> JSONResponse:
    """
    Transcribe an uploaded audio file with word-level timestamps.

    Returns:
        {
          "duration": float,            # seconds of audio
          "language": str,
          "language_probability": float,
          "text": str,                  # full transcript
          "words": [ {"word": str, "start": float, "end": float}, ... ],
          "transcribe_ms": int          # server-side inference time
        }
    """
    if _model is None:
        return JSONResponse(
            status_code=503,
            content={"error": _load_error or "model not loaded"},
        )

    lang = language or DEFAULT_LANGUAGE
    lang = None if lang == "auto" else lang

    suffix = os.path.splitext(audio.filename or "")[1] or ".wav"
    tmp = tempfile.NamedTemporaryFile(suffix=suffix, delete=False)
    try:
        tmp.write(await audio.read())
        tmp.flush()
        tmp.close()

        t0 = time.perf_counter()
        segments, info = _model.transcribe(
            tmp.name,
            language=lang,
            word_timestamps=True,
        )

        words = []
        texts = []
        for seg in segments:
            texts.append(seg.text)
            for w in (seg.words or []):
                words.append(
                    {
                        "word": w.word.strip(),
                        "start": round(float(w.start), 3),
                        "end": round(float(w.end), 3),
                    }
                )
        elapsed_ms = int((time.perf_counter() - t0) * 1000)

        return JSONResponse(
            content={
                "duration": round(float(info.duration), 3),
                "language": info.language,
                "language_probability": round(float(info.language_probability), 3),
                "text": "".join(texts).strip(),
                "words": words,
                "transcribe_ms": elapsed_ms,
            }
        )
    finally:
        try:
            os.unlink(tmp.name)
        except OSError:
            pass
