<?php

namespace App\Services\Enhance;

/**
 * Cleans up a voice reference clip (denoise + enhance) before it becomes the
 * reference. Applied to both recorded and uploaded clips.
 *
 * Degrade-safe BY CONTRACT: {@see enhance()} returns null on ANY failure and
 * never throws — callers fall back to the original clip (mirroring
 * {@see \App\Services\Asr\AsrClient}). This is deliberately different from
 * {@see \App\Services\Tts\ReplicateChatterboxProvider}, which throws: cleanup is
 * cosmetic, so a broken enhancer must never block saving a voice.
 */
interface EnhanceProvider
{
    /**
     * Clean up a reference clip. Input is decoded WAV bytes (see
     * {@see \App\Services\Audio\AudioConverter::decodeToWav()}); returns enhanced
     * WAV bytes, or null on any failure.
     *
     * @param  array{denoise_only?: bool}  $options
     */
    public function enhance(string $wavBytes, array $options = []): ?string;

    /**
     * Report readiness for tts:doctor. With $deep, make a cheap live probe
     * (never a paid run). Never throws.
     *
     * @return array{reachable: bool, detail: string, error: string|null}
     */
    public function health(bool $deep = false): array;
}
