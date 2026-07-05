# genblaze-alias

Genblaze provider connectors for the [Alias](https://github.com/johnfmorton) self-hosted,
ElevenLabs-compatible TTS service (Chatterbox on Replicate). Built for the
**Backblaze "Build with Genblaze on B2"** hackathon.

## Providers

| Provider | Status | Wraps |
|---|---|---|
| `AliasTTSProvider` | ✅ Posture A | `POST /v1/text-to-speech/{voice_id}` (full pipeline) |
| `AliasQAProvider` | ⏳ Posture B | `POST /v1/internal/score` (ASR quality verdict) |
| `AliasStitchProvider` | ⏳ Posture B | `POST /v1/internal/stitch` (concat chunks) |

`AliasTTSProvider` is a `SyncProvider`: it calls Alias, buffers the
returned audio to a local temp file, and attaches a `file://` `Asset`. The
`ObjectStorageSink` uploads it to Backblaze B2 and rewrites the URL — no
public/signed audio URL is needed on the Laravel side.

## Install

```bash
pip install -e ".[dev]"     # dev extras: pytest, genblaze-s3, pyarrow
```

## Configuration (env)

| Var | Purpose |
|---|---|
| `ALIAS_BASE_URL` | Base URL of the Alias service (e.g. `https://tts.example.com`) |
| `ALIAS_API_KEY` | API key sent as the `xi-api-key` header |
| `GENBLAZE_OUTPUT_DIR` | Local dir for temp audio before B2 upload (defaults to the system temp dir) |

## Test

```bash
pytest                       # runs ProviderComplianceTests + unit tests
```

## Usage

```python
from genblaze_core import Pipeline
from genblaze_core.models.enums import Modality
from genblaze_alias import AliasTTSProvider

result = (
    Pipeline("narration")
    .step(AliasTTSProvider(), model="default",
          prompt="Welcome to the future of media provenance.",
          modality=Modality.AUDIO, output_format="mp3_44100_128")
    .run(sink=storage)   # ObjectStorageSink → Backblaze B2
)
```
