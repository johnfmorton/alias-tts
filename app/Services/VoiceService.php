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

        // A voice_id only has to be unique among the voices its owner can
        // reach — their own plus the shared built-ins — because resolveFor()
        // looks up slugs inside exactly that union. Re-registering YOUR OWN
        // slug updates it; a slug held by a shared voice is refused (that
        // would take over a built-in). A shared voice (null owner) appears in
        // EVERY user's union, so creating one conflicts with any owner's slug.
        $scope = Voice::where('slug', $slug);
        if ($ownerId !== null) {
            $scope->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $ownerId));
        }
        $existing = $scope->first();
        if ($existing && $existing->user_id !== $ownerId) {
            throw new RuntimeException("The voice_id '{$slug}' is already in use.");
        }

        $attributes = ['name' => $name];

        if ($audioBytes !== null) {
            $bytes = $audioBytes;
            $extension = strtolower($ext ?: 'wav');

            if ($normalize) {
                $bytes = $this->converter->normalizeReference($audioBytes);
                $extension = 'wav';
            }

            $referencePath = $this->referencePath($ownerId, $slug, $extension);
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

        return Voice::updateOrCreate(['slug' => $slug, 'user_id' => $ownerId], $attributes);
    }

    /**
     * Where a voice's reference clip is stored. Owned voices are namespaced
     * per owner (u{id}/) so two users' identically-named voice_ids never share
     * a clip file; shared voices keep the flat path — it is the canonical
     * location the built-in self-heal expects (see VoiceReference).
     */
    private function referencePath(?int $ownerId, string $slug, string $extension): string
    {
        $dir = config('tts.reference_path');

        return $ownerId === null
            ? "{$dir}/{$slug}.{$extension}"
            : "{$dir}/u{$ownerId}/{$slug}.{$extension}";
    }

    /**
     * Update an existing voice — rename its voice_id (slug), name, default seed
     * and tuning, and optionally replace the reference clip. When the slug changes
     * without a replacement clip, the stored reference is moved to match the new
     * slug. Tuning is written in Chatterbox's native form (exaggeration /
     * cfg_weight); saving through here drops any legacy ElevenLabs-style twin so
     * a cleared knob can't resurface through the old key.
     */
    public function update(
        Voice $voice,
        string $name,
        string $slug,
        ?string $audioBytes,
        ?string $ext,
        bool $normalize,
        ?int $seed,
        ?float $exaggeration = null,
        ?float $cfgWeight = null,
        ?float $temperature = null,
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
            $newPath = $this->referencePath($voice->user_id, $slug, $extension);
            $disk->put($newPath, $bytes);
            if ($referencePath && $referencePath !== $newPath) {
                $disk->delete($referencePath);
            }
            $referencePath = $newPath;
        } elseif ($referencePath && $slug !== $voice->slug) {
            $extension = strtolower(pathinfo($referencePath, PATHINFO_EXTENSION)) ?: 'wav';
            $newPath = $this->referencePath($voice->user_id, $slug, $extension);
            if ($newPath !== $referencePath && $disk->exists($referencePath)) {
                $disk->move($referencePath, $newPath);
                $referencePath = $newPath;
            }
        }

        $settings = is_array($voice->settings) ? $voice->settings : [];
        if ($seed !== null) {
            $settings['seed'] = $seed;
        } else {
            unset($settings['seed']);
        }

        $twin = ['exaggeration' => 'style', 'cfg_weight' => 'stability'];
        foreach (['exaggeration' => $exaggeration, 'cfg_weight' => $cfgWeight] as $key => $value) {
            if ($value !== null) {
                $settings[$key] = $value;
            } else {
                unset($settings[$key]);
            }
            unset($settings[$twin[$key]]);
        }

        // Temperature is native-only (no ElevenLabs twin to drop).
        if ($temperature !== null) {
            $settings['temperature'] = $temperature;
        } else {
            unset($settings['temperature']);
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
     * Clone a voice into one the given user owns: same reference clip (copied
     * byte-for-byte, no re-normalization) and same settings (tuning + seed), under
     * a fresh slug. This is how a user gets a tunable copy of a shared built-in —
     * the shared row stays untouched for everyone else.
     */
    public function duplicate(Voice $source, int $ownerId): Voice
    {
        $base = $source->slug.'-copy';
        $slug = $base;
        // Collisions only matter inside the owner's reachable set (their own
        // voices + the shared ones) — another user's "-copy" doesn't block ours.
        $taken = fn (string $candidate) => Voice::where('slug', $candidate)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $ownerId))
            ->exists();
        for ($i = 2; $taken($slug); $i++) {
            $slug = $base.'-'.$i;
        }

        $attributes = [
            'name' => $source->name.' copy',
            'user_id' => $ownerId,
            'settings' => $source->settings,
        ];

        $disk = Storage::disk(config('tts.storage_disk'));
        if ($source->reference_audio_path && $disk->exists($source->reference_audio_path)) {
            $extension = strtolower(pathinfo($source->reference_audio_path, PATHINFO_EXTENSION)) ?: 'wav';
            $path = $this->referencePath($ownerId, $slug, $extension);
            $disk->copy($source->reference_audio_path, $path);
            $attributes['reference_audio_path'] = $path;
        }

        return Voice::create(['slug' => $slug] + $attributes);
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
     * voice; the owner's own voice with the same slug is overwritten, while the
     * same slug under a DIFFERENT owner imports cleanly as a separate voice —
     * only a shared built-in's slug conflicts.
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
