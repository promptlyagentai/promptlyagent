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
        Schema::table('knowledge_document_tags', function (Blueprint $table) {
            $table->foreign(['knowledge_document_id'])->references(['id'])->on('knowledge_documents')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['knowledge_tag_id'])->references(['id'])->on('knowledge_tags')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_document_tags', function (Blueprint $table) {
            $table->dropForeign('knowledge_document_tags_knowledge_document_id_foreign');
            $table->dropForeign('knowledge_document_tags_knowledge_tag_id_foreign');
        });
    }
};
