<?php

namespace App\Services\Tts;

interface TtsProvider
{
    /**
     * Synthesize speech and return the raw audio bytes in the provider's
     * native container (see outputContainer()).
     *
     * @param  string  $text  The text to speak.
     * @param  string|null  $referenceAudio  Absolute filesystem path to a reference
     *                                       voice clip for zero-shot cloning, or null.
     * @param  array  $settings  Normalized voice settings: stability,
     *                           similarity_boost, style, use_speaker_boost.
     * @return string Raw audio bytes.
     *
     * @throws \RuntimeException on backend failure.
     */
    public function synthesize(string $text, ?string $referenceAudio, array $settings): string;

    /**
     * The container/format of the bytes returned by synthesize(), e.g. "wav"
     * or "mp3". Used by the AudioConverter to transcode to the requested format.
     */
    public function outputContainer(): string;
}
