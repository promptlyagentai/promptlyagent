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
        Schema::table('chat_interaction_attachments', function (Blueprint $table) {
            $table->enum('attached_to', ['question', 'answer'])
                ->default('question')
                ->after('chat_interaction_id')
                ->comment('Whether the attachment belongs to the user question or agent answer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_interaction_attachments', function (Blueprint $table) {
            $table->dropColumn('attached_to');
        });
    }
};
