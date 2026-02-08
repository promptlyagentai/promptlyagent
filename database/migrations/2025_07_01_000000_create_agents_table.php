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
        Schema::create('agents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->enum('agent_type', ['individual', 'workflow', 'direct', 'promptly', 'synthesizer'])->default('individual');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->longText('system_prompt');
            $table->json('workflow_config')->nullable();
            $table->string('ai_provider')->default('openai')->index();
            $table->string('ai_model')->default('gpt-4o');
            $table->integer('max_steps')->default(10);
            $table->json('ai_config')->nullable();
            $table->enum('status', ['active', 'inactive', 'archived'])->default('active');
            $table->boolean('is_public')->default(false);
            $table->boolean('show_in_chat')->default(true);
            $table->boolean('streaming_enabled')->default(false)->index('idx_agents_streaming_enabled');
            $table->boolean('thinking_enabled')->default(false)->comment('Enable thinking/reasoning process streaming for this agent');
            $table->boolean('available_for_research')->default(false)->index();
            $table->unsignedBigInteger('created_by')->index('agents_created_by_foreign');
            $table->timestamps();
            $table->char('integration_id', 36)->nullable()->index('agents_integration_id_foreign');

            $table->index(['status', 'is_public']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
