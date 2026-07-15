"""Pronunciation detection — the Genblaze CHAT step of the pre-processor.

Given a body of text, asks an LLM to return ONLY a substitution map (term ->
ASCII respelling) for the words a TTS engine is likely to mispronounce. The LLM
never rewrites the prose; the find-and-replace is performed downstream (the PHP
PronunciationSubstituter).

Genblaze's role here is its provider-agnostic ``chat()`` surface: the same call
and the same ``ChatResponse`` shape work across Gemini / OpenAI / NVIDIA, so an
admin swaps the detection provider with no response-handling change. (Unlike the
TTS pipeline, chat is NOT manifest-tracked — see ``genblaze_core.models.chat`` —
so we record a lightweight provenance dict ourselves: provider, model, tokens,
prompt hash.)

The connector packages (``genblaze_openai``, ``genblaze_google``) are optional
and may be absent; every entry point degrades to ``available: False`` rather than
raising, so a missing adapter or key never blocks project creation upstream.
"""

from __future__ import annotations

import hashlib
import importlib
import importlib.util
import json
import os
from pydantic import AliasChoices, BaseModel, ConfigDict, Field, ValidationError

from genblaze_core.models.chat import ChatMessage

# --- structured-output schema (spec §3) -----------------------------------


class Substitution(BaseModel):
    # Lenient on purpose: providers without ENFORCED structured output (e.g. the
    # default Replicate LLMs, which only see the prompt) vary field names and omit
    # optionals — so accept common aliases for `phonetic` and treat category /
    # confidence as optional free strings rather than strict enums.
    model_config = ConfigDict(populate_by_name=True, extra="ignore")

    term: str
    phonetic: str = Field(validation_alias=AliasChoices("phonetic", "respelling", "pronunciation"))
    category: str | None = None
    confidence: str | None = None
    note: str | None = None


class SubstitutionMap(BaseModel):
    substitutions: list[Substitution] = []


# --- provider registry ----------------------------------------------------

# provider -> (connector module, env var holding the key, default model)
# (For keyless providers the env-var slot holds their required connection
# setting instead — see the ollama entry.)
_PROVIDERS: dict[str, tuple[str, str, str]] = {
    # Default: Replicate's LLMs wrapped as a Genblaze chat provider (custom,
    # in-runner — there is no published genblaze-replicate CHAT adapter). Reuses
    # the Replicate token the stack already holds, behind the same chat()
    # interface as the off-the-shelf adapters, so swapping to Gemini is a config
    # change with no code change.
    "replicate": (
        "genblaze_runner.providers.replicate_chat",
        "REPLICATE_API_TOKEN",
        # Llama 4 Scout — verified far better at respelling + clean JSON than
        # llama-3-8b, still cheap/fast. Override with TTS_PRONUNCIATION_MODEL.
        "meta/llama-4-scout-instruct",
    ),
    # Off-the-shelf genblaze chat adapters — the swappable second/third options.
    "gemini": ("genblaze_google", "GEMINI_API_KEY", "gemini-2.5-flash"),
    "openai": ("genblaze_openai", "OPENAI_API_KEY", "gpt-5-nano"),
    # Anthropic has no published genblaze chat adapter either, so it's a second
    # custom in-runner provider (Messages API + tool-use structured output).
    "anthropic": ("genblaze_runner.providers.anthropic_chat", "ANTHROPIC_API_KEY", "claude-haiku-4-5"),
    # Fully-local option: a model served by Ollama on the same machine — no key,
    # no per-call cost, and no text leaves the box (pairs with TTS_PROVIDER=local
    # for an offline dev stack). Keyless, so the env-var slot holds the required
    # server address instead: where Ollama listens FROM THE RUNNER's vantage
    # (see the module docstring for per-topology values).
    "ollama": ("genblaze_runner.providers.ollama_chat", "OLLAMA_HOST", "gemma4:26b"),
}


def default_model(provider: str) -> str | None:
    spec = _PROVIDERS.get(provider)
    return spec[2] if spec else None


def available_providers() -> dict[str, dict[str, bool]]:
    """Importable + keyed status per provider, for the /health surface."""
    return {
        name: {
            "importable": importlib.util.find_spec(module) is not None,
            "keyed": bool(os.getenv(env_var)),
        }
        for name, (module, env_var, _model) in _PROVIDERS.items()
    }


# --- detection system prompt (spec §1) ------------------------------------
# A byte-stable module constant (never interpolated) so providers that support
# prompt caching bill this system block at the cached rate.
DETECTION_SYSTEM_PROMPT = """You are a pronunciation pre-processor for a text-to-speech (TTS) pipeline.

Your job: scan the user's text and identify ONLY the terms a TTS engine is
likely to MISPRONOUNCE, and propose a plain-spelling respelling for each that
will be read aloud correctly.

You DO NOT rewrite, summarize, reword, or reformat the text. You return only a
list of substitutions.

## What to flag
Flag a term only if a typical TTS voice would likely get it wrong:
- Initialisms spelled out letter-by-letter (DDEV, SQL, AWS, CI, JWT, IPv6)
- Acronyms said as a word but likely mispronounced (YAML, CRON, nginx)
- Technical / product / brand names with non-obvious pronunciation
  (kubectl, PostgreSQL, Caddy, pnpm, Laravel, Craft)
- Proper nouns, place names, or surnames with unusual pronunciation
- Symbols, version strings, or mixed alphanumerics read ambiguously
  (v2.5, C#, .env, x86)
- Domain jargon you judge likely to be voiced incorrectly

## What NOT to flag
- Ordinary English words, even long or uncommon ones
- Terms already pronounced correctly by default
- Anything in the provided `known_terms` list (already handled)
- Do not flag a term merely because it is capitalized or technical — flag it
  only if mispronunciation is genuinely likely

## Respelling rules
- Use plain ASCII that reads naturally when spoken aloud. No IPA.
- Letter-by-letter initialisms: separate lowercase letters
  -> "DDEV" => "dee dev",  "SQL" => "ess cue ell"
- Word-style terms: respell with spaces/hyphens to guide syllables
  -> "nginx" => "engine ex",  "kubectl" => "cube control",  "Caddy" => "kaddy"
- Avoid gratuitous capitals: Chatterbox reads a lone capital as emphasis, so
  prefer "engine ex" over "engine X".
- Change only what is needed for correct pronunciation; keep it minimal.
- Copy the `term` field VERBATIM from the input (exact casing and characters)
  so a literal match succeeds downstream.

## Output
Return ONLY valid JSON of EXACTLY this shape — no markdown, no code fences, no
commentary. Use these exact field names:
{"substitutions": [{"term": "DDEV", "phonetic": "dee dev", "category": "initialism", "confidence": "high", "note": "spelled-out letters"}]}
- "phonetic" is the spoken respelling (use the key "phonetic", not "respelling").
- "category" is one of: initialism, acronym, tech_name, proper_noun, symbol_version, jargon.
- "confidence" is one of: high, medium, low.
- "note" is optional.
If nothing needs changing, return {"substitutions": []}."""


def build_user_message(text: str, known_terms: list[str]) -> str:
    """The user turn (spec §2): the full text plus already-decided terms."""
    return f'known_terms: {json.dumps(known_terms)}\n\ntext:\n"""\n{text}\n"""'


def _call_chat(provider: str, messages: list[ChatMessage], *, model: str, temperature: float):
    """Invoke a connector's standalone ``chat()`` and return its ChatResponse.

    Lazily imports the connector so the runner keeps no hard dependency on any
    LLM adapter. Raises ImportError when the adapter isn't installed, or whatever
    the connector raises on a transport/credential error — ``detect_substitutions``
    turns all of these into a degrade-safe result. This is the seam tests stub.
    """
    spec = _PROVIDERS.get(provider)
    if spec is None:
        raise ValueError(f"Unknown detection provider {provider!r}")

    chat = importlib.import_module(spec[0]).chat

    # Off-the-shelf genblaze connectors take `model` as the first positional arg
    # and `messages` second; our custom in-runner providers (replicate, anthropic)
    # take `messages` first with `model` as a keyword.
    if provider == "openai":
        from genblaze_core.models.chat import coerce_response_format

        return chat(model, messages, response_format=coerce_response_format(SubstitutionMap), temperature=temperature)
    if provider == "gemini":
        # The google connector exposes no response_format parameter, so rely on
        # the JSON-only system prompt (like Replicate) and parse the returned text.
        return chat(model, messages, temperature=temperature)

    # Custom providers: anthropic forces a tool from the schema, ollama constrains
    # sampling with it (native `format`); replicate ignores it.
    response_format = SubstitutionMap if provider in ("anthropic", "ollama") else None
    return chat(messages, model=model, response_format=response_format, temperature=temperature)


def detect_substitutions(
    *,
    text: str,
    known_terms: list[str],
    provider: str,
    model: str | None,
    temperature: float,
    config=None,
) -> dict:
    """Return a degrade-safe detection result.

    Success: ``{available: True, substitutions: [...], provenance: {...}}``.
    Any failure (adapter missing, no key, transport error, bad JSON):
    ``{available: False, substitutions: [], error: "..."}`` — never raises, so the
    upstream project-creation flow continues straight to chunking.
    """
    text = (text or "").strip()
    if not text:
        return {"available": False, "substitutions": [], "error": "empty text"}

    resolved_model = model or default_model(provider)
    if resolved_model is None:
        return {"available": False, "substitutions": [], "error": f"unknown provider {provider!r}"}

    messages = [
        ChatMessage(role="system", content=DETECTION_SYSTEM_PROMPT),
        ChatMessage(role="user", content=build_user_message(text, known_terms)),
    ]

    try:
        response = _call_chat(provider, messages, model=resolved_model, temperature=temperature)
        parsed = SubstitutionMap.model_validate_json(response.text)
    except (ValidationError, json.JSONDecodeError) as exc:
        return {"available": False, "substitutions": [], "error": f"invalid LLM JSON: {exc}"}
    except Exception as exc:  # noqa: BLE001 — adapter/transport/credential: degrade, never block
        return {"available": False, "substitutions": [], "error": str(exc)}

    return {
        "available": True,
        "substitutions": [s.model_dump() for s in parsed.substitutions],
        "provenance": {
            "provider": provider,
            "model": getattr(response, "model", resolved_model),
            "tokens_in": getattr(response, "tokens_in", None),
            "tokens_out": getattr(response, "tokens_out", None),
            "cost_usd": getattr(response, "cost_usd", None),
            "prompt_sha256": _prompt_hash(messages),
        },
    }


def _prompt_hash(messages: list[ChatMessage]) -> str:
    payload = json.dumps(
        [{"role": m.role, "content": m.content} for m in messages],
        ensure_ascii=False,
        sort_keys=True,
    )
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()
