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
    'max_text_length' => (int) env('TTS_MAX_TEXT_LENGTH', 5000),

    // Long text is split into ~this many characters per backend call (Chatterbox
    // is short-form), then the generated audio is concatenated. Lower = more,
    // shorter calls.
    'chunk_chars' => (int) env('TTS_CHUNK_CHARS', 280),

    'ttl_hours' => (int) env('TTS_TTL_HOURS', 720), // cache generated audio for 30 days

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
        ],
    ],
];
