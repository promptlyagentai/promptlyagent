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
        Schema::table('artifact_integrations', function (Blueprint $table) {
            $table->foreign(['artifact_id'])->references(['id'])->on('artifacts')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['integration_id'])->references(['id'])->on('integrations')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('artifact_integrations', function (Blueprint $table) {
            $table->dropForeign('artifact_integrations_artifact_id_foreign');
            $table->dropForeign('artifact_integrations_integration_id_foreign');
        });
    }
};
