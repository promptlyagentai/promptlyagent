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
        Schema::table('agent_sources', function (Blueprint $table) {
            $table->foreign(['execution_id'])->references(['id'])->on('agent_executions')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_sources', function (Blueprint $table) {
            $table->dropForeign('agent_sources_execution_id_foreign');
        });
    }
};
