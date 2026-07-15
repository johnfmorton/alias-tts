"""Custom Genblaze chat provider for a local Ollama server.

The fully-local counterpart to the cloud chat providers: detection runs on a
model served by Ollama (https://ollama.com) on the same machine, so no text
leaves the box and there is no API key or per-call cost. Pairs with
TTS_PROVIDER=local (the Chatterbox sidecar) for an end-to-end offline dev
stack — see docs/CHATTERBOX-LOCAL.md.

Reads ``OLLAMA_HOST`` from the runner environment — required, because the
right value depends on where the runner lives relative to Ollama:
``http://127.0.0.1:11434`` beside a host-process runner, and
``http://host.docker.internal:11434`` from the DDEV runner container (or any
Docker topology where Ollama runs on the host). Accepts Ollama's own
``OLLAMA_HOST`` shorthand forms — scheme and port are optional and default to
``http`` / ``11434``.

Structured output uses Ollama's native ``format`` parameter: the detector's
pydantic JSON schema constrains sampling grammar-side, so any pulled model
returns valid substitution-map JSON — enforcement the Replicate default can
only approximate by prompting.
"""

from __future__ import annotations

import os
from urllib.parse import urlparse

import httpx
from pydantic import BaseModel

from genblaze_core.models.chat import ChatMessage, ChatResponse

# Local inference pays no per-token bill, but a cold model load (tens of
# seconds for a 15GB+ model) precedes the first token — be generous.
_DEFAULT_TIMEOUT = 300.0


def chat(
    messages: list[ChatMessage],
    *,
    model: str,
    response_format=None,
    temperature: float = 0.2,
    timeout: float = _DEFAULT_TIMEOUT,
) -> ChatResponse:
    host = os.getenv("OLLAMA_HOST")
    if not host:
        raise RuntimeError(
            "OLLAMA_HOST is not set in the runner environment "
            "(http://127.0.0.1:11434 beside a host runner; "
            "http://host.docker.internal:11434 from the DDEV runner container)"
        )

    body: dict = {
        "model": model,
        "stream": False,
        "messages": [
            {"role": m.role, "content": m.content}
            for m in messages
            if isinstance(m.content, str) and m.content
        ],
        "options": {"temperature": temperature},
    }

    # Grammar-enforced structured output from the pydantic schema.
    if isinstance(response_format, type) and issubclass(response_format, BaseModel):
        body["format"] = response_format.model_json_schema()

    with httpx.Client(timeout=timeout) as client:
        resp = client.post(f"{normalize_host(host)}/api/chat", json=body)
        resp.raise_for_status()
        data = resp.json()

    return ChatResponse(
        text=(data.get("message") or {}).get("content", ""),
        model=data.get("model", model),
        finish_reason=data.get("done_reason"),
        tokens_in=data.get("prompt_eval_count"),
        tokens_out=data.get("eval_count"),
        raw=data,
    )


def normalize_host(host: str) -> str:
    """Expand OLLAMA_HOST shorthand to a full base URL (no trailing slash)."""
    host = host.rstrip("/")
    if "://" not in host:
        host = f"http://{host}"
    if urlparse(host).port is None:
        host = f"{host}:11434"
    return host
