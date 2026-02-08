<?php

namespace App\Services;

use App\Models\StatusStream;
use Illuminate\Support\Facades\Log;

/**
 * Status Reporter - Real-time Execution Progress Tracking.
 *
 * Manages WebSocket-based status updates for agent executions, workflows, and
 * research operations. Provides real-time progress tracking to users through
 * StatusStream broadcasting with workflow continuity support for multi-agent
 * scenarios.
 *
 * Architecture:
 * - Creates StatusStream records that auto-broadcast via WebSocket (Reverb)
 * - Maintains status history for duration calculations and debugging
 * - Supports workflow continuity mode for tracking multi-agent workflows
 * - Provides AI response streaming with XSS sanitization
 *
 * Workflow Continuity Mode:
 * - Enables tracking across multiple agent executions in workflows
 * - Links child executions to root execution ID
 * - Preserves step names for hierarchical progress display
 * - Used by WorkflowOrchestrator for multi-agent coordination
 *
 * Streaming Capabilities:
 * - AI response streaming: Real-time response chunks with sanitization
 * - Thinking process streaming: Reasoning/planning transparency
 * - Status updates: Step-by-step progress with metadata
 * - Forced updates: Critical milestone broadcasts
 *
 * Status History Format:
 * - source: Origin of update (e.g., "search", "agent", "workflow")
 * - message: Human-readable status message
 * - metadata: Additional context (tool results, progress indicators)
 * - timestamp: Microtime for duration calculations
 * - interaction_id: Associated chat interaction
 *
 * @see \App\Models\StatusStream
 * @see \App\Services\Agents\WorkflowOrchestrator
 * @see \App\Services\Agents\AgentExecutor
 */
class StatusReporter
{
    /**
     * @var int|null The ID of the interaction this reporter is associated with
     */
    protected ?int $interactionId;

    /**
     * @var int|null The ID of the agent execution this reporter is associated with
     */
    protected ?int $agentExecutionId;

    /**
     * @var array<array{source: string, message: string, metadata: array, timestamp: float, interaction_id: int}> Status history for duration calculations
     */
    protected array $statusHistory = [];

    /**
     * @var bool Workflow continuity mode for multi-agent scenarios
     */
    protected bool $workflowContinuityMode = false;

    /**
     * @var int|null Root execution ID for workflow continuity
     */
    protected ?int $workflowRootExecutionId = null;

    /**
     * @var string|null Step name for workflow continuity
     */
    protected ?string $workflowStepName = null;

    /**
     * Create a new StatusReporter instance
     *
     * @param  int|null  $interactionId  The chat interaction ID
     * @param  int|null  $agentExecutionId  The agent execution ID
     */
    public function __construct(?int $interactionId = null, ?int $agentExecutionId = null)
    {
        $this->interactionId = $interactionId;
        $this->agentExecutionId = $agentExecutionId;
    }

    /**
     * Report a status update
     *
     * Creates a StatusStream entry which automatically broadcasts via WebSockets
     *
     * @param  string  $source  The source of the status update (e.g., "search", "agent")
     * @param  string  $message  The status message
     * @param  bool  $createEvent  Whether to create an event (default: true)
     * @param  bool  $isSignificant  Whether the update is significant (default: false)
     */
    public function report(string $source, string $message, bool $createEvent = true, bool $isSignificant = false): void
    {
        if (! $this->interactionId) {
            Log::debug('StatusReporter: No interaction ID available for status reporting', [
                'source' => $source,
                'message' => $message,
            ]);

            return;
        }

        if ($createEvent) {
            // Create status entry which auto-broadcasts via WebSockets
            StatusStream::report($this->interactionId, $source, $message, [], $createEvent, $isSignificant, $this->agentExecutionId);
        }
    }

    /**
     * Report a status update with metadata
     *
     * Creates a StatusStream entry with additional metadata,
     * which automatically broadcasts via WebSockets
     *
     * @param  string  $source  The source of the status update
     * @param  string  $message  The status message
     * @param  array  $metadata  Additional metadata to include
     * @param  bool  $createEvent  Whether to create an event (default: true)
     * @param  bool  $isSignificant  Whether the update is significant (default: false)
     */
    public function reportWithMetadata(string $source, string $message, array $metadata = [], bool $createEvent = true, bool $isSignificant = false): void
    {
        if (! $this->interactionId) {
            Log::debug('StatusReporter: No interaction ID available for status reporting', [
                'source' => $source,
                'message' => $message,
                'metadata' => $metadata,
            ]);

            return;
        }

        // Store in local history for duration calculations
        $this->statusHistory[] = [
            'source' => $source,
            'message' => $message,
            'metadata' => $metadata,
            'timestamp' => microtime(true),
            'interaction_id' => $this->interactionId,
        ];

        if ($createEvent) {
            // Create status entry with metadata which auto-broadcasts via WebSockets
            StatusStream::report($this->interactionId, $source, $message, $metadata, $createEvent, $isSignificant, $this->agentExecutionId);
        }
    }

    /**
     * Force a status update, always creating an event
     *
     * @param  string  $source  The source of the status update
     * @param  string  $message  The status message
     * @param  bool  $isSignificant  Whether the update is significant (default: false)
     */
    public function reportForced(string $source, string $message, bool $isSignificant = false): void
    {
        if (! $this->interactionId) {
            Log::debug('StatusReporter: No interaction ID available for status reporting', [
                'source' => $source,
                'message' => $message,
            ]);

            return;
        }

        // Create status entry which auto-broadcasts via WebSockets
        StatusStream::report($this->interactionId, $source, $message, [], true, $isSignificant, $this->agentExecutionId);
    }

    /**
     * Check if the reporter has a Livewire component (for backward compatibility)
     * Always returns false in the new implementation
     */
    public function hasLivewireComponent(): bool
    {
        return false;
    }

    /**
     * Determine the step type for UI display
     *
     * @param  string  $source  The status update source
     * @param  string  $message  The status message
     * @return string The step type
     */
    public function determineStepType(string $source, string $message): string
    {
        $messageLower = strtolower($message);

        if (strpos($messageLower, 'search') !== false) {
            return 'search';
        } elseif (strpos($messageLower, 'validat') !== false || strpos($messageLower, 'checking') !== false) {
            return 'validation';
        } elseif (strpos($messageLower, 'download') !== false || strpos($messageLower, 'fetch') !== false) {
            return 'download';
        } elseif (strpos($messageLower, 'analyz') !== false || strpos($messageLower, 'process') !== false) {
            return 'analysis';
        } elseif (strpos($messageLower, 'complet') !== false || strpos($messageLower, 'finish') !== false) {
            return 'complete';
        } elseif (strpos($messageLower, 'error') !== false || strpos($messageLower, 'fail') !== false) {
            return 'error';
        }

        return 'info';
    }

    /**
     * Enable workflow continuity mode for multi-agent scenarios
     *
     * @param  int|null  $rootExecutionId  The root execution ID to track
     * @param  string|null  $stepName  The current workflow step name
     */
    public function enableWorkflowContinuity(?int $rootExecutionId = null, ?string $stepName = null): void
    {
        $this->workflowContinuityMode = true;
        $this->workflowRootExecutionId = $rootExecutionId;
        $this->workflowStepName = $stepName;
    }

    /**
     * Disable workflow continuity mode
     */
    public function disableWorkflowContinuity(): void
    {
        $this->workflowContinuityMode = false;
        $this->workflowRootExecutionId = null;
        $this->workflowStepName = null;
    }

    /**
     * Check if workflow continuity mode is enabled
     */
    public function isWorkflowContinuityEnabled(): bool
    {
        return $this->workflowContinuityMode;
    }

    /**
     * Get the root execution ID for workflow continuity
     */
    public function getWorkflowRootExecutionId(): ?int
    {
        return $this->workflowRootExecutionId;
    }

    /**
     * Get the current workflow step name
     */
    public function getWorkflowStepName(): ?string
    {
        return $this->workflowStepName;
    }

    /**
     * Get the interaction ID this reporter is associated with
     */
    public function getInteractionId(): ?int
    {
        return $this->interactionId;
    }

    /**
     * Get the agent execution ID this reporter is associated with
     */
    public function getAgentExecutionId(): ?int
    {
        return $this->agentExecutionId;
    }

    /**
     * Set the agent execution ID
     *
     * @param  int|null  $agentExecutionId  The agent execution ID
     */
    public function setAgentExecutionId(?int $agentExecutionId): void
    {
        $this->agentExecutionId = $agentExecutionId;
    }

    /**
     * Set the interaction ID
     *
     * @param  int|null  $interactionId  The interaction ID
     */
    public function setInteractionId(?int $interactionId): void
    {
        $this->interactionId = $interactionId;
    }

    /**
     * Set the workflow step name
     *
     * @param  string|null  $stepName  The step name
     */
    public function setWorkflowStepName(?string $stepName): void
    {
        $this->workflowStepName = $stepName;
    }

    /**
     * Stream AI response content in real-time
     *
     * @param  string  $content  The AI response content chunk
     * @param  string  $type  The type of response (agent_response, synthesis, etc.)
     */
    public function streamAiResponse(string $content, string $type = 'agent_response'): void
    {
        if (! $this->interactionId) {
            Log::debug('StatusReporter: No interaction ID available for AI response streaming', [
                'content_length' => strlen($content),
                'type' => $type,
            ]);

            return;
        }

        // SECURITY: Strip ALL HTML tags to prevent XSS via tag attributes
        // strip_tags() with allowed tags is vulnerable to attribute-based XSS:
        // e.g., <p onload="alert(1)">text</p> passes through with malicious onload
        // Frontend can handle formatting via markdown or proper HTML escaping
        $sanitizedContent = strip_tags($content);

        StatusStream::report(
            $this->interactionId,
            'ai_response_stream',
            $sanitizedContent,
            [
                'stream_type' => $type,
                'agent_execution_id' => $this->agentExecutionId,
                'content_length' => strlen($sanitizedContent),
                'timestamp' => microtime(true),
            ],
            false, // Don't create events for streaming chunks - they shouldn't appear in Steps tab
            false,
            $this->agentExecutionId
        );
    }

    /**
     * Stream AI thinking/reasoning process
     *
     * @param  string  $reasoning  The AI reasoning content
     */
    public function streamThinkingProcess(string $reasoning): void
    {
        if (! $this->interactionId) {
            Log::debug('StatusReporter: No interaction ID available for thinking process streaming', [
                'reasoning_length' => strlen($reasoning),
            ]);

            return;
        }

        // SECURITY: Strip ALL HTML tags to prevent XSS via tag attributes
        // Same rationale as streamAIResponse() above
        $sanitizedReasoning = strip_tags($reasoning);

        StatusStream::report(
            $this->interactionId,
            'thinking_process',
            $sanitizedReasoning,
            [
                'type' => 'reasoning',
                'agent_execution_id' => $this->agentExecutionId,
                'reasoning_length' => strlen($sanitizedReasoning),
                'timestamp' => microtime(true),
            ],
            false, // Don't create events for thinking process streams - they shouldn't appear in Steps tab
            false,
            $this->agentExecutionId
        );
    }

    /**
     * Check if this reporter supports AI streaming
     */
    public function supportsAiStreaming(): bool
    {
        return ! empty($this->interactionId);
    }
}
