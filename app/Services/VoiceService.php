<?php

namespace App\Services;

use App\Models\Voice;
use App\Services\Audio\AudioConverter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Registers and removes voices. Shared by the `voice:create` console command and
 * the dashboard so both go through one normalize/store path.
 */
class VoiceService
{
    public function __construct(
        private AudioConverter $converter,
    ) {}

    /**
     * Create or update a voice. When $audioBytes is provided it becomes the
     * reference clip (normalized unless $normalize is false). Re-registering
     * without new audio leaves the existing reference untouched.
     */
    public function register(
        string $name,
        ?string $slug,
        ?string $audioBytes,
        ?string $ext,
        bool $normalize,
        ?int $seed,
    ): Voice {
        $slug = $slug ?: Str::slug($name);

        $attributes = ['name' => $name];

        if ($audioBytes !== null) {
            $bytes = $audioBytes;
            $extension = strtolower($ext ?: 'wav');

            if ($normalize) {
                $bytes = $this->converter->normalizeReference($audioBytes);
                $extension = 'wav';
            }

            $referencePath = config('tts.reference_path').'/'.$slug.'.'.$extension;
            Storage::disk(config('tts.storage_disk'))->put($referencePath, $bytes);

            $attributes['reference_audio_path'] = $referencePath;
        }

        if ($seed !== null) {
            $attributes['settings'] = ['seed' => $seed];
        }

        return Voice::updateOrCreate(['slug' => $slug], $attributes);
    }

    /**
     * Delete a voice along with its reference clip and any cached audio.
     */
    public function delete(Voice $voice): void
    {
        $disk = Storage::disk(config('tts.storage_disk'));

        foreach ($voice->speeches()->pluck('audio_path')->filter() as $path) {
            $disk->delete($path);
        }
        $voice->speeches()->delete();

        if ($voice->reference_audio_path) {
            $disk->delete($voice->reference_audio_path);
        }

        $voice->delete();
    }
}
