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
        Schema::table('artifacts', function (Blueprint $table) {
            $table->foreign(['asset_id'])->references(['id'])->on('assets')->onUpdate('no action')->onDelete('set null');
            $table->foreign(['author_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['parent_artifact_id'])->references(['id'])->on('artifacts')->onUpdate('no action')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('artifacts', function (Blueprint $table) {
            $table->dropForeign('artifacts_asset_id_foreign');
            $table->dropForeign('artifacts_author_id_foreign');
            $table->dropForeign('artifacts_parent_artifact_id_foreign');
        });
    }
};
