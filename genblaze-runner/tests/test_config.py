"""Config parsing — region normalization for the common B2 endpoint-host mistake."""

from genblaze_runner.config import _normalize_b2_region


def test_normalize_b2_region_accepts_all_three_forms():
    assert _normalize_b2_region("us-west-001") == "us-west-001"          # bare region (correct)
    assert _normalize_b2_region("s3.us-west-001") == "us-west-001"       # endpoint-host prefix
    assert _normalize_b2_region("s3.us-west-001.backblazeb2.com") == "us-west-001"  # full host
    assert _normalize_b2_region("  s3.eu-central-003  ") == "eu-central-003"        # whitespace


def test_normalize_b2_region_empty_is_none():
    assert _normalize_b2_region("") is None
    assert _normalize_b2_region(None) is None
