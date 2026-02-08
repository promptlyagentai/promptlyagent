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
        Schema::create('agent_executions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('parent_agent_execution_id')->nullable();
            $table->string('workflow_step_name', 100)->nullable();
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('chat_session_id')->nullable()->index();
            $table->longText('input');
            $table->longText('output')->nullable();
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'cancelled'])->default('pending')->index();
            $table->string('state')->default('pending')->index();
            $table->string('active_execution_key', 100)->nullable();
            $table->string('current_phase')->default('initializing');
            $table->json('phase_progress')->nullable();
            $table->json('phase_timeline')->nullable();
            $table->string('status_message')->nullable();
            $table->json('metadata')->nullable();
            $table->json('workflow_plan')->nullable();
            $table->integer('max_steps');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error_message')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'status']);
            $table->unique(['agent_id', 'user_id', 'chat_session_id', 'active_execution_key'], 'agent_executions_duplicate_prevention');
            $table->index(['user_id', 'status']);
            $table->unique(['agent_id', 'user_id', 'chat_session_id', 'parent_agent_execution_id', 'active_execution_key'], 'agent_executions_workflow_unique');
            $table->index(['parent_agent_execution_id', 'created_at'], 'idx_parent_execution_timeline');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_executions');
    }
};
