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
        Schema::create('artifact_artifact_tag', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('artifact_id');
            $table->unsignedBigInteger('artifact_tag_id')->index('artifact_artifact_tag_artifact_tag_id_foreign');
            $table->timestamps();

            $table->unique(['artifact_id', 'artifact_tag_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artifact_artifact_tag');
    }
};
