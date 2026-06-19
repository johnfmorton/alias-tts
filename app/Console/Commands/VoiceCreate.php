<?php

namespace App\Console\Commands;

use App\Services\VoiceService;
use Illuminate\Console\Command;

class VoiceCreate extends Command
{
    protected $signature = 'voice:create
                            {name : Display name for the voice}
                            {audio? : Path to a clean reference audio clip (~15-30s)}
                            {--slug= : Public voice_id used in the URL (defaults to a slug of the name)}
                            {--raw : Store the reference as-is, skipping auto-normalization}
                            {--seed= : Default generation seed for this voice (pins reproducible output)}
                            {--stability= : Default stability 0-1 (higher = steadier pacing)}
                            {--style= : Default style 0-1 (higher = more animated delivery)}';

    protected $description = 'Register a voice (and store its reference clip for zero-shot cloning)';

    public function handle(VoiceService $voices): int
    {
        $audio = $this->argument('audio');
        $seed = $this->option('seed') !== null ? (int) $this->option('seed') : null;
        $stability = $this->option('stability') !== null ? (float) $this->option('stability') : null;
        $style = $this->option('style') !== null ? (float) $this->option('style') : null;

        $bytes = null;
        $ext = null;
        $normalized = false;

        if ($audio) {
            if (! is_file($audio)) {
                $this->error("Audio file not found: {$audio}");

                return Command::FAILURE;
            }

            $bytes = (string) file_get_contents($audio);
            $ext = strtolower(pathinfo($audio, PATHINFO_EXTENSION)) ?: 'wav';
            $normalized = (bool) config('tts.normalize_reference') && ! $this->option('raw');
        }

        $voice = $voices->register(
            name: $this->argument('name'),
            slug: $this->option('slug') ?: null,
            audioBytes: $bytes,
            ext: $ext,
            normalize: $normalized,
            seed: $seed,
            stability: $stability,
            style: $style,
        );

        $this->info('Voice saved.');
        $this->newLine();
        $this->table(['Field', 'Value'], [
            ['ID', $voice->id],
            ['Name', $voice->name],
            ['voice_id (slug)', $voice->slug],
            ['Reference', $voice->reference_audio_path ?: '(none — set one for cloning)'],
            ['Normalized', $audio ? ($normalized ? 'Yes (mono, loudness-normalized, peak-limited)' : 'No (stored raw)') : 'n/a'],
            ['Default seed', $voice->settings['seed'] ?? '(random)'],
            ['Default stability', $voice->settings['stability'] ?? '(system default)'],
            ['Default style', $voice->settings['style'] ?? '(system default)'],
        ]);
        $this->newLine();
        $this->info("Set this voice_id ({$voice->slug}) in your client to use it.");

        return Command::SUCCESS;
    }
}
