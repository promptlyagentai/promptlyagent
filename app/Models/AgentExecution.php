<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Agent Execution Model
 *
 * Represents a single execution instance of an agent, tracking its lifecycle through
 * various states from initiation to completion. Supports both standalone executions
 * and multi-agent workflow orchestration with parent-child relationships.
 *
 * **Execution Lifecycle:**
 * - pending → planning → planned → executing → synthesizing → completed
 * - Can transition to failed or cancelled from any non-terminal state
 * - Terminal states: completed, failed, cancelled
 *
 * **State Machine:**
 * - Enforces valid state transitions (see transitionTo method)
 * - Prevents transitions out of terminal states
 * - Logs all state changes for auditing
 *
 * **Workflow Support:**
 * - Parent-child execution relationships for multi-agent workflows
 * - Active execution key constraint prevents concurrent executions per agent
 * - Tool overrides can be set per execution for runtime customization
 *
 * **IMPORTANT - Status vs State:**
 * - ALWAYS use 'state' column in queries: whereIn('state', ['pending', 'planning', ...])
 * - NEVER use 'status' in queries: whereIn('status', ...) will NOT work
 * - 'status' is a computed attribute (read-only) derived from 'state'
 * - Status values: pending, running, completed, failed, cancelled
 * - State values: pending, planning, planned, executing, synthesizing, completed, failed, cancelled
 * - Status 'running' maps to states: planning, planned, executing, synthesizing
 *
 * @property int $id
 * @property int $agent_id
 * @property int $user_id
 * @property int|null $chat_session_id
 * @property int|null $parent_agent_execution_id
 * @property string|null $workflow_step_name
 * @property string $input
 * @property string|null $output
 * @property-read string $status (COMPUTED from state - DO NOT QUERY THIS)
 * @property string $state (USE THIS for database queries)
 * @property array|null $metadata
 * @property int|null $max_steps
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property string|null $error_message
 * @property string|null $active_execution_key
 */
class AgentExecution extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent_id',
        'user_id',
        'chat_session_id',
        'parent_agent_execution_id',
        'workflow_step_name',
        'input',
        'output',
        'status',
        'state',
        'metadata',
        'max_steps',
        'started_at',
        'completed_at',
        'error_message',
        'active_execution_key',
    ];

    protected $casts = [
        'metadata' => 'array',
        'max_steps' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Attributes to append to model's array/JSON form
     *
     * ARCHITECTURAL NOTE: The 'status' attribute is computed from 'state'
     * to maintain backward compatibility while transitioning to a single
     * source of truth. See Issue #79 for migration plan.
     *
     * @var array
     */
    protected $appends = ['status'];

    /**
     * State constants for the research workflow
     */
    public const STATE_PENDING = 'pending';

    public const STATE_PLANNING = 'planning';

    public const STATE_PLANNED = 'planned';

    public const STATE_EXECUTING = 'executing';

    public const STATE_SYNTHESIZING = 'synthesizing';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    public const STATE_CANCELLED = 'cancelled';

    protected static function boot()
    {
        parent::boot();

        // Set active_execution_key before creating/updating
        static::creating(function ($execution) {
            // SECURITY: Prevent race condition by checking for active executions with database lock
            // This prevents two simultaneous requests from both creating active executions
            // EXCEPTION: Allow child executions (workflows) which have parent_agent_execution_id set
            // EXCEPTION: Skip locking check for parent executions (workflow containers) - identified by having
            // metadata['workflow_type'] set. Parent executions are just trackers; child executions do the work.
            $isChildExecution = ! empty($execution->parent_agent_execution_id);
            $isParentExecution = isset($execution->metadata['workflow_type']);

            if (in_array($execution->status ?? 'pending', ['pending', 'running']) && ! $isChildExecution && ! $isParentExecution) {
                // Define staleness threshold (20 minutes)
                $staleThreshold = now()->subMinutes(20);

                // Lock existing active executions for this agent to prevent race conditions
                // Query state column: pending='pending', running=['planning','planned','executing','synthesizing']
                $existingActive = self::where('agent_id', $execution->agent_id)
                    ->where('active_execution_key', 'active')
                    ->whereIn('state', ['pending', 'planning', 'planned', 'executing', 'synthesizing'])
                    ->lockForUpdate()
                    ->first();

                if ($existingActive) {
                    // Check if execution is stale (older than 20 minutes)
                    if ($existingActive->created_at->lt($staleThreshold)) {
                        \Illuminate\Support\Facades\Log::warning('AgentExecution: Auto-cancelling stale execution', [
                            'agent_id' => $execution->agent_id,
                            'stale_execution_id' => $existingActive->id,
                            'stale_status' => $existingActive->status,
                            'age_minutes' => $existingActive->created_at->diffInMinutes(now()),
                            'new_user' => $execution->user_id,
                        ]);

                        // Cancel the stale execution
                        $existingActive->update([
                            'state' => self::STATE_CANCELLED,
                            'error_message' => 'Execution cancelled - exceeded 20 minute timeout without starting',
                            'completed_at' => now(),
                            'active_execution_key' => null,
                        ]);
                    } else {
                        // Execution is recent - block the new request
                        \Illuminate\Support\Facades\Log::warning('AgentExecution: Blocked duplicate active execution (race condition prevented)', [
                            'agent_id' => $execution->agent_id,
                            'existing_execution_id' => $existingActive->id,
                            'existing_status' => $existingActive->status,
                            'age_seconds' => $existingActive->created_at->diffInSeconds(now()),
                            'attempted_by_user' => $execution->user_id,
                        ]);

                        throw new \RuntimeException(
                            "Agent {$execution->agent_id} is already executing (execution {$existingActive->id}). ".
                            'Please wait for the current execution to complete.'
                        );
                    }
                }
            }

            $execution->setActiveExecutionKey();
        });

        static::updating(function ($execution) {
            $execution->setActiveExecutionKey();
        });
    }

    /**
     * Set the active_execution_key based on status
     */
    protected function setActiveExecutionKey(): void
    {
        // Parent executions (workflow containers) never need active keys since they're just trackers
        $isParentExecution = isset($this->metadata['workflow_type']);
        if ($isParentExecution) {
            $this->active_execution_key = null;

            return;
        }

        if (in_array($this->status, ['pending', 'running'])) {
            // For active statuses, set a unique key to enforce constraint
            // If a key is already set (e.g., by workflow orchestrator), don't override it
            if (empty($this->active_execution_key)) {
                $this->active_execution_key = 'active';
            }
        } else {
            // For completed/failed statuses, set to null to allow multiple
            $this->active_execution_key = null;
        }
    }

    /**
     * Transition the execution to a new state with validation
     *
     * @param  string  $newState  The target state to transition to
     *
     * @throws \InvalidArgumentException If the state is invalid or transition is not allowed
     */
    public function transitionTo(string $newState): void
    {
        $validStates = [
            self::STATE_PENDING,
            self::STATE_PLANNING,
            self::STATE_PLANNED,
            self::STATE_EXECUTING,
            self::STATE_SYNTHESIZING,
            self::STATE_COMPLETED,
            self::STATE_FAILED,
            self::STATE_CANCELLED,
        ];

        if (! in_array($newState, $validStates)) {
            throw new \InvalidArgumentException("Invalid state: {$newState}");
        }

        // Validate state transition
        if ($this->state === self::STATE_FAILED || $this->state === self::STATE_CANCELLED) {
            // Cannot transition out of terminal states
            throw new \InvalidArgumentException("Cannot transition from terminal state {$this->state} to {$newState}");
        }

        if ($this->state === self::STATE_COMPLETED && $newState !== self::STATE_FAILED) {
            // Cannot transition from completed except to failed state
            throw new \InvalidArgumentException("Cannot transition from completed to {$newState}");
        }

        // Log the transition
        \Log::info('AgentExecution state transition', [
            'execution_id' => $this->id,
            'from_state' => $this->state,
            'to_state' => $newState,
        ]);

        // Update the state
        $this->update(['state' => $newState]);

        // Update status for backward compatibility
        $this->syncStatusFromState();
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chatSession(): BelongsTo
    {
        return $this->belongsTo(ChatSession::class);
    }

    public function chatInteraction(): HasOne
    {
        return $this->hasOne(ChatInteraction::class);
    }

    /**
     * Get the parent execution if this is a workflow child
     */
    public function parentExecution(): BelongsTo
    {
        return $this->belongsTo(AgentExecution::class, 'parent_agent_execution_id');
    }

    /**
     * Get all child executions if this is a workflow parent
     */
    public function childExecutions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AgentExecution::class, 'parent_agent_execution_id')
            ->orderBy('created_at');
    }

    /**
     * Get the root execution of a workflow
     */
    public function getRootExecution(): AgentExecution
    {
        if ($this->parent_agent_execution_id) {
            return $this->parentExecution->getRootExecution();
        }

        return $this;
    }

    /**
     * Get all executions in the workflow (including self)
     */
    public function getWorkflowExecutions(): \Illuminate\Database\Eloquent\Collection
    {
        $root = $this->getRootExecution();

        return self::where('id', $root->id)
            ->orWhere('parent_agent_execution_id', $root->id)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Check if this execution is part of a workflow
     */
    public function isWorkflowExecution(): bool
    {
        return $this->parent_agent_execution_id !== null || $this->childExecutions()->exists();
    }

    public function markAsStarted(): void
    {
        $this->update([
            'state' => self::STATE_EXECUTING,
            'started_at' => now(),
        ]);
    }

    public function markAsRunning(): void
    {
        $this->update([
            'state' => self::STATE_EXECUTING,
        ]);
    }

    public function markAsCompleted(string $output, ?array $metadata = null): void
    {
        $updateData = [
            'state' => self::STATE_COMPLETED,
            'output' => $output,
            'completed_at' => now(),
        ];

        if ($metadata !== null) {
            // Merge with existing metadata to preserve important data like ai_prompt
            $existingMetadata = $this->metadata ?? [];
            $updateData['metadata'] = array_merge($existingMetadata, $metadata);
        }

        $this->update($updateData);

        // Trigger output actions on completion
        $this->dispatchOutputActions('success');
    }

    public function markAsFailed(string $errorMessage, ?array $metadata = null): void
    {
        // Truncate error message to fit in database column (assuming 255 or 500 chars max)
        // Leave space for truncation indicator
        $maxLength = 500; // Adjust based on your database column size
        $truncatedMessage = strlen($errorMessage) > $maxLength
            ? substr($errorMessage, 0, $maxLength - 10).'...[TRUNCATED]'
            : $errorMessage;

        $updateData = [
            'state' => self::STATE_FAILED,
            'error_message' => $truncatedMessage,
            'completed_at' => now(),
        ];

        if ($metadata !== null) {
            // Merge with existing metadata to preserve important data like ai_prompt
            $existingMetadata = $this->metadata ?? [];
            $updateData['metadata'] = array_merge($existingMetadata, $metadata);
        }

        // Store full error message in metadata if it was truncated
        if (strlen($errorMessage) > $maxLength) {
            $currentMetadata = $metadata ?? [];
            $currentMetadata['full_error_message'] = $errorMessage;
            $updateData['metadata'] = $currentMetadata;

            \Log::info('AgentExecution: Error message truncated for database storage', [
                'execution_id' => $this->id,
                'original_length' => strlen($errorMessage),
                'truncated_length' => strlen($truncatedMessage),
                'full_error_stored_in_metadata' => true,
            ]);
        }

        try {
            $this->update($updateData);
        } catch (\Illuminate\Database\QueryException $e) {
            // If we still have database issues, try with an even shorter message
            if (strpos($e->getMessage(), 'Data too long') !== false) {
                \Log::warning('AgentExecution: Further truncating error message due to database constraints', [
                    'execution_id' => $this->id,
                    'db_error' => $e->getMessage(),
                ]);

                $updateData['error_message'] = substr($errorMessage, 0, 200).'...[DB LIMIT]';
                $this->update($updateData);
            } else {
                // Re-throw if it's a different database error
                throw $e;
            }
        }

        // Trigger output actions on failure
        $this->dispatchOutputActions('failed');
    }

    /**
     * Sync the legacy status field based on the new state field for backward compatibility
     *
     * @deprecated This method will be removed in Phase 2 of Issue #79.
     *             Use the computed status accessor instead.
     */
    public function syncStatusFromState(): void
    {
        $statusMap = [
            self::STATE_PENDING => 'pending',
            self::STATE_PLANNING => 'running',
            self::STATE_PLANNED => 'running',
            self::STATE_EXECUTING => 'running',
            self::STATE_SYNTHESIZING => 'running',
            self::STATE_COMPLETED => 'completed',
            self::STATE_FAILED => 'failed',
            self::STATE_CANCELLED => 'cancelled',
        ];

        $newStatus = $statusMap[$this->state] ?? $this->status;

        if ($this->status !== $newStatus) {
            $this->update(['status' => $newStatus]);
        }
    }

    /**
     * Compute status from state (Phase 1 of Issue #79)
     *
     * Provides backward-compatible status field derived from the granular state field.
     * This eliminates dual field synchronization while maintaining API compatibility.
     *
     * Status Mapping:
     * - pending → pending
     * - planning/planned/executing/synthesizing → running
     * - completed → completed
     * - failed → failed
     * - cancelled → cancelled
     *
     * @return string The computed status value
     */
    public function getStatusAttribute(): string
    {
        // If reading from database attributes directly (before accessor)
        $currentState = $this->attributes['state'] ?? self::STATE_PENDING;

        return match ($currentState) {
            self::STATE_PENDING => 'pending',
            self::STATE_PLANNING,
            self::STATE_PLANNED,
            self::STATE_EXECUTING,
            self::STATE_SYNTHESIZING => 'running',
            self::STATE_COMPLETED => 'completed',
            self::STATE_FAILED => 'failed',
            self::STATE_CANCELLED => 'cancelled',
            default => 'unknown',
        };
    }

    /**
     * Intercept status writes and convert to state writes (Phase 1 backward compatibility)
     *
     * This mutator allows legacy code to continue setting 'status' directly by
     * automatically converting the status value to the appropriate state value.
     *
     * DEPRECATION NOTICE: Log usage for future removal. Use 'state' directly instead.
     *
     * @param  string  $value  The status value being set
     */
    public function setStatusAttribute(string $value): void
    {
        // Log status attribute writes to identify remaining legacy usage
        // Capture full backtrace WITH file/line info for debugging
        // Use IGNORE_ARGS to avoid logging potentially sensitive data while keeping file/line
        // Increased depth to 25 to capture full call chain through Laravel internals to app code
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25);

        // Filter to find application code (not vendor/framework)
        $appFrames = collect($backtrace)
            ->skip(1)
            ->filter(fn ($trace) => isset($trace['file']) && ! str_contains($trace['file'], '/vendor/'))
            ->values();

        $firstAppFrame = $appFrames->first();

        \Log::warning('AgentExecution: Status attribute write detected (use state instead)', [
            'execution_id' => $this->id ?? 'new',
            'status_value' => $value,
            'app_caller' => $firstAppFrame
                ? ($firstAppFrame['file'] ?? 'unknown').':'.($firstAppFrame['line'] ?? '?').' - '.
                  ($firstAppFrame['class'] ?? '').($firstAppFrame['type'] ?? '').($firstAppFrame['function'] ?? '')
                : 'not found - check full call_chain',
            'immediate_caller' => isset($backtrace[1])
                ? ($backtrace[1]['file'] ?? 'unknown').':'.($backtrace[1]['line'] ?? '?').' - '.
                  ($backtrace[1]['class'] ?? '').($backtrace[1]['type'] ?? '').($backtrace[1]['function'] ?? '')
                : 'unknown',
            'call_chain' => collect($backtrace)
                ->skip(1) // Skip this method
                ->take(20) // Get up to 20 levels to capture full chain through Laravel internals
                ->map(fn ($trace) => [
                    'location' => ($trace['file'] ?? 'unknown').':'.($trace['line'] ?? '?'),
                    'caller' => ($trace['class'] ?? '').($trace['type'] ?? '').($trace['function'] ?? ''),
                    'is_app_code' => isset($trace['file']) && ! str_contains($trace['file'], '/vendor/'),
                ])
                ->values()
                ->all(),
        ]);

        // Set both status and state for backward compatibility
        $this->attributes['status'] = $value;
        $this->attributes['state'] = match ($value) {
            'pending' => self::STATE_PENDING,
            'running' => self::STATE_EXECUTING, // Default running state
            'completed' => self::STATE_COMPLETED,
            'failed' => self::STATE_FAILED,
            'cancelled' => self::STATE_CANCELLED,
            default => self::STATE_PENDING,
        };
    }

    /**
     * Cancel this execution and all child executions
     */
    public function cancel(): void
    {
        // Update this execution
        $this->update([
            'state' => self::STATE_CANCELLED,
            'completed_at' => now(),
        ]);

        // Cancel all child executions
        // Query state column: pending='pending', running=['planning','planned','executing','synthesizing']
        $this->childExecutions()->whereIn('state', ['pending', 'planning', 'planned', 'executing', 'synthesizing'])->each(function ($child) {
            $child->cancel();
        });
    }

    public function getDuration(): ?float
    {
        if (! $this->started_at || ! $this->completed_at) {
            return null;
        }

        return $this->started_at->diffInSeconds($this->completed_at, true);
    }

    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * @deprecated Use scopeActive() or scopeInState() instead. Status is a computed attribute.
     */
    public function scopeByStatus($query, $status)
    {
        \Log::warning('AgentExecution: scopeByStatus is deprecated, use scopeActive() or scopeInState()', [
            'status' => $status,
            'backtrace' => collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3))
                ->skip(1)
                ->map(fn ($trace) => ($trace['class'] ?? '').'::'.($trace['function'] ?? ''))
                ->filter()
                ->values()
                ->take(2)
                ->all(),
        ]);

        return $query->where('status', $status);
    }

    /**
     * Scope to get active (pending or running) executions
     * Running means: planning, planned, executing, synthesizing
     */
    public function scopeActive($query)
    {
        return $query->whereIn('state', [
            self::STATE_PENDING,
            self::STATE_PLANNING,
            self::STATE_PLANNED,
            self::STATE_EXECUTING,
            self::STATE_SYNTHESIZING,
        ]);
    }

    /**
     * Scope to get executions in a specific state
     */
    public function scopeInState($query, string|array $states)
    {
        $states = is_array($states) ? $states : [$states];

        return $query->whereIn('state', $states);
    }

    /**
     * Scope to get completed executions
     */
    public function scopeCompleted($query)
    {
        return $query->where('state', self::STATE_COMPLETED);
    }

    /**
     * Scope to get failed executions
     */
    public function scopeFailed($query)
    {
        return $query->where('state', self::STATE_FAILED);
    }

    public function scopeRecent($query, $days = 7)
    {
        return $query->where('created_at', '>=', Carbon::now()->subDays($days));
    }

    /**
     * Scope for filtering by state
     */
    public function scopeByState($query, $state)
    {
        return $query->where('state', $state);
    }

    /**
     * Check if the execution is in a specific state
     */
    public function isInState(string $state): bool
    {
        return $this->state === $state;
    }

    /**
     * Check if the execution is in a terminal state
     */
    public function isTerminalState(): bool
    {
        return in_array($this->state, [
            self::STATE_COMPLETED,
            self::STATE_FAILED,
            self::STATE_CANCELLED,
        ]);
    }

    /**
     * Get a human-readable display name for the current phase
     */
    public function getCurrentPhaseDisplay(): string
    {
        if (! $this->current_phase) {
            return 'Unknown';
        }

        try {
            $phase = \App\Enums\AgentPhase::from($this->current_phase);

            return $phase->getDisplayName();
        } catch (\ValueError $e) {
            // If the phase doesn't match any enum value, return a capitalized version
            return ucfirst(str_replace('_', ' ', $this->current_phase));
        }
    }

    /**
     * Set tool overrides for this execution
     */
    public function setToolOverrides(array $enabledTools, array $enabledServers, bool $overrideEnabled = true): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['tool_overrides'] = [
            'enabled_tools' => $enabledTools,
            'enabled_servers' => $enabledServers,
            'override_enabled' => $overrideEnabled, // Store whether override toggle was enabled
            'created_at' => now()->toISOString(),
        ];

        $this->update(['metadata' => $metadata]);
    }

    /**
     * Get tool overrides from this execution's metadata
     *
     * @return array{enabled_tools: array<string>, enabled_servers: array<string>, override_enabled: bool, created_at: string}|null
     */
    public function getToolOverrides(): ?array
    {
        $metadata = $this->metadata ?? [];

        return $metadata['tool_overrides'] ?? null;
    }

    /**
     * Get tool overrides only if they were enabled when set
     */
    public function getEnabledToolOverrides(): ?array
    {
        $overrides = $this->getToolOverrides();

        if (! $overrides || ! ($overrides['override_enabled'] ?? false)) {
            return null;
        }

        return $overrides;
    }

    /**
     * Check if this execution has tool overrides
     */
    public function hasToolOverrides(): bool
    {
        return $this->getToolOverrides() !== null;
    }

    /**
     * Get enabled tools for this execution (overrides or defaults)
     */
    public function getEnabledTools(): ?array
    {
        $overrides = $this->getToolOverrides();

        return $overrides ? $overrides['enabled_tools'] : null;
    }

    /**
     * Get enabled servers for this execution (overrides or defaults)
     */
    public function getEnabledServers(): ?array
    {
        $overrides = $this->getToolOverrides();

        return $overrides ? $overrides['enabled_servers'] : null;
    }

    /**
     * Dispatch output actions for this agent execution
     *
     * Dispatches two types of output actions:
     * 1. Agent output actions - Fire when THIS specific agent completes
     * 2. Input trigger output actions - Fire when the FULL workflow/task completes (if triggered by input trigger)
     */
    protected function dispatchOutputActions(string $status): void
    {
        // PERFORMANCE: Eager load all required relationships to prevent N+1 queries
        // Without this: 3 queries (agent + chatInteraction + inputTrigger)
        // With this: 1 query (all relationships loaded together)
        $this->loadMissing(['agent', 'chatInteraction.inputTrigger']);

        try {
            $dispatcher = app(\App\Services\OutputAction\OutputActionDispatcher::class);

            // 1. Dispatch agent-specific output actions
            $agent = $this->agent;
            if ($agent) {
                $executionData = [
                    'result' => $this->output ?? $this->error_message ?? '',
                    'session_id' => $this->chat_session_id,
                    'execution_id' => $this->id,
                    'user_id' => $this->user_id,
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'timestamp' => $this->completed_at ? $this->completed_at->toIso8601String() : now()->toIso8601String(),
                    'status' => $status,
                ];

                $dispatcher->dispatchForAgent($agent, $executionData, $status);
            }

            // 2. Dispatch input trigger output actions (only for root/final execution)
            // Check if this execution was initiated by an input trigger
            $interaction = $this->chatInteraction;
            if ($interaction && $interaction->input_trigger_id) {
                $trigger = $interaction->inputTrigger;

                if ($trigger) {
                    $invocationData = [
                        'result' => $this->output ?? $this->error_message ?? '',
                        'session_id' => $this->chat_session_id,
                        'execution_id' => $this->id,
                        'user_id' => $this->user_id,
                        'trigger_id' => $trigger->id,
                        'trigger_name' => $trigger->name,
                        'timestamp' => $this->completed_at ? $this->completed_at->toIso8601String() : now()->toIso8601String(),
                        'status' => $status,
                    ];

                    $dispatcher->dispatchForTrigger($trigger, $invocationData, $status);
                }
            }

        } catch (\Exception $e) {
            \Log::error('Failed to dispatch output actions for agent execution', [
                'execution_id' => $this->id,
                'agent_id' => $this->agent_id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
