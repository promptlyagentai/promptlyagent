<?php

namespace App\Listeners;

use App\Events\ChatInteractionCompleted;
use App\Services\UrlTracker;
use Illuminate\Support\Facades\Log;

/**
 * Track Interaction URLs Listener
 *
 * Tracks all URLs found in chat interaction answers for analytics and validation.
 * Extracts both markdown links and plain URLs from the answer text.
 *
 * **Execution Priority:** MEDIUM (after source extraction, before embeddings)
 * URL tracking provides metadata that can be useful for quality checks but
 * doesn't need to block other operations.
 *
 * **Error Handling:** Non-blocking - failures are logged but don't prevent
 * other listeners from executing. URL tracking is non-critical.
 *
 * **Use Cases:**
 * - Verify all cited URLs are valid
 * - Track which sources agents reference
 * - Identify broken links in responses
 * - Analytics on source usage patterns
 *
 * @see \App\Services\UrlTracker
 * @see \App\Events\ChatInteractionCompleted
 */
class TrackInteractionUrls
{
    /**
     * Handle the event.
     *
     * @param  ChatInteractionCompleted  $event  The completed interaction event
     */
    public function handle(ChatInteractionCompleted $event): void
    {
        try {
            $answer = $event->chatInteraction->answer ?? '';

            if (empty($answer)) {
                Log::debug('TrackInteractionUrls: No answer to track URLs from', [
                    'interaction_id' => $event->chatInteraction->id,
                    'context' => $event->context,
                ]);

                return;
            }

            $trackedCount = UrlTracker::trackUrlsInText(
                $answer,
                $event->chatInteraction,
                'direct_chat',
                'interaction_urls_listener'
            );

            Log::debug('TrackInteractionUrls listener executed', [
                'interaction_id' => $event->chatInteraction->id,
                'tracked_url_count' => $trackedCount,
                'answer_length' => strlen($answer),
                'context' => $event->context,
            ]);

        } catch (\Exception $e) {
            Log::error('TrackInteractionUrls listener failed', [
                'interaction_id' => $event->chatInteraction->id,
                'context' => $event->context,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't throw - allow other listeners to execute
        }
    }
}
