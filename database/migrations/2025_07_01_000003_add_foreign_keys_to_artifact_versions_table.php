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
        Schema::table('artifact_versions', function (Blueprint $table) {
            $table->foreign(['artifact_id'])->references(['id'])->on('artifacts')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['asset_id'])->references(['id'])->on('assets')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['created_by'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('no action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('artifact_versions', function (Blueprint $table) {
            $table->dropForeign('artifact_versions_artifact_id_foreign');
            $table->dropForeign('artifact_versions_asset_id_foreign');
            $table->dropForeign('artifact_versions_created_by_foreign');
        });
    }
};
