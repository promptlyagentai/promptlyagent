<?php

namespace App\Services\InputTrigger;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\ChatInteraction;
use App\Models\ChatSession;
use App\Models\InputTrigger;
use App\Services\Agents\AgentExecutor;
use App\Services\Chat\ChatStreamingService;
use App\Services\EventStreamNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Streaming Trigger Executor - SSE Real-Time Streaming for External Triggers.
 *
 * Provides Server-Sent Events (SSE) streaming for InputTriggers, enabling
 * real-time response streaming to external API consumers and webhooks.
 * Handles both direct chat agents (streaming text) and research agents
 * (streaming status updates).
 *
 * SSE Event Types:
 * - response_chunk: Partial text responses from direct agents
 * - status: Progress updates from research/workflow agents
 * - tool_execution: Tool invocation notifications
 * - thinking: AI reasoning process updates
 * - complete: Final completion marker
 * - error: Error notifications
 *
 * Transaction Strategy (CRITICAL):
 * - ChatInteraction created with empty answer
 * - Database transaction committed BEFORE streaming starts
 * - Ensures ChatInteraction persisted even if stream disconnects
 * - Agent execution updates answer after stream complete
 *
 * Streaming Modes:
 * - **Direct Chat**: Real-time text streaming via ChatStreamingService
 * - **Research/Workflow**: Status updates via EventStreamNotifier polling
 *
 * Session Resolution:
 * - Same parameter precedence as TriggerExecutor
 * - Same session strategies (new, existing, latest)
 * - Transaction committed before session data exposed via stream
 *
 * Polling Strategy (Research Mode):
 * - Poll EventStreamNotifier Redis queue
 * - 500ms intervals, 10 second timeout
 * - Yields SSE events as they arrive
 *
 * @see \App\Services\InputTrigger\TriggerExecutor
 * @see \App\Services\Chat\ChatStreamingService
 * @see \App\Services\EventStreamNotifier
 */
class StreamingTriggerExecutor
{
    public function __construct(
        private ChatStreamingService $chatStreamingService,
        private AgentExecutor $agentExecutor,
        private InputTriggerRegistry $registry
    ) {}

    /**
     * Stream trigger execution with SSE
     *
     * @param  InputTrigger  $trigger  The trigger to execute
     * @param  array  $input  Input data ['input' => string]
     * @param  array  $options  Execution options
     * @return \Generator SSE-formatted events
     */
    public function stream(InputTrigger $trigger, array $input, array $options = []): \Generator
    {
        Log::info('StreamingTriggerExecutor: Starting stream', [
            'trigger_id' => $trigger->id,
            'trigger_name' => $trigger->name,
            'input_length' => strlen($input['input'] ?? ''),
        ]);

        try {
            // Validate and prepare
            $this->validateInput($input);
            $this->checkRateLimits($trigger);

            // Apply parameter precedence rule
            $options = $this->applyParameterPrecedence($trigger, $options);

            // Wrap preparation in transaction that commits BEFORE streaming starts
            // This allows the background job to start immediately for real-time status updates
            [$interaction, $agent, $execution] = DB::transaction(function () use ($trigger, $input, $options) {
                // Resolve/create chat session (with lockForUpdate)
                $session = $this->resolveSession($trigger, $options);

                // Create chat interaction
                $interaction = $this->createInteraction($trigger, $session, $input, $options);

                // Handle file attachments if present
                if (isset($options['attachments']) && ! empty($options['attachments'])) {
                    $attachmentMetadata = $options['attachment_metadata'] ?? [];
                    $this->processAttachments($interaction, $options['attachments'], $attachmentMetadata);
                }

                // Determine agent type
                $agent = Agent::findOrFail($interaction->agent_id);

                Log::info('StreamingTriggerExecutor: Agent loaded', [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'agent_type' => $agent->agent_type,
                ]);

                // For research agents, create execution record
                $execution = null;
                if ($agent->agent_type !== 'direct') {
                    $execution = $this->createExecution($agent, $interaction, $trigger);
                }

                // Return all needed objects (transaction will commit here)
                return [$interaction, $agent, $execution];
            });

            // Transaction is now committed - job can start immediately
            // Route to appropriate streaming handler based on agent type
            if ($agent->agent_type === 'direct') {
                yield from $this->streamDirectAgent($trigger, $interaction, $agent);
            } else {
                yield from $this->streamResearchAgent($trigger, $interaction, $agent, $execution);
            }

            // Track usage
            $trigger->incrementUsage();

        } catch (\Exception $e) {
            Log::error('StreamingTriggerExecutor: Stream failed', [
                'trigger_id' => $trigger->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            yield $this->chatStreamingService->formatSSE('update', $this->chatStreamingService->safeJsonEncode([
                'content' => "❌ Execution failed: {$e->getMessage()}",
                'type' => 'error',
            ]));
        }

        yield $this->chatStreamingService->formatSSE('update', '</stream>');
    }

    /**
     * Stream direct agent execution
     */
    protected function streamDirectAgent(InputTrigger $trigger, ChatInteraction $interaction, Agent $agent): \Generator
    {
        Log::info('StreamingTriggerExecutor: Streaming direct agent', [
            'trigger_id' => $trigger->id,
            'interaction_id' => $interaction->id,
            'agent_id' => $agent->id,
        ]);

        // Use ChatStreamingService for direct chat streaming
        yield from $this->chatStreamingService->streamDirectExecution(
            $interaction->question,
            $interaction->id,
            $trigger->user_id
        );
    }

    /**
     * Stream research agent execution with status updates
     *
     * @param  InputTrigger  $trigger  The trigger being executed
     * @param  ChatInteraction  $interaction  The interaction record
     * @param  Agent  $agent  The agent to execute
     * @param  AgentExecution  $execution  The execution record (already created and committed)
     */
    protected function streamResearchAgent(InputTrigger $trigger, ChatInteraction $interaction, Agent $agent, AgentExecution $execution): \Generator
    {
        Log::info('StreamingTriggerExecutor: Streaming research agent', [
            'trigger_id' => $trigger->id,
            'interaction_id' => $interaction->id,
            'agent_id' => $agent->id,
            'execution_id' => $execution->id,
        ]);

        try {

            // Send initial status with session info
            yield $this->chatStreamingService->formatSSE('update', $this->chatStreamingService->safeJsonEncode([
                'status' => 'Initializing agent workflow...',
                'type' => 'research_step',
                'session_id' => $interaction->chat_session_id,
                'interaction_id' => $interaction->id,
            ]));

            // Flush immediately
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();

            // Dispatch agent execution in background
            // This allows us to stream status updates while execution happens
            \App\Jobs\ExecuteResearchAgentStreamingJob::dispatch($execution->id, $interaction->id);

            // Stream progress by polling for events
            $maxWaitTime = 900; // 15 minutes maximum
            $elapsed = 0;
            $pollInterval = 0.5; // Poll every 0.5 seconds for more responsive updates
            $researchComplete = false;

            // Debug: Check if job has started by checking execution status
            Log::info('StreamingTriggerExecutor: Starting polling loop', [
                'interaction_id' => $interaction->id,
                'execution_id' => $execution->id,
                'execution_status' => $execution->status,
            ]);

            while ($elapsed < $maxWaitTime && ! $researchComplete) {
                // Check for and send queued real-time events
                $events = EventStreamNotifier::getAndClearEvents($interaction->id);

                // Debug: Check Redis directly to see if keys exist (use eventstream connection)
                $queueKey = "eventstream_queue_{$interaction->id}";
                $redis = \Illuminate\Support\Facades\Redis::connection('eventstream');
                $queueExists = $redis->exists($queueKey);
                $queueLength = $redis->llen($queueKey);

                // Log polling activity to confirm loop is running
                Log::info('StreamingTriggerExecutor: Poll iteration', [
                    'interaction_id' => $interaction->id,
                    'elapsed' => round($elapsed, 2),
                    'events_retrieved' => count($events),
                    'redis_queue_exists' => $queueExists,
                    'redis_queue_length' => $queueLength,
                    'execution_status' => $execution->fresh()->status,
                ]);

                foreach ($events as $event) {
                    yield $this->chatStreamingService->formatSSE('update', $this->chatStreamingService->safeJsonEncode($event));

                    // Flush output immediately after yielding each event
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();

                    // Check if this is the research complete event
                    if (isset($event['type']) && $event['type'] === 'research_complete') {
                        $researchComplete = true;
                    }

                    // Also check for interaction_updated event with answer
                    if (isset($event['type']) && $event['type'] === 'interaction_updated' && isset($event['data']['has_answer']) && $event['data']['has_answer']) {
                        $researchComplete = true;
                    }
                }

                // If research is complete, send final answer and break
                if ($researchComplete) {
                    $interaction->refresh();
                    $execution->refresh();

                    if ($interaction->answer) {
                        yield $this->chatStreamingService->formatSSE('update', $this->chatStreamingService->safeJsonEncode([
                            'content' => $interaction->answer,
                            'type' => 'answer_stream',
                            'session_id' => $interaction->chat_session_id,
                            'interaction_id' => $interaction->id,
                        ]));
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }

                    // Send execution steps
                    if (isset($execution->metadata['execution_steps'])) {
                        yield $this->chatStreamingService->formatSSE('update', $this->chatStreamingService->safeJsonEncode([
                            'steps' => $execution->metadata['execution_steps'],
                            'type' => 'execution_steps',
                        ]));
                        if (ob_get_level() > 0) {
                            ob_flush();
                        }
                        flush();
                    }

                    yield $this->chatStreamingService->formatSSE('update', $this->chatStreamingService->safeJsonEncode([
                        'status' => 'Agent execution completed successfully',
                        'type' => 'research_step',
                        'session_id' => $interaction->chat_session_id,
                        'interaction_id' => $interaction->id,
                    ]));
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();

                    break;
                }

                // Check for failure/cancellation by examining execution status as fallback
                $execution->refresh();
                if (in_array($execution->status, ['failed', 'cancelled'])) {
                    yield $this->chatStreamingService->formatSSE('update', $this->chatStreamingService->safeJsonEncode([
                        'status' => 'Agent execution '.$execution->status,
                        'type' => 'error',
                        'session_id' => $interaction->chat_session_id,
                        'interaction_id' => $interaction->id,
                    ]));
                    break;
                }

                // Wait before next poll
                usleep($pollInterval * 1000000); // Convert seconds to microseconds
                $elapsed += $pollInterval;
            }

            // Timeout handling
            if ($elapsed >= $maxWaitTime) {
                yield $this->chatStreamingService->formatSSE('update', $this->chatStreamingService->safeJsonEncode([
                    'status' => 'Agent execution timeout - please check results manually',
                    'type' => 'error',
                ]));
            }

        } catch (\Exception $e) {
            Log::error('StreamingTriggerExecutor: Research agent stream error', [
                'trigger_id' => $trigger->id,
                'interaction_id' => $interaction->id,
                'error' => $e->getMessage(),
            ]);

            yield $this->chatStreamingService->formatSSE('update', $this->chatStreamingService->safeJsonEncode([
                'content' => "❌ Execution failed: {$e->getMessage()}",
                'type' => 'error',
            ]));

            if (isset($interaction)) {
                $interaction->update(['answer' => "❌ Execution failed: {$e->getMessage()}"]);
            }
        }
    }

    /**
     * Create agent execution record with metadata
     *
     * Must be called within a transaction that commits before job dispatch
     * to allow immediate job execution for real-time streaming.
     *
     * @param  Agent  $agent  The agent to execute
     * @param  ChatInteraction  $interaction  The interaction record
     * @param  InputTrigger  $trigger  The trigger being executed
     * @return AgentExecution The created execution record
     */
    protected function createExecution(Agent $agent, ChatInteraction $interaction, InputTrigger $trigger): AgentExecution
    {
        // Prepare execution metadata
        $executionMetadata = [
            'triggered_via' => 'input_trigger',
            'trigger_id' => $trigger->id,
            'trigger_type' => $trigger->provider_id,
            'streaming_mode' => 'sse',
        ];

        // Copy tool override from interaction metadata if present
        if (isset($interaction->metadata['tool_override'])) {
            $executionMetadata['tool_overrides'] = $interaction->metadata['tool_override'];
            Log::info('StreamingTriggerExecutor: Tool override applied to execution', [
                'interaction_id' => $interaction->id,
                'tools' => $interaction->metadata['tool_override']['enabled_tools'] ?? [],
            ]);
        }

        // Create execution record
        $execution = AgentExecution::create([
            'agent_id' => $agent->id,
            'user_id' => $trigger->user_id,
            'chat_session_id' => $interaction->chat_session_id,
            'input' => $interaction->question,
            'status' => 'running',
            'max_steps' => $agent->max_steps,
            'metadata' => $executionMetadata,
        ]);

        // Link execution to interaction
        $interaction->update(['agent_execution_id' => $execution->id]);

        Log::info('StreamingTriggerExecutor: Execution record created', [
            'execution_id' => $execution->id,
            'interaction_id' => $interaction->id,
            'agent_id' => $agent->id,
        ]);

        return $execution;
    }

    /**
     * Resolve or create chat session based on trigger strategy
     *
     * Security: Uses lockForUpdate() to prevent race conditions
     * and validates ownership for all session strategies.
     */
    protected function resolveSession(InputTrigger $trigger, array $options): ChatSession
    {
        // Option 1: Use specified session from options
        if (isset($options['session_id'])) {
            // Lock session to prevent concurrent modifications
            $session = ChatSession::where('id', $options['session_id'])
                ->lockForUpdate()
                ->first();

            if (! $session) {
                Log::warning('StreamingTriggerExecutor: Session not found', [
                    'session_id' => $options['session_id'],
                    'trigger_id' => $trigger->id,
                    'user_id' => $trigger->user_id,
                ]);

                throw new \Exception('Session not found');
            }

            // Verify ownership
            if ($session->user_id !== $trigger->user_id) {
                Log::warning('StreamingTriggerExecutor: Session ownership violation attempted', [
                    'session_id' => $session->id,
                    'session_owner_id' => $session->user_id,
                    'trigger_user_id' => $trigger->user_id,
                    'trigger_id' => $trigger->id,
                    'ip' => request()->ip(),
                ]);

                throw new \Exception('Session does not belong to trigger owner');
            }

            return $session;
        }

        // Option 2: Continue last session from THIS trigger
        if ($trigger->session_strategy === 'continue_last') {
            // Lock to prevent race condition where multiple concurrent requests
            // might try to continue the same session
            $lastSession = ChatSession::where('user_id', $trigger->user_id)
                ->where('metadata->input_trigger_id', $trigger->id)
                ->latest()
                ->lockForUpdate()
                ->first();

            if ($lastSession) {
                Log::debug('StreamingTriggerExecutor: Continuing last session', [
                    'session_id' => $lastSession->id,
                    'trigger_id' => $trigger->id,
                ]);

                return $lastSession;
            }

            Log::debug('StreamingTriggerExecutor: No previous session found, will create new', [
                'trigger_id' => $trigger->id,
                'strategy' => 'continue_last',
            ]);
        }

        // Option 3: Use trigger's default session
        if ($trigger->session_strategy === 'specified' && $trigger->default_session_id) {
            $session = ChatSession::where('id', $trigger->default_session_id)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw new \Exception('Trigger default session not found');
            }

            // Verify ownership (default session should belong to trigger owner)
            if ($session->user_id !== $trigger->user_id) {
                Log::error('StreamingTriggerExecutor: Default session ownership mismatch', [
                    'session_id' => $session->id,
                    'session_owner_id' => $session->user_id,
                    'trigger_user_id' => $trigger->user_id,
                    'trigger_id' => $trigger->id,
                ]);

                throw new \Exception('Trigger default session does not belong to trigger owner');
            }

            return $session;
        }

        // Option 4: Create new session
        $provider = $this->registry->getProvider($trigger->provider_id);
        $sessionName = $options['session_name'] ?? "{$provider?->getTriggerIcon()} {$trigger->name}";

        Log::info('StreamingTriggerExecutor: Creating new session', [
            'trigger_id' => $trigger->id,
            'session_name' => $sessionName,
            'strategy' => $trigger->session_strategy,
        ]);

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
     */
    protected function createInteraction(
        InputTrigger $trigger,
        ChatSession $session,
        array $input,
        array $options
    ): ChatInteraction {
        $provider = $this->registry->getProvider($trigger->provider_id);

        $metadata = [
            'trigger_source' => $trigger->provider_id,
            'trigger_name' => $trigger->name,
            'trigger_icon' => $provider?->getTriggerIcon(),
            'api_version' => $options['api_version'] ?? 'v1',
            'client_metadata' => $options['client_metadata'] ?? [],
            'request_metadata' => $options['metadata'] ?? [],
            'streaming_mode' => 'sse',
        ];

        // Add tool override to metadata if provided
        if (isset($options['tool_override']) && $options['tool_override'] !== null) {
            $metadata['tool_override'] = [
                'override_enabled' => true,
                'enabled_tools' => $options['tool_override'],
                'source' => 'api_trigger',
            ];
        }

        return ChatInteraction::create([
            'chat_session_id' => $session->id,
            'user_id' => $trigger->user_id,
            'question' => $input['input'],
            'answer' => '', // Empty - execution will populate
            'agent_id' => $options['agent_id'] ?? $trigger->agent_id,
            'input_trigger_id' => $trigger->id,
            'metadata' => $metadata,
        ]);
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
     * Apply parameter precedence rule
     *
     * Trigger-configured values take precedence over runtime API/webhook parameters.
     */
    protected function applyParameterPrecedence(InputTrigger $trigger, array $options): array
    {
        // Agent ID: If configured in trigger, it CANNOT be overridden
        if ($trigger->agent_id) {
            if (isset($options['agent_id']) && $options['agent_id'] !== $trigger->agent_id) {
                Log::warning('StreamingTriggerExecutor: Attempted to override trigger agent_id', [
                    'trigger_id' => $trigger->id,
                    'trigger_agent_id' => $trigger->agent_id,
                    'requested_agent_id' => $options['agent_id'],
                ]);
            }
            $options['agent_id'] = $trigger->agent_id;
        }

        // Workflow Config: If configured in trigger, it CANNOT be overridden
        if (isset($trigger->config['workflow_config']) && ! empty($trigger->config['workflow_config'])) {
            if (isset($options['workflow']) && $options['workflow'] !== $trigger->config['workflow_config']) {
                Log::warning('StreamingTriggerExecutor: Attempted to override trigger workflow config', [
                    'trigger_id' => $trigger->id,
                ]);
            }
            $options['workflow'] = $trigger->config['workflow_config'];
        }

        return $options;
    }

    /**
     * Process and store file attachments with secure storage
     *
     * @param  ChatInteraction  $interaction  The interaction to attach files to
     * @param  array  $files  Array of UploadedFile objects
     * @param  array  $metadata  Array of secure file metadata from SecureFileValidator
     */
    protected function processAttachments(ChatInteraction $interaction, array $files, array $metadata = []): void
    {
        foreach ($files as $index => $file) {
            try {
                // Get secure metadata for this file (if available)
                $fileMetadata = $metadata[$index] ?? null;

                // Use secure storage filename if available, otherwise fallback to UUID
                $storageFilename = $fileMetadata['storage_filename'] ?? \Illuminate\Support\Str::uuid().'.'.$file->getClientOriginalExtension();

                // Store file in storage/app/chat-attachments/{session_id}/{interaction_id}/
                $storagePath = "chat-attachments/{$interaction->chat_session_id}/{$interaction->id}";
                $path = $file->storeAs($storagePath, $storageFilename, 'local');

                // Use sanitized original filename from metadata if available
                $originalFilename = $fileMetadata['original_filename'] ?? basename($file->getClientOriginalName());

                // Create attachment record
                \App\Models\ChatInteractionAttachment::create([
                    'chat_interaction_id' => $interaction->id,
                    'filename' => $originalFilename,
                    'storage_path' => $path,
                    'file_size' => $file->getSize(),
                    'mime_type' => $fileMetadata['mime_type'] ?? $file->getMimeType(),
                    'type' => 'document',
                    'metadata' => [
                        'uploaded_via' => 'api_trigger',
                        'original_extension' => $fileMetadata['extension'] ?? $file->getClientOriginalExtension(),
                        'secure_validation' => $fileMetadata ? 'passed' : 'legacy',
                        'storage_filename' => $storageFilename,
                    ],
                ]);

                Log::info('StreamingTriggerExecutor: File attachment stored securely', [
                    'interaction_id' => $interaction->id,
                    'original_filename' => $originalFilename,
                    'storage_filename' => $storageFilename,
                    'file_size' => $file->getSize(),
                    'secure_validation' => $fileMetadata ? 'yes' : 'no',
                ]);

            } catch (\Exception $e) {
                Log::error('StreamingTriggerExecutor: Failed to store attachment', [
                    'interaction_id' => $interaction->id,
                    'file_name' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ]);

                // Don't fail the entire request - just log and continue
            }
        }
    }
}
