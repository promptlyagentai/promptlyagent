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
        Schema::create('artifact_integrations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('artifact_id');
            $table->char('integration_id', 36)->nullable()->index('artifact_integrations_integration_id_foreign');
            $table->string('external_id')->index();
            $table->string('external_url')->nullable();
            $table->boolean('auto_sync_enabled')->default(false)->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_status')->nullable();
            $table->text('last_sync_error')->nullable();
            $table->json('sync_metadata')->nullable();
            $table->timestamps();

            $table->unique(['artifact_id', 'integration_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artifact_integrations');
    }
};
