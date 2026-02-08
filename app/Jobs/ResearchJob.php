<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsWorkflowEvents;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\ChatInteraction;
use App\Services\Agents\AgentExecutor;
use App\Services\Agents\ResearchPlan;
use App\Services\Agents\ToolRegistry;
use App\Services\Research\ResearchService;
use App\Services\StatusReporter;
use App\Traits\HandlesExecutionFailures;
use App\Traits\TracksJobStatus;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class ResearchJob implements ShouldQueue
{
    use Batchable, BroadcastsWorkflowEvents, Dispatchable, HandlesExecutionFailures, InteractsWithQueue, Queueable, SerializesModels, TracksJobStatus;

    public const MODE_PLAN = 'plan';

    public const MODE_EXECUTE = 'execute';

    public const MODE_SYNTHESIZE = 'synthesize';

    public const MODE_SINGLE_AGENT = 'single_agent';

    // Job configuration
    public $tries = 1; // Never retry - research failures should not be retried automatically

    public $timeout = 300; // 5 minutes per operation (adjust as needed)

    public $deleteWhenMissingModels = true; // Delete job if models are missing

    /**
     * Store only primitive types/IDs to avoid serialization issues with closures/objects
     */
    // Job properties
    protected string $mode;

    protected int $executionId;

    protected ?int $interactionId = null;

    protected ?int $threadIndex = null;

    protected ?string $threadQuery = null;

    protected array $metadata = [];

    // Set a higher priority for better processing in Redis
    public $queue_priority = 100;

    public function __construct(
        string $mode,
        int $executionId,
        ?int $interactionId = null,
        ?int $threadIndex = null,
        ?string $threadQuery = null,
        array $metadata = []
    ) {
        $this->mode = $mode;
        $this->executionId = $executionId;
        $this->interactionId = $interactionId;
        $this->threadIndex = $threadIndex;
        $this->threadQuery = $threadQuery;
        $this->metadata = $metadata;

        // Set queue name based on mode
        $this->onQueue($this->getQueueNameForMode());

        // Track job as queued if we have an interaction ID
        $this->trackJobQueued();
    }

    /**
     * Get the interaction ID for this job
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
            'mode' => $this->mode,
            'queue' => $this->getQueueNameForMode(),
            'class' => static::class,
            'thread_index' => $this->threadIndex,
            'execution_id' => $this->executionId,
        ];
    }

    public function handle(ToolRegistry $toolRegistry): void
    {
        Log::info('ResearchJob: Starting job execution', [
            'mode' => $this->mode,
            'execution_id' => $this->executionId,
            'interaction_id' => $this->interactionId,
            'thread_index' => $this->threadIndex,
            'queue' => $this->queue,
        ]);

        // Preserve existing StatusReporter instance if available
        if ($this->interactionId) {
            $existingStatusReporter = app()->has('status_reporter') ? app('status_reporter') : null;

            if (! $existingStatusReporter ||
                ! $existingStatusReporter instanceof \App\Services\StatusReporter) {
                // Only create new instance if none exists or is invalid
                // Pass both interaction ID and agent execution ID
                $statusReporter = new StatusReporter($this->interactionId, $this->executionId);
                app()->instance('status_reporter', $statusReporter);
            } else {
                // Use existing StatusReporter but update the agent execution ID
                $existingStatusReporter->setAgentExecutionId($this->executionId);
                Log::info('ResearchJob: Using existing StatusReporter, updated agent execution ID', [
                    'interaction_id' => $this->interactionId,
                    'agent_execution_id' => $this->executionId,
                ]);
            }
        }

        try {
            // Get execution model - hydrate model from ID
            $execution = AgentExecution::findOrFail($this->executionId);

            // Check if execution was cancelled
            if ($execution->status === 'cancelled') {
                Log::info('ResearchJob: Execution was cancelled, skipping', [
                    'execution_id' => $this->executionId,
                    'mode' => $this->mode,
                ]);

                return;
            }

            // Create research service instance
            $researchService = app(ResearchService::class);

            // Handle job based on mode
            switch ($this->mode) {
                case self::MODE_PLAN:
                    $this->handlePlanMode($execution, $toolRegistry, $researchService);
                    break;

                case self::MODE_EXECUTE:
                    $this->handleExecuteMode($execution, $toolRegistry, $researchService);
                    break;

                case self::MODE_SYNTHESIZE:
                    $this->handleSynthesizeMode($execution, $toolRegistry, $researchService);
                    break;

                case self::MODE_SINGLE_AGENT:
                    $this->handleSingleAgentMode($execution, $toolRegistry, $researchService);
                    break;

                default:
                    throw new \InvalidArgumentException("Unknown research job mode: {$this->mode}");
            }

            Log::info('ResearchJob: Job completed successfully', [
                'execution_id' => $this->executionId,
                'mode' => $this->mode,
            ]);

        } catch (\Error $e) {
            // Handle PHP Fatal Errors (like DOMEntityReference::getAttribute())
            $this->handleDOMError($e);

        } catch (Throwable $e) {
            // Check for specific DOM parsing errors that should be handled gracefully
            if ($this->isDOMParsingError($e)) {
                $this->handleDOMError($e);

                return; // Don't re-throw for DOM errors
            }

            Log::error('ResearchJob: Job failed with exception', [
                'execution_id' => $this->executionId,
                'mode' => $this->mode,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark execution as failed if not already marked
            try {
                $execution = AgentExecution::find($this->executionId);
                if ($execution && ! $execution->isFailed()) {
                    $execution->markAsFailed($e->getMessage());
                }

                // Update interaction with error message if available
                if ($this->interactionId) {
                    $interaction = ChatInteraction::find($this->interactionId);
                    if ($interaction) {
                        $interaction->update([
                            'answer' => $interaction->answer."\n\n❌ Research failed: ".$e->getMessage(),
                        ]);
                    }
                }

                // Broadcast failure if this is the main execution
                if ($this->mode !== self::MODE_EXECUTE || $this->threadIndex === null) {
                    $this->broadcastFailure($e->getMessage());
                }

            } catch (Throwable $innerException) {
                Log::error('ResearchJob: Error while handling failure', [
                    'execution_id' => $this->executionId,
                    'mode' => $this->mode,
                    'original_error' => $e->getMessage(),
                    'handling_error' => $innerException->getMessage(),
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
     * Handle planning mode - analyze query and create research plan
     */
    protected function handlePlanMode(AgentExecution $execution, ToolRegistry $toolRegistry, ResearchService $researchService): void
    {
        if (! $this->interactionId) {
            throw new \InvalidArgumentException('Interaction ID is required for plan mode');
        }

        try {
            // Delegate to the research service
            $plan = $researchService->handlePlanMode($execution, $toolRegistry, $this->interactionId);

            // Check if we got a WorkflowPlan or ResearchPlan
            if ($plan instanceof \App\Services\Agents\WorkflowPlan) {
                // NEW SYSTEM: Use WorkflowOrchestrator for multi-strategy workflows
                Log::info('ResearchJob: Received WorkflowPlan, routing to WorkflowOrchestrator', [
                    'execution_id' => $execution->id,
                    'strategy_type' => $plan->strategyType,
                    'stages_count' => count($plan->stages),
                ]);

                $orchestrator = new \App\Services\Agents\WorkflowOrchestrator;
                $batchId = $orchestrator->execute($plan, $execution, $this->interactionId);

                Log::info('ResearchJob: WorkflowOrchestrator execution started', [
                    'execution_id' => $execution->id,
                    'batch_id' => $batchId,
                    'interaction_id' => $this->interactionId,
                ]);

                // Workflow will handle its own completion/synthesis
                return;

            } else {
                // OLD SYSTEM: ResearchPlan with parallel research
                // Check if plan has executionStrategy property - if not, it may be an error result
                if (! isset($plan->executionStrategy) || $plan->executionStrategy === 'error') {
                    // Plan creation failed, already logged in the service
                    Log::error('ResearchJob: Plan creation failed or returned error plan', [
                        'execution_id' => $execution->id,
                    ]);

                    return;
                }

                // Execute research steps based on plan
                if ($plan->executionStrategy === 'simple') {
                    // For simple queries, just dispatch a single execute job
                    $this->dispatchExecuteJob($execution->id, $this->interactionId, 0, $plan->subQueries[0]);
                } else {
                    // For standard/complex queries, create a batch of execute jobs
                    $this->dispatchExecuteBatch($execution, $plan, $this->interactionId);
                }
            }

        } catch (\Exception $e) {
            Log::error('ResearchJob: Planning failed', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Report failure via service
            $statusReporter = app('status_reporter');
            $statusReporter->report('planning_failed', "Planning failed: {$e->getMessage()}");
            $this->broadcastFailure("Research planning failed: {$e->getMessage()}");

            throw $e;
        }
    }

    /**
     * Handle execute mode - run a single research thread
     */
    protected function handleExecuteMode(AgentExecution $execution, ToolRegistry $toolRegistry, ResearchService $researchService): void
    {
        // Validate we have the necessary thread data
        if ($this->threadIndex === null || $this->threadQuery === null || ! $this->interactionId) {
            throw new \InvalidArgumentException('Thread index, query, and interaction ID are required for execute mode');
        }

        try {
            // Delegate to the research service
            $result = $researchService->handleExecuteMode($execution, $toolRegistry, $this->interactionId, $this->threadIndex, $this->threadQuery);

            // Check if result indicates an error
            if (isset($result['error']) && $result['error'] === true) {
                Log::error('ResearchJob: Execute mode returned error result', [
                    'execution_id' => $execution->id,
                    'thread_index' => $this->threadIndex,
                    'error_message' => $result['message'] ?? 'Unknown error',
                ]);

                return;
            }

            // Get parent execution
            $parentExecution = $execution->parent_agent_execution_id ?
                AgentExecution::find($execution->parent_agent_execution_id) :
                $execution;

            // Note: We've removed the ad-hoc "last job" detection logic from here.
            // For batch jobs, synthesis is handled via the batch's then() callback.
            // For non-batch single jobs, synthesis is dispatched directly when the job is created.
            // This eliminates potential race conditions where multiple jobs might detect they're the "last" one.

        } catch (\Exception $e) {
            Log::error('ResearchJob: Exception in execute mode', [
                'execution_id' => $execution->id,
                'thread_index' => $this->threadIndex,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle synthesize mode - combine results and create final answer
     */
    protected function handleSynthesizeMode(AgentExecution $execution, ToolRegistry $toolRegistry, ResearchService $researchService): void
    {
        if (! $this->interactionId) {
            throw new \InvalidArgumentException('Interaction ID is required for synthesize mode');
        }

        try {
            // Handle executions that are already in completed state
            if ($execution->isInState(AgentExecution::STATE_COMPLETED)) {
                Log::info('ResearchJob: Execution already in completed state, continuing with synthesis anyway', [
                    'execution_id' => $execution->id,
                    'interaction_id' => $this->interactionId,
                ]);
            }

            // Delegate to the research service
            $result = $researchService->handleSynthesizeMode($execution, $toolRegistry, $this->interactionId);

            // Check if result indicates an error
            if (isset($result['error']) && $result['error'] === true) {
                Log::error('ResearchJob: Synthesize mode returned error result', [
                    'execution_id' => $execution->id,
                    'error_message' => $result['message'] ?? 'Unknown error',
                ]);

                // Broadcast failure with the error message
                $this->broadcastFailure($result['message'] ?? 'Synthesis failed with unknown error');

                return;
            }

            // Broadcast completion event
            if (isset($result['finalAnswer']) && isset($result['metadata'])) {
                $this->broadcastCompletion($result['finalAnswer'], $result['metadata']);
            }
        } catch (\Exception $e) {
            Log::error('ResearchJob: Synthesis failed', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Broadcast failure
            $this->broadcastFailure("Synthesis failed: {$e->getMessage()}");

            throw $e;
        }
    }

    /**
     * Handle single agent mode execution - unified execution for all individual agents
     */
    protected function handleSingleAgentMode(AgentExecution $execution, ToolRegistry $toolRegistry, ResearchService $researchService): void
    {
        Log::info('ResearchJob: Handling single agent mode', [
            'execution_id' => $this->executionId,
            'interaction_id' => $this->interactionId,
            'agent_id' => $execution->agent_id,
            'agent_name' => $execution->agent->name ?? 'Unknown',
        ]);

        try {
            // CRITICAL FIX: Set container bindings for tools to access execution context
            app()->instance('current_user_id', $execution->user_id);
            app()->instance('current_agent_id', $execution->agent_id);
            app()->instance('current_interaction_id', $this->interactionId);

            Log::info('ResearchJob: Set container bindings for single agent execution', [
                'user_id' => $execution->user_id,
                'agent_id' => $execution->agent_id,
                'interaction_id' => $this->interactionId,
            ]);

            // PROMPTLY AGENT ROUTING: If this is Promptly Agent, use AI-based agent selection
            if ($execution->agent->name === 'Promptly Agent') {
                Log::info('ResearchJob: Detected Promptly Agent - routing through PromptlyService', [
                    'execution_id' => $this->executionId,
                    'interaction_id' => $this->interactionId,
                    'query' => substr($execution->input, 0, 100),
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
                $answer = $selectedExecution->output;

                Log::info('ResearchJob: Promptly routed to agent and completed', [
                    'promptly_execution_id' => $this->executionId,
                    'selected_agent_id' => $selectedExecution->agent_id,
                    'selected_agent_name' => $selectedExecution->agent->name,
                    'result_length' => strlen($answer),
                ]);
            } else {
                // Create AgentExecutor instance via dependency injection
                $agentExecutor = app(AgentExecutor::class);

                // Execute single agent normally
                $answer = $agentExecutor->executeSingleAgent($execution);
            }

            // Refresh execution to check if it was orchestrated as a workflow
            $execution->refresh();
            $metadata = $execution->metadata ?? [];

            // Check if this execution orchestrated a workflow (empty answer + workflow_orchestrated flag)
            if (empty($answer) && isset($metadata['workflow_orchestrated']) && $metadata['workflow_orchestrated']) {
                Log::info('ResearchJob: Agent orchestrated a workflow, waiting for synthesis', [
                    'execution_id' => $this->executionId,
                    'interaction_id' => $this->interactionId,
                    'agent_id' => $execution->agent_id,
                    'awaiting_synthesis' => $metadata['awaiting_synthesis'] ?? false,
                ]);

                // Don't mark as completed, don't broadcast - synthesis will handle this
                // The execution is already marked as 'running' by orchestrateWorkflowPlan()
                return;
            }

            // Format result to match expected structure
            $result = [
                'success' => true,
                'final_answer' => $answer,
                'metadata' => [
                    'execution_strategy' => 'single_agent',
                    'agent_name' => $execution->agent->name,
                    'agent_id' => $execution->agent_id,
                ],
            ];

            // DO NOT update interaction directly - let the frontend handler do this
            // This matches the holistic workflow pattern where only broadcast is used
            // and the frontend handleHolisticWorkflowCompleted() calls setFinalAnswer()
            Log::info('ResearchJob: Preparing to broadcast single agent result', [
                'interaction_id' => $this->interactionId,
                'execution_id' => $this->executionId,
                'agent_id' => $execution->agent_id,
                'success' => $result['success'],
                'result_length' => strlen($result['final_answer']),
            ]);

            // Mark execution as completed using proper method to trigger output actions
            // Preserve existing metadata (like ai_prompt) by merging with ResearchJob metadata
            $existingMetadata = $execution->fresh()->metadata ?? [];
            $jobMetadata = $result['metadata'] ?? [];
            $mergedMetadata = array_merge($existingMetadata, $jobMetadata);

            // Use markAsCompleted() or markAsFailed() to properly trigger output actions
            if ($result['success']) {
                $execution->markAsCompleted($result['final_answer'], $mergedMetadata);
            } else {
                $execution->markAsFailed($result['final_answer'], $mergedMetadata);
            }

            // Notify completion via status reporter
            $statusReporter = app(StatusReporter::class, ['interactionId' => $this->interactionId]);
            $statusReporter->report('single_agent_completed', 'Agent execution completed successfully. Results are now available.', true);

            // Broadcast completion event
            if (isset($result['final_answer']) && isset($result['metadata'])) {
                $this->broadcastCompletion($result['final_answer'], $result['metadata']);
                Log::info('ResearchJob: Single agent mode execution completed', [
                    'execution_id' => $this->executionId,
                    'interaction_id' => $this->interactionId,
                    'agent_id' => $execution->agent_id,
                    'success' => $result['success'],
                    'result_length' => strlen($result['final_answer']),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('ResearchJob: Single agent mode execution failed', [
                'execution_id' => $this->executionId,
                'interaction_id' => $this->interactionId,
                'agent_id' => $execution->agent_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark execution as failed
            $execution->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            // Broadcast failure
            $this->broadcastFailure($e->getMessage());

            throw $e;
        }
    }

    /**
     * Dispatch a single execute job
     */
    protected function dispatchExecuteJob(int $executionId, int $interactionId, int $threadIndex, string $query): void
    {
        $job = new self(
            self::MODE_EXECUTE,
            $executionId,
            $interactionId,
            $threadIndex,
            $query
        );

        dispatch($job)->onQueue('research-threads');

        Log::info('ResearchJob: Dispatched execute job', [
            'execution_id' => $executionId,
            'thread_index' => $threadIndex,
            'query' => $query,
        ]);

        // For single thread scenarios, schedule synthesis directly after dispatching the execute job
        // This is safer than trying to detect "last job" in handleExecuteMode, which can lead to race conditions
        $execution = AgentExecution::find($executionId);
        $threadCount = $execution->metadata['thread_count'] ?? 1;

        if ($threadCount === 1) {
            // Queue synthesis job with a delay to ensure execute job completes first
            $synthesisJob = new self(
                self::MODE_SYNTHESIZE,
                $executionId,
                $interactionId
            );

            dispatch($synthesisJob)->onQueue('research-coordinator')->delay(now()->addSeconds(5));

            Log::info('ResearchJob: Proactively dispatched synthesis job for single-thread research', [
                'execution_id' => $executionId,
            ]);
        }
    }

    /**
     * Dispatch batch of execute jobs
     */
    protected function dispatchExecuteBatch(AgentExecution $execution, ResearchPlan $plan, int $interactionId): void
    {
        // Create batch of execute jobs
        $jobs = [];

        foreach ($plan->subQueries as $index => $subQuery) {
            $jobs[] = new self(
                self::MODE_EXECUTE,
                $execution->id,
                $interactionId,
                $index,
                $subQuery
            );
        }

        try {
            // Update execution state to reflect that we're batching threads
            $execution->transitionTo(AgentExecution::STATE_EXECUTING);

            // Store only scalar values for callbacks to prevent serialization issues
            $executionId = $execution->id;

            // Create batch with then/catch callbacks
            // Create optimized batch with improved configuration
            $batch = Bus::batch($jobs)
                ->name("Research-{$executionId}")
                ->onQueue('research-threads')
                ->allowFailures()
                // Note: Jobs will run concurrently based on queue worker configuration
                ->then(static function (Batch $batch) use ($executionId, $interactionId) {
                    Log::info('ResearchJob: Batch completed successfully', [
                        'execution_id' => $executionId,
                        'batch_id' => $batch->id,
                        'processed_jobs' => $batch->processedJobs(),
                        'pending_jobs' => $batch->pendingJobs,
                        'total_jobs' => $batch->totalJobs,
                    ]);

                    // Store batch metrics in execution metadata
                    $execution = AgentExecution::find($executionId);
                    if ($execution) {
                        $execution->update([
                            'metadata' => array_merge($execution->metadata ?? [], [
                                'batch_metrics' => [
                                    'processed_jobs' => $batch->processedJobs(),
                                    'failed_jobs' => $batch->failedJobs,
                                    'total_jobs' => $batch->totalJobs,
                                    'completion_time' => now()->toISOString(),
                                ],
                            ]),
                        ]);

                        // Dispatch synthesis job
                        // Use dispatch helper function to avoid capturing $this
                        $job = new ResearchJob(
                            ResearchJob::MODE_SYNTHESIZE,
                            $executionId,
                            $interactionId
                        );
                        dispatch($job)->onQueue('research-coordinator');

                        Log::info('ResearchJob: Dispatched synthesis job from batch completion', [
                            'execution_id' => $executionId,
                        ]);

                        // Report via StatusReporter if available
                        if (app()->has('status_reporter')) {
                            $statusReporter = app('status_reporter');
                            $statusReporter->report('synthesis_queued', 'All research threads completed. Synthesizing results...');
                        }
                    }
                })
                ->catch(static function (Batch $batch, Throwable $e) use ($executionId, $interactionId) {
                    Log::error('ResearchJob: Batch failed', [
                        'execution_id' => $executionId,
                        'batch_id' => $batch->id,
                        'error' => $e->getMessage(),
                        'failed_jobs' => $batch->failedJobs,
                        'processed_jobs' => $batch->processedJobs(),
                    ]);

                    // Even with failures, try to synthesize what we have
                    if ($batch->processedJobs() > 0) {
                        // Store partial success metrics
                        $execution = AgentExecution::find($executionId);
                        if ($execution) {
                            $execution->update([
                                'metadata' => array_merge($execution->metadata ?? [], [
                                    'batch_metrics' => [
                                        'processed_jobs' => $batch->processedJobs(),
                                        'failed_jobs' => $batch->failedJobs,
                                        'total_jobs' => $batch->totalJobs,
                                        'partial_success' => true,
                                        'error' => $e->getMessage(),
                                        'completion_time' => now()->toISOString(),
                                    ],
                                ]),
                            ]);
                        }

                        // Dispatch synthesis job with only scalar values
                        $job = new ResearchJob(
                            ResearchJob::MODE_SYNTHESIZE,
                            $executionId,
                            $interactionId
                        );
                        dispatch($job)->onQueue('research-coordinator');

                        Log::info('ResearchJob: Dispatched synthesis job after partial batch completion', [
                            'execution_id' => $executionId,
                            'successful_jobs' => $batch->processedJobs(),
                            'failed_jobs' => $batch->failedJobs,
                        ]);
                    } else {
                        // All jobs failed, mark execution as failed
                        $execution = AgentExecution::find($executionId);
                        if ($execution) {
                            $execution->markAsFailed("All research threads failed: {$e->getMessage()}");
                        }

                        // Update interaction with error
                        $interaction = ChatInteraction::find($interactionId);
                        if ($interaction) {
                            $interaction->update([
                                'answer' => '❌ Research failed: All research threads failed to complete.',
                            ]);
                        }

                        // Broadcast failure using static helper
                        if (app()->has('status_reporter')) {
                            $statusReporter = app('status_reporter');
                            $statusReporter->report('research_failed', "Research failed: {$e->getMessage()}");
                        }

                        // Broadcast failure event using trait method
                        try {
                            self::broadcastHolisticFailureStatic($interactionId, $executionId, "Research failed: {$e->getMessage()}");
                        } catch (Throwable $broadcastError) {
                            Log::error('ResearchJob: Failed to broadcast failure event', [
                                'interaction_id' => $interactionId,
                                'execution_id' => $executionId,
                                'error' => $broadcastError->getMessage(),
                            ]);
                        }
                    }
                })
                ->dispatch();

            // Store batch ID in execution metadata for future reference
            $execution->update([
                'metadata' => array_merge($execution->metadata ?? [], [
                    'batch_id' => $batch->id,
                    'thread_count' => count($plan->subQueries),
                ]),
            ]);

            // Report batch creation via StatusReporter
            $statusReporter = app('status_reporter');
            $statusReporter->report('research_batch_created',
                "Created batch with {$batch->totalJobs} research threads. ID: {$batch->id}");

        } catch (\Exception $e) {
            Log::error('ResearchJob: Failed to create batch', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark execution as failed
            $execution->markAsFailed("Failed to create research batch: {$e->getMessage()}");

            // Update interaction with error message
            $interaction = ChatInteraction::find($interactionId);
            if ($interaction) {
                $interaction->update([
                    'answer' => "❌ Research failed: Unable to create research batch. {$e->getMessage()}",
                ]);
            }

            // Broadcast failure
            $statusReporter = app('status_reporter');
            $statusReporter->report('research_failed', "Failed to create research batch: {$e->getMessage()}");
            $this->broadcastFailure("Failed to create research batch: {$e->getMessage()}");

            throw $e;
        }

        Log::info('ResearchJob: Dispatched execute batch', [
            'execution_id' => $execution->id,
            'thread_count' => count($plan->subQueries),
        ]);
    }

    /**
     * Dispatch synthesis job
     */
    protected function dispatchSynthesizeJob(int $executionId, int $interactionId): void
    {
        // Create and dispatch job using only scalar values
        $job = new self(
            self::MODE_SYNTHESIZE,
            $executionId,
            $interactionId
        );

        dispatch($job)->onQueue('research-coordinator');

        Log::info('ResearchJob: Dispatched synthesis job', [
            'execution_id' => $executionId,
        ]);

        // Report via StatusReporter if available
        if (app()->has('status_reporter')) {
            $statusReporter = app('status_reporter');
            $statusReporter->report('synthesis_queued', 'All research threads completed. Synthesizing results...');
        }
    }

    /**
     * Get the queue name for this job mode
     */
    protected function getQueueNameForMode(): string
    {
        return match ($this->mode) {
            self::MODE_PLAN => 'research-coordinator',
            self::MODE_EXECUTE => 'research-threads',
            self::MODE_SYNTHESIZE => 'research-coordinator',
            self::MODE_SINGLE_AGENT => 'research-threads',
            default => 'high:research-agents' // Use high priority for default mode
        };
    }

    /**
     * Broadcast successful completion to UI using proper event classes
     */
    protected function broadcastCompletion(string $result, array $metadata): void
    {
        if (! $this->interactionId || ! $this->executionId) {
            Log::warning('ResearchJob: Cannot broadcast completion - missing interaction or execution ID');

            return;
        }

        // Extract sources from metadata if available
        $sources = $metadata['source_links'] ?? [];

        // Use trait methods based on workflow type
        if ($this->mode === self::MODE_SINGLE_AGENT) {
            $this->broadcastSingleAgentCompletion($this->interactionId, $this->executionId, $result, $metadata);
        } else {
            $this->broadcastHolisticCompletion($this->interactionId, $this->executionId, $result, $metadata, $sources);
        }
    }

    /**
     * Static helper to broadcast completion without $this context
     * This can be used from static closures to avoid serialization issues
     */
    public static function broadcastCompletionStatic(string $result, array $metadata, int $interactionId, int $executionId): void
    {
        // Extract sources from metadata if available
        $sources = $metadata['source_links'] ?? [];

        // Always use holistic completion for static calls (they come from batch operations)
        self::broadcastHolisticCompletionStatic($interactionId, $executionId, $result, $metadata, $sources);
    }

    /**
     * Broadcast failure to UI using proper event classes
     */
    protected function broadcastFailure(string $error): void
    {
        if (! $this->interactionId || ! $this->executionId) {
            Log::warning('ResearchJob: Cannot broadcast failure - missing interaction or execution ID');

            return;
        }

        // Use trait methods based on workflow type
        if ($this->mode === self::MODE_SINGLE_AGENT) {
            $this->broadcastSingleAgentFailure($this->interactionId, $this->executionId, $error);
        } else {
            // Map mode to phase for holistic workflows
            $phase = match ($this->mode) {
                self::MODE_PLAN => 'plan',
                self::MODE_EXECUTE => 'execute',
                self::MODE_SYNTHESIZE => 'synthesize',
                default => null
            };

            $this->broadcastHolisticFailure($this->interactionId, $this->executionId, $error, $phase);
        }
    }

    /**
     * Static helper to broadcast failure without $this context
     * This can be used from static closures to avoid serialization issues
     */
    public static function broadcastFailureStatic(string $error, int $interactionId, int $executionId): void
    {
        // Always use holistic failure for static calls (they come from batch operations)
        self::broadcastHolisticFailureStatic($interactionId, $executionId, $error);
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ResearchJob: Job failed permanently', [
            'mode' => $this->mode,
            'execution_id' => $this->executionId,
            'thread_index' => $this->threadIndex,
            'error' => $exception->getMessage(),
        ]);

        // Mark execution as failed using trait method
        $this->safeMarkAsFailedById(
            $this->executionId,
            'Job failed: '.$exception->getMessage(),
            [
                'job_class' => self::class,
                'mode' => $this->mode,
                'thread_index' => $this->threadIndex,
            ]
        );

        // If this is an execution thread, store the error in Redis
        try {
            if ($this->mode === self::MODE_EXECUTE && $this->threadIndex !== null) {
                $resultData = [
                    'sub_query' => $this->threadQuery ?? "Thread {$this->threadIndex}",
                    'findings' => "Research thread failed: {$exception->getMessage()}",
                    'source_count' => 0,
                    'thread_index' => $this->threadIndex,
                    'error' => true,
                ];

                $resultKey = "research_thread_{$this->executionId}_{$this->threadIndex}";
                Redis::setex($resultKey, 86400, json_encode($resultData));
            }
        } catch (Throwable $e) {
            Log::error('ResearchJob: Failed to store error in Redis', [
                'execution_id' => $this->executionId,
                'thread_index' => $this->threadIndex,
                'error' => $e->getMessage(),
            ]);
        } finally {
            // Clear status reporter context
            if (app()->has('status_reporter')) {
                app()->forgetInstance('status_reporter');
            }
        }
    }

    /**
     * Get the unique ID for the job
     */
    public function uniqueId(): string
    {
        $threadSuffix = $this->threadIndex !== null ? "-thread-{$this->threadIndex}" : '';

        return "research-{$this->mode}-{$this->executionId}{$threadSuffix}";
    }

    /**
     * Check if the exception is a DOM parsing error that should be handled gracefully
     */
    protected function isDOMParsingError(Throwable $e): bool
    {
        $errorMessage = $e->getMessage();
        $errorClass = get_class($e);

        // Check for specific DOM-related errors
        $domErrorPatterns = [
            'DOMEntityReference::getAttribute()',
            'DOMEntityReference',
            'Call to undefined method DOMEntityReference',
            'DOMNode::getAttribute()',
            'fivefilters/readability',
            'andreskrey\Readability',
        ];

        foreach ($domErrorPatterns as $pattern) {
            if (str_contains($errorMessage, $pattern)) {
                return true;
            }
        }

        // Check for specific error classes
        $domErrorClasses = [
            'Error', // PHP Fatal Error (which DOMEntityReference errors are)
            'BadMethodCallException',
        ];

        foreach ($domErrorClasses as $errorType) {
            if (str_contains($errorClass, $errorType) && str_contains($errorMessage, 'DOM')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle DOM parsing errors gracefully without failing the entire job
     */
    protected function handleDOMError(Throwable $e): void
    {
        Log::warning('ResearchJob: DOM parsing error handled gracefully', [
            'execution_id' => $this->executionId,
            'mode' => $this->mode,
            'thread_index' => $this->threadIndex,
            'error' => $e->getMessage(),
            'error_class' => get_class($e),
        ]);

        try {
            $execution = AgentExecution::find($this->executionId);

            // For execute mode, store a partial result instead of failing completely
            if ($this->mode === self::MODE_EXECUTE && $this->threadIndex !== null) {
                // Store partial result with error information
                $resultData = [
                    'sub_query' => $this->threadQuery ?? "Thread {$this->threadIndex}",
                    'findings' => '⚠️ Content parsing error encountered. Some content could not be processed due to malformed HTML entities, but research continued with available data.',
                    'source_count' => 0,
                    'thread_index' => $this->threadIndex,
                    'dom_error' => true,
                    'error_handled' => true,
                ];

                $resultKey = "research_thread_{$this->executionId}_{$this->threadIndex}";
                Redis::setex($resultKey, 86400, json_encode($resultData));

                Log::info('ResearchJob: Stored partial result for DOM error in execute mode', [
                    'execution_id' => $this->executionId,
                    'thread_index' => $this->threadIndex,
                ]);
            }

            // For synthesis mode, try to continue with available data
            if ($this->mode === self::MODE_SYNTHESIZE) {
                // Update interaction with a warning message instead of complete failure
                if ($this->interactionId) {
                    $interaction = ChatInteraction::find($this->interactionId);
                    if ($interaction) {
                        $warningMessage = '⚠️ Research completed with some content parsing limitations. Final results may be incomplete due to malformed web content.';

                        // Try to provide partial results if possible
                        $currentAnswer = $interaction->answer ?? '';
                        if (empty($currentAnswer)) {
                            $interaction->update([
                                'answer' => $warningMessage,
                            ]);
                        } else {
                            $interaction->update([
                                'answer' => $currentAnswer."\n\n".$warningMessage,
                            ]);
                        }
                    }
                }

                // Mark execution as completed with warnings instead of failed
                if ($execution && ! $execution->isCompleted()) {
                    $execution->update([
                        'status' => 'completed',
                        'output' => 'Research completed with content parsing limitations',
                        'metadata' => array_merge($execution->metadata ?? [], [
                            'dom_parsing_error' => true,
                            'error_message' => $e->getMessage(),
                            'gracefully_handled' => true,
                        ]),
                    ]);
                }
            }

            // Report the warning via status reporter
            if (app()->has('status_reporter')) {
                $statusReporter = app('status_reporter');
                $statusReporter->report('dom_parsing_warning',
                    'Content parsing issue encountered but research continued. Some web content may be incomplete.');
            }

        } catch (Throwable $innerException) {
            Log::error('ResearchJob: Error while handling DOM error', [
                'execution_id' => $this->executionId,
                'mode' => $this->mode,
                'original_error' => $e->getMessage(),
                'handling_error' => $innerException->getMessage(),
            ]);

            // If we can't handle the error gracefully, fall back to normal error handling
            throw $e;
        }
    }
}
