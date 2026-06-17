<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

class ApiKeyList extends Command
{
    protected $signature = 'apikey:list';

    protected $description = 'List all API keys';

    public function handle(): int
    {
        $apiKeys = ApiKey::all();

        if ($apiKeys->isEmpty()) {
            $this->info('No API keys found.');

            return Command::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Key', 'Active', 'Rate Limit', 'Speeches', 'Created'],
            $apiKeys->map(fn (ApiKey $key) => [
                $key->id,
                $key->name,
                $key->key,
                $key->is_active ? 'Yes' : 'No',
                $key->rate_limit ?? 'None',
                $key->speeches()->count(),
                $key->created_at->format('Y-m-d H:i'),
            ])
        );

        return Command::SUCCESS;
    }
}
