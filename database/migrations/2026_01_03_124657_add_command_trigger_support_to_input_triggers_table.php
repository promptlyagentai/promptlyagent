<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add support for triggering both agents and commands.
     * This allows input triggers to execute Artisan commands with
     * dynamic parameter mapping from webhook payloads.
     */
    public function up(): void
    {
        Schema::table('input_triggers', function (Blueprint $table) {
            // Trigger target type: agent or command
            $table->enum('trigger_target_type', ['agent', 'command'])
                ->default('agent')
                ->after('agent_id')
                ->index();

            // Command class name (e.g., 'App\Console\Commands\Research\DailyDigestCommand')
            $table->string('command_class')->nullable()->after('trigger_target_type');

            // Command parameter mappings (args + options)
            // Maps webhook payload fields to command arguments/options
            // Example: {'topics': '$.data.topics', 'webhook-url': '$.callback_url'}
            $table->json('command_parameters')->nullable()->after('command_class');

            // Make agent_id truly nullable (currently nullable but always required)
            // When trigger_target_type='command', agent_id can be null
            $table->unsignedBigInteger('agent_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('input_triggers', function (Blueprint $table) {
            $table->dropColumn([
                'trigger_target_type',
                'command_class',
                'command_parameters',
            ]);
        });
    }
};
