<?php

namespace App\Console\Commands;

use App\Models\Voice;
use App\Services\VoiceService;
use Illuminate\Console\Command;

class VoiceExport extends Command
{
    protected $signature = 'voice:export
                            {slug : The voice_id (slug) to export}
                            {--output= : Path to write the .zip (default: ./<slug>.bespoken-voice.zip)}';

    protected $description = 'Export a voice (manifest + reference clip) to a portable .zip';

    public function handle(VoiceService $voices): int
    {
        $voice = Voice::where('slug', $this->argument('slug'))->first();

        if (! $voice) {
            $this->error("No voice found with voice_id '{$this->argument('slug')}'.");

            return Command::FAILURE;
        }

        $output = $this->option('output') ?: getcwd().'/'.$voice->slug.'.bespoken-voice.zip';
        file_put_contents($output, $voices->export($voice));

        $this->info("Exported '{$voice->slug}' → {$output}");

        return Command::SUCCESS;
    }
}
