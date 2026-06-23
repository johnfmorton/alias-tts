"""Map an ElevenLabs-style ``output_format`` to (extension, mime, codec, rate)."""

from __future__ import annotations

# output_format -> (extension, mime, codec, sample_rate)
_FORMAT_TABLE: dict[str, tuple[str, str, str, int]] = {
    "mp3_44100_128": ("mp3", "audio/mpeg", "mp3", 44100),
    "mp3_44100_64": ("mp3", "audio/mpeg", "mp3", 44100),
    "mp3_44100_32": ("mp3", "audio/mpeg", "mp3", 44100),
    "wav": ("wav", "audio/wav", "pcm_s16le", 44100),
    "pcm_16000": ("wav", "audio/wav", "pcm_s16le", 16000),
    "pcm_24000": ("wav", "audio/wav", "pcm_s16le", 24000),
    "ulaw_8000": ("wav", "audio/basic", "pcm_mulaw", 8000),
}


def format_meta(output_format: str | None, content_type: str) -> tuple[str, str, str, int]:
    """Resolve (extension, mime, codec, sample_rate), falling back to the
    server's response content-type when the format string is unknown."""
    if output_format and output_format in _FORMAT_TABLE:
        return _FORMAT_TABLE[output_format]
    if "wav" in (content_type or ""):
        return ("wav", "audio/wav", "pcm_s16le", 44100)
    return ("mp3", "audio/mpeg", "mp3", 44100)
