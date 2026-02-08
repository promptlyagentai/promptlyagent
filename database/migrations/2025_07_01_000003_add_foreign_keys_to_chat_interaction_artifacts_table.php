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
        Schema::table('chat_interaction_artifacts', function (Blueprint $table) {
            $table->foreign(['artifact_id'])->references(['id'])->on('artifacts')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['chat_interaction_id'])->references(['id'])->on('chat_interactions')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_interaction_artifacts', function (Blueprint $table) {
            $table->dropForeign('chat_interaction_artifacts_artifact_id_foreign');
            $table->dropForeign('chat_interaction_artifacts_chat_interaction_id_foreign');
        });
    }
};
