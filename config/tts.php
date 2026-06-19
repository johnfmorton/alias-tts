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
    'default_voice_settings' => [
        'stability' => 0.5,
        'similarity_boost' => 0.75,
        'style' => 0.0,
        'use_speaker_boost' => true,
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

    // True digital silence (ms) inserted between chunks at a sentence seam and
    // at a block/paragraph seam respectively. Tune by ear for natural pacing.
    'chunk_gap_ms' => (int) env('TTS_CHUNK_GAP_MS', 120),
    'paragraph_gap_ms' => (int) env('TTS_PARAGRAPH_GAP_MS', 400),

    'ttl_hours' => (int) env('TTS_TTL_HOURS', 720), // cache generated audio for 30 days

    // How long (minutes) a project's single-use auto-login link stays valid. The
    // link logs the user into the control panel and lands them on the project;
    // it is consumed on first use, so this is just the grace window before the
    // unused link goes stale.
    'magic_login_ttl_minutes' => (int) env('TTS_MAGIC_LOGIN_TTL_MINUTES', 60),

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

    /*
    |--------------------------------------------------------------------------
    | ffmpeg
    |--------------------------------------------------------------------------
    */
    'ffmpeg_path' => env('TTS_FFMPEG_PATH', 'ffmpeg'),

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
    | Provider configuration
    |--------------------------------------------------------------------------
    |
    | NOTE: confirm the exact Replicate model slug and its input field names
    | from the model's schema page before going live. Chatterbox commonly takes
    | the text in "prompt" and the reference clip in "audio_prompt".
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

            // Minimum gap (ms) enforced between prediction creations to respect
            // the burst limit proactively. 0 = disabled (rely on 429 retry);
            // set to ~10000 to stay under a 6/min limit without 429s.
            'min_request_gap_ms' => (int) env('REPLICATE_MIN_REQUEST_GAP_MS', 0),
        ],
    ],
];
