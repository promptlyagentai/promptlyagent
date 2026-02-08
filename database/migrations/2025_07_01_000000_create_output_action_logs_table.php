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
        Schema::create('output_action_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('output_action_id', 36);
            $table->unsignedBigInteger('user_id');
            $table->string('triggerable_type')->nullable();
            $table->unsignedBigInteger('triggerable_id')->nullable();
            $table->string('url', 2048);
            $table->string('method', 10);
            $table->json('headers')->nullable();
            $table->text('body')->nullable();
            $table->enum('status', ['success', 'failed', 'timeout'])->default('success');
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('executed_at')->index();
            $table->timestamps();

            $table->index(['output_action_id', 'status']);
            $table->index(['triggerable_type', 'triggerable_id']);
            $table->index(['user_id', 'executed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('output_action_logs');
    }
};
