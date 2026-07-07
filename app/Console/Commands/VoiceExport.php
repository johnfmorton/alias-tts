<?php

namespace App\Console\Commands;

use App\Models\Voice;
use App\Services\VoiceService;
use Illuminate\Console\Command;

class VoiceExport extends Command
{
    protected $signature = 'voice:export
                            {slug : The voice_id (slug) or ID to export}
                            {--output= : Path to write the .zip (default: ./<slug>.alias-voice.zip)}';

    protected $description = 'Export a voice (manifest + reference clip) to a portable .zip';

    public function handle(VoiceService $voices): int
    {
        // Slugs are only unique per owner, so the same voice_id can exist for
        // several users — when it does, the caller must pick a row by ID.
        $matches = Voice::with('user')->where('slug', $this->argument('slug'))->get();
        if ($matches->isEmpty()) {
            $matches = Voice::with('user')->whereKey($this->argument('slug'))->get();
        }

        if ($matches->count() > 1) {
            $this->error("Several users own a voice with voice_id '{$this->argument('slug')}' — re-run with one of these IDs:");
            $this->table(['ID', 'Owner'], $matches->map(fn (Voice $v) => [
                $v->id, $v->user?->email ?? 'shared',
            ])->all());

            return Command::FAILURE;
        }

        $voice = $matches->first();

        if (! $voice) {
            $this->error("No voice found with voice_id '{$this->argument('slug')}'.");

            return Command::FAILURE;
        }

        $output = $this->option('output') ?: getcwd().'/'.$voice->slug.'.alias-voice.zip';
        file_put_contents($output, $voices->export($voice));

        $this->info("Exported '{$voice->slug}' → {$output}");

        return Command::SUCCESS;
    }
}
