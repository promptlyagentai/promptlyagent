<?php

namespace App\Livewire\Traits;

use App\Models\ChatInteraction;
use App\Models\ChatSession;
use App\Services\Chat\SessionSearchService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Provides session management functionality for chat components.
 *
 * Features:
 * - Session CRUD operations
 * - Interaction loading with relationships
 * - Source link extraction from tool results
 * - Automatic title generation using TitleGenerator service
 * - Session persistence (last active session tracking)
 *
 * Properties added to using class:
 *
 * @property \Illuminate\Support\Collection $sessions Available chat sessions
 * @property int|null $currentSessionId Currently active session
 * @property \Illuminate\Support\Collection $interactions Interactions for current session
 * @property array<int, array<int, array{url: string, title: string, tool: string}>> $sourceLinks
 */
trait HasSessionManagement
{
    public $sessions = [];

    public $currentSessionId = null;

    public $interactions = [];

    public $sourceLinks = [];

    public $sessionSearch = '';

    public $sessionSourceFilter = 'all';

    public $showArchived = false;

    public $showKeptOnly = false;

    // Bulk edit mode properties
    public $bulkEditMode = false;

    public $selectedSessionIds = [];

    public $selectAll = false;

    // Operation progress tracking
    public $bulkOperationInProgress = false;

    public $bulkOperationProgress = [
        'current' => 0,
        'total' => 0,
        'action' => '',
    ];

    public function loadSessions()
    {
        $query = ChatSession::where('user_id', Auth::id());

        // Apply source type filter
        if ($this->sessionSourceFilter !== 'all') {
            $query->bySourceType($this->sessionSourceFilter);
        }

        // Apply archived filter
        if (! $this->showArchived) {
            $query->active();
        }

        // Apply kept filter
        if ($this->showKeptOnly) {
            $query->kept();
        }

        // Apply search if provided
        if (! empty(trim($this->sessionSearch)) && config('chat.search_enabled', true)) {
            $searchService = app(SessionSearchService::class);
            $this->sessions = $searchService->search(
                Auth::user(),
                $this->sessionSearch,
                [
                    'source_type' => $this->sessionSourceFilter,
                    'include_archived' => $this->showArchived,
                    'kept_only' => $this->showKeptOnly,
                    'limit' => 50,
                ]
            );
        } else {
            $this->sessions = $query
                ->orderByDesc('updated_at')
                ->limit(50)
                ->get();
        }
    }

    public function loadInteractions()
    {
        if (! $this->currentSessionId) {
            $this->interactions = [];

            return;
        }

        $this->interactions = ChatInteraction::with('agentExecution')
            ->where('chat_session_id', $this->currentSessionId)
            ->orderBy('created_at')
            ->get();
    }

    public function createSession()
    {
        // Generate default title with current date and time
        $defaultTitle = 'Chat '.now()->format('m-d-Y H:i');

        $session = ChatSession::create([
            'user_id' => Auth::id(),
            'title' => $defaultTitle,
        ]);

        \Log::info('HasSessionManagement::createSession called', [
            'old_session_id' => $this->currentSessionId,
            'new_session_id' => $session->id,
            'default_title' => $defaultTitle,
            'timestamp' => now()->toISOString(),
        ]);

        $oldSessionId = $this->currentSessionId;
        $this->loadSessions();
        $this->currentSessionId = $session->id;
        $this->loadInteractions();

        // Set this as the last active session
        $this->setLastActiveSession($session->id);

        // Clear tool status for the old session when creating a new one
        if ($oldSessionId) {
            $this->dispatch('clearToolStatusForSession', ['sessionId' => $oldSessionId]);
        }

        // Force re-render to update the toolbar with new session ID
        $this->dispatch('$refresh');
    }

    public function deleteSession($sessionId)
    {
        $session = ChatSession::where('id', $sessionId)
            ->where('user_id', Auth::id())
            ->first();

        if ($session) {
            // Delete associated interactions
            ChatInteraction::where('chat_session_id', $sessionId)->delete();

            // Delete the session
            $session->delete();

            // Reload sessions
            $this->loadSessions();

            // If we deleted the current session, switch to the first available one
            if ($this->currentSessionId == $sessionId) {
                if ($this->sessions->isEmpty()) {
                    $this->createSession();
                } else {
                    $this->currentSessionId = $this->sessions->first()->id;
                    $this->loadInteractions();

                    // Set this as the last active session
                    $this->setLastActiveSession($this->currentSessionId);

                    // No need to clear status when switching due to deletion

                    // Force re-render to update the toolbar with new session ID
                    $this->dispatch('$refresh');
                }
            }
        }
    }

    public function openSession($sessionId)
    {
        \Log::info('HasSessionManagement::openSession called', [
            'old_session_id' => $this->currentSessionId,
            'new_session_id' => $sessionId,
            'timestamp' => now()->toISOString(),
        ]);

        $oldSessionId = $this->currentSessionId;
        $this->currentSessionId = $sessionId;
        $this->loadInteractions();

        // Set this as the last active session
        $this->setLastActiveSession($sessionId);

        // Clear tool status for the old session, not all sessions
        if ($oldSessionId && $oldSessionId !== $sessionId) {
            $this->dispatch('clearToolStatusForSession', ['sessionId' => $oldSessionId]);
        }

        // Force re-render to update the toolbar with new session ID
        $this->dispatch('$refresh');
    }

    public function renameSession($sessionId, string $newTitle): void
    {
        $session = ChatSession::where('id', $sessionId)
            ->where('user_id', Auth::id())
            ->first();

        if ($session) {
            $session->update(['title' => $newTitle]);
            $this->loadSessions();
        }
    }

    protected function generateTitle(int $sessionId, string $question, string $answer): void
    {
        $session = ChatSession::find($sessionId);
        if (! $session || $session->title) {
            return;
        }

        try {
            // Use the centralized TitleGenerator service
            $titleGenerator = new \App\Services\TitleGenerator;
            $title = $titleGenerator->generateFromContent($question, $answer);

            if ($title) {
                $session->update(['title' => $title]);
                // refresh cached sessions so the new title appears immediately
                $this->loadSessions();

                Log::info('HasSessionManagement: Generated title using TitleGenerator', [
                    'session_id' => $sessionId,
                    'title' => $title,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('HasSessionManagement: Failed to generate title using TitleGenerator', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            // Fallback: derive from user question
            $title = Str::words($question, 5, '');
            $title = trim($title);

            if ($title) {
                $session->update(['title' => $title]);
                // refresh cached sessions so the new title appears immediately
                $this->loadSessions();
            }
        }
    }

    protected function extractTitleFromUrl(string $url): string
    {
        // Extract a readable title from URL
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? 'Unknown Source';
        $path = $parsed['path'] ?? '';

        // Clean up common patterns
        $title = str_replace(['www.', '-', '_'], [' ', ' ', ' '], $host);
        $title = ucwords($title);

        // Add path info if meaningful
        if ($path && $path !== '/') {
            $pathParts = explode('/', trim($path, '/'));
            $lastPart = end($pathParts);
            if ($lastPart && strlen($lastPart) > 3) {
                $cleanPath = str_replace(['-', '_'], ' ', $lastPart);
                $title .= ' - '.ucwords($cleanPath);
            }
        }

        return $title;
    }

    protected function extractUrlsFromText(string $text): array
    {
        $urlPattern = '/https?:\/\/[^\s<>"]+|www\.[^\s<>"]+/';
        preg_match_all($urlPattern, $text, $matches);

        return $matches[0] ?? [];
    }

    protected function extractSourceLinks($toolResult, int $interactionId): void
    {
        try {
            if (! isset($toolResult->result)) {
                Log::debug('No result in tool result for source extraction', [
                    'interaction_id' => $interactionId,
                    'tool_name' => $toolResult->toolName ?? 'unknown',
                ]);

                return;
            }

            $resultData = json_decode($toolResult->result, true);
            if (! is_array($resultData)) {
                return;
            }

            $sources = [];

            // Handle different response formats
            // 1. Direct metadata.source format
            if (isset($resultData['metadata']['source'])) {
                $sources[] = [
                    'url' => $resultData['metadata']['source'],
                    'title' => $this->extractTitleFromUrl($resultData['metadata']['source']),
                    'tool' => $toolResult->toolName ?? 'unknown',
                ];
            }

            // 2. Multiple citations (like Perplexity)
            if (empty($sources) && isset($resultData['data']['citations']) && is_array($resultData['data']['citations'])) {
                foreach ($resultData['data']['citations'] as $citation) {
                    if (isset($citation['url']) && $citation['type'] === 'markdown_link') {
                        $sources[] = [
                            'url' => $citation['url'],
                            'title' => $citation['text'] ?? $this->extractTitleFromUrl($citation['url']),
                            'tool' => $toolResult->toolName ?? 'unknown',
                        ];
                    }
                }
            }

            // 3. Generic sources array
            if (empty($sources) && isset($resultData['sources']) && is_array($resultData['sources'])) {
                foreach ($resultData['sources'] as $source) {
                    if (is_string($source)) {
                        $sources[] = [
                            'url' => $source,
                            'title' => $this->extractTitleFromUrl($source),
                            'tool' => $toolResult->toolName ?? 'unknown',
                        ];
                    } elseif (is_array($source) && isset($source['url'])) {
                        $sources[] = [
                            'url' => $source['url'],
                            'title' => $source['title'] ?? $this->extractTitleFromUrl($source['url']),
                            'tool' => $toolResult->toolName ?? 'unknown',
                        ];
                    }
                }
            }

            // Store unique sources for this interaction
            if (! empty($sources)) {
                if (! isset($this->sourceLinks[$interactionId])) {
                    $this->sourceLinks[$interactionId] = [];
                }

                foreach ($sources as $source) {
                    // Avoid duplicates
                    $isDuplicate = false;
                    foreach ($this->sourceLinks[$interactionId] as $existing) {
                        if ($existing['url'] === $source['url']) {
                            $isDuplicate = true;
                            break;
                        }
                    }

                    if (! $isDuplicate) {
                        $this->sourceLinks[$interactionId][] = $source;
                    }
                }
            }

        } catch (\Exception $e) {
            Log::error('Failed to extract source links', [
                'interaction_id' => $interactionId,
                'tool_name' => $toolResult->toolName ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if a title is one of our default datetime titles that can be overwritten
     */
    protected function isDefaultDatetimeTitle(?string $title): bool
    {
        return \App\Services\SessionTitleService::isDefaultDatetimeTitle($title);
    }

    /**
     * Filter sessions based on search and filter criteria
     */
    public function filterSessions()
    {
        $this->loadSessions();
    }

    /**
     * Called when sessionSearch property is updated
     */
    public function updatedSessionSearch()
    {
        $this->loadSessions();

        // Clear selections when filter changes to prevent confusion
        if ($this->bulkEditMode) {
            $this->clearBulkSelections();
        }
    }

    /**
     * Called when sessionSourceFilter property is updated
     */
    public function updatedSessionSourceFilter()
    {
        $this->loadSessions();

        // Clear selections when filter changes to prevent confusion
        if ($this->bulkEditMode) {
            $this->clearBulkSelections();
        }
    }

    /**
     * Called when showArchived property is updated
     */
    public function updatedShowArchived()
    {
        $this->loadSessions();

        // Clear selections when filter changes to prevent confusion
        if ($this->bulkEditMode) {
            $this->clearBulkSelections();
        }
    }

    /**
     * Called when showKeptOnly property is updated
     */
    public function updatedShowKeptOnly()
    {
        $this->loadSessions();

        // Clear selections when filter changes to prevent confusion
        if ($this->bulkEditMode) {
            $this->clearBulkSelections();
        }
    }

    /**
     * Toggle keep flag on a session
     */
    public function toggleSessionKeep($sessionId)
    {
        $session = ChatSession::where('id', $sessionId)
            ->where('user_id', Auth::id())
            ->first();

        if ($session) {
            $session->toggleKeep();
            $this->loadSessions();

            Log::info('HasSessionManagement: Toggled keep flag', [
                'session_id' => $sessionId,
                'user_id' => Auth::id(),
                'is_kept' => $session->is_kept,
            ]);
        }
    }

    /**
     * Archive a session
     */
    public function archiveSession($sessionId)
    {
        $session = ChatSession::where('id', $sessionId)
            ->where('user_id', Auth::id())
            ->first();

        if ($session) {
            if ($session->is_kept) {
                Log::warning('HasSessionManagement: Attempted to archive kept session', [
                    'session_id' => $sessionId,
                    'user_id' => Auth::id(),
                ]);

                return;
            }

            $session->archive();
            $this->loadSessions();

            // If we archived the current session, switch to first available one
            if ($this->currentSessionId == $sessionId) {
                if ($this->sessions->isEmpty()) {
                    $this->createSession();
                } else {
                    $this->currentSessionId = $this->sessions->first()->id;
                    $this->loadInteractions();
                    $this->setLastActiveSession($this->currentSessionId);
                    $this->dispatch('$refresh');
                }
            }

            Log::info('HasSessionManagement: Archived session', [
                'session_id' => $sessionId,
                'user_id' => Auth::id(),
            ]);
        }
    }

    /**
     * Unarchive a session
     */
    public function unarchiveSession($sessionId)
    {
        $session = ChatSession::where('id', $sessionId)
            ->where('user_id', Auth::id())
            ->first();

        if ($session && $session->isArchived()) {
            $session->unarchive();
            $this->loadSessions();

            Log::info('HasSessionManagement: Unarchived session', [
                'session_id' => $sessionId,
                'user_id' => Auth::id(),
            ]);
        }
    }

    /**
     * Toggle bulk edit mode on/off
     */
    public function toggleBulkEditMode()
    {
        $this->bulkEditMode = ! $this->bulkEditMode;

        // Clear selections when exiting bulk edit mode
        if (! $this->bulkEditMode) {
            $this->clearBulkSelections();
        }
    }

    /**
     * Toggle session selection
     */
    public function toggleSessionSelection($sessionId)
    {
        if (in_array($sessionId, $this->selectedSessionIds)) {
            $this->selectedSessionIds = array_values(array_diff($this->selectedSessionIds, [$sessionId]));
        } else {
            $this->selectedSessionIds[] = $sessionId;
        }

        // Update select all state
        $this->updateSelectAllState();
    }

    /**
     * Toggle select all filtered sessions
     */
    public function toggleSelectAll()
    {
        $this->selectAll = ! $this->selectAll;

        if ($this->selectAll) {
            // Select all currently filtered sessions
            $this->selectedSessionIds = $this->sessions->pluck('id')->toArray();
        } else {
            $this->selectedSessionIds = [];
        }
    }

    /**
     * Update select all state based on current selections
     */
    protected function updateSelectAllState()
    {
        $visibleSessionIds = $this->sessions->pluck('id')->toArray();
        $this->selectAll = count($visibleSessionIds) > 0
            && count(array_intersect($this->selectedSessionIds, $visibleSessionIds)) === count($visibleSessionIds);
    }

    /**
     * Clear selections after operation
     */
    protected function clearBulkSelections()
    {
        $this->selectedSessionIds = [];
        $this->selectAll = false;
    }

    /**
     * Bulk toggle keep flag on selected sessions
     */
    public function bulkToggleKeep()
    {
        if (empty($this->selectedSessionIds)) {
            return;
        }

        $this->bulkOperationInProgress = true;
        $this->bulkOperationProgress = [
            'current' => 0,
            'total' => count($this->selectedSessionIds),
            'action' => 'Updating sessions',
        ];

        $successCount = 0;
        $errors = [];

        foreach ($this->selectedSessionIds as $index => $sessionId) {
            try {
                $session = ChatSession::where('id', $sessionId)
                    ->where('user_id', Auth::id())
                    ->first();

                if ($session) {
                    $session->toggleKeep();
                    $successCount++;
                } else {
                    $errors[] = "Session {$sessionId} not found or access denied";
                }
            } catch (\Exception $e) {
                $errors[] = "Error updating session {$sessionId}: {$e->getMessage()}";
                Log::error('HasSessionManagement: Bulk keep operation failed for session', [
                    'session_id' => $sessionId,
                    'user_id' => Auth::id(),
                    'error' => $e->getMessage(),
                ]);
            }

            $this->bulkOperationProgress['current'] = $index + 1;
            $this->dispatch('$refresh');
        }

        $this->bulkOperationInProgress = false;
        $this->clearBulkSelections();
        $this->loadSessions();

        Log::info('HasSessionManagement: Bulk keep operation completed', [
            'user_id' => Auth::id(),
            'success_count' => $successCount,
            'error_count' => count($errors),
        ]);
    }

    /**
     * Bulk archive selected sessions
     */
    public function bulkArchive()
    {
        if (empty($this->selectedSessionIds)) {
            return;
        }

        $this->bulkOperationInProgress = true;
        $this->bulkOperationProgress = [
            'current' => 0,
            'total' => count($this->selectedSessionIds),
            'action' => 'Archiving sessions',
        ];

        $successCount = 0;
        $skippedCount = 0;
        $errors = [];

        foreach ($this->selectedSessionIds as $index => $sessionId) {
            try {
                $session = ChatSession::where('id', $sessionId)
                    ->where('user_id', Auth::id())
                    ->first();

                if (! $session) {
                    $errors[] = "Session {$sessionId} not found or access denied";
                } elseif ($session->is_kept) {
                    $skippedCount++;
                    Log::debug('HasSessionManagement: Skipped archiving kept session', [
                        'session_id' => $sessionId,
                    ]);
                } elseif ($session->isArchived()) {
                    $skippedCount++;
                } else {
                    $session->archive();
                    $successCount++;
                }
            } catch (\Exception $e) {
                $errors[] = "Error archiving session {$sessionId}: {$e->getMessage()}";
                Log::error('HasSessionManagement: Bulk archive operation failed for session', [
                    'session_id' => $sessionId,
                    'user_id' => Auth::id(),
                    'error' => $e->getMessage(),
                ]);
            }

            $this->bulkOperationProgress['current'] = $index + 1;
            $this->dispatch('$refresh');
        }

        $this->bulkOperationInProgress = false;

        // Store current selection before clearing
        $wasCurrentSessionSelected = in_array($this->currentSessionId, $this->selectedSessionIds);

        $this->clearBulkSelections();
        $this->loadSessions();

        // If we archived the current session, switch to first available one
        if ($wasCurrentSessionSelected) {
            if ($this->sessions->isEmpty()) {
                $this->createSession();
            } else {
                $this->currentSessionId = $this->sessions->first()->id;
                $this->loadInteractions();
                $this->setLastActiveSession($this->currentSessionId);
            }
        }

        Log::info('HasSessionManagement: Bulk archive operation completed', [
            'user_id' => Auth::id(),
            'success_count' => $successCount,
            'skipped_count' => $skippedCount,
            'error_count' => count($errors),
        ]);
    }

    /**
     * Bulk unarchive selected sessions
     */
    public function bulkUnarchive()
    {
        if (empty($this->selectedSessionIds)) {
            return;
        }

        $this->bulkOperationInProgress = true;
        $this->bulkOperationProgress = [
            'current' => 0,
            'total' => count($this->selectedSessionIds),
            'action' => 'Unarchiving sessions',
        ];

        $successCount = 0;
        $skippedCount = 0;
        $errors = [];

        foreach ($this->selectedSessionIds as $index => $sessionId) {
            try {
                $session = ChatSession::where('id', $sessionId)
                    ->where('user_id', Auth::id())
                    ->first();

                if (! $session) {
                    $errors[] = "Session {$sessionId} not found or access denied";
                } elseif (! $session->isArchived()) {
                    $skippedCount++;
                } else {
                    $session->unarchive();
                    $successCount++;
                }
            } catch (\Exception $e) {
                $errors[] = "Error unarchiving session {$sessionId}: {$e->getMessage()}";
                Log::error('HasSessionManagement: Bulk unarchive operation failed for session', [
                    'session_id' => $sessionId,
                    'user_id' => Auth::id(),
                    'error' => $e->getMessage(),
                ]);
            }

            $this->bulkOperationProgress['current'] = $index + 1;
            $this->dispatch('$refresh');
        }

        $this->bulkOperationInProgress = false;
        $this->clearBulkSelections();
        $this->loadSessions();

        Log::info('HasSessionManagement: Bulk unarchive operation completed', [
            'user_id' => Auth::id(),
            'success_count' => $successCount,
            'skipped_count' => $skippedCount,
            'error_count' => count($errors),
        ]);
    }

    /**
     * Show confirmation dialog for bulk delete
     */
    public function confirmBulkDelete()
    {
        if (empty($this->selectedSessionIds)) {
            return;
        }

        $this->dispatch('show-bulk-delete-confirmation', [
            'count' => count($this->selectedSessionIds),
        ]);
    }

    /**
     * Bulk delete selected sessions
     */
    public function bulkDelete()
    {
        if (empty($this->selectedSessionIds)) {
            return;
        }

        $this->bulkOperationInProgress = true;
        $this->bulkOperationProgress = [
            'current' => 0,
            'total' => count($this->selectedSessionIds),
            'action' => 'Deleting sessions',
        ];

        $successCount = 0;
        $errors = [];

        foreach ($this->selectedSessionIds as $index => $sessionId) {
            try {
                $session = ChatSession::where('id', $sessionId)
                    ->where('user_id', Auth::id())
                    ->first();

                if ($session) {
                    // Delete associated interactions
                    ChatInteraction::where('chat_session_id', $sessionId)->delete();

                    // Delete the session
                    $session->delete();
                    $successCount++;
                } else {
                    $errors[] = "Session {$sessionId} not found or access denied";
                }
            } catch (\Exception $e) {
                $errors[] = "Error deleting session {$sessionId}: {$e->getMessage()}";
                Log::error('HasSessionManagement: Bulk delete operation failed for session', [
                    'session_id' => $sessionId,
                    'user_id' => Auth::id(),
                    'error' => $e->getMessage(),
                ]);
            }

            $this->bulkOperationProgress['current'] = $index + 1;
            $this->dispatch('$refresh');
        }

        $this->bulkOperationInProgress = false;

        // Store current selection before clearing
        $wasCurrentSessionSelected = in_array($this->currentSessionId, $this->selectedSessionIds);

        $this->clearBulkSelections();
        $this->loadSessions();

        // If we deleted the current session, switch to first available one or create new
        if ($wasCurrentSessionSelected) {
            if ($this->sessions->isEmpty()) {
                $this->createSession();
            } else {
                $this->currentSessionId = $this->sessions->first()->id;
                $this->loadInteractions();
                $this->setLastActiveSession($this->currentSessionId);
            }
        }

        Log::info('HasSessionManagement: Bulk delete operation completed', [
            'user_id' => Auth::id(),
            'success_count' => $successCount,
            'error_count' => count($errors),
        ]);
    }
}
