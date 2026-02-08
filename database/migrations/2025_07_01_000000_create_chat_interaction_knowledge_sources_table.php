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
        Schema::create('chat_interaction_knowledge_sources', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('chat_interaction_id');
            $table->unsignedBigInteger('knowledge_document_id')->index('chat_interaction_knowledge_sources_knowledge_document_id_foreign');
            $table->double('relevance_score')->nullable();
            $table->string('discovery_method')->default('knowledge_search');
            $table->string('discovery_tool')->default('KnowledgeRAGTool');
            $table->text('content_excerpt')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['chat_interaction_id', 'knowledge_document_id'], 'chat_interaction_knowledge_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_interaction_knowledge_sources');
    }
};
