<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Make the 'url' and 'method' columns nullable to support output actions that don't use HTTP webhooks
     * (e.g., Slack, Discord, etc. that use API SDKs instead of HTTP requests).
     *
     * Fixes issue #28: Slack output actions fail with "Column 'url' cannot be null" error
     */
    public function up(): void
    {
        Schema::table('output_action_logs', function (Blueprint $table) {
            $table->string('url', 2048)->nullable()->change();
            $table->string('method', 10)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('output_action_logs', function (Blueprint $table) {
            $table->string('url', 2048)->nullable(false)->change();
            $table->string('method', 10)->nullable(false)->change();
        });
    }
};
