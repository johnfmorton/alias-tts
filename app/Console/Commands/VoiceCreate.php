<?php

namespace App\Console\Commands;

use App\Models\Voice;
use App\Services\Audio\AudioConverter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class VoiceCreate extends Command
{
    protected $signature = 'voice:create
                            {name : Display name for the voice}
                            {audio? : Path to a clean reference audio clip (~15-30s)}
                            {--slug= : Public voice_id used in the URL (defaults to a slug of the name)}
                            {--raw : Store the reference as-is, skipping auto-normalization}
                            {--seed= : Default generation seed for this voice (pins reproducible output)}';

    protected $description = 'Register a voice (and store its reference clip for zero-shot cloning)';

    public function handle(AudioConverter $converter): int
    {
        $name = $this->argument('name');
        $slug = $this->option('slug') ?: Str::slug($name);
        $audio = $this->argument('audio');

        $referencePath = null;
        $normalized = false;

        if ($audio) {
            if (! is_file($audio)) {
                $this->error("Audio file not found: {$audio}");

                return Command::FAILURE;
            }

            $disk = config('tts.storage_disk');
            $bytes = (string) file_get_contents($audio);
            $ext = strtolower(pathinfo($audio, PATHINFO_EXTENSION)) ?: 'wav';

            if (config('tts.normalize_reference') && ! $this->option('raw')) {
                try {
                    $bytes = $converter->normalizeReference($bytes);
                    $ext = 'wav';
                    $normalized = true;
                } catch (Throwable $e) {
                    $this->warn('Normalization failed; storing the clip as-is: '.$e->getMessage());
                }
            }

            $referencePath = config('tts.reference_path').'/'.$slug.'.'.$ext;
            Storage::disk($disk)->put($referencePath, $bytes);
        }

        $attributes = ['name' => $name, 'reference_audio_path' => $referencePath];
        if ($this->option('seed') !== null) {
            $attributes['settings'] = ['seed' => (int) $this->option('seed')];
        }

        $voice = Voice::updateOrCreate(['slug' => $slug], $attributes);

        $this->info('Voice saved.');
        $this->newLine();
        $this->table(['Field', 'Value'], [
            ['ID', $voice->id],
            ['Name', $voice->name],
            ['voice_id (slug)', $voice->slug],
            ['Reference', $voice->reference_audio_path ?: '(none — set one for cloning)'],
            ['Normalized', $audio ? ($normalized ? 'Yes (mono, loudness-normalized, peak-limited)' : 'No (stored raw)') : 'n/a'],
            ['Default seed', $voice->settings['seed'] ?? '(random)'],
        ]);
        $this->newLine();
        $this->info("Set this voice_id ({$voice->slug}) in your client to use it.");

        return Command::SUCCESS;
    }
}
