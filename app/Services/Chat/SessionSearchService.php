<?php

namespace App\Services\Chat;

use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Session Search Service
 *
 * Handles searching chat sessions using Meilisearch for full-text search
 * across session titles and interaction content with filtering capabilities.
 */
class SessionSearchService
{
    /**
     * Search sessions for a user with optional filters
     *
     * @param  User  $user  The user whose sessions to search
     * @param  string  $query  Search query string
     * @param  array  $filters  Filter options:
     *                          - source_type: string (web, api, webhook, slack, trigger, all)
     *                          - include_archived: bool
     *                          - kept_only: bool
     *                          - limit: int (max 50)
     */
    public function search(User $user, string $query, array $filters = []): Collection
    {
        // Start with base query for user's sessions
        $sessionsQuery = ChatSession::where('user_id', $user->id);

        // Apply source type filter
        if (! empty($filters['source_type']) && $filters['source_type'] !== 'all') {
            $sessionsQuery->bySourceType($filters['source_type']);
        }

        // Apply archived filter
        if (empty($filters['include_archived'])) {
            $sessionsQuery->active();
        }

        // Apply kept filter
        if (! empty($filters['kept_only'])) {
            $sessionsQuery->kept();
        }

        // If query is empty, just return filtered sessions
        if (empty(trim($query))) {
            return $sessionsQuery
                ->orderBy('updated_at', 'desc')
                ->limit($filters['limit'] ?? 50)
                ->get();
        }

        // Use whereHas to search in title and interactions
        $sessionsQuery->where(function ($q) use ($query) {
            // Search in session title (if it exists and is not default)
            $q->where('title', 'like', "%{$query}%")
                // Search in interactions
                ->orWhereHas('interactions', function ($interactionQuery) use ($query) {
                    $interactionQuery->where(function ($iq) use ($query) {
                        $iq->where('question', 'like', "%{$query}%")
                            ->orWhere('answer', 'like', "%{$query}%");
                    });
                });
        });

        // Order by relevance (prioritize title matches) then updated_at
        $sessions = $sessionsQuery
            ->orderByRaw('CASE WHEN title LIKE ? THEN 0 ELSE 1 END', ["%{$query}%"])
            ->orderBy('updated_at', 'desc')
            ->limit($filters['limit'] ?? 50)
            ->get();

        return $sessions;
    }

    /**
     * Search only in session titles (faster, less comprehensive)
     */
    public function searchTitles(User $user, string $query, array $filters = []): Collection
    {
        $sessionsQuery = ChatSession::where('user_id', $user->id)
            ->where('title', 'like', "%{$query}%");

        // Apply filters
        if (! empty($filters['source_type']) && $filters['source_type'] !== 'all') {
            $sessionsQuery->bySourceType($filters['source_type']);
        }

        if (empty($filters['include_archived'])) {
            $sessionsQuery->active();
        }

        if (! empty($filters['kept_only'])) {
            $sessionsQuery->kept();
        }

        return $sessionsQuery
            ->orderBy('updated_at', 'desc')
            ->limit($filters['limit'] ?? 50)
            ->get();
    }
}
