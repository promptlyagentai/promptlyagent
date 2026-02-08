<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            // Add new columns for session management
            $table->boolean('is_kept')->default(false)->after('metadata');
            $table->timestamp('archived_at')->nullable()->after('is_kept');
            $table->string('source_type', 50)->nullable()->after('archived_at');

            // Add indexes for efficient filtering
            $table->index(['user_id', 'archived_at']);
            $table->index(['user_id', 'source_type']);
            $table->index(['user_id', 'is_kept']);
        });

        // Backfill source_type from metadata->initiated_by
        $this->backfillSourceType();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            // Drop indexes
            $table->dropIndex(['user_id', 'archived_at']);
            $table->dropIndex(['user_id', 'source_type']);
            $table->dropIndex(['user_id', 'is_kept']);

            // Drop columns
            $table->dropColumn(['is_kept', 'archived_at', 'source_type']);
        });
    }

    /**
     * Backfill source_type from existing metadata
     */
    protected function backfillSourceType(): void
    {
        // Update source_type from metadata->initiated_by for existing sessions
        DB::table('chat_sessions')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunk(100, function ($sessions) {
                foreach ($sessions as $session) {
                    $metadata = json_decode($session->metadata, true);
                    $sourceType = $metadata['initiated_by'] ?? 'web';

                    DB::table('chat_sessions')
                        ->where('id', $session->id)
                        ->update(['source_type' => $sourceType]);
                }
            });

        // Set default 'web' for sessions without metadata
        DB::table('chat_sessions')
            ->whereNull('source_type')
            ->update(['source_type' => 'web']);
    }
};
