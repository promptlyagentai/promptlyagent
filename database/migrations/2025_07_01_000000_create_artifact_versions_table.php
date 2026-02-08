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
        Schema::create('artifact_versions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('artifact_id');
            $table->string('version');
            $table->longText('content')->nullable();
            $table->unsignedBigInteger('asset_id')->nullable()->index('artifact_versions_asset_id_foreign');
            $table->json('changes')->nullable();
            $table->unsignedBigInteger('created_by')->index('artifact_versions_created_by_foreign');
            $table->timestamp('created_at')->nullable()->index();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['artifact_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artifact_versions');
    }
};
