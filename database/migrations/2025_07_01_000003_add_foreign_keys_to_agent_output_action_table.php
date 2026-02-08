<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('agent_output_action', function (Blueprint $table) {
            $table->foreign(['agent_id'])->references(['id'])->on('agents')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['output_action_id'])->references(['id'])->on('output_actions')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_output_action', function (Blueprint $table) {
            $table->dropForeign('agent_output_action_agent_id_foreign');
            $table->dropForeign('agent_output_action_output_action_id_foreign');
        });
    }
};
