<?php

namespace App\Listeners;

use App\Events\ChatInteractionCompleted;
use App\Models\ChatInteraction;
use Illuminate\Support\Facades\Log;

/**
 * Queue Interaction Embeddings Listener
 *
 * Queues embedding generation for completed chat interactions to enable
 * semantic search and similarity matching across conversation history.
 *
 * **Execution Priority:** LOWEST (must run AFTER title generation)
 * Embeddings are generated from the final interaction state including
 * the session title, so title generation must complete first.
 *
 * **Error Handling:** Non-blocking - failures are logged but don't prevent
 * other listeners from executing. Embedding failures are recoverable via
 * manual reindexing.
 *
 * **Authorization:** Only queues embeddings for authenticated users who
 * own the interaction. Prevents embedding poisoning attacks.
 *
 * **Performance:** Embeddings are queued to background jobs, not generated
 * synchronously. This keeps response times fast.
 *
 * @see \App\Models\ChatInteraction::queueEmbeddingGeneration()
 * @see \App\Jobs\GenerateInteractionEmbeddings
 * @see \App\Events\ChatInteractionCompleted
 */
class QueueInteractionEmbeddings
{
    /**
     * Handle the event.
     *
     * @param  ChatInteractionCompleted  $event  The completed interaction event
     */
    public function handle(ChatInteractionCompleted $event): void
    {
        try {
            // Use the model's static method which handles all authorization
            // and configuration checks internally
            ChatInteraction::queueEmbeddingGeneration($event->chatInteraction);

            Log::debug('QueueInteractionEmbeddings listener executed', [
                'interaction_id' => $event->chatInteraction->id,
                'context' => $event->context,
            ]);

        } catch (\Exception $e) {
            Log::error('QueueInteractionEmbeddings listener failed', [
                'interaction_id' => $event->chatInteraction->id,
                'context' => $event->context,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't throw - allow other listeners to execute
        }
    }
}
