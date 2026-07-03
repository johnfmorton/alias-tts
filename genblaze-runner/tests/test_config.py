"""Config parsing — region normalization for the common B2 endpoint-host mistake,
and the shared-bucket storage root."""

from genblaze_runner.config import RunnerConfig, _normalize_b2_region, _normalize_storage_root
from genblaze_runner.sink import sink_prefix


def test_normalize_b2_region_accepts_all_three_forms():
    assert _normalize_b2_region("us-west-001") == "us-west-001"          # bare region (correct)
    assert _normalize_b2_region("s3.us-west-001") == "us-west-001"       # endpoint-host prefix
    assert _normalize_b2_region("s3.us-west-001.backblazeb2.com") == "us-west-001"  # full host
    assert _normalize_b2_region("  s3.eu-central-003  ") == "eu-central-003"        # whitespace


def test_normalize_b2_region_empty_is_none():
    assert _normalize_b2_region("") is None
    assert _normalize_b2_region(None) is None


def test_normalize_storage_root_strips_slashes_and_whitespace():
    assert _normalize_storage_root("mimic") == "mimic"
    assert _normalize_storage_root("/mimic/") == "mimic"
    assert _normalize_storage_root("  mimic  ") == "mimic"
    assert _normalize_storage_root("") is None
    assert _normalize_storage_root("/") is None
    assert _normalize_storage_root(None) is None


def test_sink_prefix_nests_genblaze_under_the_storage_root():
    assert sink_prefix(None) == "genblaze"                    # default: top-level, matches the app
    assert sink_prefix("mimic") == "mimic/genblaze"           # shared bucket: TTS_STORAGE_ROOT set


def test_storage_root_reads_the_apps_env_name_directly(monkeypatch):
    # A wrapper that sources the site's .env passes TTS_STORAGE_ROOT through
    # untouched — no MIMIC_* mapping needed; MIMIC_STORAGE_ROOT still overrides.
    monkeypatch.setenv("TTS_STORAGE_ROOT", "mimic")
    assert RunnerConfig.from_env().storage_root == "mimic"

    monkeypatch.setenv("MIMIC_STORAGE_ROOT", "other")
    assert RunnerConfig.from_env().storage_root == "other"


def test_health_reports_the_storage_root(monkeypatch):
    # The app's tts:doctor compares this against its own TTS_STORAGE_ROOT to
    # flag a shared-bucket disagreement, so /health must always carry the key.
    from genblaze_runner import app as runner_app

    monkeypatch.setattr(runner_app, "_config", RunnerConfig(storage_root="mimic"))
    assert runner_app.health()["storage_root"] == "mimic"

    monkeypatch.setattr(runner_app, "_config", RunnerConfig())
    assert runner_app.health()["storage_root"] is None
