"""Build the Backblaze B2 provenance sink (or None for local dev / tests)."""

from __future__ import annotations

from genblaze_core import KeyStrategy, ObjectStorageSink
from genblaze_s3 import S3StorageBackend

from genblaze_runner.config import RunnerConfig


def sink_prefix(storage_root: str | None) -> str:
    """The bucket prefix all uploads live under: ``genblaze``, nested inside the
    app's shared-bucket subfolder (``TTS_STORAGE_ROOT``) when one is set. Must
    match where the app's provenance proxy looks (its s3 disk root + genblaze/)."""
    return f"{storage_root}/genblaze" if storage_root else "genblaze"


def build_sink(config: RunnerConfig):
    """Return an :class:`ObjectStorageSink` backed by Backblaze B2, or ``None``
    when no bucket is configured (assets then stay as local ``file://`` URLs).

    Uses the run-grouped HIERARCHICAL layout so every take + manifest lands under
    ``runs/{tenant}/{date}/{run_id}/`` — the provenance trail the demo shows off.
    """
    if not config.b2_bucket:
        return None

    backend = S3StorageBackend.for_backblaze(
        config.b2_bucket,
        region=config.b2_region or None,
        public_url_base=config.b2_public_url_base or None,
    )
    return ObjectStorageSink(backend, prefix=sink_prefix(config.storage_root), key_strategy=KeyStrategy.HIERARCHICAL)
