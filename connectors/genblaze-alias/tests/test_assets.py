"""read_asset_bytes — authenticated B2 reads so the provenance bucket can stay private."""

from urllib.parse import urlparse

import boto3

from genblaze_alias._assets import _parse_b2_s3_url, read_asset_bytes, write_audio_asset


def test_parse_b2_s3_url_path_style():
    region, bucket, key = _parse_b2_s3_url(
        urlparse("https://s3.us-west-001.backblazeb2.com/johnfmorton/genblaze/runs/x/assets/a.wav")
    )
    assert region == "us-west-001"
    assert bucket == "johnfmorton"
    assert key == "genblaze/runs/x/assets/a.wav"


def test_file_url_round_trips(tmp_path):
    asset = write_audio_asset(tmp_path, "s1", b"hello", ext="wav", mime="audio/wav", codec="pcm_s16le")
    assert read_asset_bytes(asset.url) == b"hello"


def test_b2_url_uses_authenticated_get_when_creds_present(monkeypatch):
    monkeypatch.setenv("B2_KEY_ID", "keyid")
    monkeypatch.setenv("B2_APP_KEY", "appkey")
    seen = {}

    class _Body:
        def read(self):
            return b"signed-bytes"

    class _Client:
        def get_object(self, Bucket, Key):
            seen.update(bucket=Bucket, key=Key)
            return {"Body": _Body()}

    def _fake_client(service, **kw):
        seen.update(service=service, endpoint=kw.get("endpoint_url"),
                    region=kw.get("region_name"), akid=kw.get("aws_access_key_id"))
        return _Client()

    monkeypatch.setattr(boto3, "client", _fake_client)

    data = read_asset_bytes("https://s3.us-west-001.backblazeb2.com/johnfmorton/genblaze/runs/x/assets/a.wav")

    assert data == b"signed-bytes"
    assert seen["service"] == "s3"
    assert seen["endpoint"] == "https://s3.us-west-001.backblazeb2.com"
    assert seen["region"] == "us-west-001"
    assert seen["akid"] == "keyid"
    assert seen["bucket"] == "johnfmorton"
    assert seen["key"] == "genblaze/runs/x/assets/a.wav"


def test_b2_url_falls_back_to_plain_get_without_creds(monkeypatch):
    monkeypatch.delenv("B2_KEY_ID", raising=False)
    monkeypatch.delenv("B2_APP_KEY", raising=False)
    import genblaze_alias._assets as assets

    class _Resp:
        content = b"public-bytes"

        def raise_for_status(self):
            pass

    monkeypatch.setattr(assets.httpx, "get", lambda url, timeout=120.0: _Resp())

    assert read_asset_bytes("https://s3.us-west-001.backblazeb2.com/johnfmorton/x/a.wav") == b"public-bytes"
