<?php

namespace App\Jobs;

use App\Models\ChatInteraction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateChatInteractionEmbeddings implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    protected ChatInteraction $interaction;

    /**
     * Create a new job instance.
     */
    public function __construct(ChatInteraction $interaction)
    {
        $this->interaction = $interaction;
        $this->onQueue('embeddings');
    }

    public function handle(): void
    {
        Log::info('GenerateChatInteractionEmbeddings: Starting embedding generation', [
            'interaction_id' => $this->interaction->id,
            'chat_session_id' => $this->interaction->chat_session_id,
            'has_question' => ! empty($this->interaction->question),
            'has_answer' => ! empty($this->interaction->answer),
        ]);

        try {
            // Only generate embeddings if the interaction has content
            if (! $this->interaction->shouldBeSearchable()) {
                Log::debug('GenerateChatInteractionEmbeddings: Skipping embedding generation, interaction not searchable', [
                    'interaction_id' => $this->interaction->id,
                ]);

                return;
            }

            // With transient embedding architecture, we just need to trigger Scout indexing
            // Embeddings will be generated during the indexing process by MeilisearchVectorEngine
            $this->interaction->searchable();

            Log::info('GenerateChatInteractionEmbeddings: Successfully triggered indexing for interaction', [
                'interaction_id' => $this->interaction->id,
            ]);

        } catch (\Exception $e) {
            Log::error('GenerateChatInteractionEmbeddings: Exception during indexing', [
                'interaction_id' => $this->interaction->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger retry mechanism
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateChatInteractionEmbeddings: Job failed after all retries', [
            'interaction_id' => $this->interaction->id,
            'error' => $exception->getMessage(),
        ]);

        // With transient embedding architecture, we don't store failure metadata
        // The job will be retried according to the retry policy
    }

    /**
     * Determine the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'embedding-generation',
            'chat-interaction:'.$this->interaction->id,
            'session:'.$this->interaction->chat_session_id,
        ];
    }
}
