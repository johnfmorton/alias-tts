# Audio cleanup — fighting Chatterbox's edge artifacts

Chatterbox (via Replicate) reliably produces clean speech but adds noise at the
**edges** of a generation. Left in place these land at every seam when chunks are
stitched, so each generated chunk is cleaned before concatenation. All of this
lives in `App\Services\Audio\AudioConverter::trimChunk()` and runs on every
delivery path (the `/v1` speech API, Studio previews/stitch, and project final
builds — everything flows through `concatenate()`).

## The two artifact shapes we handle

1. **Short leading/trailing swoosh (the common case).** A brief low-level
   hiss/swoosh at the head and tail of a clip.
   - **Head:** trimmed unboundedly — it is just dead air before speech.
   - **Tail:** trimmed only within the **last `chunk_trim_tail_window_ms`** (300 ms)
     with `silenceremove`. The bound is deliberate: `silenceremove` is aggressive
     and an unbounded trim would swallow a *soft final word* (e.g. a quiet "Why?")
     that sits below the silence threshold. The swoosh comes *after* the word, so a
     short end-window strips it while the word is provably untouched. No single
     amplitude threshold separates a quiet word from a similar-level swoosh — so we
     bound the window instead of tuning the threshold.

2. **Long low-frequency tail drone (the release-blocking case).** Sometimes
   Chatterbox appends a **multi-second** tonal drone after speech ends. It is
   roughly speech-level in loudness (so `silenceremove` never sees it as silence)
   and far longer than the 300 ms window — so case-1 cleanup cannot touch it. This
   is handled by `detectLongTailArtifact()`.

## How the long-tail detector works

The drone is **tonal and low-frequency** (~100 Hz fundamental), so its
**zero-crossing rate (ZCR)** is far below speech even though its amplitude is
similar. The detector scans the chunk in `chunk_tail_window_ms` windows and marks
a window as *speech* only when it is **both**:

- loud enough — RMS above `chunk_tail_rms_floor_db` (rejects the quiet gap that
  often precedes the drone), **and**
- broadband enough — zero-crossings/second above `chunk_tail_zcr_min_hz` (rejects
  the drone itself; ZCR is measured in crossings/second so the threshold is
  independent of the working sample rate).

The end of the **last** speech window is the speech end — but first any isolated
short trailing speech run is **peeled** off. Chatterbox sometimes follows the
quiet decay tail with a brief **loud, mid-band "re-swell"** that clears both gates
(it is neither quiet nor tonal); sitting at the very end, it would reset the
speech end to ~EOF, collapsing the trailing non-speech run to ~0 so the whole tail
survives. A run that a `chunk_tail_min_artifact_ms` **quiet** gap isolates from
*earlier* audio is treated as that artifact and dropped when it is **either** short
(shorter than `chunk_tail_blip_max_ms` — a brief blip) **or tonal** (a longer
drone/swell whose per-window ZCR coefficient of variation is ≤ `chunk_tail_tonal_cv_max`;
real speech alternates voiced/unvoiced so its ZCR swings widely, whereas a sustained
tone barely varies). Leading silence before the first word does not count — something
must precede the gap. The gap is measured as genuinely **quiet** windows (RMS
at/below the floor), **not** merely non-speech ones: a quiet final word like "will
be" ends in low-ZCR voiced windows that fail the speech (high-ZCR) gate while still
being *loud*, and counting those as a gap would wrongly peel the word. The decay
before a true re-swell artifact, by contrast, sits below the floor. The peel repeats
toward the body.

A cut is only made when the (post-peel) trailing non-speech run is at least
`chunk_tail_min_artifact_ms` — so ordinary clips and soft final words (which have
no long tail) are left to the case-1 trim above and are never over-trimmed. When a
long tail is found the chunk is hard-cut at the speech end + `chunk_tail_guard_ms`,
then head-trimmed and edge-faded.

## Tuning knobs (`config/tts.php`, all env-overridable)

| Key | Env | Default | Role |
| --- | --- | --- | --- |
| `chunk_trim_threshold` | `TTS_CHUNK_TRIM_THRESHOLD` | `-40dB` | silence threshold for the edge trim |
| `chunk_fade_ms` | `TTS_CHUNK_FADE_MS` | `8` | click-free edge fade |
| `chunk_trim_tail_window_ms` | `TTS_CHUNK_TRIM_TAIL_WINDOW_MS` | `300` | bound for the short-swoosh tail trim |
| `chunk_tail_artifact_enabled` | `TTS_CHUNK_TAIL_ARTIFACT` | `true` | enable the long-tail detector |
| `chunk_tail_window_ms` | `TTS_CHUNK_TAIL_WINDOW_MS` | `50` | detector analysis window |
| `chunk_tail_rms_floor_db` | `TTS_CHUNK_TAIL_RMS_FLOOR_DB` | `-40` | below this = not speech (rejects gaps) |
| `chunk_tail_zcr_min_hz` | `TTS_CHUNK_TAIL_ZCR_MIN_HZ` | `700` | crossings/sec; below = tonal/low-freq drone |
| `chunk_tail_min_artifact_ms` | `TTS_CHUNK_TAIL_MIN_ARTIFACT_MS` | `400` | only hard-cut trailing non-speech ≥ this |
| `chunk_tail_guard_ms` | `TTS_CHUNK_TAIL_GUARD_MS` | `60` | keep this much after the last speech |
| `chunk_tail_blip_max_ms` | `TTS_CHUNK_TAIL_BLIP_MAX_MS` | `400` | drop a trailing re-swell blip ≤ this isolated by a long gap (`0` disables the whole peel) |
| `chunk_tail_tonal_cv_max` | `TTS_CHUNK_TAIL_TONAL_CV_MAX` | `0.35` | also drop a LONGER isolated run whose ZCR coeff-of-variation ≤ this (a sustained tone, not speech); `0` disables the tonal path |

**Tuning hints.** If a drone still survives, lower `chunk_tail_min_artifact_ms` or
raise `chunk_tail_zcr_min_hz`. If a real, sustained trailing vowel gets clipped,
*lower* `chunk_tail_zcr_min_hz` or *raise* `chunk_tail_min_artifact_ms`. The
`min_artifact_ms` length gate is the main safety net — the detector only fires on
long tails, so it cannot regress normal clips. If a re-swell blip still survives,
*raise* `chunk_tail_blip_max_ms`; if a genuine short final word that follows a long
in-chunk pause gets clipped, *lower* it (or set `0` to disable the peel).

## Scope / known limits

The detector targets **low-ZCR (tonal/rumble)** tails, the **decay-then-blip**
re-swell, and a **longer tonal swell** after the speech ends (all isolated by a
quiet gap, all with a near-constant ZCR that the gates alone score as speech). A
*broadband* high-ZCR hiss with speech-like ZCR variability filling the tail would
still pass; catching that would need a spectral check (not built). The peel also
has one residual blind spot by construction: a
**genuine** short final word that follows a `>= min_artifact_ms` stretch of true
**silence** *within the same chunk* would be clipped — but chunks are split on
sentence/paragraph boundaries with seam gaps inserted between them, so a single
chunk rarely contains that much internal silence before a final word. (A loud
final word preceded by ordinary low-ZCR voiced speech is safe — only sub-floor
silence counts as the gap.) Generation-side mitigations (more
conservative Chatterbox params, multi-candidate selection) remain possible future
work but are not needed — post-processing removes the artifact reliably.

## Tests

`tests/Feature/AudioConverterTest.php`:
- `test_long_low_frequency_tail_artifact_is_removed` — the real fixture
  (`tests/Fixtures/tail-artifact.wav`, 17.8 s → ~14.8 s).
- `test_long_tail_detector_trims_synthetic_drone` — broadband noise + 90 Hz tone.
- `test_clean_clip_is_not_over_trimmed_by_detector` — clean clip survives.
- `test_quiet_trailing_word_survives_trim` — the soft-word regression guard.
- `test_detector_removes_decay_then_reswell_blip_tail` — speech | quiet decay |
  loud re-swell blip; the v0.9.0 slip-through, now cut.
- `test_detector_keeps_short_final_word_after_brief_pause` — peel must not clip a
  genuine short final word after a brief (sub-`min_artifact`) pause.
- `test_loud_low_zcr_speech_is_not_mistaken_for_a_gap_before_the_final_word` — a
  loud low-ZCR voiced region (e.g. the "will be" ending) is not a silent gap, so
  the final word survives.
- `test_detector_removes_a_long_tonal_swell_tail` — a >1s steady-ZCR tone behind a
  quiet gap (too long for the blip path) is peeled by the tonal path.
