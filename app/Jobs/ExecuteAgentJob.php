<?php

namespace App\Jobs;

use App\Models\AgentExecution;
use App\Services\Agents\Actions\ActionRegistry;
use App\Services\Agents\AgentExecutor;
use App\Services\Agents\ToolRegistry;
use App\Services\Agents\WorkflowResultStore;
use App\Traits\HandlesExecutionFailures;
use App\Traits\TracksJobStatus;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queued job for executing agent tasks with workflow coordination support.
 *
 * Features:
 * - Automatic queue selection based on agent type (research, analysis, default)
 * - Sequential/parallel workflow support with result chaining
 * - StatusReporter integration for real-time progress updates
 * - Result storage in Redis via WorkflowResultStore
 * - Automatic broadcast for simple/single-agent workflows
 *
 * Configuration:
 * - No retries (tries = 1) - agent failures require manual intervention
 * - 15-minute timeout for complex research tasks
 * - Queue: dynamic based on agent name/type
 */
class ExecuteAgentJob implements ShouldQueue
{
    use Batchable, HandlesExecutionFailures, InteractsWithQueue, Queueable, SerializesModels, TracksJobStatus;

    public $tries = 1; // Never retry - agent execution failures should not be retried automatically

    public $timeout = 900; // 15 minutes for complex research tasks

    public $maxExceptions = 1;

    protected AgentExecution $execution;

    public ?int $interactionId = null;

    public ?int $jobIndex = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        AgentExecution $execution,
        ?int $interactionId = null,
        ?int $jobIndex = null
    ) {
        $this->execution = $execution;
        $this->interactionId = $interactionId;
        $this->jobIndex = $jobIndex;

        // Set queue name based on agent type for load balancing
        $this->onQueue($this->getQueueName());

        // Track job as queued if we have an interaction ID
        $this->trackJobQueued();
    }

    public function handle(ToolRegistry $toolRegistry, WorkflowResultStore $resultStore): void
    {
        // Check if batch was cancelled
        if ($this->batch() && $this->batch()->cancelled()) {
            Log::info('ExecuteAgentJob: Skipped - batch cancelled', [
                'execution_id' => $this->execution->id,
                'batch_id' => $this->batch()->id,
            ]);

            return;
        }

        // RELIABILITY: Idempotency protection - prevent duplicate execution on job retry
        // Refresh execution model to get latest state from database
        $this->execution->refresh();

        // Check if execution is already in a terminal state (completed, failed, cancelled)
        if ($this->execution->isTerminalState()) {
            Log::info('ExecuteAgentJob: Skipped - execution already terminal', [
                'execution_id' => $this->execution->id,
                'state' => $this->execution->state,
                'status' => $this->execution->status,
                'job_uuid' => $this->job?->uuid(),
                'completed_at' => $this->execution->completed_at,
            ]);

            return;
        }

        // Track job UUID to detect duplicate retries
        $metadata = $this->execution->metadata ?? [];
        $existingJobUuid = $metadata['job_uuid'] ?? null;
        $currentJobUuid = $this->job?->uuid();

        if ($existingJobUuid && $currentJobUuid && $existingJobUuid !== $currentJobUuid) {
            Log::warning('ExecuteAgentJob: Duplicate job detected - different UUID', [
                'execution_id' => $this->execution->id,
                'existing_job_uuid' => $existingJobUuid,
                'current_job_uuid' => $currentJobUuid,
                'execution_status' => $this->execution->status,
            ]);

            // Another job is/was handling this execution - skip to prevent race condition
            return;
        }

        // Store current job UUID in metadata for future idempotency checks
        if ($currentJobUuid) {
            $metadata['job_uuid'] = $currentJobUuid;
            $metadata['job_started_at'] = now()->toISOString();
            $this->execution->update(['metadata' => $metadata]);

            Log::debug('ExecuteAgentJob: Stored job UUID for idempotency tracking', [
                'execution_id' => $this->execution->id,
                'job_uuid' => $currentJobUuid,
            ]);
        }

        Log::info('ExecuteAgentJob: Starting job execution', [
            'execution_id' => $this->execution->id,
            'agent_id' => $this->execution->agent_id,
            'user_id' => $this->execution->user_id,
            'queue' => $this->queue,
            'batch_id' => $this->batch()?->id,
            'job_index' => $this->jobIndex,
            'job_uuid' => $currentJobUuid,
        ]);

        // Preserve existing StatusReporter instance if it has Livewire component configured
        if ($this->interactionId) {
            $existingStatusReporter = app()->has('status_reporter') ? app('status_reporter') : null;

            if (! $existingStatusReporter ||
                ! $existingStatusReporter instanceof \App\Services\StatusReporter ||
                ! $existingStatusReporter->hasLivewireComponent()) {
                // Only create new instance if none exists, is invalid, or lacks Livewire component
                // Pass both interaction ID and agent execution ID
                $statusReporter = new \App\Services\StatusReporter($this->interactionId, $this->execution->id);
                app()->instance('status_reporter', $statusReporter);

                Log::info('ExecuteAgentJob: Created new StatusReporter instance', [
                    'interaction_id' => $this->interactionId,
                    'agent_execution_id' => $this->execution->id,
                    'had_existing' => $existingStatusReporter !== null,
                    'existing_has_livewire' => $existingStatusReporter ? $existingStatusReporter->hasLivewireComponent() : false,
                ]);
            } else {
                // Preserve existing StatusReporter with Livewire component but update agent execution ID
                $existingStatusReporter->setAgentExecutionId($this->execution->id);
                Log::info('ExecuteAgentJob: Preserving existing StatusReporter with Livewire component, updated agent execution ID', [
                    'interaction_id' => $this->interactionId,
                    'agent_execution_id' => $this->execution->id,
                ]);
            }
        }

        try {
            // Apply input actions to transform/process input data
            $this->applyInputActions();

            // For sequential and mixed workflows, enhance input with previous results
            $metadata = $this->execution->metadata ?? [];

            if ($this->shouldEnhanceWithPrevious($metadata)) {
                $previousJobIndex = $metadata['job_index'] - 1;
                $previousResult = $resultStore->getJobResult($metadata['batch_id'], $previousJobIndex);

                if ($previousResult && ! isset($previousResult['error'])) {
                    Log::info('ExecuteAgentJob: Workflow - enhancing input with previous results', [
                        'execution_id' => $this->execution->id,
                        'workflow_type' => $metadata['workflow_type'],
                        'stage_type' => $metadata['stage_type'] ?? 'N/A',
                        'stage_index' => $metadata['stage_index'] ?? 'N/A',
                        'current_job_index' => $metadata['job_index'],
                        'previous_job_index' => $previousJobIndex,
                        'previous_agent' => $previousResult['agent_name'] ?? 'Unknown',
                    ]);

                    // Enhance input with previous agent's results
                    $enhancedInput = "## Previous Agent Results\n\n";
                    $enhancedInput .= "The previous agent in this workflow has completed their task. Here are their findings:\n\n";
                    $enhancedInput .= "### {$previousResult['agent_name']}\n";
                    $enhancedInput .= "**Task**: {$previousResult['input']}\n\n";
                    $enhancedInput .= "**Results**:\n{$previousResult['result']}\n\n";

                    // Include sources if available
                    if (isset($previousResult['sources']) && ! empty($previousResult['sources'])) {
                        $enhancedInput .= "**Sources Used**:\n";
                        foreach ($previousResult['sources'] as $source) {
                            $enhancedInput .= "- {$source}\n";
                        }
                        $enhancedInput .= "\n";
                    }

                    $enhancedInput .= "---\n\n## Your Task\n\n";
                    $enhancedInput .= "Build upon these findings to complete your part of the workflow:\n\n";
                    $enhancedInput .= $this->execution->input;

                    // Update execution input with enhanced context
                    $this->execution->input = $enhancedInput;
                    $this->execution->save();

                    Log::debug('ExecuteAgentJob: Enhanced input created', [
                        'execution_id' => $this->execution->id,
                        'original_length' => strlen($this->execution->input),
                        'enhanced_length' => strlen($enhancedInput),
                    ]);
                } else {
                    Log::warning('ExecuteAgentJob: Previous result not available or contained error', [
                        'execution_id' => $this->execution->id,
                        'previous_job_index' => $previousJobIndex,
                        'batch_id' => $metadata['batch_id'],
                        'previous_had_error' => isset($previousResult['error']),
                    ]);
                }
            }

            // Create executor and run the agent
            $executor = app(AgentExecutor::class);
            $result = $executor->execute($this->execution, $this->interactionId);

            // Apply output actions to transform/process result data
            $result = $this->applyOutputActions($result);

            Log::info('ExecuteAgentJob: Job completed successfully', [
                'execution_id' => $this->execution->id,
                'result_length' => strlen($result),
            ]);

            // Store result in Redis for workflow coordination (both batch and chain workflows)
            $metadata = $this->execution->metadata ?? [];
            // CRITICAL: Use custom batch_id from metadata first (for mixed/sequential workflows)
            // Fall back to Laravel's batch ID only if no custom batch_id exists
            $batchId = $metadata['batch_id'] ?? ($this->batch() ? $this->batch()->id : null);
            $jobIndex = $this->jobIndex ?? ($metadata['job_index'] ?? null);

            if ($batchId !== null && $jobIndex !== null) {
                $resultStore->storeJobResult(
                    $batchId,
                    $jobIndex,
                    [
                        'agent_id' => $this->execution->agent_id,
                        'agent_name' => $this->execution->agent->name,
                        'execution_id' => $this->execution->id,
                        'input' => $this->execution->input,
                        'result' => $result,
                        'sources' => $this->extractSources($result),
                        'completed_at' => now()->toISOString(),
                    ]
                );

                Log::debug('ExecuteAgentJob: Stored result in WorkflowResultStore', [
                    'batch_id' => $batchId,
                    'job_index' => $jobIndex,
                    'execution_id' => $this->execution->id,
                    'workflow_type' => $metadata['workflow_type'] ?? 'unknown',
                ]);
            }

            // CRITICAL FIX: Handle completion for simple workflows without synthesis
            // AND direct single agent executions (execution_strategy: single_agent)
            // Both need to broadcast completion themselves since they don't have synthesis jobs
            $workflowType = $metadata['workflow_type'] ?? null;
            $executionStrategy = $metadata['execution_strategy'] ?? null;
            $isSimpleWorkflow = $workflowType === 'simple';
            $isSingleAgentExecution = $executionStrategy === 'single_agent' || $workflowType === null;
            $isLastJobInChain = ! $this->batch() && ($jobIndex === 0 || $jobIndex === null);

            if (($isSimpleWorkflow || $isSingleAgentExecution) && $isLastJobInChain && $this->interactionId) {
                Log::info('ExecuteAgentJob: Broadcasting completion for single agent execution', [
                    'execution_id' => $this->execution->id,
                    'interaction_id' => $this->interactionId,
                    'result_length' => strlen($result),
                    'workflow_type' => $workflowType,
                    'execution_strategy' => $executionStrategy,
                ]);

                // Use BroadcastsWorkflowEvents trait methods
                $sources = $this->extractSources($result);
                \App\Jobs\Concerns\BroadcastsWorkflowEvents::broadcastSingleAgentCompletionStatic(
                    $this->interactionId,
                    $this->execution->id,
                    $result,
                    [
                        'workflow_type' => $workflowType ?? 'single_agent',
                        'execution_strategy' => $executionStrategy ?? 'single_agent',
                        'agent_name' => $this->execution->agent->name,
                        'sources_count' => count($sources),
                    ]
                );

                // Update chat interaction with final answer
                // CONCURRENCY: Use transaction with row lock to prevent race conditions
                \Illuminate\Support\Facades\DB::transaction(function () use ($result) {
                    $interaction = \App\Models\ChatInteraction::lockForUpdate()
                        ->find($this->interactionId);

                    if ($interaction) {
                        // Check if answer was already set by another job (race condition detection)
                        if ($interaction->answer && strlen($interaction->answer) > 0) {
                            Log::warning('ExecuteAgentJob: ChatInteraction answer already set by another job', [
                                'interaction_id' => $this->interactionId,
                                'existing_answer_length' => strlen($interaction->answer),
                                'new_answer_length' => strlen($result),
                                'execution_id' => $this->execution->id,
                            ]);

                            // Don't overwrite existing answer - first job wins
                            return;
                        }

                        $interaction->update(['answer' => $result]);
                        Log::info('ExecuteAgentJob: Updated chat interaction with final answer', [
                            'interaction_id' => $this->interactionId,
                            'answer_length' => strlen($result),
                        ]);
                    }
                });
            }

        } catch (\Illuminate\Broadcasting\BroadcastException $e) {
            // Handle broadcast exceptions specifically - these shouldn't fail the job
            Log::warning('ExecuteAgentJob: Broadcast failed but job succeeded', [
                'execution_id' => $this->execution->id,
                'broadcast_error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);

            // Try to mark execution as failed with broadcast issue noted
            try {
                if (! $this->execution->isFailed()) {
                    $this->execution->markAsFailed('Job completed but broadcasting failed: '.$e->getMessage());
                }
            } catch (\Exception $markFailedException) {
                Log::error('ExecuteAgentJob: Could not mark execution as failed after broadcast error', [
                    'execution_id' => $this->execution->id,
                    'mark_failed_error' => $markFailedException->getMessage(),
                    'original_broadcast_error' => $e->getMessage(),
                ]);
            }

            // Don't re-throw broadcast exceptions - the job itself succeeded
            Log::info('ExecuteAgentJob: Job completed despite broadcast failure', [
                'execution_id' => $this->execution->id,
            ]);

        } catch (\Exception $e) {
            Log::error('ExecuteAgentJob: Job failed with exception', [
                'execution_id' => $this->execution->id,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark execution as failed if not already marked
            try {
                if (! $this->execution->isFailed()) {
                    $this->execution->markAsFailed($e->getMessage());
                }
            } catch (\Exception $markFailedException) {
                // Log but don't let marking failure mask the original error
                Log::error('ExecuteAgentJob: Error while marking execution as failed', [
                    'execution_id' => $this->execution->id,
                    'mark_failed_error' => $markFailedException->getMessage(),
                    'original_error' => $e->getMessage(),
                ]);
            }

            // Store failure in Redis for workflow coordination (both batch and chain workflows)
            $errorMetadata = $this->execution->metadata ?? [];
            $errorBatchId = $this->batch() ? $this->batch()->id : ($errorMetadata['batch_id'] ?? null);
            $errorJobIndex = $this->jobIndex ?? ($errorMetadata['job_index'] ?? null);

            if ($errorBatchId !== null && $errorJobIndex !== null) {
                $resultStore->storeJobResult(
                    $errorBatchId,
                    $errorJobIndex,
                    [
                        'agent_id' => $this->execution->agent_id,
                        'agent_name' => $this->execution->agent->name ?? 'Unknown',
                        'execution_id' => $this->execution->id,
                        'error' => true,
                        'message' => $e->getMessage(),
                        'completed_at' => now()->toISOString(),
                    ]
                );

                Log::debug('ExecuteAgentJob: Stored error result in WorkflowResultStore', [
                    'batch_id' => $errorBatchId,
                    'job_index' => $errorJobIndex,
                    'execution_id' => $this->execution->id,
                    'workflow_type' => $errorMetadata['workflow_type'] ?? 'unknown',
                ]);
            }

            throw $e;
        } finally {
            // Clear status reporter context
            if (app()->has('status_reporter')) {
                app()->forgetInstance('status_reporter');
            }
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ExecuteAgentJob: Job failed permanently', [
            'execution_id' => $this->execution->id,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);

        // Ensure execution is marked as failed using trait method
        $this->safeMarkAsFailed(
            $this->execution,
            'Job failed after '.$this->attempts().' attempts: '.$exception->getMessage(),
            [
                'job_class' => self::class,
                'attempts' => $this->attempts(),
            ]
        );

        // Clear status reporter context
        if (app()->has('status_reporter')) {
            app()->forgetInstance('status_reporter');
        }
    }

    /**
     * Get the queue name based on agent configuration
     */
    protected function getQueueName(): string
    {
        // Use different queues for different types of agents for better load distribution
        $agent = $this->execution->agent;

        if (str_contains(strtolower($agent->name), 'research')) {
            return 'research-agents';
        }

        if (str_contains(strtolower($agent->name), 'analysis')) {
            return 'analysis-agents';
        }

        return 'default-agents';
    }

    /**
     * Get the unique ID for the job
     */
    public function uniqueId(): string
    {
        return 'agent-execution-'.$this->execution->id;
    }

    /**
     * Extract source URLs from result text
     */
    protected function extractSources(string $result): array
    {
        // Extract markdown links [text](url)
        preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $result, $matches);

        // Get unique URLs
        $sources = array_unique($matches[2] ?? []);

        // Filter out invalid URLs
        return array_values(array_filter($sources, function ($url) {
            return filter_var($url, FILTER_VALIDATE_URL) !== false;
        }));
    }

    /**
     * Get the interaction ID for this job (for JobStatusManager tracking)
     */
    public function getInteractionId(): ?int
    {
        return $this->interactionId;
    }

    /**
     * Get job-specific tracking metadata for JobStatusManager.
     *
     * @return array<string, mixed>
     */
    protected function getJobTrackingMetadata(): array
    {
        return [
            'type' => 'agent_execution',
            'agent_id' => $this->execution->agent_id,
            'agent_name' => $this->execution->agent->name ?? 'Unknown',
            'execution_id' => $this->execution->id,
            'job_index' => $this->jobIndex,
            'queue' => $this->getQueueName(),
            'class' => static::class,
        ];
    }

    /**
     * Determine if this job should receive previous workflow results.
     *
     * Sequential workflows: always get previous result
     * Mixed workflows: get previous if in sequential stage OR not first stage
     * Parallel workflows: never get previous (execute independently)
     *
     * @param  array{workflow_type?: string, job_index?: int, stage_type?: string, stage_index?: int}  $metadata  Job metadata
     * @return bool True if should enhance input with previous results
     */
    protected function shouldEnhanceWithPrevious(array $metadata): bool
    {
        if (! isset($metadata['workflow_type'], $metadata['job_index']) || $metadata['job_index'] === 0) {
            return false;
        }

        if (! isset($metadata['batch_id'])) {
            return false;
        }

        if ($metadata['workflow_type'] === 'sequential') {
            return true;
        }

        if ($metadata['workflow_type'] === 'mixed' &&
            isset($metadata['stage_type']) &&
            ($metadata['stage_type'] === 'sequential' || ($metadata['stage_index'] ?? 0) > 0)) {
            return true;
        }

        return false;
    }

    /**
     * Apply input actions to transform input data before agent execution
     */
    protected function applyInputActions(): void
    {
        $metadata = $this->execution->metadata ?? [];

        if (empty($metadata['input_actions'])) {
            return;
        }

        // Sort actions by priority (lower = executes first)
        $actions = collect($metadata['input_actions'])
            ->sortBy('priority')
            ->values()
            ->toArray();

        Log::debug('ExecuteAgentJob: Applying input actions', [
            'execution_id' => $this->execution->id,
            'actions_count' => count($actions),
            'input_length_before' => strlen($this->execution->input),
        ]);

        // Emit status stream for action execution start
        if ($this->interactionId) {
            \App\Models\StatusStream::report(
                $this->interactionId,
                'input_actions',
                'Processing '.count($actions).' input action(s) before agent execution',
                ['step_type' => 'processing'],
                true,
                false,
                $this->execution->id
            );
        }

        $data = $this->execution->input;
        $context = [
            'agent' => $this->execution->agent,
            'execution' => $this->execution,
            'input' => $this->execution->input,
        ];

        $actionResults = [];

        foreach ($actions as $index => $actionConfig) {
            $actionName = $actionConfig['method'];
            $startTime = microtime(true);
            $originalLength = strlen($data);

            try {
                // Emit status for individual action
                if ($this->interactionId) {
                    \App\Models\StatusStream::report(
                        $this->interactionId,
                        'input_action',
                        "Executing input action: {$actionName}",
                        ['step_type' => 'processing', 'action' => $actionName],
                        true,
                        false,
                        $this->execution->id
                    );
                }

                $data = ActionRegistry::execute(
                    $actionName,
                    $data,
                    $context,
                    $actionConfig['params'] ?? []
                );

                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $newLength = strlen($data);

                $actionResults[] = [
                    'action' => $actionName,
                    'status' => 'success',
                    'duration_ms' => $duration,
                    'input_length' => $originalLength,
                    'output_length' => $newLength,
                    'params' => $actionConfig['params'] ?? [],
                    'executed_at' => now()->toIso8601String(),
                ];

                Log::info('ExecuteAgentJob: Input action succeeded', [
                    'execution_id' => $this->execution->id,
                    'action' => $actionName,
                    'duration_ms' => $duration,
                    'data_changed' => $originalLength !== $newLength,
                ]);

            } catch (\Exception $e) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);

                $actionResults[] = [
                    'action' => $actionName,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'duration_ms' => $duration,
                    'params' => $actionConfig['params'] ?? [],
                    'executed_at' => now()->toIso8601String(),
                ];

                Log::error('ExecuteAgentJob: Input action failed', [
                    'execution_id' => $this->execution->id,
                    'action' => $actionName,
                    'error' => $e->getMessage(),
                ]);

                // Emit failure status
                if ($this->interactionId) {
                    \App\Models\StatusStream::report(
                        $this->interactionId,
                        'input_action_error',
                        "Input action {$actionName} failed: {$e->getMessage()}",
                        ['step_type' => 'error', 'action' => $actionName],
                        true,
                        false,
                        $this->execution->id
                    );
                }

                // Continue with remaining actions even if one fails
            }
        }

        // Update execution input with transformed data
        if ($data !== $this->execution->input) {
            $this->execution->input = $data;
            $this->execution->save();

            Log::info('ExecuteAgentJob: Input transformed by actions', [
                'execution_id' => $this->execution->id,
                'length_before' => strlen($context['input']),
                'length_after' => strlen($data),
            ]);
        }

        // Store action results in execution metadata
        $metadata['input_actions_executed'] = $actionResults;
        $this->execution->metadata = $metadata;
        $this->execution->save();

        // Emit completion status
        if ($this->interactionId) {
            $successCount = collect($actionResults)->where('status', 'success')->count();
            $totalCount = count($actionResults);

            \App\Models\StatusStream::report(
                $this->interactionId,
                'input_actions',
                "Completed {$successCount}/{$totalCount} input action(s) successfully",
                ['step_type' => 'success', 'success_count' => $successCount, 'total_count' => $totalCount],
                true,
                false,
                $this->execution->id
            );
        }
    }

    /**
     * Apply output actions to transform result data after agent execution
     */
    protected function applyOutputActions(string $result): string
    {
        $metadata = $this->execution->metadata ?? [];

        if (empty($metadata['output_actions'])) {
            return $result;
        }

        // Sort actions by priority (lower = executes first)
        $actions = collect($metadata['output_actions'])
            ->sortBy('priority')
            ->values()
            ->toArray();

        Log::debug('ExecuteAgentJob: Applying output actions', [
            'execution_id' => $this->execution->id,
            'actions_count' => count($actions),
            'output_length_before' => strlen($result),
        ]);

        // Emit status stream for action execution start
        if ($this->interactionId) {
            \App\Models\StatusStream::report(
                $this->interactionId,
                'output_actions',
                'Processing '.count($actions).' output action(s) after agent execution',
                ['step_type' => 'processing'],
                true,
                false,
                $this->execution->id
            );
        }

        $data = $result;
        $context = [
            'agent' => $this->execution->agent,
            'execution' => $this->execution,
            'input' => $this->execution->input,
            'output' => $result,
        ];

        $actionResults = [];

        foreach ($actions as $index => $actionConfig) {
            $actionName = $actionConfig['method'];
            $startTime = microtime(true);
            $originalLength = strlen($data);

            try {
                // Emit status for individual action
                if ($this->interactionId) {
                    \App\Models\StatusStream::report(
                        $this->interactionId,
                        'output_action',
                        "Executing output action: {$actionName}",
                        ['step_type' => 'processing', 'action' => $actionName],
                        true,
                        false,
                        $this->execution->id
                    );
                }

                $data = ActionRegistry::execute(
                    $actionName,
                    $data,
                    $context,
                    $actionConfig['params'] ?? []
                );

                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $newLength = strlen($data);

                $actionResults[] = [
                    'action' => $actionName,
                    'status' => 'success',
                    'duration_ms' => $duration,
                    'input_length' => $originalLength,
                    'output_length' => $newLength,
                    'params' => $actionConfig['params'] ?? [],
                    'executed_at' => now()->toIso8601String(),
                ];

                Log::info('ExecuteAgentJob: Output action succeeded', [
                    'execution_id' => $this->execution->id,
                    'action' => $actionName,
                    'duration_ms' => $duration,
                    'data_changed' => $originalLength !== $newLength,
                ]);

            } catch (\Exception $e) {
                $duration = round((microtime(true) - $startTime) * 1000, 2);

                $actionResults[] = [
                    'action' => $actionName,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'duration_ms' => $duration,
                    'params' => $actionConfig['params'] ?? [],
                    'executed_at' => now()->toIso8601String(),
                ];

                Log::error('ExecuteAgentJob: Output action failed', [
                    'execution_id' => $this->execution->id,
                    'action' => $actionName,
                    'error' => $e->getMessage(),
                ]);

                // Emit failure status
                if ($this->interactionId) {
                    \App\Models\StatusStream::report(
                        $this->interactionId,
                        'output_action_error',
                        "Output action {$actionName} failed: {$e->getMessage()}",
                        ['step_type' => 'error', 'action' => $actionName],
                        true,
                        false,
                        $this->execution->id
                    );
                }

                // Continue with remaining actions even if one fails
            }
        }

        if ($data !== $result) {
            Log::info('ExecuteAgentJob: Output transformed by actions', [
                'execution_id' => $this->execution->id,
                'length_before' => strlen($result),
                'length_after' => strlen($data),
            ]);
        }

        // Store action results in execution metadata
        $metadata['output_actions_executed'] = $actionResults;
        $this->execution->metadata = $metadata;
        $this->execution->save();

        // Emit completion status
        if ($this->interactionId) {
            $successCount = collect($actionResults)->where('status', 'success')->count();
            $totalCount = count($actionResults);

            \App\Models\StatusStream::report(
                $this->interactionId,
                'output_actions',
                "Completed {$successCount}/{$totalCount} output action(s) successfully",
                ['step_type' => 'success', 'success_count' => $successCount, 'total_count' => $totalCount],
                true,
                false,
                $this->execution->id
            );
        }

        return $data;
    }
}
