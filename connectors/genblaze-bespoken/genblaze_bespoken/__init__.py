"""Genblaze provider connectors for the Bespoken TTS service.

* :class:`BespokenTTSProvider` — Posture A: wraps the whole-pipeline
  ``/v1/text-to-speech/{voice_id}`` endpoint.
* :class:`BespokenChunkProvider` — Posture B: single-chunk, single-seed
  synthesis via ``/v1/internal/generate``.
* :class:`BespokenQAProvider` — Posture B: ASR quality verdict via
  ``/v1/internal/score``.
* :class:`BespokenStitchProvider` — Posture B: concatenation via
  ``/v1/internal/stitch``.
"""

from genblaze_bespoken.provider_chunk import BespokenChunkProvider
from genblaze_bespoken.provider_qa import BespokenQAProvider
from genblaze_bespoken.provider_stitch import BespokenStitchProvider
from genblaze_bespoken.provider_tts import BespokenTTSProvider

__all__ = [
    "BespokenTTSProvider",
    "BespokenChunkProvider",
    "BespokenQAProvider",
    "BespokenStitchProvider",
]

__version__ = "0.1.0"
