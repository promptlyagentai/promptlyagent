<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            // Add UUID for public sharing
            $table->uuid('uuid')->nullable()->after('id');

            // Add public sharing fields
            $table->boolean('is_public')->default(false)->after('source_type');
            $table->timestamp('public_shared_at')->nullable()->after('is_public');
            $table->timestamp('public_expires_at')->nullable()->after('public_shared_at');
        });

        // Backfill UUIDs for existing sessions
        $this->backfillUuids();

        // After backfilling, make uuid non-nullable and unique
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->uuid('uuid')->nullable(false)->unique()->change();

            // Add indexes for efficient filtering
            $table->index(['user_id', 'is_public']);
            $table->index('public_shared_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            // Drop indexes
            $table->dropIndex(['user_id', 'is_public']);
            $table->dropIndex(['public_shared_at']);

            // Drop columns
            $table->dropColumn(['uuid', 'is_public', 'public_shared_at', 'public_expires_at']);
        });
    }

    /**
     * Backfill UUIDs for existing sessions
     */
    protected function backfillUuids(): void
    {
        // Generate UUIDs for all existing sessions in batches
        DB::table('chat_sessions')
            ->whereNull('uuid')
            ->orderBy('id')
            ->chunk(100, function ($sessions) {
                foreach ($sessions as $session) {
                    DB::table('chat_sessions')
                        ->where('id', $session->id)
                        ->update(['uuid' => Str::uuid()->toString()]);
                }
            });
    }
};
