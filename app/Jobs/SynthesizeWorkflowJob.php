<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsWorkflowEvents;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Services\Agents\AgentExecutor;
use App\Services\Agents\QualityAssuranceService;
use App\Services\Agents\ToolRegistry;
use App\Services\Agents\WorkflowResultStore;
use App\Services\StatusReporter;
use App\Traits\HandlesExecutionFailures;
use App\Traits\TracksJobStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Synthesizes results from completed workflow batch into final answer.
 *
 * Workflow:
 * 1. Collects results from all agents via WorkflowResultStore (Redis)
 * 2. Validates at least one successful result exists
 * 3. Uses Research Synthesizer agent (or configured alternative)
 * 4. Combines findings into comprehensive response
 * 5. Optional: Executes QA validation if requiresQA is true
 * 6. If QA fails and iterations < max: dispatches follow-up research
 * 7. Updates parent execution and chat interaction
 * 8. Broadcasts completion event to frontend
 * 9. Cleans up Redis result keys
 *
 * Quality Assurance (Optional):
 * - Triggered by requiresQA flag or keyword detection
 * - Uses Research QA Validator agent for structured validation
 * - Supports iterative refinement (max 2 iterations by default)
 * - Generates follow-up queries from identified gaps
 * - Tracks QA results in execution metadata
 *
 * Configuration:
 * - No retries (tries = 1) - synthesis failures require investigation
 * - 5-minute timeout
 * - Queue: research-coordinator
 */
class SynthesizeWorkflowJob implements ShouldQueue
{
    use BroadcastsWorkflowEvents, HandlesExecutionFailures, InteractsWithQueue, Queueable, SerializesModels, TracksJobStatus;

    public $timeout = 300; // 5 minutes for synthesis

    public $tries = 1; // Never retry - synthesis failures should be handled explicitly

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $batchId,
        public int $totalJobs,
        public int $parentExecutionId,
        public string $originalQuery,
        public int $userId,
        public ?int $chatSessionId = null,
        public ?int $interactionId = null,
        public ?int $synthesizerAgentId = null,
        public bool $requiresQA = false,
        public array $finalActions = [] // ActionConfig[] - Workflow-level final actions
    ) {
        $this->onQueue('research-coordinator');

        // Track job as queued if we have an interaction ID
        $this->trackJobQueued();
    }

    /**
     * Execute the job.
     */
    public function handle(
        WorkflowResultStore $resultStore,
        ToolRegistry $toolRegistry
    ): void {
        Log::info('SynthesizeWorkflowJob: Starting workflow synthesis', [
            'batch_id' => $this->batchId,
            'parent_execution_id' => $this->parentExecutionId,
            'total_jobs' => $this->totalJobs,
        ]);

        try {
            // Collect all results from Redis
            $results = $resultStore->collectBatchResults($this->batchId, $this->totalJobs);

            if (empty($results)) {
                throw new \Exception('No results collected from workflow batch');
            }

            // Separate successful and failed results
            $successful = array_filter($results, fn ($r) => ! isset($r['error']));
            $failed = array_filter($results, fn ($r) => isset($r['error']));

            Log::info('SynthesizeWorkflowJob: Collected results', [
                'batch_id' => $this->batchId,
                'total_results' => count($results),
                'successful' => count($successful),
                'failed' => count($failed),
            ]);

            // Check if we have enough successful results to synthesize
            if (count($successful) === 0) {
                throw new \Exception('All workflow agents failed - cannot synthesize results');
            }

            // Get parent execution
            $parentExecution = AgentExecution::findOrFail($this->parentExecutionId);

            // Build synthesis input
            $synthesisInput = $this->buildSynthesisInput($results);

            // Determine which agent to use for synthesis
            // Priority: WorkflowPlan's synthesizerAgentId -> Research Synthesizer (by name) -> fallback error
            // This prevents using agents like Research Planner (which output WorkflowPlan JSON) for synthesis
            $agentIdForSynthesis = $this->getSynthesizerAgentId();

            Log::info('SynthesizeWorkflowJob: Selecting agent for synthesis', [
                'parent_execution_id' => $this->parentExecutionId,
                'parent_agent_id' => $parentExecution->agent_id,
                'synthesizer_agent_id_from_plan' => $this->synthesizerAgentId,
                'agent_used_for_synthesis' => $agentIdForSynthesis,
                'using_default_synthesizer' => $this->synthesizerAgentId === null,
            ]);

            // Create synthesis execution using synthesizer agent (not parent's agent!)
            $synthesisExecution = AgentExecution::create([
                'agent_id' => $agentIdForSynthesis,
                'user_id' => $this->userId,
                'chat_session_id' => $this->chatSessionId,
                'input' => $synthesisInput,
                'max_steps' => 1, // Synthesis doesn't need tool usage
                'status' => 'running',
                'parent_agent_execution_id' => $this->parentExecutionId,
                'active_execution_key' => 'synthesis_'.$this->batchId, // Unique key for synthesis to avoid constraint violation
            ]);

            // Set up StatusReporter with interaction ID for status updates during synthesis
            if ($this->interactionId) {
                $statusReporter = new StatusReporter($this->interactionId, $synthesisExecution->id);
                app()->instance('status_reporter', $statusReporter);

                Log::info('SynthesizeWorkflowJob: Created StatusReporter for synthesis', [
                    'interaction_id' => $this->interactionId,
                    'synthesis_execution_id' => $synthesisExecution->id,
                ]);
            }

            // Execute synthesis with interaction ID
            $executor = app(AgentExecutor::class);
            $finalAnswer = $executor->execute($synthesisExecution, $this->interactionId);

            // Execute QA validation if required
            $qaResult = null;
            $qaService = app(QualityAssuranceService::class);

            if ($qaService->shouldTriggerQA($this->originalQuery, $this->requiresQA)) {
                Log::info('SynthesizeWorkflowJob: QA validation triggered', [
                    'batch_id' => $this->batchId,
                    'parent_execution_id' => $this->parentExecutionId,
                    'explicit_flag' => $this->requiresQA,
                ]);

                try {
                    // Execute QA validation
                    $qaResult = $qaService->validateSynthesis(
                        $this->originalQuery,
                        $finalAnswer,
                        $results, // Pass array, not json_encode
                        $this->parentExecutionId,
                        $this->userId,
                        $this->chatSessionId
                    );

                    Log::info('SynthesizeWorkflowJob: QA validation completed', [
                        'batch_id' => $this->batchId,
                        'qa_status' => $qaResult->qaStatus,
                        'overall_score' => $qaResult->overallScore,
                        'has_critical_gaps' => $qaResult->hasCriticalGaps(),
                    ]);

                    // Check if QA failed and we haven't reached max iterations
                    if ($qaResult->failed() && ! $qaService->hasReachedMaxIterations($parentExecution->metadata ?? [])) {
                        // Extract follow-up queries from gaps
                        $followUpQueries = $qaService->generateFollowUpQueries($qaResult);

                        if (! empty($followUpQueries)) {
                            Log::info('SynthesizeWorkflowJob: QA failed - dispatching follow-up research', [
                                'batch_id' => $this->batchId,
                                'parent_execution_id' => $this->parentExecutionId,
                                'follow_up_count' => count($followUpQueries),
                                'current_iteration' => ($parentExecution->metadata['qa_iteration'] ?? 0) + 1,
                            ]);

                            // Combine follow-up queries into comprehensive research request
                            $gapFillingQuery = "RESEARCH GAP ANALYSIS FOLLOW-UP:\n\n";
                            $gapFillingQuery .= "Original Query: {$this->originalQuery}\n\n";
                            $gapFillingQuery .= "Gaps Identified:\n";
                            foreach ($followUpQueries as $index => $query) {
                                $gapFillingQuery .= ($index + 1).". {$query}\n";
                            }
                            $gapFillingQuery .= "\nPlease conduct additional research to address these specific gaps.";

                            // Increment QA iteration counter
                            $newIteration = ($parentExecution->metadata['qa_iteration'] ?? 0) + 1;
                            $parentExecution->update([
                                'metadata' => array_merge($parentExecution->metadata ?? [], [
                                    'qa_iteration' => $newIteration,
                                    'qa_last_result' => $qaResult->toArray(),
                                ]),
                            ]);

                            // Dispatch new holistic research workflow with gap-filling queries
                            // This will trigger a new workflow execution with the follow-up queries
                            $holisticService = app(\App\Services\Agents\HolisticResearchService::class);

                            // Create new agent execution for gap-filling research
                            $gapFillingExecution = AgentExecution::create([
                                'agent_id' => $parentExecution->agent_id, // Use same agent as original
                                'user_id' => $this->userId,
                                'chat_session_id' => $this->chatSessionId,
                                'input' => $gapFillingQuery,
                                'max_steps' => 50,
                                'status' => 'planning',
                                'parent_agent_execution_id' => $this->parentExecutionId,
                                'metadata' => [
                                    'qa_gap_filling' => true,
                                    'qa_iteration' => $newIteration,
                                    'original_execution_id' => $this->parentExecutionId,
                                ],
                            ]);

                            // Execute holistic research for gap filling
                            \App\Jobs\ExecuteHolisticResearchJob::dispatch(
                                executionId: $gapFillingExecution->id,
                                interactionId: $this->interactionId
                            );

                            Log::info('SynthesizeWorkflowJob: Gap-filling research dispatched', [
                                'gap_filling_execution_id' => $gapFillingExecution->id,
                                'parent_execution_id' => $this->parentExecutionId,
                                'iteration' => $newIteration,
                            ]);

                            // Do not complete the workflow yet - wait for gap-filling research
                            return;
                        }
                    }

                    // QA passed or max iterations reached - proceed with completion
                    Log::info('SynthesizeWorkflowJob: QA validation passed or max iterations reached', [
                        'batch_id' => $this->batchId,
                        'qa_status' => $qaResult->qaStatus,
                        'qa_iteration' => $parentExecution->metadata['qa_iteration'] ?? 0,
                    ]);
                } catch (\Exception $e) {
                    Log::error('SynthesizeWorkflowJob: QA validation failed', [
                        'batch_id' => $this->batchId,
                        'parent_execution_id' => $this->parentExecutionId,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    // Continue with synthesis completion even if QA fails
                    // QA errors should not block result delivery
                }
            }

            // Extract sources from final answer
            $sources = $this->extractSourceLinksFromText($finalAnswer);

            // Update parent execution with final result
            $metadata = array_merge($parentExecution->metadata ?? [], [
                'workflow_synthesis' => true,
                'batch_id' => $this->batchId,
                'successful_jobs' => count($successful),
                'failed_jobs' => count($failed),
                'total_jobs' => $this->totalJobs,
                'source_count' => $this->countSources($results),
            ]);

            // Include QA results in metadata if validation was performed
            if ($qaResult) {
                $metadata['qa_validation'] = [
                    'performed' => true,
                    'status' => $qaResult->qaStatus,
                    'overall_score' => $qaResult->overallScore,
                    'assessment' => $qaResult->assessment,
                    'critical_gaps_count' => count($qaResult->getCriticalGaps()),
                    'iteration' => $parentExecution->metadata['qa_iteration'] ?? 0,
                    'summary' => $qaResult->getSummary(),
                ];
            }

            $parentExecution->update([
                'status' => 'completed',
                'output' => $finalAnswer,
                'metadata' => $metadata,
                'completed_at' => now(),
            ]);

            // Update chat interaction directly with final answer
            // This ensures user gets result even if broadcast fails (e.g., payload too large)
            // CONCURRENCY: Use transaction with row lock to prevent race conditions
            if ($this->interactionId) {
                \Illuminate\Support\Facades\DB::transaction(function () use ($finalAnswer) {
                    $interaction = \App\Models\ChatInteraction::lockForUpdate()
                        ->find($this->interactionId);

                    if ($interaction) {
                        // Check if answer was already set by another job (race condition detection)
                        if ($interaction->answer && strlen($interaction->answer) > 0) {
                            Log::warning('SynthesizeWorkflowJob: ChatInteraction answer already set by another job', [
                                'interaction_id' => $this->interactionId,
                                'existing_answer_length' => strlen($interaction->answer),
                                'new_answer_length' => strlen($finalAnswer),
                                'batch_id' => $this->batchId,
                                'parent_execution_id' => $this->parentExecutionId,
                            ]);

                            // Don't overwrite existing answer - first job wins
                            return;
                        }

                        $interaction->update([
                            'answer' => $finalAnswer,
                        ]);

                        Log::info('SynthesizeWorkflowJob: Updated chat interaction with final answer', [
                            'interaction_id' => $this->interactionId,
                            'answer_length' => strlen($finalAnswer),
                        ]);
                    }
                });
            }

            Log::info('SynthesizeWorkflowJob: Synthesis completed successfully', [
                'batch_id' => $this->batchId,
                'parent_execution_id' => $this->parentExecutionId,
                'synthesis_execution_id' => $synthesisExecution->id,
                'final_answer_length' => strlen($finalAnswer),
            ]);

            // Execute final workflow actions (e.g., webhook delivery, email, logging)
            if (! empty($this->finalActions)) {
                $this->executeFinalActions($finalAnswer, $parentExecution, $synthesisExecution);
            }

            // Broadcast completion to frontend
            if ($this->interactionId) {
                $this->broadcastHolisticCompletion(
                    $this->interactionId,
                    $this->parentExecutionId,
                    $finalAnswer,
                    $metadata,
                    $sources
                );

                Log::info('SynthesizeWorkflowJob: Broadcasted workflow completion', [
                    'interaction_id' => $this->interactionId,
                    'parent_execution_id' => $this->parentExecutionId,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('SynthesizeWorkflowJob: Synthesis failed', [
                'batch_id' => $this->batchId,
                'parent_execution_id' => $this->parentExecutionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark parent execution as failed
            $parentExecution = AgentExecution::find($this->parentExecutionId);
            if ($parentExecution && ! $parentExecution->isFailed()) {
                $parentExecution->markAsFailed("Synthesis failed: {$e->getMessage()}");
            }

            // Broadcast failure to frontend
            if ($this->interactionId) {
                $this->broadcastHolisticFailure(
                    $this->interactionId,
                    $this->parentExecutionId,
                    "Synthesis failed: {$e->getMessage()}",
                    'synthesize'
                );

                Log::info('SynthesizeWorkflowJob: Broadcasted workflow failure', [
                    'interaction_id' => $this->interactionId,
                    'parent_execution_id' => $this->parentExecutionId,
                ]);
            }

            throw $e;
        } finally {
            // Cleanup Redis keys
            $resultStore->cleanup($this->batchId, $this->totalJobs);

            Log::debug('SynthesizeWorkflowJob: Cleaned up workflow results from Redis', [
                'batch_id' => $this->batchId,
            ]);

            // Clear status reporter context
            if (app()->has('status_reporter')) {
                app()->forgetInstance('status_reporter');
            }
        }
    }

    /**
     * Build synthesis prompt from collected workflow results.
     *
     * Creates structured input containing:
     * - Original user query
     * - Results from each agent (or error messages)
     * - Source counts per agent
     * - Synthesis instructions
     *
     * @param  array<int, array{agent_id?: int, agent_name?: string, execution_id?: int, input?: string, result?: string, sources?: array<string>, completed_at?: string, error?: bool, message?: string}>  $results  Collected agent results
     * @return string Formatted synthesis prompt
     */
    protected function buildSynthesisInput(array $results): string
    {
        $input = "ORIGINAL QUERY: {$this->originalQuery}\n\n";
        $input .= "WORKFLOW RESULTS:\n\n";

        foreach ($results as $index => $result) {
            if (isset($result['error'])) {
                $input .= 'Agent '.($index + 1).": FAILED\n";
                $input .= "Error: {$result['message']}\n\n";
            } else {
                $agentName = $result['agent_name'] ?? 'Unknown Agent';
                $findings = $result['result'] ?? 'No findings';
                $sourceCount = count($result['sources'] ?? []);

                $input .= 'Agent '.($index + 1).": {$agentName}\n";
                $input .= "Sources Found: {$sourceCount}\n";
                $input .= "Findings:\n{$findings}\n\n";
                $input .= "---\n\n";
            }
        }

        $input .= 'Please synthesize these results into a comprehensive response that addresses the original query. ';
        $input .= 'Combine insights from all agents, highlight key findings, and provide a cohesive answer.';

        return $input;
    }

    /**
     * Count total unique sources across all results
     */
    protected function countSources(array $results): int
    {
        $allSources = [];

        foreach ($results as $result) {
            if (isset($result['sources']) && is_array($result['sources'])) {
                $allSources = array_merge($allSources, $result['sources']);
            }
        }

        return count(array_unique($allSources));
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SynthesizeWorkflowJob: Job failed permanently', [
            'batch_id' => $this->batchId,
            'parent_execution_id' => $this->parentExecutionId,
            'error' => $exception->getMessage(),
        ]);

        // Mark parent execution as failed using trait method
        $this->safeMarkAsFailedById(
            $this->parentExecutionId,
            "Synthesis job failed: {$exception->getMessage()}",
            [
                'job_class' => self::class,
                'batch_id' => $this->batchId,
            ]
        );
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
            'type' => 'synthesis',
            'batch_id' => $this->batchId,
            'total_jobs' => $this->totalJobs,
            'parent_execution_id' => $this->parentExecutionId,
            'synthesizer_agent_id' => $this->synthesizerAgentId,
            'queue' => 'research-coordinator',
            'class' => static::class,
        ];
    }

    /**
     * Get the unique ID for the job
     */
    public function uniqueId(): string
    {
        return "synthesis-{$this->batchId}";
    }

    /**
     * Get synthesizer agent ID with caching to reduce database queries
     *
     * PERFORMANCE: Caches Research Synthesizer agent ID for 1 hour to avoid
     * repeated database queries on every synthesis job. Falls back to provided
     * synthesizerAgentId if available.
     *
     * @return int The agent ID to use for synthesis
     *
     * @throws \Exception If Research Synthesizer agent not found
     */
    protected function getSynthesizerAgentId(): int
    {
        // Priority 1: Use explicit synthesizer agent ID from WorkflowPlan
        if ($this->synthesizerAgentId) {
            return $this->synthesizerAgentId;
        }

        // Priority 2: Lookup and cache Research Synthesizer agent by name
        // Cache for 1 hour to reduce DB load (cleared when agent updated)
        return \Illuminate\Support\Facades\Cache::remember('agent:synthesizer:id', 3600, function () {
            $agent = \App\Models\Agent::where('name', 'Research Synthesizer')->first();

            if (! $agent) {
                throw new \Exception('Research Synthesizer agent not found and no synthesizerAgentId provided');
            }

            return $agent->id;
        });
    }

    /**
     * Execute final workflow actions
     *
     * Runs workflow-level actions once after synthesis completes successfully.
     * Actions receive the final synthesized answer as input data.
     * Use cases: webhook delivery, email notifications, cleanup, analytics
     *
     * @param  string  $finalAnswer  The synthesized final answer
     * @param  AgentExecution  $parentExecution  Parent execution for context
     * @param  AgentExecution  $synthesisExecution  Synthesis execution for context
     */
    protected function executeFinalActions(string $finalAnswer, $parentExecution, $synthesisExecution): void
    {
        $actions = collect($this->finalActions)
            ->sortBy('priority')
            ->values()
            ->toArray();

        Log::info('SynthesizeWorkflowJob: Executing final workflow actions', [
            'parent_execution_id' => $this->parentExecutionId,
            'actions_count' => count($actions),
        ]);

        // Emit status stream for final actions start
        if ($this->interactionId) {
            \App\Models\StatusStream::report(
                $this->interactionId,
                'final_actions',
                'Processing '.count($actions).' final workflow action(s)',
                ['step_type' => 'processing'],
                true,
                false,
                $synthesisExecution->id
            );
        }

        $context = [
            'execution' => $synthesisExecution,
            'parent_execution' => $parentExecution,
            'workflow_type' => 'final',
            'original_query' => $this->originalQuery,
            'batch_id' => $this->batchId,
            'total_jobs' => $this->totalJobs,
        ];

        $actionResults = [];
        $data = $finalAnswer;

        foreach ($actions as $actionConfig) {
            $actionName = $actionConfig->method;
            $startTime = microtime(true);
            $originalLength = strlen($data);

            try {
                // Emit status for individual action
                if ($this->interactionId) {
                    \App\Models\StatusStream::report(
                        $this->interactionId,
                        'final_action',
                        "Executing final action: {$actionName}",
                        ['step_type' => 'processing', 'action' => $actionName],
                        true,
                        false,
                        $synthesisExecution->id
                    );
                }

                $data = \App\Services\Agents\Actions\ActionRegistry::execute(
                    $actionName,
                    $data, // Pass transformed data through pipeline
                    $context,
                    $actionConfig->params
                );

                $duration = round((microtime(true) - $startTime) * 1000, 2);
                $newLength = strlen($data);

                $actionResults[] = [
                    'action' => $actionName,
                    'status' => 'success',
                    'duration_ms' => $duration,
                    'input_length' => $originalLength,
                    'output_length' => $newLength,
                    'params' => $actionConfig->params,
                    'executed_at' => now()->toIso8601String(),
                ];

                Log::info('SynthesizeWorkflowJob: Final action executed', [
                    'action' => $actionName,
                    'parent_execution_id' => $this->parentExecutionId,
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
                    'params' => $actionConfig->params,
                    'executed_at' => now()->toIso8601String(),
                ];

                Log::error('SynthesizeWorkflowJob: Final action failed', [
                    'action' => $actionName,
                    'parent_execution_id' => $this->parentExecutionId,
                    'error' => $e->getMessage(),
                ]);

                // Emit failure status
                if ($this->interactionId) {
                    \App\Models\StatusStream::report(
                        $this->interactionId,
                        'final_action_error',
                        "Final action {$actionName} failed: {$e->getMessage()}",
                        ['step_type' => 'error', 'action' => $actionName],
                        true,
                        false,
                        $synthesisExecution->id
                    );
                }

                // Continue with remaining actions even if one fails
                // Final action failures shouldn't block workflow completion
            }
        }

        // Store action results in parent execution metadata
        $parentMetadata = $parentExecution->metadata ?? [];
        $parentMetadata['final_actions_executed'] = $actionResults;
        $parentExecution->metadata = $parentMetadata;
        $parentExecution->save();

        // Update ChatInteraction answer if data was transformed
        if ($data !== $finalAnswer && $this->interactionId) {
            $interaction = \App\Models\ChatInteraction::find($this->interactionId);
            if ($interaction) {
                $interaction->update(['answer' => $data]);

                Log::info('SynthesizeWorkflowJob: Updated interaction with transformed final answer', [
                    'interaction_id' => $this->interactionId,
                    'original_length' => strlen($finalAnswer),
                    'transformed_length' => strlen($data),
                ]);
            }
        }

        // Emit completion status
        if ($this->interactionId) {
            $successCount = collect($actionResults)->where('status', 'success')->count();
            $totalCount = count($actionResults);

            \App\Models\StatusStream::report(
                $this->interactionId,
                'final_actions',
                "Completed {$successCount}/{$totalCount} final action(s) successfully",
                ['step_type' => 'success', 'success_count' => $successCount, 'total_count' => $totalCount],
                true,
                false,
                $synthesisExecution->id
            );
        }
    }
}
