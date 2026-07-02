<?php

namespace App\Services;

use App\Models\Voice;
use App\Services\Audio\AudioConverter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

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
        ?float $stability = null,
        ?float $style = null,
        ?int $ownerId = null,
    ): Voice {
        $slug = $slug ?: Str::slug($name);

        // Re-registering YOUR OWN slug updates it, but a slug that exists under
        // another owner (or a shared built-in) must never be silently taken
        // over — that would replace someone else's voice reference.
        $existing = Voice::where('slug', $slug)->first();
        if ($existing && $existing->user_id !== $ownerId) {
            throw new RuntimeException("The voice_id '{$slug}' is already in use.");
        }

        $attributes = ['name' => $name, 'user_id' => $ownerId];

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

        $settings = array_filter(
            ['seed' => $seed, 'stability' => $stability, 'style' => $style],
            fn ($value) => $value !== null,
        );
        if ($settings !== []) {
            $attributes['settings'] = $settings;
        }

        return Voice::updateOrCreate(['slug' => $slug], $attributes);
    }

    /**
     * Update an existing voice — rename its voice_id (slug), name, default seed,
     * and optionally replace the reference clip. When the slug changes without a
     * replacement clip, the stored reference is moved to match the new slug.
     */
    public function update(
        Voice $voice,
        string $name,
        string $slug,
        ?string $audioBytes,
        ?string $ext,
        bool $normalize,
        ?int $seed,
        ?float $stability = null,
        ?float $style = null,
    ): Voice {
        $disk = Storage::disk(config('tts.storage_disk'));
        $referencePath = $voice->reference_audio_path;

        if ($audioBytes !== null) {
            $bytes = $audioBytes;
            $extension = strtolower($ext ?: 'wav');
            if ($normalize) {
                $bytes = $this->converter->normalizeReference($audioBytes);
                $extension = 'wav';
            }
            $newPath = config('tts.reference_path').'/'.$slug.'.'.$extension;
            $disk->put($newPath, $bytes);
            if ($referencePath && $referencePath !== $newPath) {
                $disk->delete($referencePath);
            }
            $referencePath = $newPath;
        } elseif ($referencePath && $slug !== $voice->slug) {
            $extension = strtolower(pathinfo($referencePath, PATHINFO_EXTENSION)) ?: 'wav';
            $newPath = config('tts.reference_path').'/'.$slug.'.'.$extension;
            if ($newPath !== $referencePath && $disk->exists($referencePath)) {
                $disk->move($referencePath, $newPath);
                $referencePath = $newPath;
            }
        }

        $settings = is_array($voice->settings) ? $voice->settings : [];
        foreach (['seed' => $seed, 'stability' => $stability, 'style' => $style] as $key => $value) {
            if ($value !== null) {
                $settings[$key] = $value;
            } else {
                unset($settings[$key]);
            }
        }

        $voice->update([
            'name' => $name,
            'slug' => $slug,
            'reference_audio_path' => $referencePath,
            'settings' => $settings ?: null,
        ]);

        return $voice;
    }

    /**
     * Persist just the native tuning (exaggeration/cfg_weight) onto a voice's
     * settings — the "save to voice defaults" action from the Studio tuning bench.
     * A null value clears that key (falling back to the system default); writing a
     * native knob drops its stale ElevenLabs twin so a voice never carries both
     * forms. Leaves the seed, reference clip, name and slug untouched.
     *
     * @param  array<string, float|null>  $override
     */
    public function saveTuning(Voice $voice, array $override): Voice
    {
        $twin = ['exaggeration' => 'style', 'cfg_weight' => 'stability'];
        $settings = is_array($voice->settings) ? $voice->settings : [];
        foreach ($override as $key => $value) {
            if ($value !== null) {
                $settings[$key] = $value;
                if (isset($twin[$key])) {
                    unset($settings[$twin[$key]]);
                }
            } else {
                unset($settings[$key]);
            }
        }

        $voice->update(['settings' => $settings ?: null]);

        return $voice;
    }

    /**
     * Export a voice to a portable .zip (a `voice.json` manifest + the reference
     * clip) that can be imported into another instance.
     */
    public function export(Voice $voice): string
    {
        $disk = Storage::disk(config('tts.storage_disk'));

        $audioBytes = null;
        $referenceName = null;
        if ($voice->reference_audio_path && $disk->exists($voice->reference_audio_path)) {
            $audioBytes = $disk->get($voice->reference_audio_path);
            $ext = strtolower(pathinfo($voice->reference_audio_path, PATHINFO_EXTENSION)) ?: 'wav';
            $referenceName = "reference.{$ext}";
        }

        $manifest = [
            'version' => 1,
            'slug' => $voice->slug,
            'name' => $voice->name,
            'settings' => $voice->settings,
            'reference' => $referenceName,
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'voice_').'.zip';

        try {
            $zip = new ZipArchive;
            if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the voice archive.');
            }
            $zip->addFromString('voice.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if ($audioBytes !== null) {
                $zip->addFromString($referenceName, $audioBytes);
            }
            $zip->close();

            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Import a voice from a .zip produced by export(). The reference is stored
     * as-is (it was already normalized on export). Returns the created/updated
     * voice; an existing voice with the same slug is overwritten.
     */
    public function import(string $zipBytes, ?int $ownerId = null): Voice
    {
        $tmp = tempnam(sys_get_temp_dir(), 'voice_').'.zip';
        file_put_contents($tmp, $zipBytes);

        try {
            $zip = new ZipArchive;
            if ($zip->open($tmp) !== true) {
                throw new RuntimeException('Not a valid voice archive.');
            }

            $manifestRaw = $zip->getFromName('voice.json');
            if ($manifestRaw === false) {
                throw new RuntimeException('Archive is missing voice.json.');
            }

            $manifest = json_decode($manifestRaw, true, 512, JSON_THROW_ON_ERROR);

            $slug = $manifest['slug'] ?? null;
            if ($slug !== null && ! preg_match('/^[A-Za-z0-9._-]+$/', (string) $slug)) {
                throw new RuntimeException('Archive contains an invalid voice_id.');
            }

            $audioBytes = null;
            $ext = null;
            if (! empty($manifest['reference'])) {
                $audioBytes = $zip->getFromName($manifest['reference']);
                if ($audioBytes === false) {
                    throw new RuntimeException('Archive is missing its reference audio.');
                }
                $ext = strtolower(pathinfo($manifest['reference'], PATHINFO_EXTENSION)) ?: 'wav';
            }

            $zip->close();

            $seed = isset($manifest['settings']['seed']) ? (int) $manifest['settings']['seed'] : null;

            return $this->register(
                name: $manifest['name'] ?? ($slug ?? 'Imported voice'),
                slug: $slug,
                audioBytes: $audioBytes,
                ext: $ext,
                normalize: false,
                seed: $seed,
                ownerId: $ownerId,
            );
        } finally {
            @unlink($tmp);
        }
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
