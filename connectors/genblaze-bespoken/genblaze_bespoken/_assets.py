"""Turn provider output bytes into Genblaze ``file://`` assets, and read a
chained input asset's bytes back.

Providers buffer audio/JSON to a local temp file and attach a ``file://``
:class:`Asset`; the :class:`ObjectStorageSink` uploads it to Backblaze B2 and
rewrites the URL. Inputs arrive as ``file://`` (local, pre-upload) or
``https://`` (durable B2 URL, post-upload) — :func:`read_asset_bytes` handles
both.
"""

from __future__ import annotations

import hashlib
import uuid
from pathlib import Path
from urllib.parse import quote, unquote, urlparse

import httpx
from genblaze_core.models.asset import Asset, AudioMetadata


def _file_url(path: Path) -> str:
    return f"file://{quote(str(path.resolve()))}"


def write_audio_asset(
    output_dir: str | Path,
    step_id: str | None,
    audio: bytes,
    *,
    ext: str,
    mime: str,
    codec: str,
    sample_rate: int | None = None,
) -> Asset:
    """Persist ``audio`` to a temp file and return a ``file://`` audio asset."""
    path = Path(output_dir) / f"bespoken-{step_id or uuid.uuid4().hex}.{ext}"
    path.write_bytes(audio)
    asset = Asset(url=_file_url(path), media_type=mime)
    asset.size_bytes = len(audio)
    asset.sha256 = hashlib.sha256(audio).hexdigest()
    asset.audio = AudioMetadata(codec=codec, channels=1, sample_rate=sample_rate)
    return asset


def write_json_asset(output_dir: str | Path, step_id: str | None, blob: bytes) -> Asset:
    """Persist a JSON ``blob`` to a temp file and return a ``file://`` asset."""
    path = Path(output_dir) / f"bespoken-{step_id or uuid.uuid4().hex}.json"
    path.write_bytes(blob)
    asset = Asset(url=_file_url(path), media_type="application/json")
    asset.size_bytes = len(blob)
    asset.sha256 = hashlib.sha256(blob).hexdigest()
    return asset


def read_asset_bytes(url: str, *, timeout: float = 120.0) -> bytes:
    """Read an input asset's bytes from a ``file://`` or ``https://`` URL."""
    parsed = urlparse(url)
    if parsed.scheme == "file":
        return Path(unquote(parsed.path)).read_bytes()
    if parsed.scheme == "https":
        resp = httpx.get(url, timeout=timeout)
        resp.raise_for_status()
        return resp.content
    raise ValueError(f"Unsupported asset URL scheme for {url!r}")
