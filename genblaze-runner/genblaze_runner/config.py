"""Runner configuration, read from the environment."""

from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass
class RunnerConfig:
    """Where the runner finds Bespoken and Backblaze B2, plus orchestration knobs."""

    bespoken_base_url: str = "http://localhost"
    bespoken_internal_secret: str = ""
    output_dir: str | None = None

    # Backblaze B2 (provenance store). When b2_bucket is empty the runner uses no
    # sink — fine for local dev / tests, where assets stay as local file:// URLs.
    b2_bucket: str | None = None
    b2_region: str | None = None
    b2_public_url_base: str | None = None

    max_rerolls: int = 3
    max_concurrency: int = 2

    @classmethod
    def from_env(cls) -> "RunnerConfig":
        return cls(
            bespoken_base_url=os.getenv("BESPOKEN_BASE_URL", "http://localhost"),
            bespoken_internal_secret=os.getenv("BESPOKEN_INTERNAL_SECRET", ""),
            output_dir=os.getenv("GENBLAZE_OUTPUT_DIR"),
            b2_bucket=os.getenv("B2_BUCKET") or None,
            b2_region=os.getenv("B2_REGION") or None,
            b2_public_url_base=os.getenv("B2_PUBLIC_URL_BASE") or None,
            max_rerolls=int(os.getenv("GENBLAZE_MAX_REROLLS", "3")),
            max_concurrency=int(os.getenv("GENBLAZE_MAX_CONCURRENCY", "2")),
        )
