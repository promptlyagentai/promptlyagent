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
        Schema::table('output_action_logs', function (Blueprint $table) {
            $table->foreign(['output_action_id'])->references(['id'])->on('output_actions')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('output_action_logs', function (Blueprint $table) {
            $table->dropForeign('output_action_logs_output_action_id_foreign');
            $table->dropForeign('output_action_logs_user_id_foreign');
        });
    }
};
