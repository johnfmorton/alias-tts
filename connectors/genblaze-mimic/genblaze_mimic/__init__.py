"""Genblaze provider connectors for the Mimic TTS service.

* :class:`MimicTTSProvider` — Posture A: wraps the whole-pipeline
  ``/v1/text-to-speech/{voice_id}`` endpoint.
* :class:`MimicChunkProvider` — Posture B: single-chunk, single-seed
  synthesis via ``/v1/internal/generate``.
* :class:`MimicQAProvider` — Posture B: ASR quality verdict via
  ``/v1/internal/score``.
* :class:`MimicStitchProvider` — Posture B: concatenation via
  ``/v1/internal/stitch``.
"""

from genblaze_mimic.provider_chunk import MimicChunkProvider
from genblaze_mimic.provider_qa import MimicQAProvider
from genblaze_mimic.provider_stitch import MimicStitchProvider
from genblaze_mimic.provider_tts import MimicTTSProvider

__all__ = [
    "MimicTTSProvider",
    "MimicChunkProvider",
    "MimicQAProvider",
    "MimicStitchProvider",
]

__version__ = "0.1.0"
