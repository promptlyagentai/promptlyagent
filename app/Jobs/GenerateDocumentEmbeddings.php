<?php

namespace App\Jobs;

use App\Models\KnowledgeDocument;
use App\Services\Knowledge\KnowledgeManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateDocumentEmbeddings implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 120;

    public $backoff = [30, 60, 120];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public KnowledgeDocument $document
    ) {}

    /**
     * Execute the job.
     */
    public function handle(KnowledgeManager $knowledgeManager): void
    {
        // Refresh the document to get the latest status
        $this->document->refresh();

        Log::info('GenerateDocumentEmbeddings: Starting embedding generation', [
            'document_id' => $this->document->id,
            'title' => $this->document->title,
            'processing_status' => $this->document->processing_status,
        ]);

        try {
            /**
             * Wait for document processing to complete before generating embeddings
             *
             * Configuration values:
             * - maxWaitTime: 30 seconds (prevents infinite wait for failed processing)
             * - sleep interval: 2 seconds (balances responsiveness vs database load)
             *
             * Rationale:
             * Embedding generation requires completed text extraction. If we run too early,
             * we generate embeddings from incomplete/empty content. The 30-second timeout
             * prevents embedding jobs from blocking the queue indefinitely if document
             * processing fails.
             */
            $maxWaitTime = 30; // Maximum seconds to wait for document processing
            $waited = 0;
            while ($this->document->processing_status === 'pending' && $waited < $maxWaitTime) {
                Log::debug('GenerateDocumentEmbeddings: Waiting for document to complete processing', [
                    'document_id' => $this->document->id,
                    'waited_seconds' => $waited,
                ]);
                sleep(2); // Check every 2 seconds (reduce for faster response, increase to reduce database queries)
                $waited += 2;
                $this->document->refresh();
            }

            // Only generate embeddings if the document is completed
            if ($this->document->processing_status !== 'completed') {
                Log::warning('GenerateDocumentEmbeddings: Document not in completed status after waiting, skipping', [
                    'document_id' => $this->document->id,
                    'status' => $this->document->processing_status,
                    'waited_seconds' => $waited,
                ]);

                return;
            }

            // Check if embeddings are already present
            $embeddingStatus = $knowledgeManager->getEmbeddingStatus();
            $documentStatus = collect($embeddingStatus['documents'])
                ->firstWhere('id', $this->document->id);

            if ($documentStatus && $documentStatus['embedding_status'] !== 'missing') {
                Log::info('GenerateDocumentEmbeddings: Document already has embeddings', [
                    'document_id' => $this->document->id,
                    'embedding_status' => $documentStatus['embedding_status'],
                ]);

                return;
            }

            // Generate embeddings using the regenerate method
            $success = $knowledgeManager->regenerateDocumentEmbedding($this->document);

            if ($success) {
                Log::info('GenerateDocumentEmbeddings: Successfully generated embeddings', [
                    'document_id' => $this->document->id,
                    'title' => $this->document->title,
                    'duration_seconds' => now()->diffInSeconds($this->document->created_at),
                    'attempt' => $this->attempts(),
                ]);
            } else {
                Log::error('GenerateDocumentEmbeddings: Failed to generate embeddings', [
                    'document_id' => $this->document->id,
                    'title' => $this->document->title,
                ]);

                // Throw exception to trigger retry
                throw new \Exception('Failed to generate embeddings for document '.$this->document->id);
            }

        } catch (\Exception $e) {
            Log::error('GenerateDocumentEmbeddings: Exception during embedding generation', [
                'document_id' => $this->document->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateDocumentEmbeddings: Job failed after all retries', [
            'document_id' => $this->document->id,
            'error' => $exception->getMessage(),
        ]);

        // Optionally update document status or notify user
        $this->document->update([
            'metadata' => array_merge($this->document->metadata ?? [], [
                'embedding_generation_failed' => true,
                'embedding_generation_error' => $exception->getMessage(),
                'embedding_generation_failed_at' => now()->toISOString(),
            ]),
        ]);
    }

    /**
     * Determine the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['knowledge', 'embeddings', 'document:'.$this->document->id];
    }
}
