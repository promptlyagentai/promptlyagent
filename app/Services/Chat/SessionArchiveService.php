<?php

namespace App\Services\Chat;

use App\Models\ChatSession;
use Illuminate\Support\Facades\Log;

/**
 * Session Archive Service
 *
 * Handles automatic archiving of old chat sessions based on
 * configurable rules while respecting keep flags.
 */
class SessionArchiveService
{
    /**
     * Archive sessions that are eligible for archiving
     *
     * @param  bool  $dryRun  If true, return count without actually archiving
     * @return int Number of sessions archived (or would be archived in dry-run mode)
     */
    public function archiveEligibleSessions(bool $dryRun = false): int
    {
        $threshold = config('chat.auto_archive_days', 90);
        $cutoffDate = now()->subDays($threshold);

        // Find eligible sessions
        $eligibleSessions = ChatSession::query()
            ->where('is_kept', false)
            ->whereNull('archived_at')
            ->where('updated_at', '<', $cutoffDate)
            ->get();

        if ($dryRun) {
            Log::info('Dry-run: Would archive sessions', [
                'count' => $eligibleSessions->count(),
                'threshold_days' => $threshold,
                'cutoff_date' => $cutoffDate->toDateTimeString(),
            ]);

            return $eligibleSessions->count();
        }

        $archivedCount = 0;

        // Archive each eligible session
        foreach ($eligibleSessions as $session) {
            if ($session->archive()) {
                $archivedCount++;
            }
        }

        Log::info('Archived old sessions', [
            'count' => $archivedCount,
            'threshold_days' => $threshold,
            'cutoff_date' => $cutoffDate->toDateTimeString(),
        ]);

        return $archivedCount;
    }

    /**
     * Check if a session is eligible for archiving
     */
    public function isEligibleForArchiving(ChatSession $session): bool
    {
        // Never archive kept sessions
        if ($session->is_kept) {
            return false;
        }

        // Don't archive already archived sessions
        if ($session->isArchived()) {
            return false;
        }

        // Check if session is older than threshold
        $threshold = config('chat.auto_archive_days', 90);
        $cutoffDate = now()->subDays($threshold);

        return $session->updated_at->lt($cutoffDate);
    }

    /**
     * Archive a specific session
     *
     * @param  bool  $force  Force archiving even if not eligible
     */
    public function archiveSession(ChatSession $session, bool $force = false): bool
    {
        if (! $force && ! $this->isEligibleForArchiving($session)) {
            return false;
        }

        return $session->archive();
    }

    /**
     * Unarchive a session
     */
    public function unarchiveSession(ChatSession $session): bool
    {
        return $session->unarchive();
    }

    /**
     * Get statistics about archivable sessions
     */
    public function getArchiveStats(): array
    {
        $threshold = config('chat.auto_archive_days', 90);
        $cutoffDate = now()->subDays($threshold);

        $totalSessions = ChatSession::count();
        $archivedSessions = ChatSession::archived()->count();
        $keptSessions = ChatSession::kept()->count();
        $eligibleForArchiving = ChatSession::query()
            ->where('is_kept', false)
            ->whereNull('archived_at')
            ->where('updated_at', '<', $cutoffDate)
            ->count();

        return [
            'total_sessions' => $totalSessions,
            'archived_sessions' => $archivedSessions,
            'kept_sessions' => $keptSessions,
            'eligible_for_archiving' => $eligibleForArchiving,
            'threshold_days' => $threshold,
            'cutoff_date' => $cutoffDate->toDateTimeString(),
        ];
    }
}
