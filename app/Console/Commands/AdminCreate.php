<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Create or update the dashboard admin. Designed so the BARE command works on a
 * fresh install that followed .env.example: the email and password fall back to
 * ADMIN_EMAIL / ADMIN_PASSWORD (read via config, so a cached production config
 * still resolves them), and anything still missing is prompted for when the
 * terminal is interactive.
 */
class AdminCreate extends Command
{
    protected $signature = 'admin:create
                            {email? : The admin email address (defaults to ADMIN_EMAIL from .env)}
                            {--password= : The admin password (defaults to ADMIN_PASSWORD, else prompted securely)}
                            {--name=Admin : Display name}';

    protected $description = 'Create or update the dashboard admin user (reads ADMIN_EMAIL / ADMIN_PASSWORD when arguments are omitted)';

    public function handle(): int
    {
        $email = (string) ($this->argument('email') ?: config('tts.admin.email') ?: '');

        if ($email === '' && $this->input->isInteractive()) {
            $email = (string) $this->ask('Admin email');
        }

        if ($email === '') {
            $this->error('No email given — pass one (`admin:create you@example.com`) or set ADMIN_EMAIL in .env.');

            return Command::FAILURE;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("\"{$email}\" is not a valid email address.");

            return Command::FAILURE;
        }

        $password = (string) ($this->option('password') ?: config('tts.admin.password') ?: '');

        if ($password === '' && $this->input->isInteractive()) {
            $password = (string) $this->secret('Password');
        }

        if ($password === '') {
            $this->error('No password given — use --password=..., set ADMIN_PASSWORD in .env, or run interactively.');

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
