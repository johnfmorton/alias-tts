<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per-user "Advanced tuning" toggle for Studio (off by default keeps
            // the inspector friendly; on reveals the knobs + A/B tuning bench).
            $table->boolean('studio_advanced')->default(false)->after('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('studio_advanced');
        });
    }
};
