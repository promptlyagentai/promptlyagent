<?php

namespace App\Listeners;

use App\Events\ResearchWorkflowCompleted;
use App\Services\UrlTracker;
use Illuminate\Support\Facades\Log;

/**
 * Track Research URLs Listener
 *
 * Tracks all URLs found in research workflow final answers to ensure
 * all cited sources are captured for validation and quality checks.
 *
 * **Execution Priority:** MEDIUM (after workflow completes)
 * URL tracking provides metadata for quality checks but is non-blocking.
 *
 * **Error Handling:** Non-blocking - failures are logged but don't prevent
 * other listeners from executing. URL tracking is non-critical.
 *
 * **Use Cases:**
 * - Track URLs in research answers
 * - Verify all research sources are cited
 * - Identify broken links in research outputs
 * - Analytics on source usage in research
 * - Quality validation for research workflows
 *
 * @see \App\Services\UrlTracker
 * @see \App\Events\ResearchWorkflowCompleted
 */
class TrackResearchUrls
{
    /**
     * Handle the event.
     *
     * @param  ResearchWorkflowCompleted  $event  The completed workflow event
     */
    public function handle(ResearchWorkflowCompleted $event): void
    {
        try {
            $finalAnswer = $event->finalAnswer ?? '';

            if (empty($finalAnswer)) {
                Log::debug('TrackResearchUrls: No answer to track URLs from', [
                    'interaction_id' => $event->chatInteraction->id,
                    'context' => $event->context,
                ]);

                return;
            }

            $trackedCount = UrlTracker::trackUrlsInText(
                $finalAnswer,
                $event->chatInteraction,
                'answer_extraction',
                'research_urls_listener'
            );

            Log::debug('TrackResearchUrls listener executed', [
                'interaction_id' => $event->chatInteraction->id,
                'tracked_url_count' => $trackedCount,
                'answer_length' => strlen($finalAnswer),
                'context' => $event->context,
            ]);

        } catch (\Exception $e) {
            Log::error('TrackResearchUrls listener failed', [
                'interaction_id' => $event->chatInteraction->id,
                'context' => $event->context,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't throw - allow other listeners to execute
        }
    }
}
