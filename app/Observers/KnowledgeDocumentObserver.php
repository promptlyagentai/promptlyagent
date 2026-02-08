<?php

namespace App\Observers;

use App\Jobs\GenerateDocumentEmbeddings;
use App\Models\KnowledgeDocument;
use Illuminate\Support\Facades\Log;

/**
 * Observer for KnowledgeDocument lifecycle events
 *
 * Orchestrates the RAG pipeline by:
 * - Queuing embedding generation for new/updated documents
 * - Managing Meilisearch index synchronization
 * - Intelligently filtering internal updates to prevent unnecessary reprocessing
 * - Handling soft delete and restore scenarios
 *
 * This observer is central to the knowledge system's search and retrieval capabilities.
 */
class KnowledgeDocumentObserver
{
    /**
     * Handle the KnowledgeDocument "created" event
     *
     * Queues embedding generation job with 5-second delay to allow transaction completion.
     * Job is routed to 'embeddings' queue for processing.
     *
     * @param  KnowledgeDocument  $knowledgeDocument  The newly created document
     */
    public function created(KnowledgeDocument $knowledgeDocument): void
    {
        $this->queueEmbeddingGeneration($knowledgeDocument, 'created');
    }

    /**
     * Handle the KnowledgeDocument "updated" event
     *
     * Implements intelligent update filtering to prevent unnecessary embedding regeneration:
     * - Skips internal processing status updates (processing_status, meilisearch_document_id, etc.)
     * - Only regenerates embeddings when content, title, or description changes
     * - Requires document to be in 'completed' status before regenerating
     * - Uses 15-second delay for update-triggered jobs to batch rapid changes
     *
     * @param  KnowledgeDocument  $knowledgeDocument  The updated document with changes
     */
    public function updated(KnowledgeDocument $knowledgeDocument): void
    {
        $changes = $knowledgeDocument->getChanges();
        $changedFields = array_keys($changes);

        Log::info('KnowledgeDocumentObserver: updated() called', [
            'document_id' => $knowledgeDocument->id,
            'title' => $knowledgeDocument->title,
            'changed_fields' => $changedFields,
            'content_changed' => $knowledgeDocument->wasChanged('content'),
            'title_changed' => $knowledgeDocument->wasChanged('title'),
        ]);

        // Skip embedding generation for internal processing status updates
        if ($this->isInternalProcessingUpdate($changedFields)) {
            Log::debug('KnowledgeDocumentObserver: Skipping embedding generation for internal processing update', [
                'document_id' => $knowledgeDocument->id,
                'changed_fields' => $changedFields,
            ]);

            return;
        }

        // Only regenerate embeddings when document is completed and has meaningful changes
        if ($knowledgeDocument->processing_status === 'completed' && $this->requiresEmbeddingRegeneration($changedFields)) {
            Log::info('KnowledgeDocumentObserver: Document updated, queuing embedding regeneration', [
                'document_id' => $knowledgeDocument->id,
                'changed_fields' => $changedFields,
            ]);

            $this->queueEmbeddingGeneration($knowledgeDocument, 'updated', 15);
        }
    }

    /**
     * Queue embedding generation job for a document
     *
     * Performs safety checks before dispatching:
     * - Verifies document processing status (completed for created/deleted events)
     * - Checks if embedding service is enabled in configuration
     * - Routes to 'embeddings' queue with configurable delay
     *
     * @param  KnowledgeDocument  $document  The document to process
     * @param  string  $event  The triggering event (created|updated|restored)
     * @param  int  $delaySeconds  Delay before job execution (default: 5s, updated: 15s)
     */
    protected function queueEmbeddingGeneration(KnowledgeDocument $document, string $event, int $delaySeconds = 5): void
    {
        // For updated documents, we might be in 'pending' status due to reprocessing
        // So we'll queue the job anyway and let it check the status when it runs
        if ($event !== 'updated' && $document->processing_status !== 'completed') {
            Log::debug('KnowledgeDocumentObserver: Skipping embedding generation, document not completed', [
                'document_id' => $document->id,
                'status' => $document->processing_status,
                'event' => $event,
            ]);

            return;
        }

        // Check if embeddings are enabled
        $embeddingService = app(\App\Services\Knowledge\Embeddings\EmbeddingService::class);
        if (! $embeddingService->isEnabled()) {
            Log::debug('KnowledgeDocumentObserver: Skipping embedding generation, service not enabled', [
                'document_id' => $document->id,
                'event' => $event,
            ]);

            return;
        }

        // Dispatch job with appropriate delay
        try {
            GenerateDocumentEmbeddings::dispatch($document)
                ->delay(now()->addSeconds($delaySeconds))
                ->onQueue('embeddings');

            Log::info('KnowledgeDocumentObserver: Queued embedding generation', [
                'document_id' => $document->id,
                'title' => $document->title,
                'event' => $event,
                'delay_seconds' => $delaySeconds,
            ]);
        } catch (\Exception $e) {
            Log::error('KnowledgeDocumentObserver: Failed to queue embedding generation', [
                'document_id' => $document->id,
                'event' => $event,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Handle the KnowledgeDocument "deleted" event
     *
     * Removes document from Meilisearch index via Laravel Scout.
     * Uses soft-delete aware unsearchable() method to handle index cleanup.
     *
     * @param  KnowledgeDocument  $knowledgeDocument  The deleted document (with soft-delete data still accessible)
     */
    public function deleted(KnowledgeDocument $knowledgeDocument): void
    {
        Log::info('KnowledgeDocumentObserver: deleted() called', [
            'document_id' => $knowledgeDocument->id,
            'title' => $knowledgeDocument->title,
            'meilisearch_id' => $knowledgeDocument->meilisearch_document_id,
        ]);

        // Force Scout to remove document from search index
        try {
            // Make sure Scout removes this document from the search index
            $knowledgeDocument->unsearchable();

            Log::info('KnowledgeDocumentObserver: Document removal queued via Scout', [
                'document_id' => $knowledgeDocument->id,
                'meilisearch_id' => $knowledgeDocument->meilisearch_document_id,
            ]);
        } catch (\Exception $e) {
            Log::error('KnowledgeDocumentObserver: Failed to queue document removal via Scout', [
                'document_id' => $knowledgeDocument->id,
                'meilisearch_id' => $knowledgeDocument->meilisearch_document_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the KnowledgeDocument "restored" event
     *
     * Regenerates embeddings for soft-deleted documents that are restored.
     * Uses standard 5-second delay as restored documents are typically infrequent.
     *
     * @param  KnowledgeDocument  $knowledgeDocument  The restored document
     */
    public function restored(KnowledgeDocument $knowledgeDocument): void
    {
        Log::info('KnowledgeDocumentObserver: Document restored, queuing embedding regeneration', [
            'document_id' => $knowledgeDocument->id,
            'title' => $knowledgeDocument->title,
        ]);

        // Queue embedding regeneration for restored documents
        $this->queueEmbeddingGeneration($knowledgeDocument, 'restored');
    }

    /**
     * Handle the KnowledgeDocument "force deleted" event
     *
     * Performs same cleanup as soft delete by removing from Meilisearch index.
     * Note: Force deletion is permanent - embeddings and index entries are unrecoverable.
     *
     * @param  KnowledgeDocument  $knowledgeDocument  The permanently deleted document
     */
    public function forceDeleted(KnowledgeDocument $knowledgeDocument): void
    {
        Log::warning('KnowledgeDocumentObserver: Document force deleted', [
            'document_id' => $knowledgeDocument->id,
            'title' => $knowledgeDocument->title,
            'note' => 'Permanent deletion - embeddings and index entries unrecoverable',
        ]);

        // Same as deleted - clean up embeddings
        $this->deleted($knowledgeDocument);
    }

    /**
     * Check if update contains only internal processing fields
     *
     * Internal fields (processing_status, processing_error, meilisearch_document_id, updated_at)
     * are changed by the system during document processing and should not trigger
     * embedding regeneration to prevent infinite loops.
     *
     * @param  array<string>  $changedFields  Array of changed field names from getChanges()
     * @return bool True if only internal fields changed, false if user-facing fields changed
     */
    protected function isInternalProcessingUpdate(array $changedFields): bool
    {
        // Internal processing fields that shouldn't trigger embedding regeneration
        $internalFields = [
            'processing_status',
            'processing_error',
            'meilisearch_document_id',
            'updated_at',
        ];

        // If only internal fields changed, skip embedding generation
        $significantChanges = array_diff($changedFields, $internalFields);

        return empty($significantChanges);
    }

    /**
     * Check if changes require embedding regeneration
     *
     * Embedding regeneration is expensive, so only trigger when semantically
     * meaningful fields change (content, title, description).
     *
     * @param  array<string>  $changedFields  Array of changed field names from getChanges()
     * @return bool True if changes affect searchable content, false otherwise
     */
    protected function requiresEmbeddingRegeneration(array $changedFields): bool
    {
        // Fields that require embedding regeneration when changed
        $embeddingTriggerFields = [
            'content',
            'title',
            'description',
        ];

        return ! empty(array_intersect($changedFields, $embeddingTriggerFields));
    }
}
