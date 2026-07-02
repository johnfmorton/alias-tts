# Bespoken — Pronunciation Pre-Processor Spec

A pre-processing stage that catches terms Chatterbox is likely to mispronounce
(e.g. `DDEV`), respells them phonetically (`dee dev`), and applies the change
*before* the text reaches the TTS endpoint.

## Design principle

The LLM **does not rewrite your text**. It returns a *substitution map* only —
a small JSON list of `{ term, phonetic }` pairs. Your own code performs the
find-and-replace. This keeps your prose untouched, keeps output tokens tiny, and
gives you a reviewable, auditable list of every change made.

Pronunciations are persisted in a **dictionary** so a term like `DDEV` is decided
once, not re-inferred on every generation (which is the root cause of the same
word being voiced differently within one audio file).

## Pipeline order of operations

```
input text
   │
   ▼
1. Apply known dictionary entries   ← deterministic, no LLM, no cost
   │   (longest-match first, word-boundary aware)
   ▼
2. Run LLM detection pass           ← only to surface NEW unknown terms
   │   (pass known terms so they're skipped)
   ▼
3. Surface suggestions for approval  ← user accepts → write to dictionary
   │
   ▼
4. Apply approved substitutions
   │
   ▼
phonetic text → Chatterbox
```

Once a term is approved into the dictionary, step 2 never pays for it again.

---

## 1. Detection system prompt

Send this as the system / instruction message. Keep it stable so it can be
prompt-cached (cache hits bill at ~10% of input rate on Claude; Gemini also
discounts cached tokens). The canonical copy lives in code as
`DETECTION_SYSTEM_PROMPT` in `genblaze-runner/genblaze_runner/pronounce.py` —
this block mirrors it:

```text
You are a pronunciation pre-processor for a text-to-speech (TTS) pipeline.

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
If nothing needs changing, return {"substitutions": []}.
```

> Enable the provider's JSON / structured-output mode if available, and set
> **temperature 0–0.3** so the same input yields the same map.

---

## 2. Input (user message) format

Pass the full original text (the model needs surrounding context to judge) plus
the terms you already have, so it won't waste effort re-suggesting them.

```text
known_terms: ["DDEV", "nginx", "Laravel"]

text:
"""
<the original text that will be spoken>
"""
```

---

## 3. Output JSON schema

```json
{
  "substitutions": [
    {
      "term": "DDEV",
      "phonetic": "dee dev",
      "category": "initialism",
      "confidence": "high",
      "note": "Letters are spoken individually, not as a word"
    }
  ]
}
```

| Field        | Type   | Notes                                                                 |
|--------------|--------|-----------------------------------------------------------------------|
| `term`       | string | Verbatim copy from the input (exact case) so a literal match works.    |
| `phonetic`   | string | ASCII spoken respelling.                                              |
| `category`   | enum   | `initialism` \| `acronym` \| `tech_name` \| `proper_noun` \| `symbol_version` \| `jargon` |
| `confidence` | enum   | `high` \| `medium` \| `low` — use to auto-apply high, queue the rest.  |
| `note`       | string | Optional short reason; useful in the approval UI.                     |

---

## 4. Worked example

**Input text**

```
To get started with DDEV, you run a couple of commands. It uses nginx and
PostgreSQL under the hood and works great alongside your existing Docker setup.
```

**Model output**

```json
{
  "substitutions": [
    { "term": "DDEV", "phonetic": "dee dev", "category": "initialism", "confidence": "high", "note": "Spelled-out letters" },
    { "term": "nginx", "phonetic": "engine ex", "category": "tech_name", "confidence": "high" },
    { "term": "PostgreSQL", "phonetic": "post gres Q L", "category": "tech_name", "confidence": "medium", "note": "Commonly said post-gres-Q-L" }
  ]
}
```

`Docker` is left alone — it's pronounced fine, so it isn't flagged.

---

## 5. Applying substitutions (find-and-replace rules)

Order and boundary handling matter:

1. **Longest term first.** Sort substitutions by `term` length descending so
   `PostgreSQL` is replaced before any bare `SQL` could match inside it.
2. **Word boundaries.** Match on boundaries so `SQL` inside `NoSQLDB` is not
   touched.
3. **Replace all occurrences** of each term.
4. **Case handling.** `term` is verbatim, so a case-sensitive match is safest by
   default. If you prefer one dictionary entry to cover `DDEV`/`ddev`/`Ddev`,
   store a `case_insensitive` flag on the entry and match accordingly.
5. **Dictionary first, then LLM suggestions**, so curated entries always win.

**PHP notes (Craft plugin):**

- `strtr($text, $map)` does simultaneous, longest-key-first, non-overlapping
  replacement — clean, but has **no word-boundary awareness**.
- For boundary safety, build a single alternation and use `preg_replace`:

  ```php
  // $map: ['DDEV' => 'dee dev', 'PostgreSQL' => 'post gres Q L', ...]
  uksort($map, fn($a, $b) => strlen($b) <=> strlen($a)); // longest first
  $pattern = '/\b(' . implode('|', array_map(
      fn($t) => preg_quote($t, '/'), array_keys($map)
  )) . ')\b/';
  $result = preg_replace_callback(
      $pattern,
      fn($m) => $map[$m[1]] ?? $m[0],
      $text
  );
  ```

  (`\b` is ASCII-word-boundary; if you flag terms with leading symbols like
  `.env`, those need a custom boundary instead of `\b`.)

- The shipped service-side implementation uses `(?<!\w)…(?!\w)` lookaround
  boundaries instead of `\b`, which handles symbol-edged terms like `.env`
  without a special case — see `App\Services\Pronunciation\PronunciationSubstituter`
  before copying the pattern above.

---

## 6. Persistent dictionary schema

Store per-user (and optionally a shared global seed list).

```json
{
  "version": 1,
  "entries": [
    {
      "term": "DDEV",
      "phonetic": "dee dev",
      "match": "case_insensitive",
      "category": "initialism",
      "source": "user",
      "approved": true,
      "added": "2026-06-25"
    }
  ]
}
```

- `source`: `user` (typed in / corrected) vs `llm` (suggested, then approved).
- `approved`: gate so unreviewed LLM suggestions aren't auto-applied.
- Expose the dictionary as an editable list in the UI — curating their own
  pronunciation lexicon (brand names, surnames, jargon) is a feature users will
  want, not just plumbing.

---

## 7. Model & settings

Genblaze makes switching LLM providers easy, and this feature is a natural
demonstration of that strength. An admin picks the provider in Settings; if the
provider is pinned via `.env`, Settings shows it as a display-only value.

Provider guidance:

- **Tier:** budget / Flash class. This is a detection + transform task, not
  reasoning — a frontier model is overkill.
- **Candidates:** Claude Haiku 4.5 (strong at not over-editing + clean JSON),
  `gemini-2.5-flash` (cheap, good free tier), `gpt-5-nano`.
  Local Ollama (Gemma) is viable for zero per-call cost once the prompt is
  tightly constrained — validate JSON output carefully.
- **Temperature:** 0–0.3 for stable, repeatable maps.
- **Caching:** keep the system prompt fixed so it caches; per-call you only pay
  for the (small) text body and the (tiny) JSON output.
- **Validation:** parse the JSON defensively; on parse failure, skip the pass
  and send the original text rather than blocking generation.

---

## Resolved: ASCII respelling only (no phoneme / IPA / SSML)

The pinned open-source Chatterbox model on Replicate (`resemble-ai/chatterbox`)
accepts **plain `prompt` text only** — its provider input is `prompt` +
`audio_prompt` + numeric knobs (`cfg_weight`, `exaggeration`, `seed`); there is no
SSML / `<phoneme>` / IPA field. (The SSML/`apply_custom_pronunciations` features
seen online belong to Resemble's *commercial* API, not this model.) `TextNormalizer`
also strips `<…>` before TTS. So emitting `dˈiːdɛv` would be voiced as literal
glyphs — **ASCII respelling is the only viable representation here.** Caveat:
Chatterbox treats a lone capital as emphasis, so respellings avoid gratuitous
capitals (prefer `engine ex` over `engine X`). The `phonetic` column could later
hold a per-engine form if an SSML/IPA-capable backend is added.

---

## Implementation status (built 2026-06-25, behind `TTS_PRONUNCIATION_ENABLED`, default off)

Built on `feat/genblaze-b2` (since merged to `main`); full PHP suite green
(318 tests at the time) + runner pytest (8).

- **Detection = a Genblaze CHAT step in the runner.** Default provider is
  **Replicate's LLMs wrapped as a custom Genblaze chat provider**
  (`genblaze-runner/genblaze_runner/providers/replicate_chat.py`) — reusing `REPLICATE_API_TOKEN`,
  behind the same `chat()` interface as the off-the-shelf adapters, so swapping to
  **Gemini / OpenAI** (off-the-shelf `genblaze-google`/`genblaze-openai`) or
  **Anthropic** (another custom provider, tool-use) is a Settings change with no
  code change. That provider-agnostic swap is the genblaze demonstration. (Chat is
  NOT manifest-tracked in genblaze — provenance is recorded as a lightweight
  provider/model/tokens/prompt-hash dict; the B2 manifest story stays with the TTS
  pipeline.) Runner endpoint `POST /pronounce`; degrade-safe everywhere.
- **Dictionary = service-owned, per-user** (`pronunciation_entries`); approved via
  the new review screen; read API `GET /v1/pronunciations` for the Craft plugin to
  sync later. The plugin keeps its own find-and-replace (backend-agnostic).
- **Flow:** the new-project create form posts to a pre-chunking **review screen**
  (`StudioProjectController::review`/`applyAndStore`); approve → persist to the
  dictionary + apply to the project text → chunk. Disabled / no-suggestions / LLM
  down all fall straight through to chunking.
- **Live-verified 2026-06-25:** runner restarted with the new code (`/pronounce`
  responds; `/health` shows `replicate: importable+keyed`), and a real end-to-end
  call returns parsed substitutions. The runner needs `REPLICATE_API_TOKEN` in
  ITS env now (the old `/run` path delegated Replicate to PHP, so it didn't).
  Default Replicate model is `meta/llama-4-scout-instruct` (verified strong at
  respelling + clean JSON; override with `TTS_PRONUNCIATION_MODEL`). For top
  quality add an `ANTHROPIC_API_KEY` and select the `anthropic` provider —
  Claude Haiku 4.5 (`claude-haiku-4-5`) uses tool-use for guaranteed-valid JSON.
  The detection schema is lenient (accepts `respelling` alias for `phonetic`,
  optional category/confidence) since Replicate LLMs have no enforced JSON mode.
- **Deferred:** off-the-shelf Gemini/OpenAI adapters (`pip install -e '.[pronounce]'`).
