<?php

namespace App\Jobs;

use App\Models\AgentExecution;
use App\Models\ChatInteraction;
use App\Services\Agents\AgentExecutor;
use App\Services\EventStreamNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Execute Research Agent Streaming Job
 *
 * Handles async execution of research agents for SSE streaming.
 * This allows the API endpoint to start streaming status updates immediately
 * while the agent executes in the background.
 */
class ExecuteResearchAgentStreamingJob implements ShouldQueue
{
    use Queueable;

    public int $executionId;

    public int $interactionId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $executionId, int $interactionId)
    {
        $this->executionId = $executionId;
        $this->interactionId = $interactionId;

        // Queue on 'default' queue
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(AgentExecutor $agentExecutor): void
    {
        Log::info('ExecuteResearchAgentStreamingJob: Job started by queue worker', [
            'execution_id' => $this->executionId,
            'interaction_id' => $this->interactionId,
            'queue' => $this->queue,
            'timestamp' => now()->toISOString(),
        ]);

        try {
            $execution = AgentExecution::with('chatInteraction.attachments')->findOrFail($this->executionId);
            $interaction = ChatInteraction::with('attachments')->findOrFail($this->interactionId);

            Log::info('ExecuteResearchAgentStreamingJob: Starting execution', [
                'execution_id' => $this->executionId,
                'interaction_id' => $this->interactionId,
                'agent_id' => $execution->agent_id,
                'execution_status' => $execution->status,
            ]);

            // PROMPTLY AGENT ROUTING: If this is Promptly Agent, use AI-based agent selection
            if ($execution->agent->name === 'Promptly Agent') {
                Log::info('ExecuteResearchAgentStreamingJob: Detected Promptly Agent - routing through PromptlyService', [
                    'execution_id' => $this->executionId,
                    'interaction_id' => $this->interactionId,
                    'query' => substr($execution->input, 0, 100),
                ]);

                // CRITICAL: Mark execution as executing before delegating to prevent race conditions
                $execution->update([
                    'state' => \App\Models\AgentExecution::STATE_EXECUTING,
                    'started_at' => now(),
                ]);

                $promptlyService = app(\App\Services\Agents\PromptlyService::class);

                // PromptlyService will select and execute the best agent
                // Pass parent execution and interaction IDs so selected agent can access files and context
                $selectedExecution = $promptlyService->execute(
                    query: $execution->input,
                    user: $execution->user,
                    chatSessionId: $execution->chat_session_id,
                    async: false, // Execute synchronously within this job
                    parentExecutionId: $this->executionId,
                    interactionId: $this->interactionId
                );

                // Wait for the selected agent to complete (it's synchronous)
                $selectedExecution->refresh();

                // Use the selected agent's result
                $result = $selectedExecution->output;

                // CRITICAL: Mark the parent Promptly execution as completed
                // This prevents blocking further requests and triggers output actions
                $execution->markAsCompleted(
                    $result,
                    array_merge($execution->fresh()->metadata ?? [], [
                        'routed_to_agent_id' => $selectedExecution->agent_id,
                        'routed_to_agent_name' => $selectedExecution->agent->name,
                        'routed_execution_id' => $selectedExecution->id,
                    ])
                );

                Log::info('ExecuteResearchAgentStreamingJob: Promptly routed to agent and completed', [
                    'execution_id' => $this->executionId,
                    'interaction_id' => $this->interactionId,
                    'selected_agent_id' => $selectedExecution->agent_id,
                    'selected_agent_name' => $selectedExecution->agent->name,
                    'result_length' => strlen($result),
                    'parent_execution_state' => $execution->fresh()->state,
                ]);
            } else {
                // Execute with full pipeline (StatusReporter, container instances, etc.)
                $result = $agentExecutor->execute($execution, $interaction->id);
            }

            // Update interaction with result
            $interaction->update(['answer' => $result]);

            // **CRITICAL**: Notify SSE polling loop that answer is ready
            // This pushes event to Redis so ChatStreamingService can send the final answer
            EventStreamNotifier::interactionUpdated($interaction->id, $result);

            Log::info('ExecuteResearchAgentStreamingJob: Execution completed and event pushed to Redis', [
                'execution_id' => $this->executionId,
                'interaction_id' => $this->interactionId,
                'status' => $execution->fresh()->state,
                'answer_length' => strlen($result),
            ]);

        } catch (\Throwable $e) {
            Log::error('ExecuteResearchAgentStreamingJob: Execution failed', [
                'execution_id' => $this->executionId,
                'interaction_id' => $this->interactionId,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update execution status
            if (isset($execution)) {
                $execution->update([
                    'state' => 'failed',
                    'error' => $e->getMessage(),
                ]);
            }

            // Update interaction with error
            if (isset($interaction)) {
                $interaction->update([
                    'answer' => "❌ Execution failed: {$e->getMessage()}",
                ]);
            }

            // Don't rethrow - mark as failed and continue
            // This prevents job retries for errors we can't recover from
        }
    }
}
