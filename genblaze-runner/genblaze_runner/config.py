"""Runner configuration, read from the environment."""

from __future__ import annotations

import os
from dataclasses import dataclass


def _normalize_storage_root(raw: str | None) -> str | None:
    """A shared-bucket subfolder (the app's ``TTS_STORAGE_ROOT``), normalized to
    a bare segment: no surrounding whitespace or slashes, empty → ``None``."""
    if not raw:
        return None
    return raw.strip().strip("/") or None


def _normalize_b2_region(raw: str | None) -> str | None:
    """B2's S3 endpoint is ``s3.<region>.backblazeb2.com``, so people often paste
    the endpoint-host form (``s3.us-west-001`` or the full host) into B2_REGION —
    but boto3 wants the bare region name (``us-west-001``). Strip a leading ``s3.``
    and any trailing ``.backblazeb2.com`` so all three forms work."""
    if not raw:
        return None
    region = raw.strip().removeprefix("s3.").split(".")[0]
    return region or None


@dataclass
class RunnerConfig:
    """Where the runner finds Alias and its object storage, plus orchestration
    knobs. Provenance storage is provider-agnostic: it reads the app's own
    ``AWS_*`` config (any S3-compatible provider — AWS S3, B2, R2, MinIO, …) so
    the runner writes to the SAME bucket with a single source of truth, and falls
    back to the legacy ``B2_*`` vars for a daemon whose env predates this."""

    alias_base_url: str = "http://localhost"
    alias_internal_secret: str = ""
    output_dir: str | None = None

    # Provider-agnostic S3 config, mirrored from the app's AWS_* env. s3_endpoint
    # blank => AWS S3; set it for B2 / R2 / MinIO / etc. (matches AWS_ENDPOINT).
    s3_bucket: str | None = None
    s3_endpoint: str | None = None
    s3_region: str | None = None
    s3_access_key_id: str | None = None
    s3_secret_access_key: str | None = None
    s3_public_url_base: str | None = None

    # Legacy Backblaze B2 provenance store — fallback when AWS_* is not set.
    # When neither an s3_bucket nor a b2_bucket is configured the runner uses no
    # sink (assets stay as local file:// URLs — fine for local dev / tests).
    b2_bucket: str | None = None
    b2_region: str | None = None
    b2_public_url_base: str | None = None

    # Shared-bucket subfolder: uploads go under {storage_root}/genblaze/ instead
    # of genblaze/, mirroring the app's TTS_STORAGE_ROOT so one bucket can serve
    # several apps. Both sides must agree or the app's provenance proxy 404s.
    storage_root: str | None = None

    max_rerolls: int = 3
    max_concurrency: int = 2

    @classmethod
    def from_env(cls) -> "RunnerConfig":
        return cls(
            # ALIAS_* are the current names; BESPOKEN_* are still read as a
            # fallback so a daemon whose env predates the rename keeps working.
            alias_base_url=os.getenv("ALIAS_BASE_URL") or os.getenv("BESPOKEN_BASE_URL", "http://localhost"),
            alias_internal_secret=os.getenv("ALIAS_INTERNAL_SECRET") or os.getenv("BESPOKEN_INTERNAL_SECRET", ""),
            output_dir=os.getenv("GENBLAZE_OUTPUT_DIR"),
            # The app's storage config, read directly so a wrapper that sources
            # the site's .env needs no mapping. Endpoint/region/keys/bucket all
            # come from the same AWS_* names Laravel uses.
            s3_bucket=os.getenv("AWS_BUCKET") or None,
            s3_endpoint=os.getenv("AWS_ENDPOINT") or None,
            s3_region=(os.getenv("AWS_DEFAULT_REGION") or "").strip() or None,
            s3_access_key_id=os.getenv("AWS_ACCESS_KEY_ID") or None,
            s3_secret_access_key=os.getenv("AWS_SECRET_ACCESS_KEY") or None,
            s3_public_url_base=os.getenv("AWS_URL") or None,
            b2_bucket=os.getenv("B2_BUCKET") or None,
            b2_region=_normalize_b2_region(os.getenv("B2_REGION")),
            b2_public_url_base=os.getenv("B2_PUBLIC_URL_BASE") or None,
            # TTS_STORAGE_ROOT is read directly so a wrapper that sources the
            # site's .env needs no extra mapping; ALIAS_STORAGE_ROOT overrides.
            storage_root=_normalize_storage_root(
                os.getenv("ALIAS_STORAGE_ROOT") or os.getenv("TTS_STORAGE_ROOT")
            ),
            max_rerolls=int(os.getenv("GENBLAZE_MAX_REROLLS", "3")),
            max_concurrency=int(os.getenv("GENBLAZE_MAX_CONCURRENCY", "2")),
        )
