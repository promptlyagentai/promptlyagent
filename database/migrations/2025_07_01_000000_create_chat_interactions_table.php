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
        Schema::create('chat_interactions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->text('question');
            $table->text('answer');
            $table->text('summary')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('user_id')->index('chat_interactions_user_id_foreign');
            $table->unsignedBigInteger('agent_execution_id')->nullable()->index();
            $table->char('input_trigger_id', 36)->nullable()->index();
            $table->unsignedBigInteger('agent_id')->nullable()->index();
            $table->unsignedBigInteger('chat_session_id')->index('chat_interactions_chat_session_id_foreign');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_interactions');
    }
};
