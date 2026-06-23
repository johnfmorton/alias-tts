"""Build the Backblaze B2 provenance sink (or None for local dev / tests)."""

from __future__ import annotations

from genblaze_core import KeyStrategy, ObjectStorageSink
from genblaze_s3 import S3StorageBackend

from genblaze_runner.config import RunnerConfig


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
    return ObjectStorageSink(backend, prefix="genblaze", key_strategy=KeyStrategy.HIERARCHICAL)
