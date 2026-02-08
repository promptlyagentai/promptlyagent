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
        Schema::create('chat_interaction_sources', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('chat_interaction_id');
            $table->unsignedBigInteger('source_id');
            $table->decimal('relevance_score', 5, 3)->default(0);
            $table->decimal('initial_relevance_score', 5, 3)->default(0);
            $table->decimal('content_relevance_score', 5, 3)->nullable();
            $table->string('discovery_method', 50);
            $table->string('discovery_tool', 100);
            $table->text('relevance_reasoning')->nullable();
            $table->json('relevance_metadata')->nullable();
            $table->text('content_summary')->nullable();
            $table->timestamp('summary_generated_at')->nullable();
            $table->boolean('was_scraped')->default(false);
            $table->boolean('recommended_for_scraping')->default(false);
            $table->timestamp('discovered_at');
            $table->timestamp('last_relevance_update')->nullable();
            $table->timestamps();

            $table->unique(['chat_interaction_id', 'source_id']);
            $table->index(['chat_interaction_id', 'relevance_score'], 'cis_interaction_relevance_idx');
            $table->index(['discovery_method', 'relevance_score'], 'cis_method_relevance_idx');
            $table->index(['recommended_for_scraping', 'relevance_score'], 'cis_recommended_idx');
            $table->index(['source_id', 'relevance_score'], 'cis_source_relevance_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_interaction_sources');
    }
};
