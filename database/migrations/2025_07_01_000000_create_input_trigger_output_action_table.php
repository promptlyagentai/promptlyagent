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
        Schema::create('input_trigger_output_action', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->char('input_trigger_id', 36)->index();
            $table->char('output_action_id', 36)->index();
            $table->timestamps();

            $table->unique(['input_trigger_id', 'output_action_id'], 'trigger_action_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('input_trigger_output_action');
    }
};
