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
            $this->command->warn('Skipping AdminSeeder: set ADMIN_EMAIL and ADMIN_PASSWORD in .env (or run `php artisan admin:create`).');

            return;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => 'Admin', 'password' => Hash::make($password), 'is_super_admin' => true],
        );

        $this->command->info(($user->wasRecentlyCreated ? 'Created' : 'Updated')." admin: {$email}");
    }
}
