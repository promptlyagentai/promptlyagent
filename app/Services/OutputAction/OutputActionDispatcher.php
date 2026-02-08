<?php

namespace App\Services\OutputAction;

use App\Jobs\ExecuteOutputActionJob;
use App\Models\Agent;
use App\Models\InputTrigger;
use App\Models\OutputAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Output Action Dispatcher - Event-Driven Action Execution.
 *
 * Orchestrates output action execution in response to agent completions and
 * input trigger invocations. Provides event-driven automation by dispatching
 * configured actions (webhooks, API calls, notifications) to Horizon job queue.
 *
 * Dispatch Strategy:
 * - Filters actions by agent and trigger associations
 * - Evaluates conditions for conditional execution
 * - Resolves template variables in action configuration
 * - Queues actions for async execution via ExecuteOutputActionJob
 *
 * Supported Events:
 * - Agent execution complete (success or failed)
 * - Input trigger invoked
 * - Chat interaction created
 *
 * Condition Evaluation:
 * - Status-based: Execute only on success or failed
 * - Content-based: Regex matching on agent response
 * - Time-based: Execute within time windows
 *
 * Variable Resolution:
 * - {{result}}: Agent response text
 * - {{session_id}}: Chat session identifier
 * - {{interaction_id}}: ChatInteraction identifier
 * - {{user_id}}: User identifier
 * - {{agent_name}}: Name of agent that executed
 * - {{trigger_name}}: Name of triggering InputTrigger (if applicable)
 *
 * Action Filtering:
 * - Only dispatches actions associated with agent or trigger
 * - Respects enabled/disabled status
 * - Applies condition matching
 *
 * @see \App\Jobs\ExecuteOutputActionJob
 * @see \App\Services\OutputAction\TemplateVariableResolver
 * @see \App\Models\OutputAction
 */
class OutputActionDispatcher
{
    public function __construct(
        protected TemplateVariableResolver $variableResolver
    ) {}

    /**
     * Dispatch output actions for an agent execution
     *
     * @param  Agent  $agent  The agent that was executed
     * @param  array  $executionData  Data from the agent execution
     * @param  string  $status  Execution status ('success' or 'failed')
     */
    public function dispatchForAgent(Agent $agent, array $executionData, string $status): void
    {
        // Get active output actions for this agent that should execute for the given status
        $actions = $agent->outputActions()
            ->active()
            ->get()
            ->filter(fn (OutputAction $action) => $action->shouldExecuteForStatus($status));

        if ($actions->isEmpty()) {
            Log::debug('OutputActionDispatcher: No actions to dispatch for agent', [
                'agent_id' => $agent->id,
                'status' => $status,
            ]);

            return;
        }

        // Build execution context
        $context = $this->variableResolver->buildContext(array_merge($executionData, [
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'status' => $status,
        ]));

        // Dispatch each action to the queue
        $this->dispatchActions($actions, $context, $executionData['user_id'] ?? null);

        Log::info('OutputActionDispatcher: Dispatched actions for agent execution', [
            'agent_id' => $agent->id,
            'status' => $status,
            'action_count' => $actions->count(),
        ]);
    }

    /**
     * Dispatch output actions for an input trigger invocation
     *
     * @param  InputTrigger  $trigger  The input trigger that was invoked
     * @param  array  $invocationData  Data from the trigger invocation
     * @param  string  $status  Invocation status ('success' or 'failed')
     */
    public function dispatchForTrigger(InputTrigger $trigger, array $invocationData, string $status): void
    {
        // Get active output actions for this trigger that should execute for the given status
        $actions = $trigger->outputActions()
            ->active()
            ->get()
            ->filter(fn (OutputAction $action) => $action->shouldExecuteForStatus($status));

        if ($actions->isEmpty()) {
            Log::debug('OutputActionDispatcher: No actions to dispatch for trigger', [
                'trigger_id' => $trigger->id,
                'status' => $status,
            ]);

            return;
        }

        // Build execution context
        $context = $this->variableResolver->buildContext(array_merge($invocationData, [
            'trigger_id' => $trigger->id,
            'trigger_name' => $trigger->name,
            'status' => $status,
        ]));

        // Dispatch each action to the queue
        $this->dispatchActions($actions, $context, $invocationData['user_id'] ?? $trigger->user_id);

        Log::info('OutputActionDispatcher: Dispatched actions for trigger invocation', [
            'trigger_id' => $trigger->id,
            'status' => $status,
            'action_count' => $actions->count(),
        ]);
    }

    /**
     * Dispatch multiple actions to the queue
     *
     * @param  Collection  $actions  Collection of OutputAction models
     * @param  array  $context  Template variable context
     * @param  int|null  $userId  User ID for logging
     */
    protected function dispatchActions(Collection $actions, array $context, ?int $userId): void
    {
        foreach ($actions as $action) {
            try {
                // Dispatch to queue
                ExecuteOutputActionJob::dispatch($action, $context, $userId)
                    ->onQueue('http-output-actions');

                Log::debug('OutputActionDispatcher: Queued action', [
                    'action_id' => $action->id,
                    'action_name' => $action->name,
                    'provider_id' => $action->provider_id,
                ]);
            } catch (\Exception $e) {
                Log::error('OutputActionDispatcher: Failed to queue action', [
                    'action_id' => $action->id,
                    'action_name' => $action->name,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Test an output action immediately without queueing
     *
     * @param  OutputAction  $action  The action to test
     * @param  array  $testPayload  Test payload data
     * @param  User  $user  User performing the test
     * @return array Test result
     */
    public function test(OutputAction $action, array $testPayload, User $user): array
    {
        $context = $this->variableResolver->buildContext($testPayload);

        Log::info('OutputActionDispatcher: Testing action', [
            'action_id' => $action->id,
            'action_name' => $action->name,
            'user_id' => $user->id,
        ]);

        try {
            // Execute immediately (not queued)
            $registry = app(OutputActionRegistry::class);
            $provider = $registry->getProvider($action->provider_id);

            if (! $provider) {
                return [
                    'success' => false,
                    'error' => "Provider not found: {$action->provider_id}",
                ];
            }

            return $provider->execute($action, $context);
        } catch (\Exception $e) {
            Log::error('OutputActionDispatcher: Test failed', [
                'action_id' => $action->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
