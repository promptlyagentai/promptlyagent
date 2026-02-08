<?php

namespace App\Listeners;

use App\Events\ChatInteractionCompleted;
use App\Services\SessionTitleService;
use Illuminate\Support\Facades\Log;

/**
 * Generate Session Title Listener
 *
 * Automatically generates a descriptive title for chat sessions based on the
 * first interaction's question and answer. Only generates titles for sessions
 * that have no title or have the default datetime title.
 *
 * **Execution Priority:** HIGH (must run before embedding generation)
 * Session titles affect embedding content, so this must complete first.
 *
 * **Error Handling:** Non-blocking - failures are logged but don't prevent
 * other listeners from executing. Falls back to truncated question if AI
 * title generation fails.
 *
 * @see \App\Services\SessionTitleService
 * @see \App\Events\ChatInteractionCompleted
 */
class GenerateSessionTitle
{
    /**
     * Handle the event.
     *
     * @param  ChatInteractionCompleted  $event  The completed interaction event
     */
    public function handle(ChatInteractionCompleted $event): void
    {
        try {
            SessionTitleService::generateTitleIfNeeded($event->chatInteraction);

            Log::debug('GenerateSessionTitle listener executed', [
                'interaction_id' => $event->chatInteraction->id,
                'session_id' => $event->chatInteraction->chat_session_id,
                'context' => $event->context,
            ]);
        } catch (\Exception $e) {
            Log::error('GenerateSessionTitle listener failed', [
                'interaction_id' => $event->chatInteraction->id,
                'session_id' => $event->chatInteraction->chat_session_id,
                'context' => $event->context,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't throw - allow other listeners to execute
        }
    }
}
