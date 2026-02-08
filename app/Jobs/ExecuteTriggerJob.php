<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\ChatInteraction;
use App\Models\InputTrigger;
use App\Services\Agents\AgentExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Execute Input Trigger Job - Asynchronous Webhook and Trigger Handler.
 *
 * Handles background execution of input triggers (webhooks, scheduled tasks, API calls)
 * allowing webhook endpoints to respond immediately with 202 Accepted while execution
 * happens asynchronously. Supports multiple trigger providers (HTTP webhooks, MQTT, etc.).
 *
 * Execution Flow:
 * 1. Webhook/trigger endpoint creates ChatInteraction and queues this job
 * 2. Job loads InputTrigger and ChatInteraction models
 * 3. Creates AgentExecution record with trigger metadata
 * 4. Links execution to interaction for tracking
 * 5. Executes agent via AgentExecutor with full pipeline
 * 6. Updates interaction with result or error
 *
 * Benefits of Async Execution:
 * - Immediate webhook response (no timeout waiting for agent)
 * - Better scalability (queue-based processing)
 * - Automatic retry capabilities (via Laravel queue)
 * - Resource isolation (prevents webhook blocking)
 *
 * Trigger Types Supported:
 * - HTTP Webhooks (POST/GET with payload)
 * - Scheduled Tasks (cron-based triggers)
 * - MQTT Messages (IoT integration)
 * - Custom integration triggers
 *
 * Error Handling:
 * - Updates interaction with error message on failure
 * - Logs comprehensive error details with trace
 * - Re-throws exception for queue failure tracking
 *
 * Queue: 'default'
 * Timeout: Default (300 seconds)
 * Retries: Default (queue configuration)
 *
 * @see \App\Models\InputTrigger
 * @see \App\Models\ChatInteraction
 * @see \App\Services\Agents\AgentExecutor
 */
class ExecuteTriggerJob implements ShouldQueue
{
    use Queueable;

    public string $triggerId;

    public int $interactionId;

    public array $options;

    /**
     * Create a new job instance.
     */
    public function __construct(
        string|int $triggerId,
        string|int $interactionId,
        array $options = []
    ) {
        // Trigger IDs can be UUIDs (strings) or integers
        // Interaction IDs are always integers
        $this->triggerId = (string) $triggerId;
        $this->interactionId = (int) $interactionId;
        $this->options = $options;

        // Queue on 'default' queue
        $this->onQueue('default');
    }

    /**
     * Execute the job.
     */
    public function handle(AgentExecutor $agentExecutor): void
    {
        try {
            // Load models
            $trigger = InputTrigger::findOrFail($this->triggerId);
            $interaction = ChatInteraction::findOrFail($this->interactionId);

            Log::info('ExecuteTriggerJob: Starting execution', [
                'trigger_id' => $this->triggerId,
                'interaction_id' => $this->interactionId,
                'agent_id' => $interaction->agent_id,
            ]);

            // Load agent
            $agent = Agent::findOrFail($interaction->agent_id);

            // Create execution record
            $execution = AgentExecution::create([
                'agent_id' => $agent->id,
                'user_id' => $trigger->user_id,
                'chat_session_id' => $interaction->chat_session_id,
                'input' => $interaction->question,
                'status' => 'running',
                'max_steps' => $agent->max_steps,
                'metadata' => array_merge(
                    $this->options['execution_metadata'] ?? [],
                    [
                        'triggered_via' => 'input_trigger',
                        'trigger_id' => $trigger->id,
                        'trigger_type' => $trigger->provider_id,
                        'async_execution' => true,
                    ]
                ),
            ]);

            // Link execution to interaction
            $interaction->update(['agent_execution_id' => $execution->id]);

            // Execute with full pipeline (same as web interface)
            // This sets up StatusReporter, container instances, error handling, and workflow orchestration
            $result = $agentExecutor->execute($execution, $interaction->id);

            // Update interaction with result (this will trigger post-processing)
            $interaction->update(['answer' => $result]);

            Log::info('ExecuteTriggerJob: Execution completed', [
                'trigger_id' => $this->triggerId,
                'interaction_id' => $this->interactionId,
                'execution_id' => $execution->id,
                'status' => $execution->fresh()->status,
            ]);

        } catch (\Throwable $e) {
            Log::error('ExecuteTriggerJob: Execution failed', [
                'trigger_id' => $this->triggerId,
                'interaction_id' => $this->interactionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update interaction with error
            if (isset($interaction)) {
                $interaction->update([
                    'answer' => "❌ Execution failed: {$e->getMessage()}",
                ]);
            }

            throw $e;
        }
    }
}
