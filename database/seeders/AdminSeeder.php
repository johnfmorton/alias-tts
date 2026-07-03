<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Create (or update) the dashboard admin from ADMIN_EMAIL / ADMIN_PASSWORD.
     * Idempotent and safe to run on every deploy.
     */
    public function run(): void
    {
        // Config-first (tts.admin.* bakes ADMIN_* into the cached config at
        // deploy time); env() remains a fallback for a stale cached config that
        // predates the tts.admin block.
        $email = config('tts.admin.email') ?: env('ADMIN_EMAIL');
        $password = config('tts.admin.password') ?: env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command->warn('Skipping AdminSeeder: ADMIN_EMAIL / ADMIN_PASSWORD are not set.');
            $this->command->line('  → Set them in .env (then `php artisan config:clear` if config is cached), or just run');
            $this->command->line('    `php artisan admin:create` — it reads the same variables and prompts for anything missing.');

            return;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => 'Admin', 'password' => Hash::make($password), 'is_super_admin' => true],
        );

        $this->command->info(($user->wasRecentlyCreated ? 'Created' : 'Updated')." admin: {$email}");
    }
}
