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
        Schema::create('output_actions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('provider_id')->index();
            $table->enum('status', ['active', 'paused', 'disabled'])->default('active');
            $table->json('config');
            $table->text('webhook_secret')->nullable();
            $table->enum('trigger_on', ['success', 'failure', 'always'])->default('success');
            $table->unsignedInteger('total_executions')->default(0);
            $table->unsignedInteger('successful_executions')->default(0);
            $table->unsignedInteger('failed_executions')->default(0);
            $table->timestamp('last_executed_at')->nullable();
            $table->timestamps();
            $table->char('integration_id', 36)->nullable()->index('output_actions_integration_id_foreign');

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('output_actions');
    }
};
