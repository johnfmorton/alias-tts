<?php

namespace App\Services;

use App\Models\Voice;
use App\Services\Asr\AsrClient;
use App\Services\Audio\AudioConverter;
use App\Services\Tts\ModelCatalog;
use App\Services\Tts\Qwen3TtsTuning;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
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
        ?string $model = null,
        ?string $presetVoice = null,
    ): Voice {
        $slug = $slug ?: Str::slug($name);
        $modelChosen = ModelCatalog::isKnown($model);

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

        // The engine this voice will generate with: the caller's choice, or —
        // on a re-register that never mentioned a model — whatever it already
        // ran on. The default engine stores as NULL, the shape every
        // pre-catalog row already has.
        $engine = $modelChosen ? $model : ModelCatalog::forVoice($existing);
        $presetVoice = in_array($presetVoice, ModelCatalog::presetVoices($engine), true)
            ? $presetVoice
            : ($existing->settings['preset_voice'] ?? null);

        $attributes = ['name' => $name];

        if ($modelChosen) {
            $attributes['model'] = $engine === ModelCatalog::DEFAULT ? null : $engine;
            $attributes['provider'] = $attributes['model'] === null ? null : 'replicate';
        }

        if ($audioBytes !== null) {
            // Cap length FIRST (a pause-aware one-time trim), then normalize,
            // so loudness is measured on the content that actually ships.
            [$bytes, $extension] = $this->capReferenceLength($audioBytes, strtolower($ext ?: 'wav'));

            if ($normalize) {
                $bytes = $this->converter->normalizeReference($bytes);
                $extension = 'wav';
            }

            $this->assertReferenceLongEnough($engine, $bytes);

            $referencePath = $this->referencePath($ownerId, $slug, $extension);
            Storage::disk(config('tts.storage_disk'))->put($referencePath, $bytes);

            $attributes['reference_audio_path'] = $referencePath;
        } elseif ($modelChosen) {
            // Switching an existing voice's engine must re-check its stored clip.
            $this->assertStoredReferenceLongEnough($engine, $existing);
        }

        $this->assertVoiceHasASource(
            $engine,
            hasClip: $audioBytes !== null || (bool) $existing?->reference_audio_path,
            presetVoice: $presetVoice,
        );

        $settings = array_filter(
            ['seed' => $seed, 'stability' => $stability, 'style' => $style, 'preset_voice' => $presetVoice],
            fn ($value) => $value !== null,
        );
        if ($audioBytes !== null) {
            $settings = $this->maybeTranscribeReference($engine, $bytes, $extension, $settings);
        }
        if ($settings !== []) {
            $attributes['settings'] = $settings;
        }

        return Voice::updateOrCreate(['slug' => $slug, 'user_id' => $ownerId], $attributes);
    }

    /**
     * Some engines refuse short reference clips (Chatterbox Turbo needs > 5s).
     * Measured from the WAV header when possible; unmeasurable containers are
     * let through — the provider will surface the model's own error.
     */
    private function assertReferenceLongEnough(string $engine, string $bytes): void
    {
        $min = ModelCatalog::minReferenceSeconds($engine);
        if ($min <= 0) {
            return;
        }

        $duration = $this->converter->wavDurationSeconds($bytes);
        if ($duration !== null && $duration <= $min) {
            throw new RuntimeException(sprintf(
                '%s needs a reference clip longer than %d seconds (this one is %.1fs) — record or upload a longer one, or pick a built-in %1$s voice instead.',
                ModelCatalog::label($engine),
                (int) $min,
                $duration,
            ));
        }
    }

    private function assertStoredReferenceLongEnough(string $engine, ?Voice $voice): void
    {
        if ($voice?->reference_audio_path && ModelCatalog::minReferenceSeconds($engine) > 0) {
            $disk = Storage::disk(config('tts.storage_disk'));
            if ($disk->exists($voice->reference_audio_path)) {
                $this->assertReferenceLongEnough($engine, (string) $disk->get($voice->reference_audio_path));
            }
        }
    }

    /**
     * Cap an incoming reference clip at `tts.reference_max_seconds` — the
     * whole stored clip ships with every chunk render, yet the engines only
     * read its head, so extra length is pure per-render payload. Trimming is
     * pause-aware ({@see AudioConverter::trimReference}) and degrade-safe: an
     * unparseable/undecodable clip is kept as-is. Within-cap clips keep their
     * original bytes AND container; an over-long non-WAV upload comes back as
     * WAV (length matters more than container, and only over-long clips are
     * ever touched).
     *
     * @return array{0: string, 1: string} [bytes, extension]
     */
    private function capReferenceLength(string $bytes, string $extension): array
    {
        $max = (float) config('tts.reference_max_seconds', 25);
        if ($max <= 0) {
            return [$bytes, $extension];
        }

        try {
            $wav = strncmp($bytes, 'RIFF', 4) === 0 ? $bytes : $this->converter->decodeToWav($bytes);
            $trimmed = $this->converter->trimReference($wav, $max);
        } catch (Throwable $e) {
            Log::info('Reference length cap skipped', ['error' => $e->getMessage()]);

            return [$bytes, $extension];
        }

        return $trimmed === null ? [$bytes, $extension] : [$trimmed, 'wav'];
    }

    /**
     * Best-effort transcript for a freshly stored reference clip, via the
     * Whisper sidecar — only for engines whose clone mode reads along
     * (qwen's reference_text), only when no transcript is set, and never
     * blocking the save: any failure just leaves the field empty for the
     * user to fill by hand on the edit page.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function maybeTranscribeReference(string $engine, string $clipBytes, string $extension, array $settings): array
    {
        if (! ModelCatalog::acceptsReferenceText($engine)
            || trim((string) ($settings['reference_text'] ?? '')) !== '') {
            return $settings;
        }

        $asr = app(AsrClient::class);
        if (! $asr->enabled()) {
            return $settings;
        }

        try {
            $wav = $extension === 'wav' ? $clipBytes : $this->converter->decodeToWav($clipBytes);
            $text = trim((string) ($asr->transcribe($wav, 'reference.wav')['text'] ?? ''));
            if ($text !== '') {
                $settings['reference_text'] = mb_substr($text, 0, 2000);
            }
        } catch (Throwable $e) {
            Log::info('Reference-clip auto-transcription skipped', ['error' => $e->getMessage()]);
        }

        return $settings;
    }

    /** A voice must be able to speak: a reference clip, or a built-in preset. */
    private function assertVoiceHasASource(string $engine, bool $hasClip, ?string $presetVoice): void
    {
        if ($hasClip || $presetVoice !== null || ModelCatalog::presetVoices($engine) === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'A %s voice needs a reference clip or one of its built-in voices — upload/record a clip, or pick a built-in.',
            ModelCatalog::label($engine),
        ));
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
        ?string $model = null,
        ?string $presetVoice = null,
        ?float $topP = null,
        ?int $topK = null,
        ?float $repetitionPenalty = null,
        ?string $language = null,
        ?string $styleInstruction = null,
        ?string $referenceText = null,
        bool $removeClip = false,
    ): Voice {
        $disk = Storage::disk(config('tts.storage_disk'));
        $referencePath = $voice->reference_audio_path;

        // The engine this voice will generate with after the save (the form
        // always submits one; a null keeps the current engine for callers that
        // predate the catalog).
        $modelChosen = ModelCatalog::isKnown($model);
        $engine = $modelChosen ? $model : ModelCatalog::forVoice($voice);
        $presetVoice = in_array($presetVoice, ModelCatalog::presetVoices($engine), true) ? $presetVoice : null;

        if ($audioBytes !== null) {
            // Cap length first, then normalize — see register().
            [$bytes, $extension] = $this->capReferenceLength($audioBytes, strtolower($ext ?: 'wav'));
            if ($normalize) {
                $bytes = $this->converter->normalizeReference($bytes);
                $extension = 'wav';
            }
            $this->assertReferenceLongEnough($engine, $bytes);
            $newPath = $this->referencePath($voice->user_id, $slug, $extension);
            $disk->put($newPath, $bytes);
            if ($referencePath && $referencePath !== $newPath) {
                $disk->delete($referencePath);
            }
            $referencePath = $newPath;
        } elseif ($removeClip) {
            // Deliberate removal: the voice falls back to a built-in, or to the
            // engine's own generic voice for engines that ship none. The stored
            // object is deleted only once the row is safely updated (below) —
            // assertVoiceHasASource() can still reject this save, and dropping
            // the bytes first would leave the voice pointing at nothing.
            $clipToDelete = $referencePath;
            $referencePath = null;
        } elseif ($modelChosen && $engine !== ModelCatalog::forVoice($voice)) {
            // Switching engines without a new clip re-checks the stored one.
            $this->assertStoredReferenceLongEnough($engine, $voice);
        }

        if ($audioBytes === null && $referencePath && $slug !== $voice->slug) {
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

        // Turbo's sampling knobs — same null-clears semantics, no EL twins.
        foreach (['top_p' => $topP, 'top_k' => $topK, 'repetition_penalty' => $repetitionPenalty] as $key => $value) {
            if ($value !== null) {
                $settings[$key] = $value;
            } else {
                unset($settings[$key]);
            }
        }

        // Qwen's string knobs — null-clears like every other knob. `auto` is
        // the language default, so it stores as a clear too.
        $language = $language !== null ? Qwen3TtsTuning::clampLanguage($language) : null;
        if ($language !== null && $language !== Qwen3TtsTuning::LANGUAGE_DEFAULT) {
            $settings['language'] = $language;
        } else {
            unset($settings['language']);
        }
        $styleInstruction = Qwen3TtsTuning::cleanStyleInstruction($styleInstruction);
        if ($styleInstruction !== null) {
            $settings['style_instruction'] = $styleInstruction;
        } else {
            unset($settings['style_instruction']);
        }

        // The reference clip's transcript (qwen's voice_clone rides it along
        // for better fidelity — see ModelCatalog::stamp). Not a resolver knob:
        // it describes the CLIP, not the delivery.
        $referenceText = trim((string) $referenceText);
        if ($referenceText !== '') {
            $settings['reference_text'] = mb_substr($referenceText, 0, 2000);
        } else {
            unset($settings['reference_text']);
        }

        // The built-in voice a clip-less voice speaks through, for engines
        // that ship them. An engine without presets always clears it.
        if ($presetVoice !== null) {
            $settings['preset_voice'] = $presetVoice;
        } else {
            unset($settings['preset_voice']);
        }

        // A NEW clip with no transcript typed → best-effort ASR auto-fill
        // (deliberately after the form's own reference_text landed above, so a
        // typed transcript always wins).
        if ($audioBytes !== null) {
            $settings = $this->maybeTranscribeReference($engine, $bytes, $extension, $settings);
        }

        // The transcript describes the clip, so REMOVING the clip takes it along —
        // leaving it would hand qwen a description of audio that isn't there.
        // Scoped to an actual removal: a preset-only voice that never had a clip
        // may still carry a transcript for a clip added later.
        if ($removeClip && $audioBytes === null) {
            unset($settings['reference_text']);
        }

        $this->assertVoiceHasASource($engine, hasClip: (bool) $referencePath, presetVoice: $presetVoice);

        $columns = [
            'name' => $name,
            'slug' => $slug,
            'reference_audio_path' => $referencePath,
            'settings' => $settings ?: null,
        ];

        if ($modelChosen) {
            $columns['model'] = $engine === ModelCatalog::DEFAULT ? null : $engine;
            $columns['provider'] = $columns['model'] === null ? null : 'replicate';
        }

        $voice->update($columns);

        if (isset($clipToDelete) && $clipToDelete) {
            $disk->delete($clipToDelete);
        }

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
        $slug = $this->availableSlug($source->slug.'-copy', $ownerId);

        $attributes = [
            'name' => $source->name.' copy',
            'user_id' => $ownerId,
            'settings' => $source->settings,
            'provider' => $source->provider,
            'model' => $source->model,
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
     * Copy another user's voice into $ownerId's account VERBATIM — same
     * voice_id (slug), name, tuning and a byte-copy of the reference clip — so
     * a duplicated project keeps generating with the exact voice it was made
     * with. Unlike {@see duplicate()} (a user's explicit "give me a tunable
     * copy", which gets "-copy" naming), this preserves the voice's identity;
     * only a collision with a voice_id the new owner can already reach forces
     * a "-2" suffix, mirrored into the name so pickers never show two
     * indistinguishable entries.
     */
    public function cloneTo(Voice $source, int $ownerId): Voice
    {
        $slug = $this->availableSlug($source->slug, $ownerId);

        $name = $source->name;
        if ($slug !== $source->slug) {
            $name .= ' '.substr($slug, strlen($source->slug) + 1);
        }

        $attributes = [
            'name' => $name,
            'user_id' => $ownerId,
            'settings' => $source->settings,
            'provider' => $source->provider,
            'model' => $source->model,
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
     * A voice $ownerId can already reach that would SOUND identical to
     * $source: same provider, model and tuning, and a byte-identical
     * reference clip (or both clipless). Project duplication points the copy
     * at such a voice instead of {@see cloneTo()}-minting a redundant "-2"
     * clone — the common case being the same recording registered in both
     * accounts. Prefers a same-slug match when several qualify. A clip that
     * is missing from the disk can't be verified, so it never matches.
     */
    public function equivalentFor(Voice $source, int $ownerId): ?Voice
    {
        $disk = Storage::disk(config('tts.storage_disk'));

        $sourceSha = null;
        if ($source->reference_audio_path !== null) {
            if (! $disk->exists($source->reference_audio_path)) {
                return null;
            }
            $sourceSha = sha1($disk->get($source->reference_audio_path));
        }

        $candidates = Voice::visibleTo($ownerId)
            ->whereKeyNot($source->id)
            ->where('provider', $source->provider)
            ->where('model', $source->model)
            ->get()
            ->sortBy(fn (Voice $v) => $v->slug === $source->slug ? 0 : 1);

        foreach ($candidates as $candidate) {
            // Loose array comparison: same key/value pairs, key order aside.
            if (($candidate->settings ?? []) != ($source->settings ?? [])) {
                continue;
            }

            if ($sourceSha === null) {
                if ($candidate->reference_audio_path === null) {
                    return $candidate;
                }

                continue;
            }

            if ($candidate->reference_audio_path !== null
                && $disk->exists($candidate->reference_audio_path)
                && sha1($disk->get($candidate->reference_audio_path)) === $sourceSha) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The first free slug starting from $base ("$base", then "$base-2", …).
     * Collisions only matter inside the owner's reachable set (their own
     * voices + the shared ones) — another user's identical slug doesn't block
     * ours, because slugs are only unique per owner.
     */
    private function availableSlug(string $base, int $ownerId): string
    {
        $taken = fn (string $candidate) => Voice::where('slug', $candidate)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $ownerId))
            ->exists();

        $slug = $base;
        for ($i = 2; $taken($slug); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
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
            'model' => $voice->model,
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
                model: isset($manifest['model']) ? (string) $manifest['model'] : null,
                presetVoice: isset($manifest['settings']['preset_voice']) ? (string) $manifest['settings']['preset_voice'] : null,
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
