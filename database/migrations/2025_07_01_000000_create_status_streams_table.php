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
        Schema::create('status_streams', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('interaction_id');
            $table->unsignedBigInteger('agent_execution_id')->nullable();
            $table->string('source');
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->boolean('is_significant')->default(false);
            $table->boolean('create_event')->default(true);
            $table->timestamp('timestamp');
            $table->timestamps();

            $table->index(['agent_execution_id', 'timestamp']);
            $table->index(['interaction_id', 'timestamp']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('status_streams');
    }
};
