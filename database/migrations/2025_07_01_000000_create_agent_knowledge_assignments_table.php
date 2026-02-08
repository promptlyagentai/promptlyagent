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
        Schema::create('agent_knowledge_assignments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('knowledge_document_id')->nullable()->index('agent_knowledge_assignments_knowledge_document_id_foreign');
            $table->unsignedBigInteger('knowledge_tag_id')->nullable()->index('agent_knowledge_assignments_knowledge_tag_id_foreign');
            $table->enum('assignment_type', ['document', 'tag', 'all'])->default('document');
            $table->json('assignment_config')->nullable();
            $table->boolean('include_expired')->default(false);
            $table->integer('priority')->default(1);
            $table->timestamps();

            $table->index(['assignment_type', 'priority']);
            $table->unique(['agent_id', 'knowledge_document_id'], 'unique_agent_document');
            $table->unique(['agent_id', 'knowledge_tag_id'], 'unique_agent_tag');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_knowledge_assignments');
    }
};
