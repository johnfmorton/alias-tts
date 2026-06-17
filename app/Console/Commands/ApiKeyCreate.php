<?php

namespace App\Console\Commands;

use App\Models\ApiKey;
use Illuminate\Console\Command;

class ApiKeyCreate extends Command
{
    protected $signature = 'apikey:create
                            {name : The name for the API key}
                            {--rate-limit= : Optional rate limit (requests per hour)}';

    protected $description = 'Create a new API key';

    public function handle(): int
    {
        $rateLimit = $this->option('rate-limit');

        $apiKey = ApiKey::generate($this->argument('name'), $rateLimit ? (int) $rateLimit : null);

        $this->info('API key created successfully!');
        $this->newLine();
        $this->table(['Field', 'Value'], [
            ['ID', $apiKey->id],
            ['Name', $apiKey->name],
            ['Key', $apiKey->key],
            ['Rate Limit', $apiKey->rate_limit ?? 'None'],
            ['Active', $apiKey->is_active ? 'Yes' : 'No'],
        ]);
        $this->newLine();
        $this->info('Use this key in the xi-api-key header. View keys anytime with apikey:list.');

        return Command::SUCCESS;
    }
}
