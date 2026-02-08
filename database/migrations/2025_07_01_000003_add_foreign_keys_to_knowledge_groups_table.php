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
        Schema::table('knowledge_groups', function (Blueprint $table) {
            $table->foreign(['knowledge_document_id'])->references(['id'])->on('knowledge_documents')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('knowledge_groups', function (Blueprint $table) {
            $table->dropForeign('knowledge_groups_knowledge_document_id_foreign');
        });
    }
};
