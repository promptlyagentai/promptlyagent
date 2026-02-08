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
        Schema::create('agent_tools', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agent_id');
            $table->string('tool_name');
            $table->json('tool_config')->nullable();
            $table->boolean('enabled')->default(true);
            $table->integer('execution_order')->default(0);
            $table->enum('priority_level', ['preferred', 'standard', 'fallback'])->default('standard');
            $table->enum('execution_strategy', ['always', 'if_preferred_fails', 'if_no_preferred_results', 'never_if_preferred_succeeds'])->default('always');
            $table->integer('min_results_threshold')->nullable();
            $table->integer('max_execution_time')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'enabled']);
            $table->index(['agent_id', 'enabled', 'priority_level']);
            $table->index(['agent_id', 'priority_level']);
            $table->unique(['agent_id', 'tool_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_tools');
    }
};
