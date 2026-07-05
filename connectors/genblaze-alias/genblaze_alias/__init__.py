"""Genblaze provider connectors for the Alias TTS service.

* :class:`AliasTTSProvider` — Posture A: wraps the whole-pipeline
  ``/v1/text-to-speech/{voice_id}`` endpoint.
* :class:`AliasChunkProvider` — Posture B: single-chunk, single-seed
  synthesis via ``/v1/internal/generate``.
* :class:`AliasQAProvider` — Posture B: ASR quality verdict via
  ``/v1/internal/score``.
* :class:`AliasStitchProvider` — Posture B: concatenation via
  ``/v1/internal/stitch``.
"""

from genblaze_alias.provider_chunk import AliasChunkProvider
from genblaze_alias.provider_qa import AliasQAProvider
from genblaze_alias.provider_stitch import AliasStitchProvider
from genblaze_alias.provider_tts import AliasTTSProvider

__all__ = [
    "AliasTTSProvider",
    "AliasChunkProvider",
    "AliasQAProvider",
    "AliasStitchProvider",
]

__version__ = "0.1.0"
