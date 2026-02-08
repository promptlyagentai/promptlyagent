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
        Schema::create('artifacts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('asset_id')->nullable()->index('artifacts_asset_id_foreign');
            $table->string('title');
            $table->text('description')->nullable();
            $table->longText('content')->nullable();
            $table->string('filetype', 50)->nullable()->index();
            $table->string('version')->default('1.0.0');
            $table->enum('privacy_level', ['private', 'team', 'public'])->default('private')->index();
            $table->json('metadata')->nullable();
            $table->unsignedBigInteger('author_id')->index();
            $table->unsignedBigInteger('parent_artifact_id')->nullable()->index('artifacts_parent_artifact_id_foreign');
            $table->timestamps();

            $table->index(['author_id'], 'artifacts_author_id_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artifacts');
    }
};
