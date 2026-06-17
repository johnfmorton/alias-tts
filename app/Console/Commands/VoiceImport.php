<?php

namespace App\Console\Commands;

use App\Services\VoiceService;
use Illuminate\Console\Command;
use Throwable;

class VoiceImport extends Command
{
    protected $signature = 'voice:import {file : Path to a .zip created with voice:export}';

    protected $description = 'Import a voice from a .zip (overwrites a voice with the same voice_id)';

    public function handle(VoiceService $voices): int
    {
        $file = $this->argument('file');

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return Command::FAILURE;
        }

        try {
            $voice = $voices->import((string) file_get_contents($file));
        } catch (Throwable $e) {
            $this->error('Import failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        $this->info("Imported voice '{$voice->slug}'.");

        return Command::SUCCESS;
    }
}
