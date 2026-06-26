"""A custom Genblaze chat provider for Replicate's hosted language models.

There is no published ``genblaze-replicate`` CHAT adapter (the off-the-shelf one
targets image/video), so this wraps Replicate's prediction API behind the same
standalone ``chat(messages, *, model, ...) -> ChatResponse`` surface the other
connectors expose. That lets the pronunciation detector treat Replicate as just
another swappable provider — reusing the Replicate token the stack already holds
while keeping Genblaze's uniform chat interface (swap to Gemini with no code
change).

Reads ``REPLICATE_API_TOKEN`` from the runner environment. Structured output is
best-effort: Replicate LLMs have no universal json-schema mode, so we rely on the
detector's JSON-only system prompt and parse the returned text downstream. The
default model slug is overridable per call (the detector passes the configured
model); confirm the exact slug + its input field names against the model's
Replicate schema page before going live.
"""

from __future__ import annotations

import os
import time

import httpx

from genblaze_core.models.chat import ChatMessage, ChatResponse

_BASE = "https://api.replicate.com/v1"
_DEFAULT_TIMEOUT = 120.0


def chat(
    messages: list[ChatMessage],
    *,
    model: str,
    response_format=None,  # accepted for interface parity; Replicate has no schema mode
    temperature: float = 0.2,
    timeout: float = _DEFAULT_TIMEOUT,
) -> ChatResponse:
    token = os.getenv("REPLICATE_API_TOKEN")
    if not token:
        raise RuntimeError("REPLICATE_API_TOKEN is not set in the runner environment")

    payload = {
        "input": {
            "prompt": _join_role(messages, "user"),
            "system_prompt": _join_role(messages, "system"),
            "temperature": temperature,
        }
    }
    headers = {
        "Authorization": f"Bearer {token}",
        "Content-Type": "application/json",
        "Prefer": "wait",  # block until the (short) JSON completion is ready
    }

    with httpx.Client(timeout=timeout) as client:
        resp = client.post(f"{_BASE}/models/{model}/predictions", headers=headers, json=payload)
        resp.raise_for_status()
        prediction = _await_terminal(client, resp.json(), headers, timeout)

    status = prediction.get("status")
    if status != "succeeded":
        raise RuntimeError(f"Replicate prediction did not succeed: {prediction.get('error') or status}")

    metrics = prediction.get("metrics") or {}
    return ChatResponse(
        text=_flatten_output(prediction.get("output")),
        model=model,
        finish_reason=status,
        tokens_in=metrics.get("input_token_count"),
        tokens_out=metrics.get("output_token_count"),
        raw=prediction,
    )


def _join_role(messages: list[ChatMessage], role: str) -> str:
    parts = [m.content for m in messages if m.role == role and isinstance(m.content, str)]
    return "\n\n".join(p for p in parts if p)


def _flatten_output(output) -> str:
    """Replicate LLMs stream output as a list of token strings; join to one text."""
    if output is None:
        return ""
    if isinstance(output, str):
        return output
    if isinstance(output, list):
        return "".join(str(chunk) for chunk in output)
    return str(output)


def _await_terminal(client: httpx.Client, prediction: dict, headers: dict, timeout: float) -> dict:
    """Poll to a terminal state if ``Prefer: wait`` returned before completion."""
    terminal = {"succeeded", "failed", "canceled"}
    deadline = time.monotonic() + timeout
    while prediction.get("status") not in terminal and time.monotonic() < deadline:
        get_url = (prediction.get("urls") or {}).get("get")
        if not get_url:
            break
        time.sleep(1.0)
        r = client.get(get_url, headers=headers)
        r.raise_for_status()
        prediction = r.json()
    return prediction
