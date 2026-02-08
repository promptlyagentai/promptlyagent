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
        Schema::table('chat_interactions', function (Blueprint $table) {
            $table->foreign(['agent_execution_id'])->references(['id'])->on('agent_executions')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['agent_id'])->references(['id'])->on('agents')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['chat_session_id'])->references(['id'])->on('chat_sessions')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['input_trigger_id'])->references(['id'])->on('input_triggers')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_interactions', function (Blueprint $table) {
            $table->dropForeign('chat_interactions_agent_execution_id_foreign');
            $table->dropForeign('chat_interactions_agent_id_foreign');
            $table->dropForeign('chat_interactions_chat_session_id_foreign');
            $table->dropForeign('chat_interactions_input_trigger_id_foreign');
            $table->dropForeign('chat_interactions_user_id_foreign');
        });
    }
};
