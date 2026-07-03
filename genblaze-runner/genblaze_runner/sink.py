"""Build the object-storage provenance sink (or None for local dev / tests)."""

from __future__ import annotations

from genblaze_core import KeyStrategy, ObjectStorageSink
from genblaze_s3 import S3StorageBackend

from genblaze_runner.config import RunnerConfig


def sink_prefix(storage_root: str | None) -> str:
    """The bucket prefix all uploads live under: ``genblaze``, nested inside the
    app's shared-bucket subfolder (``TTS_STORAGE_ROOT``) when one is set. Must
    match where the app's provenance proxy looks (its s3 disk root + genblaze/)."""
    return f"{storage_root}/genblaze" if storage_root else "genblaze"


def build_backend(config: RunnerConfig):
    """Return an ``S3StorageBackend`` for provenance, or ``None`` when no bucket
    is configured (assets then stay as local ``file://`` URLs).

    Prefers the app's provider-agnostic ``AWS_*`` config (any S3-compatible
    provider — a blank endpoint means AWS S3; a set endpoint means B2 / R2 /
    MinIO / …), so the runner writes to the SAME bucket the app does. Falls back
    to the legacy Backblaze-only ``B2_*`` config for an env that predates this.
    """
    if config.s3_bucket:
        # Generic S3: endpoint_url=None => AWS S3; a B2 endpoint auto-enables the
        # backend's B2-specific handling (region auto-detect, checksum quirks).
        return S3StorageBackend(
            config.s3_bucket,
            endpoint_url=config.s3_endpoint or None,
            region=config.s3_region or None,
            aws_access_key_id=config.s3_access_key_id or None,
            aws_secret_access_key=config.s3_secret_access_key or None,
            public_url_base=config.s3_public_url_base or None,
        )

    if config.b2_bucket:
        return S3StorageBackend.for_backblaze(
            config.b2_bucket,
            region=config.b2_region or None,
            public_url_base=config.b2_public_url_base or None,
        )

    return None


def build_sink(config: RunnerConfig):
    """Wrap {@see build_backend} in an :class:`ObjectStorageSink`, or ``None`` when
    no storage is configured.

    Uses the run-grouped HIERARCHICAL layout so every take + manifest lands under
    ``runs/{tenant}/{date}/{run_id}/`` — the provenance trail the demo shows off.
    """
    backend = build_backend(config)
    if backend is None:
        return None

    return ObjectStorageSink(backend, prefix=sink_prefix(config.storage_root), key_strategy=KeyStrategy.HIERARCHICAL)
