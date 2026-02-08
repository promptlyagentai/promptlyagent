<?php

namespace App\Events;

use App\Models\ChatInteraction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Chat Interaction Completed Event
 *
 * Fired when a chat interaction has been fully processed and the answer is finalized.
 * This event triggers side effects like title generation, URL tracking, source link
 * extraction, and embedding generation.
 *
 * **Purpose:**
 * Decouples side effects from core chat processing logic, making them:
 * - Easier to test in isolation
 * - Simpler to add/remove without touching core logic
 * - More maintainable with clear separation of concerns
 *
 * **Triggered By:**
 * - StreamingController after direct chat completion
 * - ChatStreamingService after agent execution completion
 *
 * **Side Effects (via listeners):**
 * - Session title generation (if first interaction)
 * - URL tracking in answer text
 * - Source link extraction from tool results
 * - Embedding generation queueing
 *
 * **Event Data:**
 * - chatInteraction: The completed interaction with finalized answer
 * - toolResults: Array of tool call results used during agent execution
 * - context: String identifying where completion occurred (for logging/debugging)
 *
 * @see \App\Services\SessionTitleService
 * @see \App\Services\UrlTracker
 * @see \App\Http\Controllers\StreamingController
 * @see \App\Services\Chat\ChatStreamingService
 */
class ChatInteractionCompleted
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  ChatInteraction  $chatInteraction  The completed interaction
     * @param  array  $toolResults  Tool call results from agent execution
     * @param  string  $context  Context identifier (e.g., 'streaming_controller', 'streaming_service')
     */
    public function __construct(
        public ChatInteraction $chatInteraction,
        public array $toolResults = [],
        public string $context = 'unknown'
    ) {
        \Log::debug('ChatInteractionCompleted event constructed', [
            'interaction_id' => $chatInteraction->id,
            'session_id' => $chatInteraction->chat_session_id,
            'answer_length' => strlen($chatInteraction->answer ?? ''),
            'tool_results_count' => count($toolResults),
            'context' => $context,
        ]);
    }
}
