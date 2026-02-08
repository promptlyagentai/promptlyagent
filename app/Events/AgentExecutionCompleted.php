<?php

namespace App\Events;

use App\Models\AgentExecution;
use App\Models\ChatInteraction;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Agent Execution Completed Event
 *
 * Fired when an agent execution finishes successfully with a result.
 * This event triggers side effects like URL tracking from synthesis results.
 *
 * **Purpose:**
 * Decouples post-execution side effects from core agent execution logic:
 * - URL tracking from synthesis/execution results
 * - Future: metrics collection, audit logging, notification triggers
 *
 * **Triggered By:**
 * - AgentExecutor after successful execution with synthesis
 * - Multi-agent workflow orchestration upon workflow completion
 *
 * **Side Effects (via listeners):**
 * - URL tracking in execution results
 * - Source link extraction
 * - Future: execution metrics, performance tracking
 *
 * **Event Data:**
 * - agentExecution: The completed execution with status and metadata
 * - chatInteraction: Associated chat interaction (if applicable)
 * - result: The final execution result text
 * - context: String identifying execution context
 *
 * @see \App\Services\Agents\AgentExecutor
 * @see \App\Services\UrlTracker
 * @see \App\Models\AgentExecution
 */
class AgentExecutionCompleted
{
    use Dispatchable, SerializesModels;

    /**
     * Create a new event instance.
     *
     * @param  AgentExecution  $agentExecution  The completed execution
     * @param  ChatInteraction|null  $chatInteraction  Associated interaction (if applicable)
     * @param  string  $result  The execution result text
     * @param  string  $context  Context identifier (e.g., 'agent_executor', 'workflow_orchestrator')
     */
    public function __construct(
        public AgentExecution $agentExecution,
        public ?ChatInteraction $chatInteraction,
        public string $result,
        public string $context = 'unknown'
    ) {
        \Log::debug('AgentExecutionCompleted event constructed', [
            'execution_id' => $agentExecution->id,
            'agent_id' => $agentExecution->agent_id,
            'interaction_id' => $chatInteraction?->id,
            'result_length' => strlen($result),
            'context' => $context,
        ]);
    }
}
