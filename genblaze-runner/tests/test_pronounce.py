"""Pronunciation detector tests — the chat call is stubbed, so no network, no
adapter install, and no API key are needed.
"""

import json

from genblaze_core.models.chat import ChatResponse

from genblaze_runner import pronounce
from genblaze_runner.pronounce import Substitution, SubstitutionMap, detect_substitutions


def _stub_response(text: str, model: str = "meta/llama-4-scout-instruct") -> ChatResponse:
    return ChatResponse(text=text, model=model, tokens_in=12, tokens_out=7)


def test_schema_round_trips():
    m = SubstitutionMap(
        substitutions=[
            Substitution(term="DDEV", phonetic="dee dev", category="initialism", confidence="high")
        ]
    )
    again = SubstitutionMap.model_validate_json(m.model_dump_json())
    assert again.substitutions[0].term == "DDEV"


def test_detect_parses_and_reports_provenance(monkeypatch):
    payload = json.dumps(
        {
            "substitutions": [
                {"term": "DDEV", "phonetic": "dee dev", "category": "initialism", "confidence": "high"}
            ]
        }
    )
    monkeypatch.setattr(pronounce, "_call_chat", lambda *a, **k: _stub_response(payload))

    out = detect_substitutions(
        text="Install DDEV", known_terms=[], provider="replicate", model=None, temperature=0.2
    )

    assert out["available"] is True
    assert out["substitutions"][0]["term"] == "DDEV"
    assert out["provenance"]["provider"] == "replicate"
    assert out["provenance"]["model"] == "meta/llama-4-scout-instruct"
    assert len(out["provenance"]["prompt_sha256"]) == 64


def test_duplicate_terms_are_collapsed_keeping_highest_confidence(monkeypatch):
    payload = json.dumps(
        {
            "substitutions": [
                {"term": "Llama", "phonetic": "lama", "category": "tech_name", "confidence": "low"},
                {"term": "DDEV", "phonetic": "dee dev", "category": "initialism", "confidence": "high"},
                {"term": "Llama", "phonetic": "lama", "category": "tech_name", "confidence": "high"},
                {"term": "LLAMA", "phonetic": "lama", "category": "tech_name", "confidence": "medium"},
            ]
        }
    )
    monkeypatch.setattr(pronounce, "_call_chat", lambda *a, **k: _stub_response(payload))

    out = detect_substitutions(
        text="Llama, Llama, LLAMA, DDEV", known_terms=[], provider="replicate", model=None, temperature=0.2
    )

    # One row per term, highest confidence wins, first-seen order preserved.
    assert [(s["term"], s["confidence"]) for s in out["substitutions"]] == [
        ("Llama", "high"),
        ("DDEV", "high"),
    ]


def test_bad_json_degrades_safely(monkeypatch):
    monkeypatch.setattr(pronounce, "_call_chat", lambda *a, **k: _stub_response("not json"))

    out = detect_substitutions(text="x", known_terms=[], provider="replicate", model=None, temperature=0.2)

    assert out["available"] is False
    assert out["substitutions"] == []
    assert "error" in out


def test_adapter_or_transport_error_degrades_safely(monkeypatch):
    def boom(*a, **k):
        raise RuntimeError("REPLICATE_API_TOKEN is not set in the runner environment")

    monkeypatch.setattr(pronounce, "_call_chat", boom)

    out = detect_substitutions(text="x", known_terms=[], provider="replicate", model=None, temperature=0.2)

    assert out["available"] is False


def test_unknown_provider_degrades_safely():
    out = detect_substitutions(text="x", known_terms=[], provider="bogus", model=None, temperature=0.2)

    assert out["available"] is False


def test_empty_text_degrades_safely():
    out = detect_substitutions(text="   ", known_terms=[], provider="replicate", model=None, temperature=0.2)

    assert out["available"] is False


def test_available_providers_lists_replicate_first_and_importable():
    status = pronounce.available_providers()

    assert list(status.keys())[0] == "replicate"
    assert {"replicate", "gemini", "openai", "anthropic", "ollama"} <= set(status)
    # Replicate, Anthropic, and Ollama are in-runner modules, so always importable.
    assert status["replicate"]["importable"] is True
    assert status["anthropic"]["importable"] is True
    assert status["ollama"]["importable"] is True
    assert set(status["replicate"]) == {"importable", "keyed"}


def test_ollama_reports_keyed_from_its_host_setting(monkeypatch):
    # Keyless provider: "keyed" tracks OLLAMA_HOST, its required connection setting.
    monkeypatch.delenv("OLLAMA_HOST", raising=False)
    assert pronounce.available_providers()["ollama"]["keyed"] is False

    monkeypatch.setenv("OLLAMA_HOST", "http://host.docker.internal:11434")
    assert pronounce.available_providers()["ollama"]["keyed"] is True


def test_default_model_resolves():
    assert pronounce.default_model("replicate") == "meta/llama-4-scout-instruct"
    assert pronounce.default_model("anthropic") == "claude-haiku-4-5"
    assert pronounce.default_model("ollama") == "gemma4:26b"
    assert pronounce.default_model("bogus") is None
