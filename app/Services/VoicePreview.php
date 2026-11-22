<?php

namespace App\Services;

use App\Models\Voice;
use Illuminate\Support\Facades\Storage;

/**
 * The saved "Test" sample of a voice (Voices page). Rendering one costs a
 * provider call, so the last sample is kept and replayed on later visits.
 *
 * The risk in replaying is staleness: a sample rendered from an older
 * reference clip or older tuning would misrepresent what the voice sounds like
 * now. So each sample is stamped with a fingerprint of everything that shaped
 * it, and {@see isCurrent()} is the only gate to playing it back — once the
 * voice (or the global defaults a Test renders with) changes, the saved sample
 * simply stops being offered until the next Test replaces it.
 *
 * One sample per voice, shared by everyone who can see the voice: a Test
 * renders with the voice's own settings, not the viewer's.
 */
class VoicePreview
{
    /** The sentence a Test speaks. Part of the fingerprint — it names the voice. */
    public function text(Voice $voice): string
    {
        return "This is a preview of the {$voice->name} voice on my self hosted text to speech service.";
    }

    public function fingerprint(Voice $voice): string
    {
        return hash('sha256', json_encode([
            'voice' => $voice->id,
            // Every edit of the voice row (clip replaced, tuning saved,
            // renamed, engine changed) moves updated_at — the same signal the
            // clip-metadata cache keys on. store() deliberately does not.
            'updated_at' => $voice->updated_at?->getTimestamp(),
            'reference' => $voice->reference_audio_path,
            'settings' => $voice->settings,
            'model' => $voice->model,
            'provider' => $voice->provider,
            'text' => $this->text($voice),
            'defaults' => [
                config('tts.provider'),
                config('tts.default_voice_settings'),
                config('tts.default_model_id'),
                config('tts.default_output_format'),
            ],
        ]));
    }

    /** Whether the saved sample still represents the voice as it is now. */
    public function isCurrent(Voice $voice): bool
    {
        return $voice->preview_audio_path !== null
            && $voice->preview_fingerprint !== null
            && hash_equals($voice->preview_fingerprint, $this->fingerprint($voice));
    }

    /** Keep freshly rendered Test audio as the voice's sample, replacing any older one. */
    public function store(Voice $voice, string $bytes, string $mime): void
    {
        $disk = Storage::disk(config('tts.storage_disk'));
        $fingerprint = $this->fingerprint($voice);
        $ext = str_contains($mime, 'wav') ? 'wav' : 'mp3';
        $path = trim((string) config('tts.voice_preview_path', 'voice-previews'), '/')
            .'/'.$voice->id.'/'.$fingerprint.'.'.$ext;

        $previous = $voice->preview_audio_path;
        $disk->put($path, $bytes);

        // Without touching updated_at: the fingerprint includes it, so a
        // normal save here would invalidate the sample it is recording.
        $voice->timestamps = false;
        try {
            $voice->forceFill(['preview_audio_path' => $path, 'preview_fingerprint' => $fingerprint])->saveQuietly();
        } finally {
            $voice->timestamps = true;
        }

        if ($previous !== null && $previous !== $path) {
            $disk->delete($previous);
        }
    }

    /** Delete the saved sample's file (the voice row is the caller's to update or delete). */
    public function deleteFile(Voice $voice): void
    {
        if ($voice->preview_audio_path !== null) {
            Storage::disk(config('tts.storage_disk'))->delete($voice->preview_audio_path);
        }
    }
}
