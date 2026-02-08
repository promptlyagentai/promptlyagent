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
        Schema::table('output_actions', function (Blueprint $table) {
            $table->foreign(['integration_id'])->references(['id'])->on('integrations')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['user_id'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('output_actions', function (Blueprint $table) {
            $table->dropForeign('output_actions_integration_id_foreign');
            $table->dropForeign('output_actions_user_id_foreign');
        });
    }
};
