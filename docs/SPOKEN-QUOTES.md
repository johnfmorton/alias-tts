# Spoken quote marks

An opt-in, per-user setting that reads quoted passages aloud the way automated
news narration does: the voice says **"open quote"** where a quotation begins
and **"close quote"** where it ends, instead of silently skipping the marks.

Turn it on under **Settings → Speech generation → Spoken quote marks**. It is
off by default; each user opts in individually.

## Modes

| Mode | Opening mark | Closing mark |
| --- | --- | --- |
| Off (default) | left as written | left as written |
| Open and close | "open quote," | ", close quote" |
| Quote and close | "quote," | ", close quote" |
| Open only | "quote," | removed silently, nothing spoken |

Example, in *Open and close* mode:

> He said, "It's decided." Then he left.

is spoken as

> He said, open quote, It's decided, close quote. Then he left.

The spoken close is placed *inside* the sentence — before the final period or
comma — so the sentence still ends naturally and chunking is unaffected.

## What is (and is not) touched

The transform is deliberately conservative. **Only double quote marks that
confidently pair into a quotation are altered; everything else is preserved
byte for byte.**

- Double quotes only: straight `"` and curly `“` `”`. Single quotes,
  apostrophes, and guillemets are never touched.
- Curly marks are trusted as written — `“` opens, `”` closes — because
  smart-quote software emits them in matched pairs. Mixing curly and straight
  marks in one pair works fine.
- A straight `"` is read from context (whitespace and punctuation around it).
  One directly after a digit is treated as an inches mark — `5' 10"` — and
  ignored. The trade-off: a genuine straight-quoted phrase *ending in a digit*
  (`"It's 10"`) is also skipped, and skipping means the whole quotation is left
  untouched rather than half-voiced. Curly quotes handle that case fully, so
  **curly quotes are the most reliable input**.
- A mark that never finds its partner — a stray quote, an unclosed opening, an
  empty `""` — is left exactly as written. If we are not sure, nothing in that
  quotation is changed.

## Quotes that span paragraphs

News writing continues a long quotation by re-opening each paragraph with a
quote mark and closing only the final one. The transform follows that
convention: the quotation is announced **once at its true start**, the
paragraph-initial re-opening marks are consumed silently, and the close is
spoken **once at its true end** — the listener hears one quotation, not three.

A chain that breaks the convention (the next paragraph does not re-open as its
first content, or a second quote opens mid-paragraph) is treated as
unresolvable and left untouched.

## Where it runs

The transform is a text pre-processing step, applied **after** the
pronunciation dictionary and **before** chunking — so dictionary respellings
can never rewrite the inserted words, and the words land in the chunk text that
Studio shows, receipts snapshot, and the ASR quality check scores (the words
are genuinely spoken, so transcripts match).

It applies to:

- **Studio projects** — creating a project from text and *Start over* both use
  the requesting user's setting. `source_text` always keeps the writer's
  original marks; only the chunked/normalized text carries the spoken words.
- **The Genblaze demo** — the run reports a `quotes` pipeline step after
  `pronounce` with a count of quotations voiced.

It never applies to direct **/v1 API calls** (including the Bespoken plugin
and `/v1/projects`), even when the key owner has opted in — the mode is passed
explicitly by the Studio controllers rather than read from per-user config, so
the API path cannot inherit it by accident.

One asymmetry to know about: on a SuperAdmin support *Start over* of another
user's project, the quote mode follows the **requester** (like chunking mode)
while the pronunciation dictionary follows the **owner**.

## Implementation

`App\Services\SpokenQuotes` (pure, no IO) does classification → pairing →
replacement in byte offsets; `tests/Unit/SpokenQuotesTest.php` is the
behavioral spec, including the guarantee that output is a fixed point of
`TextNormalizer::normalize()` (the Genblaze path normalizes after the
transform). Config key: `tts.spoken_quotes` (`TTS_SPOKEN_QUOTES`), registered
as a per-user enum in `config/settings.php`.
