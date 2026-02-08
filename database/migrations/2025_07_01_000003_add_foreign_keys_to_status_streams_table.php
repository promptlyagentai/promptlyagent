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
        Schema::table('status_streams', function (Blueprint $table) {
            $table->foreign(['agent_execution_id'])->references(['id'])->on('agent_executions')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['interaction_id'])->references(['id'])->on('chat_interactions')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('status_streams', function (Blueprint $table) {
            $table->dropForeign('status_streams_agent_execution_id_foreign');
            $table->dropForeign('status_streams_interaction_id_foreign');
        });
    }
};
