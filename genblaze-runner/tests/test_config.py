"""Config parsing — region normalization for the common B2 endpoint-host mistake,
the shared-bucket storage root, and provider-agnostic storage selection."""

from genblaze_runner.config import RunnerConfig, _normalize_b2_region, _normalize_storage_root
from genblaze_runner.sink import build_backend, sink_prefix

# Every storage env var from_env() reads — cleared before the mapping tests so an
# ambient AWS_*/B2_* in the shell can't leak in.
_STORAGE_ENV = (
    "AWS_BUCKET", "AWS_ENDPOINT", "AWS_DEFAULT_REGION", "AWS_ACCESS_KEY_ID",
    "AWS_SECRET_ACCESS_KEY", "AWS_URL",
    "B2_BUCKET", "B2_REGION", "B2_PUBLIC_URL_BASE",
)


def _clear_storage_env(monkeypatch):
    for name in _STORAGE_ENV:
        monkeypatch.delenv(name, raising=False)


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


def test_from_env_reads_the_apps_aws_storage_config(monkeypatch):
    # Provider-agnostic: the runner mirrors the app's AWS_* names (any S3 host).
    _clear_storage_env(monkeypatch)
    monkeypatch.setenv("AWS_BUCKET", "shared-bucket")
    monkeypatch.setenv("AWS_ENDPOINT", "https://s3.us-west-001.backblazeb2.com")
    monkeypatch.setenv("AWS_DEFAULT_REGION", "us-west-001")
    monkeypatch.setenv("AWS_ACCESS_KEY_ID", "keyid")
    monkeypatch.setenv("AWS_SECRET_ACCESS_KEY", "secret")

    config = RunnerConfig.from_env()

    assert config.s3_bucket == "shared-bucket"
    assert config.s3_endpoint == "https://s3.us-west-001.backblazeb2.com"
    assert config.s3_region == "us-west-001"
    assert config.s3_access_key_id == "keyid"
    assert config.s3_secret_access_key == "secret"


def test_from_env_falls_back_to_legacy_b2_when_aws_is_absent(monkeypatch):
    _clear_storage_env(monkeypatch)
    monkeypatch.setenv("B2_BUCKET", "b2-bucket")
    monkeypatch.setenv("B2_REGION", "s3.us-west-001")  # endpoint-host form, normalized

    config = RunnerConfig.from_env()

    assert config.s3_bucket is None
    assert config.b2_bucket == "b2-bucket"
    assert config.b2_region == "us-west-001"


def test_build_backend_prefers_the_apps_s3_config():
    # AWS_* present → generic S3 backend pointed at that endpoint/bucket, so the
    # runner writes to whatever provider the app uses (AWS, B2, R2, MinIO, …).
    backend = build_backend(RunnerConfig(
        s3_bucket="shared-bucket",
        s3_endpoint="https://s3.us-west-001.backblazeb2.com",
        s3_region="us-west-001",
        s3_access_key_id="keyid",
        s3_secret_access_key="secret",
    ))

    assert backend is not None
    assert backend._bucket == "shared-bucket"
    assert backend._endpoint_url == "https://s3.us-west-001.backblazeb2.com"
    assert backend._region == "us-west-001"


def test_build_backend_uses_aws_s3_when_no_endpoint_is_set():
    # Blank endpoint => real AWS S3 (boto3 default endpoint).
    backend = build_backend(RunnerConfig(
        s3_bucket="my-aws-bucket", s3_region="us-east-1",
        s3_access_key_id="AKIA", s3_secret_access_key="secret",
    ))

    assert backend is not None
    assert backend._endpoint_url is None
    assert backend._is_b2 is False


def test_build_backend_falls_back_to_backblaze_factory(monkeypatch):
    # Only legacy B2_* set → route through the B2 convenience factory.
    seen = {}

    def fake_for_backblaze(bucket, *, region=None, public_url_base=None):
        seen.update(bucket=bucket, region=region)
        return "B2_BACKEND"

    monkeypatch.setattr("genblaze_runner.sink.S3StorageBackend.for_backblaze", fake_for_backblaze)

    backend = build_backend(RunnerConfig(b2_bucket="b2-bucket", b2_region="us-west-001"))

    assert backend == "B2_BACKEND"
    assert seen == {"bucket": "b2-bucket", "region": "us-west-001"}


def test_build_backend_none_without_any_bucket():
    assert build_backend(RunnerConfig()) is None


def test_health_reports_the_storage_root(monkeypatch):
    # The app's tts:doctor compares this against its own TTS_STORAGE_ROOT to
    # flag a shared-bucket disagreement, so /health must always carry the key.
    from genblaze_runner import app as runner_app

    monkeypatch.setattr(runner_app, "_config", RunnerConfig(storage_root="mimic"))
    assert runner_app.health()["storage_root"] == "mimic"

    monkeypatch.setattr(runner_app, "_config", RunnerConfig())
    assert runner_app.health()["storage_root"] is None
