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
        Schema::create('agent_sources', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('execution_id')->index();
            $table->string('url', 2048);
            $table->text('title')->nullable();
            $table->text('snippet')->nullable();
            $table->string('favicon_url', 512)->nullable();
            $table->string('domain')->nullable()->index();
            $table->integer('link_usage_count')->default(0)->index();
            $table->integer('click_count')->default(0);
            $table->decimal('quality_score', 3)->default(0)->index();
            $table->enum('source_type', ['web', 'document', 'file', 'api'])->default('web');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_sources');
    }
};
