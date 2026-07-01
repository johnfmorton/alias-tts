<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-user account fields. `is_super_admin` (added earlier) already distinguishes
 * the two roles the UI exposes — SuperAdmin vs User — so no separate role column is
 * needed. These add the lifecycle + presence bits the Account and Users screens need:
 *  - status:         active | suspended | invited (drives the Users table + suspend flow)
 *  - last_active_at: powers "Last active" and presence, bumped by EnsureAccountIsActive
 *  - avatar_path:    optional uploaded avatar; null falls back to initials
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status')->default('active')->after('is_super_admin');
            $table->timestamp('last_active_at')->nullable()->after('status');
            $table->string('avatar_path')->nullable()->after('last_active_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['status', 'last_active_at', 'avatar_path']);
        });
    }
};
