<?php

namespace App\Listeners;

use App\Events\AgentExecutionCompleted;
use App\Services\UrlTracker;
use Illuminate\Support\Facades\Log;

/**
 * Track Agent Execution URLs Listener
 *
 * Tracks all URLs found in agent execution results (especially synthesis results)
 * to ensure all cited sources are captured for validation and analytics.
 *
 * **Execution Priority:** MEDIUM (after execution completes)
 * URL tracking provides metadata for quality checks but is non-blocking.
 *
 * **Error Handling:** Non-blocking - failures are logged but don't prevent
 * other listeners from executing. URL tracking is non-critical.
 *
 * **Use Cases:**
 * - Track URLs in synthesis results
 * - Verify citations in agent outputs
 * - Identify broken links in generated content
 * - Analytics on source usage by agents
 *
 * @see \App\Services\UrlTracker
 * @see \App\Events\AgentExecutionCompleted
 */
class TrackAgentExecutionUrls
{
    /**
     * Handle the event.
     *
     * @param  AgentExecutionCompleted  $event  The completed execution event
     */
    public function handle(AgentExecutionCompleted $event): void
    {
        try {
            if (! $event->chatInteraction) {
                Log::debug('TrackAgentExecutionUrls: No interaction linked to execution', [
                    'execution_id' => $event->agentExecution->id,
                    'context' => $event->context,
                ]);

                return;
            }

            $result = $event->result ?? '';

            if (empty($result)) {
                Log::debug('TrackAgentExecutionUrls: No result to track URLs from', [
                    'execution_id' => $event->agentExecution->id,
                    'interaction_id' => $event->chatInteraction->id,
                    'context' => $event->context,
                ]);

                return;
            }

            $trackedCount = UrlTracker::trackUrlsInText(
                $result,
                $event->chatInteraction,
                'synthesis_extraction',
                'agent_execution_urls_listener'
            );

            Log::debug('TrackAgentExecutionUrls listener executed', [
                'execution_id' => $event->agentExecution->id,
                'interaction_id' => $event->chatInteraction->id,
                'tracked_url_count' => $trackedCount,
                'result_length' => strlen($result),
                'context' => $event->context,
            ]);

        } catch (\Exception $e) {
            Log::error('TrackAgentExecutionUrls listener failed', [
                'execution_id' => $event->agentExecution->id,
                'interaction_id' => $event->chatInteraction?->id,
                'context' => $event->context,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Don't throw - allow other listeners to execute
        }
    }
}
