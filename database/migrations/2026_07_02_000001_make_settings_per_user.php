<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Settings become per-user: the old global `settings` table let any
        // signed-in user rewrite instance-wide behavior for everyone (the panel
        // has been open to all active users since 0.19.0). Each user now owns
        // their own overrides; .env pins stay instance-wide and read-only.
        Schema::create('user_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });

        // The old global rows are a mix of choices made by whoever last saved
        // the page. SuperAdmins inherit them (closest to "the operator's current
        // instance"); regular users reset to the .env/config defaults — the same
        // starting point every future user gets.
        $now = now();
        $superAdminIds = DB::table('users')->where('is_super_admin', true)->pluck('id');

        foreach (DB::table('settings')->get() as $row) {
            foreach ($superAdminIds as $userId) {
                DB::table('user_settings')->insert([
                    'user_id' => $userId,
                    'key' => $row->key,
                    'value' => $row->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Schema::dropIfExists('settings');
    }

    public function down(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // Best-effort restore: collapse per-user rows back to one global set,
        // preferring the lowest-id SuperAdmin's values.
        $rows = DB::table('user_settings')
            ->join('users', 'users.id', '=', 'user_settings.user_id')
            ->where('users.is_super_admin', true)
            ->orderBy('user_settings.user_id')
            ->get(['user_settings.key', 'user_settings.value']);

        foreach ($rows as $row) {
            DB::table('settings')->insertOrIgnore([
                'key' => $row->key,
                'value' => $row->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::dropIfExists('user_settings');
    }
};
