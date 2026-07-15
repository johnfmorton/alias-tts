"""Ollama chat provider tests — httpx is swapped for a MockTransport, so no
Ollama server, no model, and no network are needed.
"""

import json

import httpx
import pytest

from genblaze_core.models.chat import ChatMessage

from genblaze_runner import pronounce
from genblaze_runner.providers import ollama_chat
from genblaze_runner.providers.ollama_chat import chat, normalize_host
from genblaze_runner.pronounce import SubstitutionMap


def _messages() -> list[ChatMessage]:
    return [
        ChatMessage(role="system", content="respell things"),
        ChatMessage(role="user", content="Install DDEV"),
    ]


def _mock_transport(captured: dict, reply: dict) -> httpx.MockTransport:
    def handler(request: httpx.Request) -> httpx.Response:
        captured["url"] = str(request.url)
        captured["body"] = json.loads(request.content)
        return httpx.Response(200, json=reply)

    return httpx.MockTransport(handler)


def _patch_client(monkeypatch, transport: httpx.MockTransport):
    real_client = httpx.Client
    monkeypatch.setattr(
        ollama_chat.httpx, "Client", lambda **kw: real_client(transport=transport)
    )


def test_missing_host_raises_with_per_topology_hint(monkeypatch):
    monkeypatch.delenv("OLLAMA_HOST", raising=False)

    with pytest.raises(RuntimeError, match="host.docker.internal"):
        chat(_messages(), model="gemma4:26b")


def test_chat_posts_schema_grammar_and_parses_the_reply(monkeypatch):
    monkeypatch.setenv("OLLAMA_HOST", "http://127.0.0.1:11434")
    captured: dict = {}
    _patch_client(
        monkeypatch,
        _mock_transport(
            captured,
            {
                "model": "gemma4:26b",
                "message": {"role": "assistant", "content": '{"substitutions": []}'},
                "done_reason": "stop",
                "prompt_eval_count": 42,
                "eval_count": 7,
            },
        ),
    )

    out = chat(_messages(), model="gemma4:26b", response_format=SubstitutionMap, temperature=0.1)

    assert captured["url"] == "http://127.0.0.1:11434/api/chat"
    assert captured["body"]["stream"] is False
    assert captured["body"]["options"]["temperature"] == 0.1
    # The pydantic schema rides along as Ollama's grammar-enforced `format`.
    assert captured["body"]["format"] == SubstitutionMap.model_json_schema()
    assert [m["role"] for m in captured["body"]["messages"]] == ["system", "user"]

    assert out.text == '{"substitutions": []}'
    assert out.model == "gemma4:26b"
    assert out.finish_reason == "stop"
    assert out.tokens_in == 42
    assert out.tokens_out == 7


def test_chat_without_response_format_sends_no_format_key(monkeypatch):
    monkeypatch.setenv("OLLAMA_HOST", "http://127.0.0.1:11434")
    captured: dict = {}
    _patch_client(
        monkeypatch,
        _mock_transport(captured, {"message": {"role": "assistant", "content": "{}"}}),
    )

    chat(_messages(), model="gemma4:26b")

    assert "format" not in captured["body"]


def test_normalize_host_expands_shorthand_forms():
    assert normalize_host("http://127.0.0.1:11434") == "http://127.0.0.1:11434"
    assert normalize_host("http://host.docker.internal:11434/") == "http://host.docker.internal:11434"
    assert normalize_host("host.docker.internal") == "http://host.docker.internal:11434"
    assert normalize_host("127.0.0.1:8080") == "http://127.0.0.1:8080"


def test_call_chat_dispatches_ollama_with_the_schema(monkeypatch):
    seen: dict = {}

    def stub(messages, *, model, response_format=None, temperature=0.2):
        seen["model"] = model
        seen["response_format"] = response_format
        return "stubbed"

    monkeypatch.setattr(ollama_chat, "chat", stub)

    result = pronounce._call_chat("ollama", _messages(), model="gemma4:26b", temperature=0.2)

    assert result == "stubbed"
    assert seen["model"] == "gemma4:26b"
    assert seen["response_format"] is SubstitutionMap
