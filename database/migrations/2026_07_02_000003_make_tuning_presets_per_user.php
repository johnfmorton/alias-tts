<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tuning presets become per-user: each user keeps (and can delete) only
        // their own named knob pairs, and the name only has to be unique within
        // one user's set. Existing presets were instance-wide, so they go to the
        // operator — the first SuperAdmin — like the voices backfill did.
        Schema::table('tuning_presets', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
            $table->dropUnique(['name']);
            $table->unique(['user_id', 'name']);
        });

        $operatorId = DB::table('users')->where('is_super_admin', true)->orderBy('id')->value('id');
        if ($operatorId !== null) {
            DB::table('tuning_presets')->update(['user_id' => $operatorId]);
        }
    }

    public function down(): void
    {
        // Restoring the global-unique name can collide when two users used the
        // same preset name; keep the oldest of each name so the index applies.
        $keep = DB::table('tuning_presets')
            ->selectRaw('MIN(id) as id')
            ->groupBy('name')
            ->pluck('id');
        DB::table('tuning_presets')->whereNotIn('id', $keep)->delete();

        Schema::table('tuning_presets', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'name']);
            $table->dropConstrainedForeignId('user_id');
            $table->unique(['name']);
        });
    }
};
