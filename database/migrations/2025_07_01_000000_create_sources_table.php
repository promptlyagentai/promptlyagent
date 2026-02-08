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
        Schema::create('sources', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('url', 2000);
            $table->string('url_hash', 64)->index();
            $table->string('domain')->index();
            $table->string('title', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('favicon', 500)->nullable();
            $table->json('open_graph')->nullable();
            $table->json('twitter_card')->nullable();
            $table->longText('content_markdown')->nullable();
            $table->text('content_preview')->nullable();
            $table->integer('http_status')->index();
            $table->string('content_type', 100)->nullable();
            $table->timestamp('content_retrieved_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->integer('ttl_hours')->default(24);
            $table->string('content_category', 50)->default('general');
            $table->boolean('is_scrapeable')->default(true);
            $table->boolean('requires_refresh')->default(false);
            $table->json('validation_metadata')->nullable();
            $table->json('scraping_metadata')->nullable();
            $table->string('last_user_agent', 500)->nullable();
            $table->integer('access_count')->default(1);
            $table->timestamp('last_accessed_at');
            $table->timestamps();

            $table->index(['content_category', 'expires_at']);
            $table->index(['domain', 'expires_at']);
            $table->index(['is_scrapeable', 'expires_at']);
            $table->unique(['url_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
