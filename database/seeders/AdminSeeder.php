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
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command->warn('Skipping AdminSeeder: ADMIN_EMAIL / ADMIN_PASSWORD are not readable here.');
            $this->command->line('  → Recommended: create the admin with `php artisan admin:create <email>` — it prompts');
            $this->command->line('    for a password and does not depend on env() or cached config, so it works on production.');
            $this->command->line('  → If you DID set them in .env: config is probably cached (e.g. `artisan optimize` runs on');
            $this->command->line('    deploy), so env() returns null outside config files. Run `php artisan config:clear` first,');
            $this->command->line('    or just use admin:create above.');

            return;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => 'Admin', 'password' => Hash::make($password), 'is_super_admin' => true],
        );

        $this->command->info(($user->wasRecentlyCreated ? 'Created' : 'Updated')." admin: {$email}");
    }
}
