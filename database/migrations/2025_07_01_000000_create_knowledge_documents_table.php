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
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('asset_id')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->enum('content_type', ['file', 'text', 'external'])->default('text');
            $table->string('source_type')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->integer('file_size')->nullable();
            $table->longText('content')->nullable();
            $table->text('external_source_identifier')->nullable();
            $table->string('external_source_class')->nullable();
            $table->json('external_source_config')->nullable();
            $table->timestamp('last_fetched_at')->nullable()->index('idx_last_fetched');
            $table->timestamp('last_refresh_attempted_at')->nullable()->comment('When the last refresh was attempted');
            $table->string('last_refresh_status')->nullable()->index()->comment('success, failed, in_progress');
            $table->text('last_refresh_error')->nullable()->comment('Error message if refresh failed');
            $table->integer('refresh_attempt_count')->default(0)->comment('Number of refresh attempts');
            $table->string('content_hash')->nullable()->index('idx_content_hash');
            $table->boolean('auto_refresh_enabled')->default(false);
            $table->timestamp('next_refresh_at')->nullable();
            $table->integer('refresh_interval_minutes')->nullable();
            $table->json('external_metadata')->nullable();
            $table->string('favicon_url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('author')->nullable();
            $table->string('language', 10)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_modified_at')->nullable();
            $table->integer('word_count')->nullable();
            $table->integer('reading_time_minutes')->nullable();
            $table->string('meilisearch_document_id')->nullable()->index();
            $table->enum('privacy_level', ['private', 'public'])->default('private');
            $table->enum('processing_status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->text('processing_error')->nullable();
            $table->timestamp('ttl_expires_at')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
            $table->char('integration_id', 36)->nullable()->index('knowledge_documents_integration_id_foreign');

            $table->index(['auto_refresh_enabled', 'next_refresh_at'], 'idx_auto_refresh');
            // Index on TEXT column requires manual creation with key length
            $table->index(['created_by', 'content_type']);
            $table->index(['privacy_level', 'processing_status']);
        });

        // Create index on TEXT column with prefix length
        \DB::statement('CREATE INDEX idx_external_source ON knowledge_documents (external_source_identifier(255), external_source_class)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
