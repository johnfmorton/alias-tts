"""Turn provider output bytes into Genblaze ``file://`` assets, and read a
chained input asset's bytes back.

Providers buffer audio/JSON to a local temp file and attach a ``file://``
:class:`Asset`; the :class:`ObjectStorageSink` uploads it to Backblaze B2 and
rewrites the URL. Inputs arrive as ``file://`` (local, pre-upload) or ``https://``
(durable B2 URL, post-upload). :func:`read_asset_bytes` handles both, fetching a
B2 object with an authenticated (SigV4) GET when B2 credentials are present — so
the provenance bucket can stay private (an unauthenticated GET would 401).
"""

from __future__ import annotations

import hashlib
import os
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
    path = Path(output_dir) / f"alias-{step_id or uuid.uuid4().hex}.{ext}"
    path.write_bytes(audio)
    asset = Asset(url=_file_url(path), media_type=mime)
    asset.size_bytes = len(audio)
    asset.sha256 = hashlib.sha256(audio).hexdigest()
    asset.audio = AudioMetadata(codec=codec, channels=1, sample_rate=sample_rate)
    return asset


def write_json_asset(output_dir: str | Path, step_id: str | None, blob: bytes) -> Asset:
    """Persist a JSON ``blob`` to a temp file and return a ``file://`` asset."""
    path = Path(output_dir) / f"alias-{step_id or uuid.uuid4().hex}.json"
    path.write_bytes(blob)
    asset = Asset(url=_file_url(path), media_type="application/json")
    asset.size_bytes = len(blob)
    asset.sha256 = hashlib.sha256(blob).hexdigest()
    return asset


def _parse_b2_s3_url(parsed) -> tuple[str | None, str, str]:
    """``(region, bucket, key)`` from a path-style B2 S3 URL
    ``https://s3.<region>.backblazeb2.com/<bucket>/<key>``."""
    region = parsed.netloc.removeprefix("s3.").removesuffix(".backblazeb2.com") or None
    bucket, _, key = unquote(parsed.path).lstrip("/").partition("/")
    return region, bucket, key


def _read_b2_object(parsed, *, timeout: float) -> bytes:
    """Authenticated GET of a B2 object using ``B2_KEY_ID`` / ``B2_APP_KEY`` from
    the environment (the same creds the sink uploads with), so a private bucket
    works — an unauthenticated GET would 401."""
    import boto3
    from botocore.config import Config

    region, bucket, key = _parse_b2_s3_url(parsed)
    client = boto3.client(
        "s3",
        endpoint_url=f"https://{parsed.netloc}",
        region_name=region,
        aws_access_key_id=os.environ["B2_KEY_ID"],
        aws_secret_access_key=os.environ["B2_APP_KEY"],
        config=Config(connect_timeout=timeout, read_timeout=timeout, retries={"max_attempts": 3}),
    )
    return client.get_object(Bucket=bucket, Key=key)["Body"].read()


def read_asset_bytes(url: str, *, timeout: float = 120.0) -> bytes:
    """Read an input asset's bytes from a ``file://`` or ``https://`` URL. A B2
    object URL is fetched with an authenticated GET when B2 credentials are present
    (private bucket); otherwise a plain GET is used (public bucket / CDN URL)."""
    parsed = urlparse(url)
    if parsed.scheme == "file":
        return Path(unquote(parsed.path)).read_bytes()
    if parsed.scheme == "https":
        if parsed.netloc.endswith(".backblazeb2.com") and os.getenv("B2_KEY_ID") and os.getenv("B2_APP_KEY"):
            return _read_b2_object(parsed, timeout=timeout)
        resp = httpx.get(url, timeout=timeout)
        resp.raise_for_status()
        return resp.content
    raise ValueError(f"Unsupported asset URL scheme for {url!r}")
