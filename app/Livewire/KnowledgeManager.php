<?php

namespace App\Livewire;

use App\Models\KnowledgeDocument;
use App\Models\KnowledgeTag;
use App\Services\Knowledge\KnowledgeManager as KnowledgeManagerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Knowledge document manager with semantic search and bulk operations.
 *
 * Features:
 * - Hybrid search (keyword + semantic) via Meilisearch
 * - Bulk operations (delete, reindex)
 * - Document preview and download
 * - Embedding status tracking
 * - Full reindex with queue-based embedding generation
 *
 * @property string $search Search query (triggers semantic search)
 * @property array<int> $selectedDocuments Documents selected for bulk operations
 * @property array<int, float> $searchRelevanceScores Document ID => relevance score mapping
 * @property array{total_documents: int, with_embeddings: int, ...} $embeddingStatistics
 */
class KnowledgeManager extends Component
{
    use WithFileUploads, WithPagination;

    public $search = '';

    public $showOnlyMyDocuments = true;

    public $selectedContentType = 'all';

    public $selectedPrivacyLevel = 'all';

    public $selectedStatus = 'all';

    public $selectedTags = [];

    public $includeExpired = false;

    public $showCreateModal = false;

    public $editingDocument = null;

    // Modal state for bulk operations
    public $showBulkDeleteModal = false;

    public $showReindexConfirmModal = false;

    public $selectedDocuments = [];

    // Preview modal state
    public $showPreviewModal = false;

    public $previewDocument = null;

    // Embedding status
    public $showEmbeddingStatus = false;

    public $embeddingStatistics = [];

    // Search relevance scores indexed by document ID
    public $searchRelevanceScores = [];

    protected $listeners = [
        'document-saved' => 'refreshDocumentList',
        'closeDocumentEditor' => 'closeModal',
        'openDocumentEditor' => 'openRegularEditor',
        'document-deleted' => 'refreshDocumentList',
        'tags-updated' => 'refreshDocumentList',
        'external-knowledge-created' => 'refreshDocumentList',
        'refresh-knowledge-list' => 'refreshDocumentList',
    ];

    protected $queryString = [
        'search' => ['except' => ''],
        'showOnlyMyDocuments' => ['except' => true],
        'selectedContentType' => ['except' => 'all'],
        'selectedPrivacyLevel' => ['except' => 'all'],
        'selectedStatus' => ['except' => 'all'],
        'includeExpired' => ['except' => false],
    ];

    public function mount($document = null)
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        // If a document ID was provided in the URL, auto-open the editor
        if ($document) {
            $knowledgeDocument = KnowledgeDocument::find($document);

            if ($knowledgeDocument && Gate::allows('view', $knowledgeDocument)) {
                // Use editDocument method to handle opening the appropriate editor
                $this->editDocument($knowledgeDocument);
            }
        }
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingShowOnlyMyDocuments()
    {
        $this->resetPage();
    }

    public function updatingSelectedContentType()
    {
        $this->resetPage();
    }

    public function updatingSelectedPrivacyLevel()
    {
        $this->resetPage();
    }

    public function updatingSelectedStatus()
    {
        $this->resetPage();
    }

    public function updatingIncludeExpired()
    {
        $this->resetPage();
    }

    public function createDocument()
    {
        // Ensure clean state for creating new documents
        $this->editingDocument = null;
        $this->showCreateModal = false; // Reset first

        // Then show the modal
        $this->showCreateModal = true;
    }

    public function editDocument(KnowledgeDocument $document)
    {
        // Check permissions
        if (! Gate::allows('update', $document)) {
            $this->dispatch('error', 'You do not have permission to edit this document.');

            return;
        }

        // Check if this is a refreshable document (external or text with URL)
        if ($document->content_type === 'external' ||
            ($document->content_type === 'text' && $document->external_source_identifier)) {
            // Dispatch event to open external knowledge editor (now supports both types)
            $this->dispatch('open-external-knowledge-editor', $document->id);

            return;
        }

        // Regular document - open standard editor
        $this->editingDocument = $document;
        $this->showCreateModal = true;
        $this->dispatch('openDocumentEditor', $document->id);
    }

    public function duplicateDocument(KnowledgeDocument $document)
    {
        try {
            $knowledgeService = app(KnowledgeManagerService::class);

            // Create duplicate with same content but new title
            $newDocument = $knowledgeService->createFromText(
                content: $document->content,
                title: 'Copy of '.$document->title,
                description: $document->description,
                tags: $document->tags->pluck('name')->toArray(),
                privacyLevel: $document->privacy_level,
                ttlHours: $document->ttl_expires_at ?
                    now()->diffInHours($document->ttl_expires_at, false) : null,
                userId: Auth::id()
            );

            $this->dispatch('success', "Document '{$newDocument->title}' created as a duplicate.");
            $this->resetPage();

        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to duplicate document: '.$e->getMessage());
        }
    }

    public function toggleDocumentStatus(KnowledgeDocument $document)
    {
        // Check permissions
        if (! Gate::allows('update', $document)) {
            $this->dispatch('error', 'You do not have permission to modify this document.');

            return;
        }

        try {
            $newStatus = $document->processing_status === 'completed' ? 'inactive' : 'completed';
            $document->update(['processing_status' => $newStatus]);

            $statusText = $newStatus === 'completed' ? 'reactivated' : 'deactivated';
            $this->dispatch('success', "Document '{$document->title}' has been {$statusText}.");

        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to update document status: '.$e->getMessage());
        }
    }

    public function reprocessDocument(KnowledgeDocument $document)
    {
        if (! Gate::allows('refresh', $document)) {
            $this->dispatch('error', 'You do not have permission to reprocess this document.');

            return;
        }

        try {
            $knowledgeService = app(KnowledgeManagerService::class);
            $result = $knowledgeService->reprocessDocument($document);

            if ($result) {
                $this->dispatch('success', "Document '{$document->title}' has been queued for reprocessing.");
            } else {
                $this->dispatch('error', 'Failed to reprocess document. Check the error details.');
            }

        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to reprocess document: '.$e->getMessage());
        }
    }

    public function deleteDocument(KnowledgeDocument $document)
    {
        // Check permissions
        if (! Gate::allows('delete', $document)) {
            $this->dispatch('error', 'You do not have permission to delete this document.');

            return;
        }

        try {
            $documentTitle = $document->title;
            $knowledgeService = app(KnowledgeManagerService::class);
            $knowledgeService->deleteDocument($document);

            $this->dispatch('success', "Document '{$documentTitle}' has been deleted.");
            $this->resetPage();

        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to delete document: '.$e->getMessage());
        }
    }

    public function downloadFile(KnowledgeDocument $document)
    {
        // Check if user can access this document
        if (! Gate::allows('download', $document)) {
            $this->dispatch('error', 'You do not have permission to download this file.');

            return;
        }

        // Check if it's a file document
        if ($document->content_type !== 'file' || ! $document->asset) {
            $this->dispatch('error', 'This document does not have an associated file.');

            return;
        }

        // Check if file exists
        if (! $document->asset->exists()) {
            $this->dispatch('error', 'The file could not be found.');

            return;
        }

        // Redirect to download route
        return redirect()->route('knowledge.download', $document);
    }

    public function toggleDocumentSelection($documentId)
    {
        if (in_array($documentId, $this->selectedDocuments)) {
            $this->selectedDocuments = array_filter($this->selectedDocuments, fn ($id) => $id != $documentId);
        } else {
            $this->selectedDocuments[] = $documentId;
        }
    }

    public function selectAllDocuments()
    {
        $documents = $this->getDocumentsQuery()->get();
        $this->selectedDocuments = $documents->pluck('id')->toArray();
    }

    public function clearSelection()
    {
        $this->selectedDocuments = [];
    }

    public function bulkDelete()
    {
        if (empty($this->selectedDocuments)) {
            $this->dispatch('error', 'No documents selected.');

            return;
        }

        $this->showBulkDeleteModal = true;
    }

    public function confirmBulkDelete()
    {
        try {
            $knowledgeService = app(KnowledgeManagerService::class);
            $deletedCount = 0;

            foreach ($this->selectedDocuments as $documentId) {
                $document = KnowledgeDocument::find($documentId);
                if ($document && Gate::allows('delete', $document)) {
                    $knowledgeService->deleteDocument($document);
                    $deletedCount++;
                }
            }

            $this->selectedDocuments = [];
            $this->showBulkDeleteModal = false;

            $this->dispatch('success', "Successfully deleted {$deletedCount} documents.");
            $this->resetPage();

        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to delete documents: '.$e->getMessage());
        }
    }

    public function closeModal()
    {
        $this->showCreateModal = false;
        $this->showBulkDeleteModal = false;
        $this->editingDocument = null;
    }

    public function refreshDocumentList()
    {
        // Reset pagination to page 1
        $this->resetPage();

        // Clear any cached data
        $this->selectedDocuments = [];
        $this->searchRelevanceScores = [];

        // Force Livewire to re-render with fresh data
        $this->dispatch('$refresh');
    }

    public function openRegularEditor($documentId)
    {
        $document = KnowledgeDocument::find($documentId);

        // Check if user can edit this document
        if (! $document || ! Gate::allows('update', $document)) {
            $this->dispatch('error', 'You do not have permission to edit this document.');

            return;
        }

        // Open the regular knowledge editor
        $this->editingDocument = $document;
        $this->showCreateModal = true;
    }

    public function openPreviewModal($documentId)
    {
        $document = KnowledgeDocument::find($documentId);

        // Check if user can view this document
        if (! $document || ! Gate::allows('view', $document)) {
            $this->dispatch('error', 'You do not have permission to preview this document.');

            return;
        }

        $this->previewDocument = $document;
        $this->showPreviewModal = true;
    }

    public function closePreviewModal()
    {
        $this->showPreviewModal = false;
        $this->previewDocument = null;
    }

    public function isMarkdownDocument($document): bool
    {
        if (! $document) {
            return false;
        }

        // Text documents are always treated as markdown
        if ($document->content_type === 'text') {
            return true;
        }

        // External documents are always treated as markdown (Notion, etc.)
        if ($document->content_type === 'external') {
            return true;
        }

        // File documents - check MIME type and extension
        if ($document->content_type === 'file' && $document->asset) {
            // Check MIME type
            if ($document->asset->mime_type === 'text/markdown') {
                return true;
            }

            // Check file extension
            $ext = strtolower(pathinfo($document->asset->original_filename, PATHINFO_EXTENSION));
            if ($ext === 'md') {
                return true;
            }
        }

        return false;
    }

    public function toggleEmbeddingStatus()
    {
        $this->showEmbeddingStatus = ! $this->showEmbeddingStatus;

        if ($this->showEmbeddingStatus) {
            $this->loadEmbeddingStatistics();
        }
    }

    public function regenerateEmbeddings()
    {
        try {
            $knowledgeManager = app(KnowledgeManagerService::class);
            $results = $knowledgeManager->regenerateMissingEmbeddings(50); // Limit to 50 documents per batch

            if ($results['successful'] > 0) {
                $this->dispatch('success', "Started embedding regeneration for {$results['processed']} documents. {$results['successful']} initiated successfully.");
            } else {
                $this->dispatch('warning', 'No documents needed embedding regeneration or embedding service is disabled.');
            }

            // Refresh statistics
            $this->loadEmbeddingStatistics();

        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to regenerate embeddings: '.$e->getMessage());
        }
    }

    protected function getDocumentsQuery()
    {
        // If we have a search query, use semantic search for better results
        if (! empty($this->search)) {
            return $this->getSemanticSearchResults();
        }

        // Clear relevance scores when not searching
        $this->searchRelevanceScores = [];

        // Otherwise use traditional database query
        $query = KnowledgeDocument::with(['creator', 'tags'])
            ->withCount('tags');

        // Owner filter
        if ($this->showOnlyMyDocuments) {
            $query->where('created_by', Auth::id());
        } else {
            // Use the forUser scope which handles admin override
            \Log::debug('KnowledgeManager: Applying forUser scope', [
                'user_id' => Auth::id(),
                'is_admin' => Auth::user() ? Auth::user()->isAdmin() : false,
            ]);
            $query->forUser(Auth::id());
        }

        // Content type filter
        if ($this->selectedContentType !== 'all') {
            $query->where('content_type', $this->selectedContentType);
        }

        // Privacy level filter
        if ($this->selectedPrivacyLevel !== 'all') {
            $query->where('privacy_level', $this->selectedPrivacyLevel);
        }

        // Status filter
        if ($this->selectedStatus !== 'all') {
            $query->where('processing_status', $this->selectedStatus);
        }

        // Tags filter
        if (! empty($this->selectedTags)) {
            $query->whereHas('tags', function ($tagQuery) {
                $tagQuery->whereIn('name', $this->selectedTags);
            });
        }

        // TTL/Expiration filter
        if (! $this->includeExpired) {
            $query->where(function ($q) {
                $q->whereNull('ttl_expires_at')
                    ->orWhere('ttl_expires_at', '>', now());
            });
        }

        return $query->orderBy('updated_at', 'desc');
    }

    protected function getSemanticSearchResults()
    {
        try {
            // Use Scout directly to get relevance scores from Meilisearch
            $builder = KnowledgeDocument::hybridSearch(
                query: $this->search,
                embedding: null,
                semanticRatio: config('knowledge.search.semantic_ratio.knowledge_manager', 0.3),
                relevanceThreshold: config('knowledge.search.relevance_threshold', 0.7)
            )->take(100);

            // Get raw search results with scores using Scout's raw() method
            $rawResults = $builder->raw();

            // Extract relevance scores from raw Meilisearch results
            $this->searchRelevanceScores = [];
            if (isset($rawResults['hits']) && is_array($rawResults['hits'])) {
                foreach ($rawResults['hits'] as $hit) {
                    $documentId = $hit['document_id'] ?? $hit['id'] ?? null;
                    $score = $hit['_rankingScore'] ?? null;
                    if ($documentId && $score !== null) {
                        // Validate that document ID is numeric and score is a valid float
                        if (is_numeric($documentId) && is_numeric($score)) {
                            $this->searchRelevanceScores[(int) $documentId] = (float) $score;
                        }
                    }
                }
            }

            // Get the documents using Scout's normal flow
            $searchResults = $builder->get();
            $documentIds = $searchResults->pluck('id')->toArray();

            if (empty($documentIds)) {
                // Return empty query if no search results
                return KnowledgeDocument::with(['creator', 'tags'])
                    ->withCount('tags')
                    ->whereRaw('1 = 0'); // Always false condition
            }

            // Build query with semantic search results as base
            $query = KnowledgeDocument::with(['creator', 'tags'])
                ->withCount('tags')
                ->whereIn('id', $documentIds);

            // Apply additional filters
            if ($this->showOnlyMyDocuments) {
                $query->where('created_by', Auth::id());
            } else {
                // Use the forUser scope which handles admin override
                $query->forUser(Auth::id());
            }

            // Content type filter
            if ($this->selectedContentType !== 'all') {
                $query->where('content_type', $this->selectedContentType);
            }

            // Privacy level filter
            if ($this->selectedPrivacyLevel !== 'all') {
                $query->where('privacy_level', $this->selectedPrivacyLevel);
            }

            // Status filter
            if ($this->selectedStatus !== 'all') {
                $query->where('processing_status', $this->selectedStatus);
            }

            // Tags filter
            if (! empty($this->selectedTags)) {
                $query->whereHas('tags', function ($tagQuery) {
                    $tagQuery->whereIn('name', $this->selectedTags);
                });
            }

            // TTL/Expiration filter (redundant but kept for safety)
            if (! $this->includeExpired) {
                $query->where(function ($q) {
                    $q->whereNull('ttl_expires_at')
                        ->orWhere('ttl_expires_at', '>', now());
                });
            }

            // Order by semantic relevance (preserve the order from search results)
            $documentIdsOrdered = $documentIds;

            // Use FIELD() to maintain semantic search result ordering with sanitized integer IDs
            if (! empty($documentIdsOrdered)) {
                // Sanitize IDs and use parameter binding for SQL safety
                $safeIds = array_map('intval', array_filter($documentIdsOrdered, 'is_numeric'));
                if (! empty($safeIds)) {
                    $placeholders = implode(',', array_fill(0, count($safeIds), '?'));
                    $query->orderByRaw("FIELD(id, {$placeholders})", $safeIds);
                }
            }

            return $query;

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('KnowledgeManager: Semantic search failed, falling back to database search', [
                'error' => $e->getMessage(),
                'search_query' => $this->search,
            ]);

            // Clear relevance scores on fallback
            $this->searchRelevanceScores = [];

            // Fallback to traditional database search
            $query = KnowledgeDocument::with(['creator', 'tags'])
                ->withCount('tags')
                ->where(function ($q) {
                    $q->where('title', 'like', '%'.$this->search.'%')
                        ->orWhere('description', 'like', '%'.$this->search.'%')
                        ->orWhere('content', 'like', '%'.$this->search.'%');
                });

            // Apply all the same filters as the traditional query
            if ($this->showOnlyMyDocuments) {
                $query->where('created_by', Auth::id());
            } else {
                // Use the forUser scope which handles admin override
                $query->forUser(Auth::id());
            }

            if ($this->selectedContentType !== 'all') {
                $query->where('content_type', $this->selectedContentType);
            }

            if ($this->selectedPrivacyLevel !== 'all') {
                $query->where('privacy_level', $this->selectedPrivacyLevel);
            }

            if ($this->selectedStatus !== 'all') {
                $query->where('processing_status', $this->selectedStatus);
            }

            if (! empty($this->selectedTags)) {
                $query->whereHas('tags', function ($tagQuery) {
                    $tagQuery->whereIn('name', $this->selectedTags);
                });
            }

            if (! $this->includeExpired) {
                $query->where(function ($q) {
                    $q->whereNull('ttl_expires_at')
                        ->orWhere('ttl_expires_at', '>', now());
                });
            }

            return $query->orderBy('updated_at', 'desc');
        }
    }

    private function ensureIndexIsClean($vectorStore)
    {
        $maxAttempts = 10;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            try {
                // Try to get the index info - if it throws an exception, the index doesn't exist
                $meilisearchClient = $vectorStore->getClient();
                $indexInfo = $meilisearchClient->getIndex('knowledge_documents');

                if ($indexInfo) {
                    // Index still exists, try to delete it again
                    $attemptNum = $attempt + 1;
                    $this->dispatch('info', "Attempt {$attemptNum}: Index still exists, deleting again...");

                    try {
                        $deleteResult = $meilisearchClient->deleteIndex('knowledge_documents');
                        if (isset($deleteResult['taskUid'])) {
                            $meilisearchClient->waitForTask($deleteResult['taskUid']);
                        }
                        sleep(2);
                    } catch (\Exception $e) {
                        $this->dispatch('info', 'Delete attempt failed: '.$e->getMessage());
                    }
                }

                $attempt++;
                sleep(1);
            } catch (\Exception $e) {
                // Index doesn't exist (this is what we want)
                $this->dispatch('info', 'Confirmed: Index does not exist');

                return true;
            }
        }

        $this->dispatch('warning', 'Could not confirm index deletion after multiple attempts');

        return false;
    }

    public function reindexEverything()
    {
        try {
            $knowledgeManager = app(KnowledgeManagerService::class);
            $vectorStore = app(\App\Services\Knowledge\VectorStores\MeilisearchVectorStore::class);
            $meilisearchClient = $vectorStore->getClient();

            // Get current document counts before deletion
            $totalDocuments = KnowledgeDocument::count();
            $currentIndexCount = $vectorStore->getDocumentCount();

            $this->dispatch('info', "Starting full reindex of {$totalDocuments} documents...");

            // Step 1: Delete the entire Meilisearch index (full flush)
            try {
                // This completely deletes the index, not just the documents
                $deleteResult = $meilisearchClient->deleteIndex('knowledge_documents');

                if (isset($deleteResult['taskUid'])) {
                    // Wait for the deletion to complete
                    $meilisearchClient->waitForTask($deleteResult['taskUid']);
                }

                $this->dispatch('info', "Completely deleted existing index ({$currentIndexCount} documents removed)");

                // Ensure the index is completely clean
                if ($this->ensureIndexIsClean($vectorStore)) {
                    $this->dispatch('info', 'Verified index is completely clean');
                } else {
                    $this->dispatch('warning', 'Index may still contain old documents');
                }
            } catch (\Exception $e) {
                // If index doesn't exist or deletion fails, that's okay, continue
                $this->dispatch('info', 'Cleared existing index (or index did not exist)');
            }

            // Step 2: Recreate the index with proper settings
            try {
                $vectorStore->createIndex();
                $this->dispatch('info', 'Recreated index with proper settings');

                // Give the index creation time to complete
                sleep(3);
            } catch (\Exception $e) {
                $this->dispatch('warning', 'Index creation may have failed: '.$e->getMessage());
            }

            // Step 3: Configure embedder if embeddings are enabled
            $embeddingService = app(\App\Services\Knowledge\Embeddings\EmbeddingService::class);
            if ($embeddingService->isEnabled()) {
                try {
                    $vectorStore->configureEmbedder();
                    $this->dispatch('info', 'Configured embedder for vector search');
                    sleep(2);
                } catch (\Exception $e) {
                    $this->dispatch('warning', 'Embedder configuration may not be supported in this Meilisearch version');
                }
            }

            // Step 4: Clear any stale Meilisearch document IDs from database before Scout import
            try {
                \DB::table('knowledge_documents')
                    ->whereNotNull('meilisearch_document_id')
                    ->update(['meilisearch_document_id' => null]);

                $this->dispatch('info', 'Cleared stale Meilisearch document IDs');
            } catch (\Exception $e) {
                $this->dispatch('warning', 'Failed to clear stale document IDs: '.$e->getMessage());
            }

            // Step 5: Trigger Scout reindex to populate with basic document data
            try {
                // Temporarily disable Scout queuing for immediate processing
                $originalQueueSetting = config('scout.queue');
                config(['scout.queue' => false]);

                $this->dispatch('info', 'Starting Scout import (this may take a few minutes)...');

                // Use chunk-based import for better memory management and progress tracking
                $completedDocuments = KnowledgeDocument::where('processing_status', 'completed')->get();
                $totalDocs = $completedDocuments->count();
                $processed = 0;

                // Process in smaller chunks to avoid timeouts
                $completedDocuments->chunk(5)->each(function ($chunk) use (&$processed, $totalDocs) {
                    foreach ($chunk as $document) {
                        try {
                            // Sync each document individually to avoid bulk timeout issues
                            $document->searchable();
                            $processed++;

                            if ($processed % 5 === 0) {
                                $this->dispatch('info', "Indexed {$processed}/{$totalDocs} documents...");
                            }
                        } catch (\Exception $docError) {
                            $this->dispatch('warning', "Failed to index document {$document->id}: ".$docError->getMessage());
                        }

                        // Small delay to prevent overwhelming Meilisearch
                        usleep(100000); // 0.1 second delay
                    }
                });

                // Restore original queue setting
                config(['scout.queue' => $originalQueueSetting]);

                // Wait for all operations to complete
                sleep(3);

                // Verify the import worked
                $indexCount = $vectorStore->getDocumentCount();
                $this->dispatch('info', "Scout import completed: {$processed} documents processed, {$indexCount} documents in index");

            } catch (\Exception $e) {
                // Restore queue setting on error
                config(['scout.queue' => $originalQueueSetting ?? true]);
                $this->dispatch('warning', 'Scout reindex failed: '.$e->getMessage());
            }

            // Step 6: Use the queue system for embedding generation (instead of synchronous processing)
            if ($embeddingService->isEnabled()) {
                try {
                    // Get all completed documents for embedding generation
                    $completedDocuments = KnowledgeDocument::where('processing_status', 'completed')
                        ->orderBy('created_at', 'desc')
                        ->get();

                    if ($completedDocuments->count() > 0) {
                        $this->dispatch('info', "Queuing embedding generation jobs for {$completedDocuments->count()} documents...");

                        // Queue embedding jobs for each document (this will trigger the observer system)
                        foreach ($completedDocuments as $document) {
                            // Use the existing job system with a small delay
                            \App\Jobs\GenerateDocumentEmbeddings::dispatch($document)
                                ->delay(now()->addSeconds(2))
                                ->onQueue('embeddings');
                        }

                        $this->dispatch('success', "Queued {$completedDocuments->count()} embedding generation jobs. Check queue status to monitor progress.");
                    } else {
                        $this->dispatch('info', 'No completed documents found for embedding generation');
                    }
                } catch (\Exception $e) {
                    $this->dispatch('error', 'Failed to queue embedding jobs: '.$e->getMessage());
                }
            } else {
                $this->dispatch('info', 'Embeddings disabled, skipping embedding generation');
            }

            // Step 7: Refresh statistics and UI
            $this->loadEmbeddingStatistics();
            $this->dispatch('$refresh');

            $this->dispatch('success', "Full reindex initiated! Check the queue for embedding generation progress. Processed {$totalDocuments} documents.");

        } catch (\Exception $e) {
            $this->dispatch('error', 'Full reindex failed: '.$e->getMessage());
        }
    }

    public function getDocumentEmbeddingStatus(KnowledgeDocument $document): array
    {
        $knowledgeManager = app(KnowledgeManagerService::class);

        return $knowledgeManager->getDocumentEmbeddingStatus($document);
    }

    /**
     * Get the search relevance score for a document (if available from search results)
     */
    public function getDocumentRelevanceScore(KnowledgeDocument $document): ?float
    {
        return $this->searchRelevanceScores[$document->id] ?? null;
    }

    /**
     * Load embedding statistics for the embedding status display
     */
    public function loadEmbeddingStatistics(): void
    {
        try {
            $knowledgeManager = app(KnowledgeManagerService::class);
            $this->embeddingStatistics = $knowledgeManager->getEmbeddingStatistics();

        } catch (\Exception $e) {
            Log::warning('Failed to load embedding statistics', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Set default statistics on error
            $this->embeddingStatistics = [
                'total_documents' => 0,
                'with_embeddings' => 0,
                'without_embeddings' => 0,
                'processing_failed' => 0,
                'completion_rate' => 0,
                'embedding_service_enabled' => false,
                'embedding_provider' => 'disabled',
                'embedding_model' => null,
                'error' => 'Failed to load statistics',
            ];
        }
    }

    /**
     * Get unified indexing status for a document
     * Returns array with status, color, icon, and label
     */
    public function getDocumentIndexingStatus(KnowledgeDocument $document): array
    {
        // If document is not processed yet, show processing status
        if ($document->processing_status !== 'completed') {
            switch ($document->processing_status) {
                case 'processing':
                    return [
                        'status' => 'processing',
                        'color' => 'yellow',
                        'icon' => 'clock',
                        'label' => 'Processing',
                    ];
                case 'pending':
                    return [
                        'status' => 'pending',
                        'color' => 'zinc',
                        'icon' => 'clock',
                        'label' => 'Pending',
                    ];
                case 'failed':
                    return [
                        'status' => 'failed',
                        'color' => 'red',
                        'icon' => 'x-circle',
                        'label' => 'Failed',
                    ];
            }
        }

        // For completed documents, check indexing and embedding status
        $isIndexed = ! empty($document->meilisearch_document_id);

        if (! $isIndexed) {
            return [
                'status' => 'not_indexed',
                'color' => 'red',
                'icon' => 'x-circle',
                'label' => 'Not Indexed',
            ];
        }

        // Document is indexed, now check embedding status
        $embeddingService = app(\App\Services\Knowledge\Embeddings\EmbeddingService::class);
        if (! $embeddingService->isEnabled()) {
            // If embeddings are disabled, indexed is the best we can do
            return [
                'status' => 'indexed',
                'color' => 'green',
                'icon' => 'check-circle',
                'label' => 'Indexed',
            ];
        }

        // Check embedding status
        $knowledgeManager = app(KnowledgeManagerService::class);
        $embeddingStatus = $knowledgeManager->getDocumentEmbeddingStatus($document);

        if ($embeddingStatus['status'] === 'available') {
            return [
                'status' => 'indexed_embedded',
                'color' => 'green',
                'icon' => 'check-circle',
                'label' => 'Indexed + Embedded',
            ];
        } else {
            // Indexed but no embeddings
            return [
                'status' => 'indexed_only',
                'color' => 'yellow',
                'icon' => 'exclamation-triangle',
                'label' => 'Indexed Only',
            ];
        }
    }

    public function render()
    {
        $documents = $this->getDocumentsQuery()->paginate(10);
        // Get tags from documents that match the current filters
        $availableTags = KnowledgeTag::select('name', 'slug', 'color')
            ->whereHas('documents', function ($query) {
                // Apply the same filtering logic as getDocumentsQuery()

                // Owner filter
                if ($this->showOnlyMyDocuments) {
                    $query->where('created_by', Auth::id());
                } else {
                    // Show public documents and user's own documents
                    $query->where(function ($q) {
                        $q->where('privacy_level', 'public')
                            ->orWhere('created_by', Auth::id());
                    });
                }

                // Content type filter
                if ($this->selectedContentType !== 'all') {
                    $query->where('content_type', $this->selectedContentType);
                }

                // Privacy level filter
                if ($this->selectedPrivacyLevel !== 'all') {
                    $query->where('privacy_level', $this->selectedPrivacyLevel);
                }

                // Status filter
                if ($this->selectedStatus !== 'all') {
                    $query->where('processing_status', $this->selectedStatus);
                }

                // TTL/Expiration filter
                if (! $this->includeExpired) {
                    $query->where(function ($q) {
                        $q->whereNull('ttl_expires_at')
                            ->orWhere('ttl_expires_at', '>', now());
                    });
                }
            })
            ->orderBy('name')
            ->get();

        return view('livewire.knowledge-manager', [
            'documents' => $documents,
            'availableTags' => $availableTags,
        ])->layout('components.layouts.app', [
            'title' => 'Knowledge Manager',
        ]);
    }
}
