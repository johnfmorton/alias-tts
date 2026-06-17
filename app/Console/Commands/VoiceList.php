<?php

namespace App\Console\Commands;

use App\Models\Voice;
use Illuminate\Console\Command;

class VoiceList extends Command
{
    protected $signature = 'voice:list';

    protected $description = 'List all registered voices';

    public function handle(): int
    {
        $voices = Voice::all();

        if ($voices->isEmpty()) {
            $this->info('No voices found. Create one with voice:create.');

            return Command::SUCCESS;
        }

        $this->table(
            ['ID', 'voice_id (slug)', 'Name', 'Reference', 'Created'],
            $voices->map(fn (Voice $voice) => [
                $voice->id,
                $voice->slug,
                $voice->name,
                $voice->reference_audio_path ? 'Yes' : 'No',
                $voice->created_at->format('Y-m-d H:i'),
            ])
        );

        return Command::SUCCESS;
    }
}
