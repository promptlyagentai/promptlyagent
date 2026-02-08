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
        Schema::create('input_triggers', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->unsignedBigInteger('user_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable()->index('input_triggers_agent_id_foreign');
            $table->string('provider_id', 50)->index();
            $table->enum('status', ['active', 'paused', 'disabled'])->default('active');
            $table->json('config')->nullable();
            $table->json('rate_limits')->nullable();
            $table->json('ip_whitelist')->nullable();
            $table->enum('session_strategy', ['new_each', 'continue_last', 'specified'])->nullable()->default('new_each');
            $table->unsignedBigInteger('default_session_id')->nullable()->index('input_triggers_default_session_id_foreign');
            $table->timestamp('secret_created_at')->nullable();
            $table->timestamp('secret_rotated_at')->nullable();
            $table->unsignedInteger('secret_rotation_count')->default(0);
            $table->integer('total_invocations')->default(0);
            $table->integer('successful_invocations')->default(0);
            $table->integer('failed_invocations')->default(0);
            $table->timestamp('last_invoked_at')->nullable();
            $table->timestamps();
            $table->char('integration_id', 36)->nullable()->index('input_triggers_integration_id_foreign');

            $table->index(['user_id', 'provider_id']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('input_triggers');
    }
};
