"""Custom in-runner Genblaze chat providers for engines without a published
off-the-shelf adapter (Replicate's LLMs, Anthropic, a local Ollama server).
Each exposes the same
standalone ``chat(messages, *, model, ...) -> ChatResponse`` surface as the
``genblaze_openai`` / ``genblaze_google`` connectors, so they slot into the
pronunciation detector's provider registry interchangeably.
"""
