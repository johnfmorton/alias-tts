<?php

return [
    /*
    |--------------------------------------------------------------------------
    | TTS Provider
    |--------------------------------------------------------------------------
    |
    | The inference backend used to synthesize speech. Drivers are bound in
    | App\Providers\AppServiceProvider. Supported: "replicate" (Chatterbox on
    | Replicate), "fake" (deterministic silent audio, used by tests/local).
    |
    */
    'provider' => env('TTS_PROVIDER', 'replicate'),

    /*
    |--------------------------------------------------------------------------
    | Bootstrap admin
    |--------------------------------------------------------------------------
    |
    | First-login credentials for `admin:create` (bare) and AdminSeeder. Read
    | through config — NOT env() at call time — so they still resolve after
    | `artisan optimize` caches the config on deploy.
    |
    */
    'admin' => [
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    // Shown on the sign-in page as the "forgot your password?" contact. The app
    // sends no email, so recovery is human-mediated: this address receives the
    // request and an admin issues a signed reset link from the Users page.
    'support_email' => env('TTS_SUPPORT_EMAIL'),

    // Proxy IPs/CIDRs (comma-separated, or "*" for any upstream) whose
    // X-Forwarded-* headers to trust when running behind a TLS-terminating proxy
    // like Cloudflare — so the app sees the real client IP and https scheme.
    // Null (default) trusts none. Read at request time by
    // App\Http\Middleware\TrustProxies, so it is safe under a cached config.
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    // Largest upload the app accepts (MB): the voice-import .zip cap, and the
    // size tts:doctor's "Upload size limit" check verifies the server allows.
    // The web server (nginx client_max_body_size) and PHP (upload_max_filesize /
    // post_max_size) must both be >= this, or uploads 413 before reaching Laravel.
    'max_upload_size_mb' => (int) env('TTS_MAX_UPLOAD_SIZE_MB', 50),
    'upload_docs_url' => env('TTS_UPLOAD_DOCS_URL', 'https://github.com/johnfmorton/alias-tts/blob/main/docs/DEPLOYMENT.md'),

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | The Bespoken Craft plugin never sends an output_format and always writes
    | .mp3 chunks that it later concatenates, so the default MUST be a fixed,
    | consistent MP3 profile for clean concatenation. model_id is accepted from
    | clients (e.g. "eleven_v3") but the configured provider decides the model.
    |
    */
    'default_model_id' => env('TTS_DEFAULT_MODEL_ID', 'chatterbox'),
    'default_output_format' => env('TTS_DEFAULT_OUTPUT_FORMAT', 'mp3_44100_128'),

    // Format for PROJECT final builds only (panel "New project" and
    // POST /v1/projects without an explicit output_format). Kept separate from
    // default_output_format so a user choosing WAV never changes what the
    // plugin's /v1/text-to-speech calls get. Null falls back to the default
    // above; UI-managed per user (see config/settings.php).
    'project_output_format' => env('TTS_PROJECT_OUTPUT_FORMAT'),
    'default_voice_settings' => [
        'stability' => 0.5,
        'similarity_boost' => 0.75,
        'style' => 0.0,
        'use_speaker_boost' => true,
        // Chatterbox's native sampling temperature (the Studio speaks native; the
        // public /v1 API has no equivalent knob so this default is sent verbatim
        // and matches the model's own default 0.8 — a no-op for existing callers).
        // Practical UI band 0.5–1.5; see ChatterboxTuning::clampTemperature.
        'temperature' => 0.8,
    ],

    // The always-present built-in voices, shipped with a bundled reference clip
    // each (a neutral US male and female, public-domain source) so a fresh install
    // produces a consistent, distinct voice immediately without uploading a custom
    // one. Seeded by migration, offered in Studio and the /v1 API (voice_id = the
    // slug), and protected from deletion in the admin UI. `default_voice_slug` is
    // also the primary default — pre-selected in the new-project picker.
    'default_voice_slug' => env('TTS_DEFAULT_VOICE_SLUG', 'default'),
    'default_voice_female_slug' => env('TTS_DEFAULT_VOICE_FEMALE_SLUG', 'default-female'),

    // ElevenLabs-compatible endpoints (POST /v1/text-to-speech/{voice_id},
    // /stream and /jobs, plus POST /v1/projects' voice_id field). The incoming
    // voice_id is a Alias slug (or voice UUID) by default; map real ElevenLabs
    // voice IDs to your own voices here so an existing ElevenLabs client works
    // with zero client-side changes. Keys are matched EXACTLY — ElevenLabs IDs
    // are case-sensitive — and unlisted IDs pass through unchanged. An alias
    // key shadows a real slug of the same value. Full resolution procedure:
    // docs/VOICES.md ("How a voice ID resolves").
    'elevenlabs_voice_aliases' => [
        // '21m00Tcm4TlvDq8ikWAM' => 'default-female', // Rachel -> bundled female
        // 'pNInz6obpgDQGcFmaJgB' => 'default',        // Adam   -> bundled male
    ],

    // OpenAI-compatible endpoint (POST /v1/audio/speech). OpenAI clients send a
    // `voice` from a fixed preset list (alloy, echo, nova, shimmer, …), whereas
    // Alias has arbitrary voice slugs and cloned voices. The `voice` field is
    // treated as a Alias slug by default; map OpenAI's preset names to your own
    // voices here so a stock OpenAI client resolves to a real voice. Unlisted
    // names pass through unchanged. See docs/OPENAI-COMPAT.md; full resolution
    // procedure: docs/VOICES.md ("How a voice ID resolves").
    'openai_voice_aliases' => [
        // 'alloy' => 'default',
        // 'nova'  => 'default-female',
    ],

    // OpenAI `model` -> engine (a tts.models catalog key). Exact catalog keys
    // ('chatterbox', 'chatterbox-turbo') always work as per-request engine
    // overrides; this map lets an operator opt OpenAI's own model names in
    // too. DELIBERATELY EMPTY by default: every stock OpenAI client sends
    // 'tts-1', and a default alias would silently switch every voice's engine.
    // Unmapped names are ignored — the voice's engine decides, never an error.
    'openai_model_aliases' => [
        // 'tts-1'    => 'chatterbox-turbo', // fast
        // 'tts-1-hd' => 'chatterbox',       // expressive
    ],

    /*
    |--------------------------------------------------------------------------
    | Internal pipeline API
    |--------------------------------------------------------------------------
    |
    | Stateless primitive endpoints under /v1/internal/* (chunk, generate,
    | score, trim, stitch) that expose the individual pipeline stages to the
    | external Genblaze orchestrator. Guarded by a shared secret in the
    | X-Internal-Secret header; leave it empty to disable the surface entirely.
    |
    */
    'internal' => [
        'secret' => env('TTS_INTERNAL_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Genblaze runner
    |--------------------------------------------------------------------------
    |
    | The Genblaze-owned orchestrator (the genblaze-runner FastAPI service) that
    | drives generate → QA-gated re-roll → stitch and persists every take + a
    | provenance manifest to Backblaze B2. The app depends on it (pronunciation
    | pre-processor, QA-gated generation), so the URL defaults to where a
    | standard install runs the daemon; set it empty only to disable the runner.
    | The judge-facing "Genblaze Demo" nav page is separate — off unless
    | TTS_GENBLAZE_DEMO=true.
    |
    */
    'genblaze' => [
        'runner_url' => rtrim((string) env('TTS_GENBLAZE_RUNNER_URL', 'http://127.0.0.1:8800'), '/'),
        'timeout' => (int) env('TTS_GENBLAZE_TIMEOUT', 600),
        'docs_url' => env('TTS_GENBLAZE_DOCS_URL', 'https://github.com/johnfmorton/alias-tts/blob/main/docs/GENBLAZE-SETUP.md'),
        'demo' => (bool) env('TTS_GENBLAZE_DEMO', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pronunciation pre-processor
    |--------------------------------------------------------------------------
    |
    | Inserts a review screen before chunking that asks an LLM (run as a Genblaze
    | chat step in the runner) to propose ASCII respellings for likely-
    | mispronounced terms ("DDEV" => "dee dev"). Approved terms persist to a
    | per-writer dictionary. Off by default; any runner/LLM failure degrades to
    | "no suggestions, continue to chunking" and never blocks generation.
    |
    | The LLM provider keys in the 'llm' block below are read by the RUNNER
    | (Python) from its own environment; they are mirrored here only so the
    | Settings page and health check can report which providers are keyed.
    |
    */
    'pronunciation' => [
        'enabled' => (bool) env('TTS_PRONUNCIATION_ENABLED', false),
        'llm_provider' => env('TTS_PRONUNCIATION_LLM_PROVIDER', 'replicate'),
        'model' => env('TTS_PRONUNCIATION_MODEL'),                  // null => provider default
        'temperature' => (float) env('TTS_PRONUNCIATION_TEMPERATURE', 0.2),
        'timeout' => (int) env('TTS_PRONUNCIATION_TIMEOUT', 60),
    ],

    'llm' => [
        'gemini' => ['key' => env('GEMINI_API_KEY')],
        'openai' => ['key' => env('OPENAI_API_KEY')],
        'anthropic' => ['key' => env('ANTHROPIC_API_KEY')],
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits & caching
    |--------------------------------------------------------------------------
    */
    // Hard cap on request text length; over it the endpoint returns an
    // ElevenLabs-shaped 422. This is bounded by the SYNCHRONOUS budget, not just
    // a number: generation is one Replicate prediction per ~chunk_chars, run
    // sequentially, and the whole call must finish under request_timeout (and the
    // client's curl timeout, ~300s). Under Replicate's burst throttle (≈one
    // prediction per ~10s) ceil(chars / chunk_chars) predictions dominate, so the
    // practical sync ceiling is only a few thousand chars. Raising this past that
    // trades 422s for 300s timeouts — lift the Replicate rate limit or add the
    // async path (see NEXT_STEPS) before raising it materially.
    'max_text_length' => (int) env('TTS_MAX_TEXT_LENGTH', 5000),

    // Hard cap for the ASYNC jobs endpoint (POST .../jobs). Generation there runs
    // in a background worker bounded by async_timeout (default 1800s), not the
    // ~300s synchronous request budget, so it accepts far longer text than the
    // synchronous max_text_length above — this is the whole point of the async
    // path. Still finite: at ~one Replicate prediction per ~10s and chunk_chars
    // per prediction, ceil(chars / chunk_chars) predictions must finish within
    // async_timeout, so the default leaves headroom for concatenation + storage.
    'max_async_text_length' => (int) env('TTS_MAX_ASYNC_TEXT_LENGTH', 40000),

    // Long text is split into ~this many characters per backend call (Chatterbox
    // is short-form), then the generated audio is concatenated. Lower = more,
    // shorter calls.
    'chunk_chars' => (int) env('TTS_CHUNK_CHARS', 280),

    // How sentences are grouped into chunks. 'packed' (default) packs sentences
    // greedily up to chunk_chars per backend call; 'sentence' gives every
    // sentence its own chunk — more, shorter calls, but each sentence can be
    // re-rolled/edited independently in Studio. Very short sentences are still
    // merged with a neighbor (min_chunk_chars) in both modes. Per-user editable
    // on the Settings page.
    'chunk_mode' => env('TTS_CHUNK_MODE', 'packed'),

    // Chunks shorter than this are merged into a neighbor so they are never sent
    // to the backend alone. Chatterbox is unreliable on very short inputs (a bare
    // "Why?" or a "The to-do list." heading) — it tends to return silence/garbage
    // and the words go missing. 0 disables merging. Raise it if longer short
    // chunks still drop; lower it to keep more standalone short lines.
    'min_chunk_chars' => (int) env('TTS_MIN_CHUNK_CHARS', 30),

    // A sentence of <= this many words that would END a chunk is moved to the
    // START of the next chunk instead. Chatterbox tends to drop a short trailing
    // utterance (a one-word "Why?") by ending generation early, but renders a
    // short LEADING phrase reliably. Only applies within a paragraph and never
    // strips a chunk of its last real sentence. 0 disables it.
    'short_trailer_words' => (int) env('TTS_SHORT_TRAILER_WORDS', 3),

    // Runs of >= this many spaces are treated as a block (paragraph) boundary,
    // in addition to blank lines. The Bespoken plugin currently flattens posts
    // to a single line and marks every block with a run of (4) spaces, so this
    // recovers paragraph pacing from real traffic; it's well clear of the
    // legitimate "two spaces after a period". Raise it high to rely on newlines
    // only once the client emits proper paragraph breaks.
    'block_space_run' => (int) env('TTS_BLOCK_SPACE_RUN', 4),

    // Each generated chunk is edge-trimmed before concatenation to remove
    // Chatterbox's trailing "swoosh"/hiss artifact (the noisy pauses at seams).
    // Threshold below which audio counts as silence to trim; raise toward -30dB
    // to cut more of a louder tail (at the risk of clipping soft speech ends).
    'chunk_trim_threshold' => env('TTS_CHUNK_TRIM_THRESHOLD', '-40dB'),

    // Fade (ms) applied to each trimmed chunk's edges so seams stay click-free.
    'chunk_fade_ms' => (int) env('TTS_CHUNK_FADE_MS', 8),

    // The trailing-swoosh trim is bounded to the LAST this-many ms of a chunk, so
    // it can never reach back far enough to swallow a quiet final word. Chatterbox
    // often renders a soft trailing word (e.g. "Why?") below the silence
    // threshold; an unbounded trim treated it as part of the trailing silence and
    // dropped the word entirely. The swoosh sits *after* the word, so a window a
    // little longer than the swoosh removes the noise while keeping the word.
    // Raise it if a longer swoosh tail survives; lower it if a soft final word is
    // still clipped. The head (leading-silence) trim stays unbounded — that's just
    // dead air to remove.
    'chunk_trim_tail_window_ms' => (int) env('TTS_CHUNK_TRIM_TAIL_WINDOW_MS', 300),

    // Long tail-artifact detector. Chatterbox occasionally appends a MULTI-SECOND
    // low-frequency drone after the speech ends — too loud for the silence trim
    // (it sits near speech level, well above chunk_trim_threshold) and far too
    // long for chunk_trim_tail_window_ms. It is detected by zero-crossing rate
    // (the drone is tonal/low-frequency, so its ZCR is much lower than speech)
    // gated by an RMS floor (so a quiet gap isn't mistaken for speech): the last
    // window that is both loud enough AND high-ZCR enough marks the speech end.
    // Only LONG trailing non-speech (>= min_artifact_ms) is hard-cut here; shorter
    // tails fall through to the bounded silence trim above, so normal clips and
    // soft final words keep their existing behavior. ZCR is in crossings/second
    // (rate-independent) because chunks are analyzed at the output sample rate.
    'chunk_tail_artifact_enabled' => (bool) env('TTS_CHUNK_TAIL_ARTIFACT', true),
    'chunk_tail_window_ms' => (int) env('TTS_CHUNK_TAIL_WINDOW_MS', 50),       // analysis window
    'chunk_tail_rms_floor_db' => (float) env('TTS_CHUNK_TAIL_RMS_FLOOR_DB', -40), // quieter than this = not speech
    'chunk_tail_zcr_min_hz' => (float) env('TTS_CHUNK_TAIL_ZCR_MIN_HZ', 700),  // crossings/sec; below = tonal/low-freq
    'chunk_tail_min_artifact_ms' => (int) env('TTS_CHUNK_TAIL_MIN_ARTIFACT_MS', 400), // only hard-cut tails >= this
    'chunk_tail_guard_ms' => (int) env('TTS_CHUNK_TAIL_GUARD_MS', 60),         // keep this much after last speech
    // Chatterbox sometimes follows the quiet decay tail with a brief loud,
    // mid-band "re-swell" that clears the speech gates and, sitting at the very
    // end, resets the detected speech end to ~EOF — so the whole tail survives.
    // A trailing speech run shorter than this, isolated from the body by a
    // >= min_artifact_ms non-speech gap, is treated as that artifact (not resumed
    // speech) and peeled off before the speech end is measured. Set 0 to disable.
    'chunk_tail_blip_max_ms' => (int) env('TTS_CHUNK_TAIL_BLIP_MAX_MS', 400),   // max isolated trailing blip to drop
    // The re-swell artifact is sometimes LONGER than blip_max_ms (a sustained
    // drone/swell, not a brief blip). Such a run still differs from speech: its
    // zero-crossing rate is nearly constant (a tone), whereas speech alternates
    // voiced/unvoiced so its ZCR swings widely. A trailing run isolated by a quiet
    // gap whose per-window ZCR coefficient of variation is at/below this is treated
    // as a tonal artifact and peeled regardless of length. 0 = disable that path
    // (only short blips are peeled). Raise toward speech's CV (~0.7+) cautiously.
    'chunk_tail_tonal_cv_max' => (float) env('TTS_CHUNK_TAIL_TONAL_CV_MAX', 0.35),
    // The ZCR speech gate marks a window as speech only when it is loud AND
    // high-ZCR, so a loud word-final VOICED coda (a nasal /n/, /m/, /ŋ/ — voiced
    // but low-frequency, hence low-ZCR) fails it and reads as the START of a
    // trailing artifact: the detector then hard-cuts mid-word (e.g. "...built in"
    // loses the "n"). Before measuring the cut, the speech end is extended forward
    // over a short voiced coda: contiguous windows that are loud, voiced (a clear
    // fundamental), and AT/BELOW the speech PEAK window level + over_speech_db (so
    // a louder re-swell swoosh is NOT folded; the peak — not the span's mean RMS,
    // which pauses dilute until an ordinary stressed final word reads "louder than
    // speech" and gets cut). The fold is bounded to this many ms
    // so a sustained voiced drone (multi-second) is NOT mistaken for a coda and is
    // still cut. Purely acoustic (voicing + loudness + duration) — no phoneme/
    // language assumptions; a nasal is voiced + low-ZCR in any language. 0 disables.
    'chunk_tail_voiced_coda_max_ms' => (int) env('TTS_CHUNK_TAIL_VOICED_CODA_MAX_MS', 300),

    // Voicing refinement of the tail detector. A trailing run that clears the
    // loud + high-ZCR speech gate but has NO fundamental (broadband hiss/noise
    // with speech-like zero-crossings) is not speech — the ZCR/tonal-CV gates
    // above miss it. A pitch-voicing check (peak normalized autocorrelation in
    // f0_min..f0_max Hz) finds the last loud VOICED window; if a trailing
    // UNVOICED run of >= unvoiced_min_ms follows it, the chunk is cut back to that
    // window plus fricative_allowance_ms. It COMBINES with the cut above (takes the
    // earlier), so it can only trim MORE and never clips a quiet voiced final word.
    // A low-freq drone is periodic (reads VOICED), so it's intentionally left to
    // the ZCR/tonal path. Pure PHP (no Python/Praat dependency). 0 unvoiced_min_ms
    // / false disables.
    //
    // CRITICAL GATE — over_speech_db: duration alone CANNOT separate a genuine
    // word-final unvoiced run (a sustained /s/, /f/, /ʃ/, or a devoiced/creaky word
    // ending) from an appended broadband-hiss artifact: both are loud, unvoiced,
    // and can run well past fricative_allowance_ms (real codas measured here run
    // 600-900 ms). The loudness RELATIONSHIP does separate them — a real coda is
    // the energy tapering off the end of a word, so it is QUIETER than or about
    // equal to the speech body (measured rel +0.7..+4.3 dB), whereas a real hiss/
    // swoosh tail is LOUDER (rel ~+9 dB). So the voicing cut only fires when the
    // trailing run's peak window is at least over_speech_db LOUDER than the speech
    // body's RMS — the same over-speech discriminator the ASR TAILNOISE signal
    // uses. Without it this path clipped the last word off otherwise-perfect clips.
    'chunk_tail_voicing_enabled' => (bool) env('TTS_CHUNK_TAIL_VOICING', true),
    'chunk_tail_voicing_acf_min' => (float) env('TTS_CHUNK_TAIL_VOICING_ACF_MIN', 0.5),
    'chunk_tail_voicing_f0_min_hz' => (float) env('TTS_CHUNK_TAIL_VOICING_F0_MIN_HZ', 75),
    'chunk_tail_voicing_f0_max_hz' => (float) env('TTS_CHUNK_TAIL_VOICING_F0_MAX_HZ', 600),
    'chunk_tail_unvoiced_min_ms' => (int) env('TTS_CHUNK_TAIL_UNVOICED_MIN_MS', 400),
    'chunk_tail_fricative_allowance_ms' => (int) env('TTS_CHUNK_TAIL_FRICATIVE_ALLOWANCE_MS', 250),
    'chunk_tail_voicing_over_speech_db' => (float) env('TTS_CHUNK_TAIL_VOICING_OVER_SPEECH_DB', 6.0),

    // True digital silence (ms) inserted between chunks at a sentence seam and
    // at a block/paragraph seam respectively. Tune by ear for natural pacing.
    'chunk_gap_ms' => (int) env('TTS_CHUNK_GAP_MS', 120),
    'paragraph_gap_ms' => (int) env('TTS_PARAGRAPH_GAP_MS', 400),

    // Delay (ms) the Studio "Generate all remaining" loop waits between chunks.
    // Generation is already sequential, but a small gap spaces out the stream of
    // Replicate predictions so a burst is less likely to spin up cold GPU replicas
    // (which can fail with transient CUDA asserts). 0 = no delay (back-to-back).
    'studio_generate_pace_ms' => (int) env('TTS_STUDIO_GENERATE_PACE_MS', 800),

    // Page size for the Studio → Projects tab list. Keeps a long project list from
    // burying the Inspector tab; the tab's count badge always shows the full total.
    'studio_projects_per_page' => (int) env('TTS_STUDIO_PROJECTS_PER_PAGE', 10),

    // Take history retention per chunk. Every synthesis (Generate, Re-roll,
    // Preview, "Use this take", auto-remediation) is saved as a selectable take so
    // none is ever lost; older takes are auto-pruned. The currently-selected take
    // is always kept. Previews are cheap auditions, so they're pruned harder.
    'takes' => [
        'keep' => (int) env('TTS_TAKES_KEEP', 10),            // committed takes kept per chunk
        'keep_preview' => (int) env('TTS_TAKES_KEEP_PREVIEW', 3), // preview takes kept per chunk
    ],

    // Estimated provider cost lives PER MODEL in the `models` catalog below
    // (tts.models.*.cost_per_1k_chars — env TTS_COST_PER_1K_CHARS for classic
    // chatterbox, TTS_COST_PER_1K_CHARS_TURBO for turbo), driving the Studio
    // spend readouts. Billed by input character only — reference clips and
    // tuning knobs are free. Set EVERY rate to 0 to hide the cost readouts
    // (e.g. when running a self-hosted provider that costs nothing per call).

    'ttl_hours' => (int) env('TTS_TTL_HOURS', 720), // cache generated audio for 30 days

    // Whether a /v1 API generation also creates an editable Studio project:
    //   always   — every call creates a project (default).
    //   on_error — only when a generation fails, so the failed text becomes a
    //              recovery project you can open in Studio, repair, and rebuild
    //              (a plain panel URL is surfaced in the API error's recovery_url;
    //              the owner opens it after a normal login).
    //   never    — stateless; no project is created.
    // Does NOT affect the explicit "Create project" API endpoint.
    'api_project_mode' => env('TTS_API_PROJECT_MODE', 'always'),

    // How long (hours) an auto-created recovery project (api_project_mode=on_error)
    // is kept before the project prune removes it if it was never opened/edited.
    // 'always'-mode projects are intentional artifacts and are NOT auto-pruned.
    'api_project_ttl_hours' => (int) env('TTS_API_PROJECT_TTL_HOURS', 168), // 7 days

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Disk used for both reference voice samples and generated audio. Use the
    | private "local" disk by default; "s3" for production.
    |
    */
    'storage_disk' => env('TTS_STORAGE_DISK', 'local'),
    'storage_path' => trim((string) env('TTS_STORAGE_PATH', 'speech'), '/'),
    'reference_path' => trim((string) env('TTS_REFERENCE_PATH', 'voices'), '/'),

    // Staging area for the "prepare a clip" flow (record/upload → clean up →
    // preview → choose). Deliberately a top-level prefix OUTSIDE storage_path so
    // `speech:cleanup --orphans` (which sweeps only storage_path) never touches a
    // pending clip; its own TTL prune (voices:prune-clips) owns cleanup here.
    'voice_clip_path' => trim((string) env('TTS_VOICE_CLIP_PATH', 'voice-clips'), '/'),

    // Accept file UPLOADS on the public /verify page? Off by default: the page
    // fingerprints the file in the visitor's browser (Web Crypto) and sends only
    // the 64-char SHA-256, so nothing is uploaded and no large file can exhaust
    // the server. Turn this on only to support non-secure-context browsers (plain
    // http, where crypto.subtle is unavailable); when on, the POST is throttled
    // and capped by verify_max_upload_kb. When off, the POST endpoint 404s and
    // the page offers no upload form — local hashing (or the receipt) only.
    'verify_allow_upload' => (bool) env('TTS_VERIFY_ALLOW_UPLOAD', false),

    // Max upload the public /verify page accepts, in KB, WHEN verify_allow_upload
    // is on. A sealed final is a single project's audio, so 200 MB covers even a
    // long WAV; the holder can always verify from the receipt if theirs is
    // bigger. Must stay under the web server's client_max_body_size / PHP
    // post_max_size (see tts:doctor).
    'verify_max_upload_kb' => (int) env('TTS_VERIFY_MAX_UPLOAD_KB', 204800),

    /*
    |--------------------------------------------------------------------------
    | ffmpeg
    |--------------------------------------------------------------------------
    */
    'ffmpeg_path' => env('TTS_FFMPEG_PATH', 'ffmpeg'),

    // ffprobe ships alongside ffmpeg; used to screen uploaded reference clips
    // for a smuggled video stream before ffmpeg ever decodes them.
    'ffprobe_path' => env('TTS_FFPROBE_PATH', 'ffprobe'),

    // Minimum ffmpeg version the health check requires. 8.1.2 is the first
    // release with the MagicYUV "PixelSmash" fix (CVE-2026-8461). Operators on
    // a distro that backported the fix to an older build can lower this to
    // their version to silence the failing health check.
    'ffmpeg_min_version' => env('TTS_FFMPEG_MIN_VERSION', '8.1.2'),

    /*
    |--------------------------------------------------------------------------
    | Reference normalization
    |--------------------------------------------------------------------------
    |
    | When a voice is registered with a reference clip, auto-clean it: downmix
    | to mono, trim leading/trailing silence, loudness-normalize, and cap the
    | true peak so it can never clip. Disable globally with
    | TTS_NORMALIZE_REFERENCE=false, or per registration with `voice:create --raw`.
    |
    */
    'normalize_reference' => (bool) env('TTS_NORMALIZE_REFERENCE', true),
    'reference_loudness' => env('TTS_REFERENCE_LOUDNESS', '-20'),    // integrated LUFS target
    'reference_true_peak' => env('TTS_REFERENCE_TRUE_PEAK', '-1.5'), // dBTP ceiling (headroom, no clipping)
    'reference_sample_rate' => (int) env('TTS_REFERENCE_SAMPLE_RATE', 44100),

    /*
    |--------------------------------------------------------------------------
    | Per-request timeout (seconds) for backend inference calls
    |--------------------------------------------------------------------------
    */
    'request_timeout' => (int) env('TTS_REQUEST_TIMEOUT', 300),

    /*
    |--------------------------------------------------------------------------
    | Async generation timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | When text is generated via the async job (POST .../jobs), this is the
    | queue job's timeout — the long ceiling the async path exists to provide,
    | well beyond request_timeout. The job pins this itself (so the worker's
    | --timeout flag is optional), and the database queue's retry_after defaults
    | just above it. Also bounds the "in-flight" dedupe window.
    |
    */
    'async_timeout' => (int) env('TTS_ASYNC_TIMEOUT', 1800),

    /*
    |--------------------------------------------------------------------------
    | Doctor queue probe timeout (seconds)
    |--------------------------------------------------------------------------
    |
    | How long `tts:doctor --deep` waits for a queue worker to drain its probe
    | job before concluding that no worker is running. A few seconds is plenty
    | for a healthy worker (it polls every --sleep seconds).
    |
    */
    'doctor_queue_probe_timeout' => (int) env('TTS_DOCTOR_QUEUE_PROBE_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | ASR transcript QA (Whisper sidecar)
    |--------------------------------------------------------------------------
    |
    | Optional. When enabled, each generated chunk is transcribed by a local
    | Whisper sidecar (see asr-sidecar/ and docs/ASR-SETUP.md) and the transcript
    | is compared to the source text to catch failure modes the DSP tail-trim
    | cannot: TRUNCATION (Chatterbox stops before finishing the script — no
    | acoustic artifact to detect), speech-like / "ghostly singing" tails, and
    | mid-stream pauses. Three signals (validated on labeled samples):
    |
    |   trail_s   seconds of audio AFTER the last recognized word  (> max ⇒ TAIL)
    |   gap_s     largest gap between consecutive words             (> max ⇒ PAUSE)
    |   tail_cov  how far into the script the transcript reached    (< min ⇒ TRUNC)
    |
    | Two further ENERGY-aware signals add extra scrutiny at the two zones where
    | Chatterbox anomalies cluster — the tail and sentence/comma boundaries —
    | catching SHORT-but-LOUD junk the duration signals above miss:
    |
    |   TAILNOISE  a loud "swoosh" right after the last word, too short to trip
    |              trail_s. Peak dBFS in the tail (past the word's natural release)
    |              above tail_energy_dbfs_max AND louder than the chunk's own speech
    |              (so a Whisper-under-timed soft word-coda isn't clipped).
    |              Lossless-trimmed, never re-rolled.
    |   BNDNOISE   a tonal "hum" filling a punctuation-boundary gap that is too
    |              short to trip gap_s. A boundary gap that is both not-silent
    |              (mean dBFS above boundary_energy_dbfs_max) AND low-frequency
    |              (ZCR below boundary_zcr_max_hz). Mid-stream ⇒ re-rolled.
    |
    | `action` controls what happens on a bad verdict. Start at 'log' (record the
    | score/verdict on the chunk, take NO action) to watch it against real
    | traffic, then move to 'auto' to re-roll/trim. The sidecar is off-by-default
    | and degrades safely: if it's disabled or unreachable, generation is
    | unaffected. Defaults below match the validated thresholds.
    |
    */
    'asr' => [
        'enabled' => (bool) env('TTS_ASR_ENABLED', false),
        'url' => rtrim((string) env('TTS_ASR_URL', 'http://127.0.0.1:8765'), '/'),
        'timeout' => (int) env('TTS_ASR_TIMEOUT', 30),
        'language' => env('TTS_ASR_LANGUAGE', 'en'),
        // Setup guide surfaced (as a link) by the health page / tts:doctor when
        // ASR is enabled but not yet reachable. Override for a fork.
        'docs_url' => env('TTS_ASR_DOCS_URL', 'https://github.com/johnfmorton/alias-tts/blob/main/docs/ASR-SETUP.md'),
        // 'log' = score + record only (no action). 'auto' = also remediate a bad
        // verdict: re-roll TRUNC/PAUSE/NOSPEECH (fresh seed, up to max_rerolls,
        // keeping the best-coverage take) and precise-trim a TAIL-only chunk at
        // the ASR speech end (no Replicate call). Any other value behaves as 'log'.
        //
        // Both generation paths default to 'auto' so a flagged chunk self-heals
        // out of the box. The interactive Studio still shows the per-chunk ASR
        // badge, so an admin can see what was remediated and re-roll further by
        // hand; set TTS_ASR_STUDIO_ACTION=log (or TTS_ASR_API_ACTION=log) to opt a
        // path back into badge-only, no automatic re-roll. `action` is the shared
        // base each path inherits when its own key is unset (null).
        'action' => env('TTS_ASR_ACTION', 'log'),
        'studio_action' => env('TTS_ASR_STUDIO_ACTION', 'auto'), // interactive path self-heals; badge still shown
        'api_action' => env('TTS_ASR_API_ACTION', 'auto'),       // unattended path self-heals by default
        'max_rerolls' => (int) env('TTS_ASR_MAX_REROLLS', 3),
        // Detection thresholds.
        'trail_s_max' => (float) env('TTS_ASR_TRAIL_S_MAX', 1.2),
        'gap_s_max' => (float) env('TTS_ASR_GAP_S_MAX', 1.5),
        'tail_cov_min' => (float) env('TTS_ASR_TAIL_COV_MIN', 0.93),
        // Kept after the last recognized word when computing a TAIL/TAILNOISE
        // trim point.
        'trim_guard_ms' => (int) env('TTS_ASR_TRIM_GUARD_MS', 80),

        // ── Energy-aware scrutiny (TAILNOISE / BNDNOISE). Measured in dBFS from
        // the chunk WAV, aligned to the Whisper word timings. Validated on a
        // labeled corpus; the boundary thresholds are deliberately conservative
        // and should be re-checked against the prod sidecar (see docs/ASR-SETUP.md).
        'energy_window_ms' => (int) env('TTS_ASR_ENERGY_WINDOW_MS', 50), // analysis window for the tail peak
        // TAILNOISE — a loud tail too short for trail_s. Peak energy in
        // [last word end + tail_release_ms .. EOF] is flagged when it is BOTH above
        // tail_energy_dbfs_max (absolute floor) AND louder than the chunk's own
        // speech by tail_over_speech_db. The release window skips the natural decay
        // of the final word; the relative gate is the critical guard against
        // DESTROYING content — Whisper under-times soft final codas (e.g. the voiced
        // "n" in "2019"→"nineteen", sometimes a zero-duration word), so the still-
        // sounding word can read as a loud tail. A word's coda is never louder than
        // its body, but a real swoosh is (~+9 dB), so the margin separates them.
        'tail_release_ms' => (int) env('TTS_ASR_TAIL_RELEASE_MS', 200),
        'tail_energy_dbfs_max' => (float) env('TTS_ASR_TAIL_ENERGY_DBFS_MAX', -38),
        'tail_over_speech_db' => (float) env('TTS_ASR_TAIL_OVER_SPEECH_DB', 6),
        // BNDNOISE — a tonal hum filling a punctuation-boundary gap too short for
        // gap_s. A gap that follows sentence/clause punctuation and is at least
        // boundary_gap_min_ms long is flagged when its inset core is BOTH not
        // silent (mean dBFS above boundary_energy_dbfs_max) AND tonal/low-freq
        // (ZCR below boundary_zcr_max_hz) — distinguishing a hum from a clean
        // breath (silent) or speech residue (broadband, high ZCR).
        'boundary_gap_min_ms' => (int) env('TTS_ASR_BOUNDARY_GAP_MIN_MS', 500),
        'boundary_gap_inset_ms' => (int) env('TTS_ASR_BOUNDARY_GAP_INSET_MS', 100), // trim each gap end before measuring
        'boundary_energy_dbfs_max' => (float) env('TTS_ASR_BOUNDARY_ENERGY_DBFS_MAX', -55),
        'boundary_zcr_max_hz' => (float) env('TTS_ASR_BOUNDARY_ZCR_MAX_HZ', 1500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reference-clip cleanup (resemble-enhance)
    |--------------------------------------------------------------------------
    |
    | Optional AI cleanup of a voice's reference clip — denoise + enhance via the
    | official `resemble-ai/resemble-enhance` model on Replicate — applied to both
    | recorded and uploaded clips before it becomes the reference. On by default:
    | it reuses REPLICATE_API_TOKEN (already required for TTS), costs ~$0.0086/run,
    | and DEGRADES SAFELY — any failure falls back to the original clip so a voice
    | is never blocked from saving. The `provider` seam keeps a future local
    | backend possible; only `replicate` and `fake` (tests/offline) are valid now.
    |
    | NOTE: the model returns a TWO-element list [denoised, enhanced]; we use the
    | enhanced take (index 1), or the denoised take (index 0) when denoise_only is
    | on. Confirm the model's input field names and pin a version from its API page
    | (https://replicate.com/resemble-ai/resemble-enhance/api) before going live —
    | tts:doctor WARNs while the version is unpinned.
    |
    */
    'enhance' => [
        'enabled' => (bool) env('TTS_ENHANCE_ENABLED', true),
        'provider' => env('TTS_ENHANCE_PROVIDER', 'replicate'), // 'replicate' | 'fake'
        'default_on' => (bool) env('TTS_ENHANCE_DEFAULT_ON', true), // the opt-out checkbox's default state
        'denoise_only' => (bool) env('TTS_ENHANCE_DENOISE_ONLY', false), // gentler: denoise without the enhancer
        'timeout' => (int) env('TTS_ENHANCE_TIMEOUT', 120), // seconds for the whole prediction (incl. polling)
        'clip_ttl_hours' => (int) env('TTS_ENHANCE_CLIP_TTL_HOURS', 24), // prepared-clip token lifetime
        'max_clip_seconds' => (int) env('TTS_ENHANCE_MAX_CLIP_SECONDS', 120), // prepare-endpoint duration cap
        'replicate' => [
            'model' => env('TTS_ENHANCE_REPLICATE_MODEL', 'resemble-ai/resemble-enhance'),
            // Pinned to a known-good version (this model 404s on the version-less
            // model-predictions endpoint, so a version is required). Override via
            // env to bump it; empty makes tts:doctor WARN to pin one.
            'version' => env('TTS_ENHANCE_REPLICATE_VERSION', '93266a7e7f5805fb79bcf213b1a4e0ef2e45aff3c06eefd96c59e850c87fd6a2'),
            // Input field names — confirm against the model schema; env-overridable
            // so a schema change needs no code change.
            'audio_field' => env('TTS_ENHANCE_REPLICATE_AUDIO_FIELD', 'input_audio'),
            'denoise_flag_field' => env('TTS_ENHANCE_REPLICATE_DENOISE_FIELD', 'denoise_flag'),
            'max_retries' => (int) env('TTS_ENHANCE_MAX_RETRIES', 2),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | TTS model catalog
    |--------------------------------------------------------------------------
    |
    | Every speech engine the app can drive, keyed by the model key stored on
    | voices (`voices.model`, null = 'chatterbox') and threaded to the provider
    | as the reserved settings key `model`. Each entry carries the engine's
    | Replicate identity (slug + pinned version), its input field names, its
    | knob dialect ('chatterbox' = cfg_weight/exaggeration, 'turbo' =
    | top_p/top_k/repetition_penalty), its per-call input cap, and its OWN
    | per-1k-character rate so spend is metered per model (see GenerationCost).
    | The chatterbox entry reads the same env names the app has always used, so
    | existing deployments keep working untouched.
    |
    */
    'models' => [
        'chatterbox' => [
            'label' => 'Chatterbox',
            'model' => env('REPLICATE_CHATTERBOX_MODEL', 'resemble-ai/chatterbox'),
            // Pinned to a known-good version; override via env to bump it.
            'version' => env('REPLICATE_CHATTERBOX_VERSION', '1b8422bc49635c20d0a84e387ed20879c0dd09254ecdb4e75dc4bec10ff94e97'),
            'text_field' => env('REPLICATE_TEXT_FIELD', 'prompt'),
            'reference_field' => env('REPLICATE_REFERENCE_FIELD', 'audio_prompt'),
            'output_container' => env('REPLICATE_OUTPUT_CONTAINER', 'wav'),
            'max_input_chars' => 0, // 0 = no per-call cap
            'cost_per_1k_chars' => (float) env('TTS_COST_PER_1K_CHARS', 0.025),
            'knobs' => 'chatterbox',
            'preset_voices' => [],
            'supports_tags' => false,
        ],
        'chatterbox-turbo' => [
            'label' => 'Chatterbox Turbo',
            'model' => env('REPLICATE_CHATTERBOX_TURBO_MODEL', 'resemble-ai/chatterbox-turbo'),
            'version' => env('REPLICATE_CHATTERBOX_TURBO_VERSION', '95c87b883ff3e842a1643044dff67f9d204f70a80228f24ff64bffe4a4b917d4'),
            'text_field' => env('REPLICATE_TURBO_TEXT_FIELD', 'text'),
            'reference_field' => env('REPLICATE_TURBO_REFERENCE_FIELD', 'reference_audio'),
            'output_container' => env('REPLICATE_TURBO_OUTPUT_CONTAINER', 'wav'),
            // Turbo rejects inputs over 500 characters; the provider fails fast
            // (before any HTTP call) so no credit is ever spent on one.
            'max_input_chars' => 500,
            'cost_per_1k_chars' => (float) env('TTS_COST_PER_1K_CHARS_TURBO', 0.025),
            'knobs' => 'turbo',
            // Built-in voices the model ships; sent as `voice` when a turbo
            // voice has no reference clip (a clip always wins on Replicate).
            'preset_voices' => [
                'Aaron', 'Abigail', 'Anaya', 'Andy', 'Archer', 'Brian', 'Chloe',
                'Dylan', 'Emmanuel', 'Ethan', 'Evelyn', 'Gavin', 'Gordon', 'Ivan',
                'Laura', 'Lucy', 'Madison', 'Marisol', 'Meera', 'Walter',
            ],
            // Paralinguistic tags ([laugh], [sigh], …) render as sounds.
            'supports_tags' => true,
            // Replicate rejects reference clips of 5 seconds or less.
            'min_reference_seconds' => 5.0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider configuration
    |--------------------------------------------------------------------------
    |
    | Transport-level Replicate settings shared by every model in the catalog
    | above: the API token and the 429/transient-failure retry knobs. The
    | model/version/field keys kept here are the legacy chatterbox values —
    | they read the same env vars as models.chatterbox and remain only for
    | direct provider construction without a catalog (tests).
    |
    */
    'providers' => [
        'replicate' => [
            'token' => env('REPLICATE_API_TOKEN'),
            'model' => env('REPLICATE_CHATTERBOX_MODEL', 'resemble-ai/chatterbox'),
            // Pinned to a known-good version; override via env to bump it.
            'version' => env('REPLICATE_CHATTERBOX_VERSION', '1b8422bc49635c20d0a84e387ed20879c0dd09254ecdb4e75dc4bec10ff94e97'),
            'text_field' => env('REPLICATE_TEXT_FIELD', 'prompt'),
            'reference_field' => env('REPLICATE_REFERENCE_FIELD', 'audio_prompt'),
            'output_container' => env('REPLICATE_OUTPUT_CONTAINER', 'wav'),

            // Replicate throttles prediction creation with a burst limit (e.g.
            // "6/min, burst 1"), returning 429 + a `retry_after` hint. We retry
            // up to max_retries, honoring retry_after, else exponential backoff
            // from retry_base_ms, with each wait capped at retry_max_ms. The
            // total wait can never exceed request_timeout.
            'max_retries' => (int) env('REPLICATE_MAX_RETRIES', 5),
            'retry_base_ms' => (int) env('REPLICATE_RETRY_BASE_MS', 1000),
            'retry_max_ms' => (int) env('REPLICATE_RETRY_MAX_MS', 30000),

            // Chatterbox occasionally fails a prediction with a TRANSIENT GPU
            // fault (e.g. "CUDA error: device-side assert triggered", CUDA OOM) on
            // a flaky worker — re-running the same request usually succeeds. Such
            // a failure is recreated up to this many times (same backoff as above,
            // bounded by request_timeout). Non-transient failures (bad input) fail
            // fast and are never retried. 0 = disable transient-failure retry.
            'predict_max_retries' => (int) env('REPLICATE_PREDICT_MAX_RETRIES', 2),

            // Minimum gap (ms) enforced between prediction creations to respect
            // the burst limit proactively. 0 = disabled (rely on 429 retry);
            // set to ~10000 to stay under a 6/min limit without 429s.
            'min_request_gap_ms' => (int) env('REPLICATE_MIN_REQUEST_GAP_MS', 0),
        ],

        // Development driver (TTS_PROVIDER=local): Chatterbox inference on the
        // in-repo sidecar (chatterbox-sidecar/) running on the developer's own
        // machine — no Replicate credit spent. From inside DDEV a host-run
        // sidecar is http://host.docker.internal:8766. See docs/CHATTERBOX-LOCAL.md.
        'local' => [
            'url' => rtrim((string) env('TTS_LOCAL_CHATTERBOX_URL', 'http://127.0.0.1:8766'), '/'),
            // Whole-call budget. Generation is synchronous AND single-file on
            // the sidecar, so this must cover queue wait + generation.
            'timeout' => (int) env('TTS_LOCAL_CHATTERBOX_TIMEOUT', 300),
        ],
    ],
];
