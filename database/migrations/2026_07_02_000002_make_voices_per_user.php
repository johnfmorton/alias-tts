<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Voices become per-user: custom voices get an owner and are visible
        // only to that owner (plus SuperAdmins on the Voices page). A NULL
        // owner means shared — the bundled built-in defaults every user sees.
        Schema::table('voices', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
        });

        // Existing custom voices belong to the operator: assign them to the
        // first SuperAdmin. Built-ins stay shared (NULL).
        $builtins = [
            (string) config('tts.default_voice_slug', 'default'),
            (string) config('tts.default_voice_female_slug', 'default-female'),
        ];
        $operatorId = DB::table('users')->where('is_super_admin', true)->orderBy('id')->value('id');

        if ($operatorId !== null) {
            DB::table('voices')->whereNotIn('slug', $builtins)->update(['user_id' => $operatorId]);
        }

        // Each user's personal ordering of the voices they can see (built-ins
        // included), driving every voice picker. Voices a user never ranked
        // sort after the ranked ones, built-in default first.
        Schema::create('voice_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('voice_id')->constrained('voices')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->timestamps();
            $table->unique(['user_id', 'voice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_orders');

        Schema::table('voices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
