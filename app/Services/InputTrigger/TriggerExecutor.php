<?php

namespace App\Services\InputTrigger;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\ChatInteraction;
use App\Models\ChatSession;
use App\Models\InputTrigger;
use App\Services\Agents\AgentExecutor;
use App\Services\Agents\WorkflowOrchestrator;
use Illuminate\Support\Facades\Log;

/**
 * Trigger Executor Service - External Trigger Execution Engine.
 *
 * Core execution engine for InputTriggers that creates chat records and invokes
 * agents. Ensures external triggers (API, webhooks) create identical ChatSession
 * and ChatInteraction records as the web interface for consistency.
 *
 * Execution Pattern (Matches Web Interface):
 * 1. Create ChatInteraction with empty answer (prevents auto-execution)
 * 2. Explicitly execute agent via AgentExecutor or WorkflowOrchestrator
 * 3. Agent execution updates ChatInteraction.answer
 * 4. ChatInteraction listener fires on answer update (triggers output actions)
 *
 * Parameter Precedence:
 * - Trigger-level parameters (from input_triggers table) serve as defaults
 * - Request-level parameters (from API/webhook payload) override defaults
 * - Enables per-request customization while maintaining trigger config
 *
 * Session Strategy:
 * - new: Always create new ChatSession
 * - existing: Require session_id in request, fail if invalid
 * - latest: Use user's most recent session, create if none exists
 *
 * Agent Selection:
 * - Trigger specifies default agent_id
 * - Request can override with agent_id parameter
 * - Workflow agents use WorkflowOrchestrator, others use AgentExecutor
 *
 * Tool Overrides:
 * - Validated via ToolOverrideValidator for security
 * - Allows per-request tool customization
 * - Must reference tools agent has access to
 *
 * @see \App\Services\InputTrigger\StreamingTriggerExecutor
 * @see \App\Services\InputTrigger\ToolOverrideValidator
 * @see \App\Models\InputTrigger
 */
class TriggerExecutor
{
    public function __construct(
        private AgentExecutor $agentExecutor,
        private WorkflowOrchestrator $workflowOrchestrator,
        private InputTriggerRegistry $registry,
        private PayloadTemplateProcessor $templateProcessor
    ) {}

    /**
     * Execute trigger synchronously
     *
     * @param  InputTrigger  $trigger  The trigger to execute
     * @param  array  $input  Input data ['input' => string]
     * @param  array  $options  Execution options [session_id, workflow, agent_id, etc.]
     * @return array Execution result
     */
    public function execute(InputTrigger $trigger, array $input, array $options = []): array
    {
        // 1. Check rate limits first
        $this->checkRateLimits($trigger);

        // 2. Store original payload for template processing
        $payload = $input;

        // 3. Check trigger target type - commands are dispatched to queue
        if ($trigger->isCommandTrigger()) {
            // Process command parameter templates
            $input = $this->processCommandTemplates($trigger, $input, $payload);

            return $this->executeCommand($trigger, $input, $options);
        }

        // 4. Build agent input from template or payload
        $input = $this->buildAgentInput($trigger, $input, $payload);

        // 5. Validate input for agent triggers (expects string input)
        $this->validateInput($input);

        // 6. Apply parameter precedence rule - trigger config takes precedence over runtime params
        $options = $this->applyParameterPrecedence($trigger, $options);

        // 7. Resolve/create chat session
        $session = $this->resolveSession($trigger, $options);

        // 8. Create chat interaction (with empty answer - no auto-execution)
        $interaction = $this->createInteraction($trigger, $session, $input, $options);

        // 9. Explicitly execute agent (same as web interface does)
        try {
            if (isset($options['workflow'])) {
                // Workflow execution
                $execution = $this->executeWithWorkflow($trigger, $interaction, $options['workflow']);
            } else {
                // Standard agent execution
                $execution = $this->executeAgent($trigger, $interaction, $options);
            }

            // 10. Track usage
            $trigger->incrementUsage();

            // 11. Return response
            return $this->formatResponse($session, $interaction, $execution);

        } catch (\Throwable $e) {
            Log::error('TriggerExecutor: Execution failed', [
                'trigger_id' => $trigger->id,
                'interaction_id' => $interaction->id,
                'error' => $e->getMessage(),
            ]);

            // Update interaction with error
            $interaction->update([
                'answer' => "❌ Execution failed: {$e->getMessage()}",
            ]);

            throw $e;
        }
    }

    /**
     * Execute trigger asynchronously
     *
     * @param  InputTrigger  $trigger  The trigger to execute
     * @param  array  $input  Input data ['input' => string]
     * @param  array  $options  Execution options
     * @return array Execution result with status URL
     */
    public function executeAsync(InputTrigger $trigger, array $input, array $options = []): array
    {
        // Check trigger target type - commands use different job
        if ($trigger->isCommandTrigger()) {
            // Store original payload for template processing
            $payload = $input;

            // Process command parameter templates
            $input = $this->processCommandTemplates($trigger, $input, $payload);

            return $this->executeCommand($trigger, $input, $options);
        }

        // Apply parameter precedence rule - trigger config takes precedence over runtime params
        $options = $this->applyParameterPrecedence($trigger, $options);

        // Same as execute() but returns immediately with status URL
        $session = $this->resolveSession($trigger, $options);
        $interaction = $this->createInteraction($trigger, $session, $input, $options);

        // Dispatch job for async execution with 1 second delay
        // This gives frontend time to receive ChatInteractionCreated event and subscribe to channels
        \App\Jobs\ExecuteTriggerJob::dispatch($trigger->id, $interaction->id, $options)
            ->delay(now()->addSecond());

        $trigger->incrementUsage();

        return [
            'success' => true,
            'invocation_id' => $interaction->id,
            'session_id' => $session->id,
            'interaction_id' => $interaction->id,
            'status' => 'processing',
            'status_url' => url("/api/v1/interactions/{$interaction->id}/status"),
            'chat_url' => url("/chat/sessions/{$session->id}"),
        ];
    }

    /**
     * Resolve or create chat session based on trigger strategy
     */
    protected function resolveSession(InputTrigger $trigger, array $options): ChatSession
    {
        // Option 1: Use specified session from options
        if (isset($options['session_id'])) {
            $session = ChatSession::findOrFail($options['session_id']);

            // Verify ownership
            if ($session->user_id !== $trigger->user_id) {
                throw new \Exception('Session does not belong to trigger owner');
            }

            return $session;
        }

        // Option 2: Continue last session from THIS trigger
        if ($trigger->session_strategy === 'continue_last') {
            $lastSession = ChatSession::where('user_id', $trigger->user_id)
                ->where('metadata->input_trigger_id', $trigger->id)
                ->latest()
                ->first();

            if ($lastSession) {
                return $lastSession;
            }
            // If no session exists from this trigger, fall through to create new
        }

        // Option 3: Use trigger's default session
        if ($trigger->session_strategy === 'specified' && $trigger->default_session_id) {
            return ChatSession::findOrFail($trigger->default_session_id);
        }

        // Option 4: Create new session (default or fallback)
        $provider = $this->registry->getProvider($trigger->provider_id);
        $sessionName = $options['session_name'] ?? "{$provider?->getTriggerIcon()} {$trigger->name}";

        return ChatSession::create([
            'user_id' => $trigger->user_id,
            'name' => $sessionName,
            'metadata' => [
                'initiated_by' => $trigger->provider_id,
                'input_trigger_id' => $trigger->id,
                'can_continue_via_web' => true,
            ],
        ]);
    }

    /**
     * Create chat interaction record
     *
     * IMPORTANT: Created with empty answer to prevent auto-execution.
     * The ChatInteraction model listener only fires when answer is populated.
     */
    protected function createInteraction(
        InputTrigger $trigger,
        ChatSession $session,
        array $input,
        array $options
    ): ChatInteraction {
        $provider = $this->registry->getProvider($trigger->provider_id);

        return ChatInteraction::create([
            'chat_session_id' => $session->id,
            'user_id' => $trigger->user_id,
            'question' => $input['input'],
            'answer' => '', // Empty - no auto-execution, explicitly execute below
            'agent_id' => $options['agent_id'] ?? $trigger->agent_id,
            'input_trigger_id' => $trigger->id,
            'metadata' => [
                'trigger_source' => $trigger->provider_id,
                'trigger_name' => $trigger->name,
                'trigger_icon' => $provider?->getTriggerIcon(),
                'api_version' => $options['api_version'] ?? 'v1',
                'client_metadata' => $options['client_metadata'] ?? [],
                'request_metadata' => $options['metadata'] ?? [],
            ],
        ]);
    }

    /**
     * Execute agent with standard flow
     */
    protected function executeAgent(
        InputTrigger $trigger,
        ChatInteraction $interaction,
        array $options
    ): AgentExecution {
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
                $options['execution_metadata'] ?? [],
                [
                    'triggered_via' => 'input_trigger',
                    'trigger_id' => $trigger->id,
                    'trigger_type' => $trigger->provider_id,
                ]
            ),
        ]);

        // Link execution to interaction
        $interaction->update(['agent_execution_id' => $execution->id]);

        // Execute with full pipeline (same as web interface)
        // This sets up StatusReporter, container instances, error handling, and workflow orchestration
        $result = $this->agentExecutor->execute($execution, $interaction->id);

        // Update interaction with result (this will trigger post-processing)
        $interaction->update(['answer' => $result]);

        return $execution->fresh();
    }

    /**
     * Execute agent with workflow
     */
    protected function executeWithWorkflow(
        InputTrigger $trigger,
        ChatInteraction $interaction,
        array $workflowJson
    ): AgentExecution {
        throw new \Exception('Workflow execution not yet implemented for input triggers');
    }

    /**
     * Format execution response
     */
    protected function formatResponse(
        ChatSession $session,
        ChatInteraction $interaction,
        AgentExecution $execution
    ): array {
        return [
            'success' => true,
            'session_id' => $session->id,
            'interaction_id' => $interaction->id,
            'execution_id' => $execution->id,
            'status' => $execution->status,
            'result' => [
                'answer' => $interaction->answer,
                'artifacts' => $interaction->artifacts->toArray(),
                'sources' => $interaction->sources->toArray(),
            ],
            'chat_url' => url("/chat/sessions/{$session->id}"),
            'timing' => [
                'started_at' => $execution->created_at,
                'completed_at' => $execution->completed_at,
                'duration_ms' => $execution->completed_at ?
                    $execution->created_at->diffInMilliseconds($execution->completed_at) : null,
            ],
        ];
    }

    /**
     * Validate input data
     */
    protected function validateInput(array $input): void
    {
        if (empty($input['input'])) {
            throw new \InvalidArgumentException('Input field is required');
        }

        if (strlen($input['input']) > 10000) {
            throw new \InvalidArgumentException('Input exceeds maximum length of 10,000 characters');
        }
    }

    /**
     * Check rate limits for trigger
     */
    protected function checkRateLimits(InputTrigger $trigger): void
    {
        if (! $trigger->checkRateLimit()) {
            throw new \Exception('Rate limit exceeded for this trigger');
        }
    }

    /**
     * Execute command trigger
     *
     * Dispatches command execution to queue worker (research-coordinator).
     * Parameters from webhook payload are passed to the command.
     *
     * @param  InputTrigger  $trigger  The trigger to execute
     * @param  array  $parameters  Parameters from webhook payload
     * @param  array  $options  Additional options
     * @return array Execution result with dispatch status
     */
    protected function executeCommand(InputTrigger $trigger, array $parameters, array $options): array
    {
        try {
            // Dispatch command execution job to queue
            \App\Jobs\ExecuteCommandTriggerJob::dispatch(
                $trigger->id,
                $parameters,
                $options['metadata'] ?? []
            );

            $trigger->incrementUsage();

            Log::info('TriggerExecutor: Command execution job dispatched', [
                'trigger_id' => $trigger->id,
                'command_class' => $trigger->command_class,
                'parameter_count' => count($parameters),
            ]);

            return [
                'success' => true,
                'trigger_id' => $trigger->id,
                'trigger_type' => 'command',
                'command_class' => $trigger->command_class,
                'status' => 'dispatched',
                'message' => 'Command execution job dispatched to queue',
                'queue' => 'research-coordinator',
            ];

        } catch (\Throwable $e) {
            Log::error('TriggerExecutor: Failed to dispatch command job', [
                'trigger_id' => $trigger->id,
                'command_class' => $trigger->command_class,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Apply parameter precedence rule
     *
     * Trigger-configured values take precedence over runtime API/webhook parameters.
     * This ensures security and consistency - once configured, these values cannot be
     * overridden by external requests.
     *
     * @param  InputTrigger  $trigger  The trigger configuration
     * @param  array  $options  Runtime options from API/webhook request
     * @return array Modified options with trigger config taking precedence
     */
    protected function applyParameterPrecedence(InputTrigger $trigger, array $options): array
    {
        // 1. Agent ID: If configured in trigger, it CANNOT be overridden
        if ($trigger->agent_id) {
            if (isset($options['agent_id']) && $options['agent_id'] !== $trigger->agent_id) {
                Log::warning('TriggerExecutor: Attempted to override trigger agent_id', [
                    'trigger_id' => $trigger->id,
                    'trigger_agent_id' => $trigger->agent_id,
                    'requested_agent_id' => $options['agent_id'],
                ]);
            }
            $options['agent_id'] = $trigger->agent_id;
        }

        // 2. Session Strategy: Trigger config controls session behavior
        // The session strategy is already enforced in resolveSession() by checking $trigger->session_strategy
        // No need to copy to options - just document this behavior
        // Note: session_id in options can still specify which session to use, but the strategy
        // determines the default behavior when no session_id is provided

        // 3. Workflow Config: If configured in trigger, it CANNOT be overridden
        if (isset($trigger->config['workflow_config']) && ! empty($trigger->config['workflow_config'])) {
            if (isset($options['workflow']) && $options['workflow'] !== $trigger->config['workflow_config']) {
                Log::warning('TriggerExecutor: Attempted to override trigger workflow config', [
                    'trigger_id' => $trigger->id,
                    'has_trigger_workflow' => true,
                    'runtime_workflow_provided' => true,
                ]);
            }
            $options['workflow'] = $trigger->config['workflow_config'];
        }

        return $options;
    }

    /**
     * Build agent input from trigger template or payload
     *
     * Priority order:
     * 1. Trigger-configured agent_input_template (from config)
     * 2. Runtime-provided input['input'] (from webhook payload)
     * 3. Default template: {{payload}} (fallback)
     *
     * Enhanced context: Combines webhook payload with built-in metadata placeholders
     * (date, time, trigger_id, etc.) for unified template support.
     *
     * @param  InputTrigger  $trigger  The trigger being executed
     * @param  array  $input  Input data from webhook
     * @param  array  $payload  Original webhook payload
     * @return array Modified input with ['input' => processed string]
     */
    protected function buildAgentInput(InputTrigger $trigger, array $input, array $payload): array
    {
        // 1. Check if trigger has a configured input template
        if ($trigger->hasAgentInputTemplate()) {
            $template = $trigger->getAgentInputTemplate();
            $source = 'trigger_config';
        }
        // 2. Check if runtime input was provided
        elseif (isset($input['input']) && is_string($input['input'])) {
            $template = $input['input'];
            $source = 'runtime';
        }
        // 3. Use default template
        else {
            $template = InputTrigger::getDefaultAgentInputTemplate();
            $source = 'default';
        }

        // Build enhanced context with webhook payload + built-in metadata
        $context = $this->buildEnhancedContext($trigger, $payload);

        // Process template placeholders
        $processed = $this->templateProcessor->process($template, $context);

        Log::info('TriggerExecutor: Built agent input', [
            'source' => $source,
            'template' => $template,
            'processed' => substr($processed, 0, 200).(strlen($processed) > 200 ? '...' : ''),
            'has_placeholders' => $this->templateProcessor->hasPlaceholders($template),
            'keys_used' => $this->templateProcessor->extractAllKeys($template),
        ]);

        return ['input' => $processed];
    }

    /**
     * Build enhanced context with webhook payload and built-in metadata
     *
     * Combines the webhook payload with built-in placeholders (date, time, trigger_id, etc.)
     * to provide a unified context for both webhook and scheduled trigger styles.
     *
     * @param  InputTrigger  $trigger  The trigger being executed
     * @param  array  $payload  Original webhook payload
     * @return array Enhanced context with all available data
     */
    protected function buildEnhancedContext(InputTrigger $trigger, array $payload): array
    {
        $now = now();

        // Start with built-in metadata placeholders
        $context = [
            'date' => $now->format('Y-m-d'),
            'time' => $now->format('H:i:s'),
            'datetime' => $now->format('Y-m-d H:i:s'),
            'timestamp' => $now->timestamp,
            'day' => $now->format('l'),
            'week' => $now->format('W'),
            'month' => $now->format('F'),
            'year' => $now->format('Y'),
            'trigger_id' => $trigger->id,
            'trigger_name' => $trigger->name,
            'user_id' => $trigger->user_id,
            'execution_count' => $trigger->total_invocations ?? 0,
        ];

        // Merge webhook payload (webhook keys override built-in if conflict)
        // This allows payload to take precedence while still providing metadata
        $context = array_merge($context, $payload);

        // Always keep original payload accessible under 'payload' key for nested access
        $context['payload'] = $payload;

        return $context;
    }

    /**
     * Process payload templates in command parameters
     *
     * Merges trigger-configured command_parameters with payload, then processes
     * any template placeholders in the parameter values.
     *
     * @param  InputTrigger  $trigger  The trigger being executed
     * @param  array  $input  Payload data from webhook
     * @param  array  $payload  Original payload for template processing
     * @return array Processed command parameters
     */
    protected function processCommandTemplates(InputTrigger $trigger, array $input, array $payload): array
    {
        // Start with trigger-configured parameters
        $parameters = $trigger->command_parameters ?? [];

        // Merge with payload parameters (payload takes precedence for dynamic values)
        $parameters = array_merge($parameters, $input);

        // Process templates in parameter values
        $processed = $this->templateProcessor->processArray($parameters, $payload);

        if ($parameters !== $processed) {
            Log::info('TriggerExecutor: Processed command parameter templates', [
                'original_count' => count($parameters),
                'processed_count' => count($processed),
                'trigger_id' => $trigger->id,
            ]);
        }

        return $processed;
    }
}
