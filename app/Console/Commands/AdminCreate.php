<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class AdminCreate extends Command
{
    protected $signature = 'admin:create
                            {email : The admin email address}
                            {--password= : The admin password (prompted securely if omitted)}
                            {--name=Admin : Display name}';

    protected $description = 'Create or update the dashboard admin user';

    public function handle(): int
    {
        $email = $this->argument('email');
        $password = $this->option('password') ?: $this->secret('Password');

        if (! $password) {
            $this->error('A password is required.');

            return Command::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $this->option('name'), 'password' => Hash::make($password), 'is_super_admin' => true],
        );

        $this->info(($user->wasRecentlyCreated ? 'Created' : 'Updated')." admin: {$email}");

        return Command::SUCCESS;
    }
}
