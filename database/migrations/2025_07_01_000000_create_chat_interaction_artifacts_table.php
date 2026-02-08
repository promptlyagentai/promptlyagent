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
        Schema::create('chat_interaction_artifacts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('chat_interaction_id');
            $table->unsignedBigInteger('artifact_id');
            $table->string('interaction_type', 50);
            $table->string('tool_used', 100);
            $table->text('context_summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('interacted_at');
            $table->timestamps();

            $table->index(['artifact_id', 'interacted_at'], 'cif_artifact_time_idx');
            $table->index(['chat_interaction_id', 'interacted_at'], 'cif_interaction_time_idx');
            $table->index(['tool_used', 'interaction_type'], 'cif_tool_type_idx');
            $table->unique(['chat_interaction_id', 'artifact_id', 'interaction_type'], 'cif_unique_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_interaction_artifacts');
    }
};
