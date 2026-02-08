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
        Schema::table('input_triggers', function (Blueprint $table) {
            $table->foreign(['agent_id'])->references(['id'])->on('agents')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['default_session_id'])->references(['id'])->on('chat_sessions')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['integration_id'])->references(['id'])->on('integrations')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('input_triggers', function (Blueprint $table) {
            $table->dropForeign('input_triggers_agent_id_foreign');
            $table->dropForeign('input_triggers_default_session_id_foreign');
            $table->dropForeign('input_triggers_integration_id_foreign');
            $table->dropForeign('input_triggers_user_id_foreign');
        });
    }
};
