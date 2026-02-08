<?php

namespace App\Jobs;

use App\Models\KnowledgeDocument;
use App\Services\Knowledge\ExternalKnowledgeManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshExternalKnowledgeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 300; // 5 minutes

    public $backoff = [30, 60, 120]; // Backoff delays in seconds

    /**
     * Create a new job instance.
     */
    public function __construct(
        public KnowledgeDocument $document
    ) {
        $this->onQueue('knowledge');
    }

    /**
     * Execute the job.
     */
    public function handle(ExternalKnowledgeManager $externalKnowledgeManager): void
    {
        // Refresh the document from database to get latest state with relationships
        $this->document = KnowledgeDocument::with('integration.integrationToken')->find($this->document->id);

        if (! $this->document || ! in_array($this->document->content_type, ['external', 'text'])) {
            Log::warning('RefreshExternalKnowledgeJob received non-refreshable document', [
                'document_id' => $this->document->id,
                'content_type' => $this->document->content_type,
            ]);

            return;
        }

        // Update refresh tracking - mark as in progress
        $this->document->update([
            'last_refresh_attempted_at' => now(),
            'last_refresh_status' => 'in_progress',
            'refresh_attempt_count' => ($this->document->refresh_attempt_count ?? 0) + 1,
        ]);

        try {
            Log::info('Starting refresh for knowledge document', [
                'document_id' => $this->document->id,
                'content_type' => $this->document->content_type,
                'source_identifier' => $this->document->external_source_identifier,
                'source_type' => $this->document->source_type,
                'attempt' => $this->document->refresh_attempt_count,
            ]);

            $wasUpdated = $externalKnowledgeManager->refreshDocument($this->document);

            // Refresh document to get any updates from refreshDocument()
            $this->document = KnowledgeDocument::with('integration.integrationToken')->find($this->document->id);

            // Update refresh tracking - mark as successful
            $this->document->update([
                'last_refresh_status' => 'success',
                'last_refresh_error' => null, // Clear any previous errors
            ]);

            if ($wasUpdated) {
                Log::info('Knowledge document refreshed successfully', [
                    'document_id' => $this->document->id,
                    'content_type' => $this->document->content_type,
                    'source_identifier' => $this->document->external_source_identifier,
                ]);
            } else {
                Log::info('Knowledge document checked - no changes detected', [
                    'document_id' => $this->document->id,
                    'content_type' => $this->document->content_type,
                    'source_identifier' => $this->document->external_source_identifier,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Failed to refresh knowledge document', [
                'document_id' => $this->document->id,
                'content_type' => $this->document->content_type,
                'source_identifier' => $this->document->external_source_identifier,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Refresh document before updating error status
            $this->document = KnowledgeDocument::with('integration.integrationToken')->find($this->document->id);

            // Update refresh tracking - mark as failed
            $this->document->update([
                'last_refresh_status' => 'failed',
                'last_refresh_error' => $e->getMessage(),
            ]);

            // Mark document as having processing error on final failure
            if ($this->attempts() >= $this->tries) {
                Log::warning('RefreshExternalKnowledgeJob: Final retry failed - marking document as failed', [
                    'document_id' => $this->document->id,
                    'source_identifier' => $this->document->external_source_identifier,
                    'total_attempts' => $this->attempts(),
                    'error' => $e->getMessage(),
                ]);

                $this->document->update([
                    'processing_status' => 'failed',
                    'processing_error' => "Refresh failed after {$this->tries} attempts: {$e->getMessage()}",
                    'next_refresh_at' => $this->document->auto_refresh_enabled ?
                        now()->addHours(24) : null, // Try again in 24 hours
                ]);
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        // Refresh document before updating
        $this->document = KnowledgeDocument::with('integration.integrationToken')->find($this->document->id);

        Log::error('RefreshExternalKnowledgeJob failed permanently', [
            'document_id' => $this->document->id,
            'source_identifier' => $this->document->external_source_identifier,
            'error' => $exception->getMessage(),
        ]);

        // Update the document to reflect the failure
        $this->document->update([
            'processing_status' => 'failed',
            'processing_error' => "Refresh job failed: {$exception->getMessage()}",
            'last_refresh_status' => 'failed',
            'last_refresh_error' => $exception->getMessage(),
            'next_refresh_at' => null, // Stop auto-refresh on permanent failure
        ]);
    }
}
