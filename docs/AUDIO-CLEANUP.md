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

The end of the **last** speech window is the speech end — with two refinements
before the cut is measured.

**Voiced-coda fold.** The speech gate needs loud **and** high-ZCR, so a loud
word-final **voiced** coda (a nasal /n/, /m/, /ŋ/ — voiced but low-frequency,
hence low-ZCR) fails it and would read as the start of a trailing artifact,
hard-cutting mid-word ("…built in" losing its "n"). So `voicedCodaEnd()` first
extends the speech end forward over up to `chunk_tail_voiced_coda_max_ms` of
contiguous windows that are loud, **voiced** (clear fundamental), and at/below
the speech-body level — a louder re-swell swoosh is not folded, and a
multi-second voiced drone is far too long to be mistaken for a coda.

**Peel.** Any isolated short trailing speech run is then **peeled** off:

- **Why:** Chatterbox sometimes follows the quiet decay tail with a brief
  **loud, mid-band "re-swell"** that clears both gates (it is neither quiet nor
  tonal); sitting at the very end, it would reset the speech end to ~EOF,
  collapsing the trailing non-speech run to ~0 so the whole tail survives.
- **What is peeled:** a run that a `chunk_tail_min_artifact_ms` **quiet** gap
  isolates from *earlier* audio is dropped when it is **either** short (shorter
  than `chunk_tail_blip_max_ms` — a brief blip) **or tonal** (a longer
  drone/swell whose per-window ZCR coefficient of variation is
  ≤ `chunk_tail_tonal_cv_max`; real speech alternates voiced/unvoiced so its ZCR
  swings widely, whereas a sustained tone barely varies).
- **Leading silence never counts:** something must precede the gap, so the dead
  air before the first word cannot trigger a peel.
- **The gap must be genuinely *quiet*** — windows with RMS at/below the floor,
  **not** merely non-speech ones. A quiet final word like "will be" ends in
  low-ZCR voiced windows that fail the speech (high-ZCR) gate while still being
  *loud*; counting those as a gap would wrongly peel the word. The decay before
  a true re-swell artifact, by contrast, sits below the floor.

The peel repeats toward the body.

A cut is only made when the (post-peel) trailing non-speech run is at least
`chunk_tail_min_artifact_ms` — so ordinary clips and soft final words (which have
no long tail) are left to the case-1 trim above and are never over-trimmed. When a
long tail is found the chunk is hard-cut at the speech end + `chunk_tail_guard_ms`,
then head-trimmed and edge-faded.

### Voicing refinement (broadband hiss the ZCR gate keeps)

ZCR has a blind spot: a trailing run that is **loud and high-ZCR but aperiodic**
— broadband hiss/noise with no fundamental — clears the speech gate and is *not*
a low-ZCR tonal artifact either, so neither path above removes it. A **pitch-voicing**
check closes it. Real speech vowels have a clear fundamental (75–600 Hz); most tail
noise does not. Using a peak normalized **autocorrelation** in that lag range (pure
PHP — no Praat/Python dependency), the detector finds the last loud **voiced**
window; if a trailing unvoiced run of ≥ `chunk_tail_unvoiced_min_ms` follows it, the
chunk is cut back to that window plus `chunk_tail_fricative_allowance_ms` (keeping a
short word-final fricative in place) plus `chunk_tail_guard_ms`.

**The over-speech gate is what separates a real coda from a tail.** Duration
alone cannot: a genuine word-final unvoiced run (a sustained /s/, /f/, /ʃ/, or a
devoiced/creaky ending) is loud, unvoiced, and routinely runs 600–900 ms — well
past the fricative allowance — exactly like an appended hiss. The **loudness
relationship** does separate them: a real coda is energy tapering off the end of
a word, so it sits at or below the speech body's level, whereas a real
hiss/swoosh tail is *louder* (the corpus swoosh measured ~+9 dB over speech).
The voicing cut therefore only fires when the trailing run's peak window is at
least `chunk_tail_voicing_over_speech_db` (6 dB) **louder** than the speech
body's RMS — the same over-speech discriminator the ASR `TAILNOISE` signal uses.
Without this gate the path clipped the last word off otherwise-perfect clips.

This **combines** with the ZCR/tonal cut above (the detector takes the *earlier* of
the two), so it can only ever trim *more*, and only when sure — a final word is
never clipped: a quiet *voiced* ending reads as voiced, and a loud *unvoiced*
coda is protected by the over-speech gate. The inverse blind spot is deliberate: a low-frequency drone is
periodic, so voicing reads it as **voiced** and leaves it to the ZCR/tonal path,
while that path leaves the aperiodic hiss to voicing. (Voiced *singing* tails are
periodic too, so neither path catches them — that's what the ASR round-trip in
`docs/ASR-SETUP.md` is for.)

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
| `chunk_tail_voiced_coda_max_ms` | `TTS_CHUNK_TAIL_VOICED_CODA_MAX_MS` | `300` | fold a short loud + voiced + at/below-speech-level run (a word-final nasal coda) back into speech before cutting; `0` disables the fold |
| `chunk_tail_voicing_enabled` | `TTS_CHUNK_TAIL_VOICING` | `true` | enable the voicing refinement (catches loud, aperiodic hiss tails) |
| `chunk_tail_voicing_acf_min` | `TTS_CHUNK_TAIL_VOICING_ACF_MIN` | `0.5` | min peak normalized autocorrelation to call a window voiced |
| `chunk_tail_voicing_f0_min_hz` | `TTS_CHUNK_TAIL_VOICING_F0_MIN_HZ` | `75` | low end of the pitch search |
| `chunk_tail_voicing_f0_max_hz` | `TTS_CHUNK_TAIL_VOICING_F0_MAX_HZ` | `600` | high end of the pitch search |
| `chunk_tail_unvoiced_min_ms` | `TTS_CHUNK_TAIL_UNVOICED_MIN_MS` | `400` | only cut when the trailing unvoiced run is ≥ this (`0` disables the voicing path) |
| `chunk_tail_fricative_allowance_ms` | `TTS_CHUNK_TAIL_FRICATIVE_ALLOWANCE_MS` | `250` | keep this much unvoiced audio after the last voiced window (protects word-final fricatives) |
| `chunk_tail_voicing_over_speech_db` | `TTS_CHUNK_TAIL_VOICING_OVER_SPEECH_DB` | `6.0` | the voicing cut fires only when the trailing run peaks ≥ this much LOUDER than the speech body's RMS (protects long word-final unvoiced codas) |

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
**loud** (≥ speech body + 6 dB) *broadband* hiss is caught by the voicing cut;
the residual blind spot is a broadband hiss at or **below** speech level with
speech-like ZCR variability — that still passes, and deliberately so: the
over-speech gate spares it because it is acoustically indistinguishable from a
genuine word-final unvoiced coda. The peel also
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
  (`tests/Fixtures/tail-artifact.wav`, 17.8 s → ~14.9 s).
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
- `test_detector_keeps_a_short_voiced_coda_at_the_end` — a word-final voiced
  nasal coda (loud but low-ZCR, e.g. "…built in") is folded back into speech,
  not hard-cut mid-word.
- `test_voicing_detector_removes_a_loud_unvoiced_noise_tail` — a loud (~+9 dB
  over speech) broadband noise tail clears the speech gate and is aperiodic;
  only the voicing path cuts it.
- `test_voicing_keeps_a_long_unvoiced_tail_at_speech_level` — the
  clipped-last-word regression guard: a genuine long word-final unvoiced run
  at/below speech level survives (the over-speech gate).
- `test_voicing_disabled_leaves_the_unvoiced_tail_in_place` — with voicing off,
  the ZCR/tonal gates alone keep the noise tail (proves the attribution).
