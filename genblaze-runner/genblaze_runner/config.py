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
    """Where the runner finds Mimic and Backblaze B2, plus orchestration knobs."""

    mimic_base_url: str = "http://localhost"
    mimic_internal_secret: str = ""
    output_dir: str | None = None

    # Backblaze B2 (provenance store). When b2_bucket is empty the runner uses no
    # sink — fine for local dev / tests, where assets stay as local file:// URLs.
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
            # MIMIC_* are the current names; BESPOKEN_* are still read as a
            # fallback so a daemon whose env predates the rename keeps working.
            mimic_base_url=os.getenv("MIMIC_BASE_URL") or os.getenv("BESPOKEN_BASE_URL", "http://localhost"),
            mimic_internal_secret=os.getenv("MIMIC_INTERNAL_SECRET") or os.getenv("BESPOKEN_INTERNAL_SECRET", ""),
            output_dir=os.getenv("GENBLAZE_OUTPUT_DIR"),
            b2_bucket=os.getenv("B2_BUCKET") or None,
            b2_region=_normalize_b2_region(os.getenv("B2_REGION")),
            b2_public_url_base=os.getenv("B2_PUBLIC_URL_BASE") or None,
            # TTS_STORAGE_ROOT is read directly so a wrapper that sources the
            # site's .env needs no extra mapping; MIMIC_STORAGE_ROOT overrides.
            storage_root=_normalize_storage_root(
                os.getenv("MIMIC_STORAGE_ROOT") or os.getenv("TTS_STORAGE_ROOT")
            ),
            max_rerolls=int(os.getenv("GENBLAZE_MAX_REROLLS", "3")),
            max_concurrency=int(os.getenv("GENBLAZE_MAX_CONCURRENCY", "2")),
        )
