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
        Schema::create('knowledge_groups', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('knowledge_document_id');
            $table->string('group_identifier');
            $table->string('group_type')->default('user_group');
            $table->timestamps();

            $table->index(['group_identifier', 'group_type']);
            $table->unique(['knowledge_document_id', 'group_identifier', 'group_type'], 'unique_document_group');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_groups');
    }
};
