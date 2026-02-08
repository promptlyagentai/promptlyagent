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
        Schema::table('artifact_artifact_tag', function (Blueprint $table) {
            $table->foreign(['artifact_id'])->references(['id'])->on('artifacts')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['artifact_tag_id'])->references(['id'])->on('artifact_tags')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('artifact_artifact_tag', function (Blueprint $table) {
            $table->dropForeign('artifact_artifact_tag_artifact_id_foreign');
            $table->dropForeign('artifact_artifact_tag_artifact_tag_id_foreign');
        });
    }
};
