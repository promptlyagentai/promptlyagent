<?php

namespace App\Events;

use App\Models\ChatInteraction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Research Workflow Completed Event
 *
 * Fired when a research workflow (single-agent or multi-agent holistic) finishes
 * successfully with a final answer. This event triggers side effects like URL tracking
 * and source link persistence.
 *
 * **Purpose:**
 * Decouples research completion side effects from workflow execution logic:
 * - URL tracking in research answers
 * - Source link extraction and persistence
 * - Future: research quality metrics, citation analysis
 *
 * **Triggered By:**
 * - ResearchService after single-agent research completion
 * - HolisticWorkflowJob after multi-agent workflow completion
 * - ChatResearchInterface after research answer finalization
 * - ResearchJobCommand after CLI research execution
 *
 * **Side Effects (via listeners):**
 * - URL tracking in final answer
 * - Source link validation and persistence
 * - Citation extraction
 * - Future: research quality scoring, fact-checking triggers
 *
 * **Event Data:**
 * - chatInteraction: The interaction containing the research request
 * - finalAnswer: The completed research result text
 * - metadata: Additional workflow metadata (execution IDs, source counts, etc.)
 * - context: String identifying workflow type/source
 *
 * @see \App\Services\Research\ResearchService
 * @see \App\Jobs\HolisticWorkflowJob
 * @see \App\Services\UrlTracker
 */
class ResearchWorkflowCompleted
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  ChatInteraction  $chatInteraction  The interaction with research request
     * @param  string  $finalAnswer  The completed research answer
     * @param  array  $metadata  Additional workflow metadata
     * @param  string  $context  Context identifier (e.g., 'research_service', 'holistic_workflow')
     */
    public function __construct(
        public ChatInteraction $chatInteraction,
        public string $finalAnswer,
        public array $metadata = [],
        public string $context = 'unknown'
    ) {
        \Log::debug('ResearchWorkflowCompleted event constructed', [
            'interaction_id' => $chatInteraction->id,
            'session_id' => $chatInteraction->chat_session_id,
            'answer_length' => strlen($finalAnswer),
            'metadata' => $metadata,
            'context' => $context,
        ]);
    }
}
