<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * API keys are per-user, but early single-admin installs created keys with no owner
 * (user_id null). Once multiple users exist, an unowned key must not be shared — so
 * reassign any legacy unowned keys to the primary admin (the ADMIN_EMAIL user, else
 * the first SuperAdmin). After this, no key is unowned and each user only ever sees
 * their own. Fresh installs / the test DB have no admin yet — this is then a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $adminId = null;

        if ($email = env('ADMIN_EMAIL')) {
            $adminId = DB::table('users')->where('email', $email)->value('id');
        }

        $adminId ??= DB::table('users')->where('is_super_admin', true)->orderBy('id')->value('id');

        if (! $adminId) {
            return;
        }

        DB::table('api_keys')->whereNull('user_id')->update(['user_id' => $adminId]);
    }

    public function down(): void
    {
        // One-way ownership backfill; rollback doesn't restore the null owner.
    }
};
