<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chatterbox Turbo's knob dialect (top_p/top_k/repetition_penalty) joins the
 * presets, plus the engine each preset was authored for — pickers only offer a
 * preset to chunks/voices running the same engine. NULL model = classic
 * chatterbox (every pre-existing preset).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tuning_presets', function (Blueprint $table) {
            $table->float('top_p')->nullable()->after('temperature');
            $table->unsignedInteger('top_k')->nullable()->after('top_p');
            $table->float('repetition_penalty')->nullable()->after('top_k');
            $table->string('model')->nullable()->after('repetition_penalty');
        });
    }

    public function down(): void
    {
        Schema::table('tuning_presets', function (Blueprint $table) {
            $table->dropColumn(['top_p', 'top_k', 'repetition_penalty', 'model']);
        });
    }
};
