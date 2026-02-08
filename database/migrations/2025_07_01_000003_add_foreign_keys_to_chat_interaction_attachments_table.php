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
            $table->foreign(['chat_interaction_id'])->references(['id'])->on('chat_interactions')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_interaction_attachments', function (Blueprint $table) {
            $table->dropForeign('chat_interaction_attachments_chat_interaction_id_foreign');
        });
    }
};
