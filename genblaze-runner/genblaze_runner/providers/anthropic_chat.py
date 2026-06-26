"""Custom Genblaze chat provider for Anthropic's Messages API.

No published genblaze chat adapter targets Anthropic, so this wraps the Messages
API behind the same ``chat(messages, *, model, ...) -> ChatResponse`` surface as
the other providers. Structured output uses tool-use: a single tool whose
``input_schema`` is the detector's pydantic schema is forced, so the model returns
the substitution map as validated tool input (we serialize that input to JSON in
``ChatResponse.text`` so the caller parses it the same way as every other
provider). The system prompt is sent with ``cache_control: ephemeral`` for
prompt-cache billing.

Reads ``ANTHROPIC_API_KEY`` from the runner environment.
"""

from __future__ import annotations

import json
import os

import httpx
from pydantic import BaseModel

from genblaze_core.models.chat import ChatMessage, ChatResponse

_URL = "https://api.anthropic.com/v1/messages"
_VERSION = "2023-06-01"
_DEFAULT_TIMEOUT = 60.0
_TOOL_NAME = "emit_substitutions"


def chat(
    messages: list[ChatMessage],
    *,
    model: str,
    response_format=None,
    temperature: float = 0.2,
    max_tokens: int = 1024,
    timeout: float = _DEFAULT_TIMEOUT,
) -> ChatResponse:
    api_key = os.getenv("ANTHROPIC_API_KEY")
    if not api_key:
        raise RuntimeError("ANTHROPIC_API_KEY is not set in the runner environment")

    body: dict = {
        "model": model,
        "max_tokens": max_tokens,
        "temperature": temperature,
        "system": [
            {"type": "text", "text": _join_role(messages, "system"), "cache_control": {"type": "ephemeral"}}
        ],
        "messages": [{"role": "user", "content": _join_role(messages, "user")}],
    }

    # Force structured output via a single tool whose schema is the pydantic model.
    if isinstance(response_format, type) and issubclass(response_format, BaseModel):
        body["tools"] = [
            {
                "name": _TOOL_NAME,
                "description": "Return the pronunciation substitution map.",
                "input_schema": response_format.model_json_schema(),
            }
        ]
        body["tool_choice"] = {"type": "tool", "name": _TOOL_NAME}

    headers = {
        "x-api-key": api_key,
        "anthropic-version": _VERSION,
        "content-type": "application/json",
    }

    with httpx.Client(timeout=timeout) as client:
        resp = client.post(_URL, headers=headers, json=body)
        resp.raise_for_status()
        data = resp.json()

    usage = data.get("usage") or {}
    return ChatResponse(
        text=_extract_text(data),
        model=data.get("model", model),
        finish_reason=data.get("stop_reason"),
        tokens_in=usage.get("input_tokens"),
        tokens_out=usage.get("output_tokens"),
        raw=data,
    )


def _extract_text(data: dict) -> str:
    """Prefer the forced tool_use input (as JSON); fall back to text blocks."""
    for block in data.get("content", []):
        if block.get("type") == "tool_use":
            return json.dumps(block.get("input", {}))
    return "".join(b.get("text", "") for b in data.get("content", []) if b.get("type") == "text")


def _join_role(messages: list[ChatMessage], role: str) -> str:
    parts = [m.content for m in messages if m.role == role and isinstance(m.content, str)]
    return "\n\n".join(p for p in parts if p)
