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
        Schema::table('input_trigger_output_action', function (Blueprint $table) {
            $table->foreign(['input_trigger_id'])->references(['id'])->on('input_triggers')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['output_action_id'])->references(['id'])->on('output_actions')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('input_trigger_output_action', function (Blueprint $table) {
            $table->dropForeign('input_trigger_output_action_input_trigger_id_foreign');
            $table->dropForeign('input_trigger_output_action_output_action_id_foreign');
        });
    }
};
