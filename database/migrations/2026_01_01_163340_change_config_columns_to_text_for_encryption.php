<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Changes config columns from JSON to TEXT to support Laravel encrypted cast.
     * Encrypted values are not valid JSON, so we need TEXT column type.
     */
    public function up(): void
    {
        // Change integration_tokens.config from JSON to TEXT
        Schema::table('integration_tokens', function (Blueprint $table) {
            $table->text('config')->nullable()->change();
        });

        // Change input_triggers.config from JSON to TEXT
        Schema::table('input_triggers', function (Blueprint $table) {
            $table->text('config')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Note: This will convert encrypted TEXT back to JSON.
     * Data must be decrypted manually before rollback.
     */
    public function down(): void
    {
        // Revert to JSON type
        Schema::table('integration_tokens', function (Blueprint $table) {
            $table->json('config')->nullable()->change();
        });

        Schema::table('input_triggers', function (Blueprint $table) {
            $table->json('config')->nullable()->change();
        });
    }
};
