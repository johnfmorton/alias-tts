<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Generic UI-editable settings store. `key` is a config() path
        // (e.g. tts.asr.api_action); `value` is the JSON-encoded scalar. Only keys
        // NOT pinned in .env are ever written here — see config/settings.php and
        // App\Services\Settings\SettingsManager.
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
