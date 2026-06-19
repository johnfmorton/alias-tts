<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Named stability/style combos the operator can reuse in the Studio
        // tuning bench. See docs/STUDIO-TUNING.md Phase 3b.
        Schema::create('tuning_presets', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('stability', 3, 2)->nullable();
            $table->decimal('style', 3, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tuning_presets');
    }
};
