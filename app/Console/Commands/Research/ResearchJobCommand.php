<?php

namespace App\Console\Commands\Research;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\ChatInteraction;
use App\Services\Agents\AgentExecutor;
use App\Services\Agents\HolisticResearchService;
use App\Services\Agents\ResearchPlan;
use App\Services\Agents\ToolRegistry;
use App\Services\StatusReporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Direct command execution for research workflows, bypassing serialization.
 *
 * Handles three execution modes:
 * - PLAN: Analyzes query complexity and creates research strategy
 * - EXECUTE: Runs individual research threads with specific sub-queries
 * - SYNTHESIZE: Combines thread results into final comprehensive answer
 *
 * This command spawns asynchronous sub-processes using nohup to avoid
 * serialization issues with complex objects. Results are coordinated via Redis.
 */
class ResearchJobCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'research:execute 
                            {execution_id : The ID of the agent execution}
                            {mode : The research mode (plan, execute, synthesize)}
                            {--interaction_id= : The related interaction ID}
                            {--thread_index= : The thread index for execute mode}
                            {--thread_query= : The thread query for execute mode}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute research workflow steps directly via command';

    // Mode constants
    public const MODE_PLAN = 'plan';

    public const MODE_EXECUTE = 'execute';

    public const MODE_SYNTHESIZE = 'synthesize';

    /**
     * Execute the console command.
     */
    public function handle(ToolRegistry $toolRegistry): int
    {
        $executionId = $this->argument('execution_id');
        $mode = $this->argument('mode');
        $interactionId = $this->option('interaction_id');
        $threadIndex = $this->option('thread_index');
        $threadQuery = $this->option('thread_query');

        Log::info('ResearchJobCommand: Starting command execution', [
            'mode' => $mode,
            'execution_id' => $executionId,
            'interaction_id' => $interactionId,
            'thread_index' => $threadIndex,
        ]);

        // Preserve existing StatusReporter instance if it has Livewire component configured
        if ($interactionId) {
            $existingStatusReporter = app()->has('status_reporter') ? app('status_reporter') : null;

            if (! $existingStatusReporter ||
                ! $existingStatusReporter instanceof \App\Services\StatusReporter ||
                ! $existingStatusReporter->hasLivewireComponent()) {
                // Only create new instance if none exists, is invalid, or lacks Livewire component
                // Pass execution ID when available for proper agent execution tracking
                $statusReporter = new StatusReporter($interactionId, $executionId);
                app()->instance('status_reporter', $statusReporter);
            } else {
                // Use existing StatusReporter with Livewire component
                // Update with execution ID if not already set
                if ($executionId && ! $existingStatusReporter->getAgentExecutionId()) {
                    $existingStatusReporter->setAgentExecutionId($executionId);
                }
                Log::info('ResearchJobCommand: Using existing StatusReporter', [
                    'interaction_id' => $interactionId,
                    'execution_id' => $executionId,
                ]);
            }
        }

        try {
            // Get execution model
            $execution = AgentExecution::findOrFail($executionId);

            // Check if execution was cancelled
            if ($execution->status === 'cancelled') {
                Log::info('ResearchJobCommand: Execution was cancelled, skipping', [
                    'execution_id' => $executionId,
                    'mode' => $mode,
                ]);

                return 0;
            }

            // Handle command based on mode
            switch ($mode) {
                case self::MODE_PLAN:
                    $this->handlePlanMode($execution, $toolRegistry);
                    break;

                case self::MODE_EXECUTE:
                    $this->handleExecuteMode($execution, $toolRegistry);
                    break;

                case self::MODE_SYNTHESIZE:
                    $this->handleSynthesizeMode($execution, $toolRegistry);
                    break;

                default:
                    throw new \InvalidArgumentException("Unknown research mode: {$mode}");
            }

            Log::info('ResearchJobCommand: Command completed successfully', [
                'execution_id' => $executionId,
                'mode' => $mode,
            ]);

            return 0;

        } catch (\Throwable $e) {
            Log::error('ResearchJobCommand: Command failed with exception', [
                'execution_id' => $executionId,
                'mode' => $mode,
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark execution as failed if not already marked
            try {
                $execution = AgentExecution::find($executionId);
                if ($execution && ! $execution->isFailed()) {
                    $execution->markAsFailed($e->getMessage());
                }

                // Update interaction with error message if available
                if ($interactionId) {
                    $interaction = ChatInteraction::find($interactionId);
                    if ($interaction) {
                        $interaction->update([
                            'answer' => $interaction->answer."\n\n❌ Research failed: ".$e->getMessage(),
                        ]);
                    }
                }

                // Broadcast failure if this is the main execution
                if ($mode !== self::MODE_EXECUTE || $threadIndex === null) {
                    $this->broadcastFailure($e->getMessage(), $interactionId, $executionId);
                }

            } catch (\Throwable $innerException) {
                Log::error('ResearchJobCommand: Error while handling failure', [
                    'execution_id' => $executionId,
                    'mode' => $mode,
                    'original_error' => $e->getMessage(),
                    'handling_error' => $innerException->getMessage(),
                ]);
            }

            $this->error('Command failed: '.$e->getMessage());

            return 1;
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
    protected function handlePlanMode(AgentExecution $execution, ToolRegistry $toolRegistry): void
    {
        $statusReporter = app('status_reporter');
        $statusReporter->report('research_planning_start', 'Analyzing query complexity and planning research approach...');

        // Get the interaction for the query text
        $interaction = ChatInteraction::findOrFail($this->option('interaction_id'));

        // Update execution state using new state management
        $execution->transitionTo(AgentExecution::STATE_PLANNING);

        // Create research planner agent
        $plannerAgent = Agent::where('name', 'Research Planner')->first();
        if (! $plannerAgent) {
            throw new \Exception('Research Planner agent not found');
        }

        // Create a temporary execution for the planner
        $plannerExecution = AgentExecution::create([
            'agent_id' => $plannerAgent->id,
            'user_id' => $execution->user_id,
            'parent_agent_execution_id' => $execution->id,
            'input' => $interaction->question,
            'status' => 'running',
            'state' => AgentExecution::STATE_PLANNING,
            'max_steps' => $plannerAgent->max_steps ?? 5,
            'metadata' => [
                'planning_start_time' => now()->toISOString(),
                'parent_execution_id' => $execution->id,
                'input_options' => [], // Initialize with empty array to avoid serialization issues
            ],
        ]);

        // Update StatusReporter to track the planner child execution BEFORE creating AgentExecutor
        $statusReporter->setAgentExecutionId($plannerExecution->id);

        // Execute planner agent to analyze query (will use StatusReporter with correct execution ID)
        $executor = app(AgentExecutor::class);

        $statusReporter->report('query_analysis_start', 'Analyzing query complexity...');

        try {
            // Execute planner agent with structured output for reliable parsing
            $holisticService = app(HolisticResearchService::class);

            // Prepare input with available agents for AI selection
            $plannerInput = $holisticService->prepareResearchPlannerInput($interaction);

            // Use structured output approach - no more regex parsing!
            try {
                $plan = $holisticService->executeResearchPlannerWithStructuredOutput($plannerAgent, $plannerInput, $execution->id);

                // Restore parent execution ID for coordination messages
                $statusReporter->setAgentExecutionId($execution->id);

                // Update the temporary execution record
                $plannerExecution->markAsCompleted('Research plan generated with structured output', [
                    'structured_output' => true,
                    'schema_used' => 'ResearchPlanSchema',
                ]);
            } catch (\Throwable $e) {
                Log::error('ResearchJobCommand: Error during structured research plan generation', [
                    'execution_id' => $execution->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Mark planner execution as failed
                $plannerExecution->markAsFailed($e->getMessage());

                // Create a simplified plan from scratch if structured output failed
                $plan = $this->createBackupResearchPlan('Structured output failed: '.$e->getMessage(), $interaction->question);
            }

            // Create a serializable version of research plan data
            $researchPlanData = [
                'execution_strategy' => $plan->executionStrategy,
                'sub_queries' => array_map(function ($query) {
                    return is_string($query) ? $query : (string) $query;
                }, $plan->subQueries),
                'synthesis_instructions' => $plan->synthesisInstructions,
                'estimated_duration_seconds' => $plan->estimatedDurationSeconds,
            ];

            // Define result summary for planner execution completion
            $planResult = "Research plan created: {$plan->executionStrategy} strategy with ".
                          count($plan->subQueries).' sub-queries';

            Log::info('ResearchJobCommand: Plan generation completed', [
                'execution_id' => $execution->id,
                'strategy' => $plan->executionStrategy,
                'sub_queries_count' => count($plan->subQueries),
                'estimated_duration_seconds' => $plan->estimatedDurationSeconds,
            ]);

            // Update planner execution with results
            $plannerExecution->markAsCompleted($planResult, [
                'planning_duration_ms' => $plannerExecution->created_at->diffInMilliseconds(now()),
                'execution_strategy' => $plan->executionStrategy,
                'thread_count' => count($plan->subQueries),
                'estimated_duration_seconds' => $plan->estimatedDurationSeconds,
            ]);

            // Store plan in parent execution metadata
            $execution->transitionTo(AgentExecution::STATE_PLANNED);
            $execution->update([
                'metadata' => array_merge($execution->metadata ?? [], [
                    'research_plan' => $researchPlanData,
                    'thread_count' => count($plan->subQueries),
                    'planning_duration_ms' => $plannerExecution->created_at->diffInMilliseconds(now()),
                ]),
            ]);

            // Update interaction with execution strategy
            $interaction->update([
                'metadata' => array_merge($interaction->metadata ?? [], [
                    'execution_strategy' => $plan->executionStrategy,
                    'research_threads' => count($plan->subQueries),
                    'estimated_duration' => $this->formatDurationEstimate($plan->estimatedDurationSeconds),
                ]),
            ]);

            $statusReporter->report('research_planning_complete',
                'Research plan created with '.count($plan->subQueries).' '.
                ($plan->executionStrategy === 'simple' ? 'step' : 'parallel threads'));

            // Execute research steps based on plan
            if ($plan->executionStrategy === 'simple') {
                // For simple queries, run a single execute command
                $this->runExecuteCommand($execution->id, $this->option('interaction_id'), 0, $plan->subQueries[0]);
            } else {
                // For standard/complex queries, execute multiple parallel commands
                $this->runExecuteBatch($execution, $plan, $this->option('interaction_id'));
            }

        } catch (\Exception $e) {
            Log::error('ResearchJobCommand: Planning failed', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark planner execution as failed
            $plannerExecution->markAsFailed("Planning failed: {$e->getMessage()}");

            // Mark parent execution as failed
            $execution->markAsFailed("Research planning failed: {$e->getMessage()}");

            // Update interaction with error
            $interaction->update([
                'answer' => "❌ Research failed: Unable to create a research plan. {$e->getMessage()}",
            ]);

            // Report failure
            $statusReporter->report('planning_failed', "Planning failed: {$e->getMessage()}");
            $this->broadcastFailure("Research planning failed: {$e->getMessage()}", $this->option('interaction_id'), $execution->id);

            throw $e;
        }
    }

    /**
     * Format duration estimate for human-readable display
     *
     * Converts seconds into user-friendly time estimates for UI display.
     *
     * @param  int  $durationSeconds  Estimated duration in seconds
     * @return string Human-readable duration (e.g., "about a minute", "5 minutes", "2 hours 30 minutes")
     */
    protected function formatDurationEstimate(int $durationSeconds): string
    {
        if ($durationSeconds < 60) {
            return 'less than a minute';
        } elseif ($durationSeconds < 120) {
            return 'about a minute';
        } elseif ($durationSeconds < 3600) {
            $minutes = round($durationSeconds / 60);

            return "{$minutes} minutes";
        } else {
            $hours = floor($durationSeconds / 3600);
            $minutes = round(($durationSeconds % 3600) / 60);

            return "{$hours} hour".($hours > 1 ? 's' : '').
                ($minutes > 0 ? " {$minutes} minute".($minutes > 1 ? 's' : '') : '');
        }
    }

    /**
     * Handle execute mode - run a single research thread
     */
    protected function handleExecuteMode(AgentExecution $execution, ToolRegistry $toolRegistry): void
    {
        $statusReporter = app('status_reporter');
        $interactionId = $this->option('interaction_id');
        $threadIndex = $this->option('thread_index');
        $threadQuery = $this->option('thread_query');

        // Validate we have the necessary thread data
        if ($threadIndex === null || $threadQuery === null) {
            throw new \InvalidArgumentException('Thread index and query are required for execute mode');
        }

        // Update execution state if it's not already executing
        if (! $execution->isInState(AgentExecution::STATE_EXECUTING)) {
            $execution->transitionTo(AgentExecution::STATE_EXECUTING);
        }

        // For the parent execution, add this thread to progress data
        $parentExecution = $execution->parent_agent_execution_id ?
            AgentExecution::find($execution->parent_agent_execution_id) :
            $execution;

        $statusReporter->report('research_thread_start',
            "Thread {$threadIndex}: Starting research on '{$threadQuery}'");

        try {
            // Get or create research agent
            $researchAgent = Agent::where('name', 'Research Assistant')->first();
            if (! $researchAgent) {
                throw new \Exception('Research Assistant agent not found');
            }

            // Create a temporary execution for this thread
            $threadExecution = AgentExecution::create([
                'agent_id' => $researchAgent->id,
                'user_id' => $execution->user_id,
                'parent_agent_execution_id' => $parentExecution->id, // Link to parent
                'input' => $threadQuery,
                'status' => 'running',
                'state' => AgentExecution::STATE_EXECUTING,
                'max_steps' => $researchAgent->max_steps ?? 30,
                'metadata' => [
                    'thread_index' => $threadIndex,
                    'parent_execution_id' => $parentExecution->id,
                ],
            ]);

            // Update StatusReporter to track this thread's child execution BEFORE creating AgentExecutor
            $statusReporter->setAgentExecutionId($threadExecution->id);

            // Create agent executor (will use the container-bound StatusReporter with correct execution ID)
            $executor = app(AgentExecutor::class);

            // Execute the research thread
            $result = $executor->executeSingleAgent($threadExecution);

            // Restore parent execution ID for coordination messages
            $statusReporter->setAgentExecutionId($parentExecution->id);

            // Count sources in the result
            $sourceCount = $this->extractSourceCount($result);

            // Store result in Redis for batch processing
            $resultData = [
                'sub_query' => $threadQuery,
                'findings' => $result,
                'source_count' => $sourceCount,
                'thread_index' => $threadIndex,
                'completion_time' => now()->toISOString(),
                'thread_execution_id' => $threadExecution->id,
            ];

            // Store in Redis with TTL (30 minutes)
            $resultKey = "research_thread_{$parentExecution->id}_{$threadIndex}";
            Redis::setex($resultKey, 1800, json_encode($resultData));

            $statusReporter->report('research_thread_complete',
                "Thread {$threadIndex}: Research complete with {$sourceCount} sources");

            // Update thread execution status
            $threadExecution->markAsCompleted($result, [
                'source_count' => $sourceCount,
                'completion_time' => now()->toISOString(),
            ]);

            // Check if this is the last thread
            $threadCount = $parentExecution->metadata['thread_count'] ?? 1;
            $completedThreads = 1; // Simplified - just assume we're done with this thread

            if ($completedThreads >= $threadCount) {
                // This is the last thread - run synthesis
                $this->runSynthesizeCommand($parentExecution->id, $interactionId);
            }

        } catch (\Throwable $e) {
            Log::error('ResearchJobCommand: Thread execution failed', [
                'execution_id' => $execution->id,
                'thread_index' => $threadIndex,
                'thread_query' => $threadQuery,
                'error' => $e->getMessage(),
            ]);

            // Store error result in Redis
            $resultData = [
                'sub_query' => $threadQuery,
                'findings' => "Research thread failed: {$e->getMessage()}",
                'source_count' => 0,
                'thread_index' => $threadIndex,
                'error' => true,
            ];

            $resultKey = "research_thread_{$execution->id}_{$threadIndex}";
            Redis::setex($resultKey, 1800, json_encode($resultData));

            $statusReporter->report('research_thread_failed',
                "Thread {$threadIndex}: Research failed: {$e->getMessage()}");

            // Re-throw to mark the command as failed
            throw $e;
        }
    }

    /**
     * Handle synthesize mode - combine results and create final answer
     */
    protected function handleSynthesizeMode(AgentExecution $execution, ToolRegistry $toolRegistry): void
    {
        $statusReporter = app('status_reporter');
        $interactionId = $this->option('interaction_id');

        $statusReporter->report('research_synthesis_start', 'Synthesizing research findings...');

        // Update execution state using new state management
        $execution->transitionTo(AgentExecution::STATE_SYNTHESIZING);

        try {
            // Get the interaction for updating with the final answer
            $interaction = ChatInteraction::findOrFail($interactionId);

            // Collect all thread results from Redis
            $threadCount = $execution->metadata['thread_count'] ?? 1;
            $results = [];
            $threadExecutionIds = [];

            for ($i = 0; $i < $threadCount; $i++) {
                $resultKey = "research_thread_{$execution->id}_{$i}";
                if ($cached = Redis::get($resultKey)) {
                    $resultData = json_decode($cached, true);
                    $results[$i] = $resultData;

                    // Track thread execution ID if available
                    if (isset($resultData['thread_execution_id'])) {
                        $threadExecutionIds[] = $resultData['thread_execution_id'];
                    }

                    Redis::del($resultKey); // Cleanup
                } else {
                    // Handle missing results
                    $results[$i] = [
                        'sub_query' => "Thread {$i}",
                        'findings' => 'Research thread did not complete or results were not found.',
                        'source_count' => 0,
                        'thread_index' => $i,
                        'error' => true,
                    ];

                    Log::warning('ResearchJobCommand: Missing thread result during synthesis', [
                        'execution_id' => $execution->id,
                        'thread_index' => $i,
                    ]);
                }
            }

            // Get original query and research plan
            $query = $interaction->question;
            $plan = $execution->metadata['research_plan'] ?? null;

            if (! $plan) {
                throw new \Exception('Research plan not found in execution metadata');
            }

            // Prepare synthesis input
            $holisticService = app(HolisticResearchService::class);
            $synthesisInput = $holisticService->prepareSynthesisInput(
                new ResearchPlan(
                    $query,
                    $plan['execution_strategy'],
                    $plan['sub_queries'],
                    $plan['synthesis_instructions'],
                    $plan['estimated_duration_seconds']
                ),
                $results
            );

            // Get or create synthesis agent
            $synthesisAgent = Agent::where('name', 'Research Synthesizer')->first() ??
                            Agent::where('name', 'Synthesis Agent')->first();

            if (! $synthesisAgent) {
                throw new \Exception('Synthesis Agent not found');
            }

            $statusReporter->report('synthesis_agent_start', 'Creating comprehensive research report from findings...');

            // Create a temporary execution for synthesis
            $synthesisExecution = AgentExecution::create([
                'agent_id' => $synthesisAgent->id,
                'user_id' => $execution->user_id,
                'parent_agent_execution_id' => $execution->id,
                'input' => $synthesisInput,
                'status' => 'running',
                'state' => AgentExecution::STATE_SYNTHESIZING,
                'max_steps' => $synthesisAgent->max_steps ?? 15,
                'metadata' => [
                    'thread_results' => $threadCount,
                    'thread_execution_ids' => $threadExecutionIds,
                    'synthesis_start_time' => now()->toISOString(),
                    'parent_execution_id' => $execution->id,
                ],
            ]);

            // Update StatusReporter to track the synthesis child execution BEFORE creating AgentExecutor
            $statusReporter->setAgentExecutionId($synthesisExecution->id);

            // Execute synthesis agent (will use StatusReporter with correct execution ID)
            $executor = app(AgentExecutor::class);

            // Execute the synthesis
            $finalAnswer = $executor->executeSingleAgent($synthesisExecution);

            // Restore parent execution ID for coordination messages
            $statusReporter->setAgentExecutionId($execution->id);

            // Calculate stats
            $totalSources = array_sum(array_column($results, 'source_count'));
            $completedThreads = count(array_filter($results, fn ($r) => ! isset($r['error'])));
            $durationSeconds = now()->diffInSeconds($execution->created_at);

            // Mark synthesis execution as completed
            $synthesisExecution->markAsCompleted($finalAnswer, [
                'total_sources' => $totalSources,
                'synthesis_duration_ms' => $synthesisExecution->created_at->diffInMilliseconds(now()),
                'thread_count' => $threadCount,
                'completed_threads' => $completedThreads,
            ]);

            // Extract source links from the final answer
            $sourceLinks = $this->extractSourceLinks($finalAnswer);

            // Update execution with final result
            $execution->transitionTo(AgentExecution::STATE_COMPLETED);
            $execution->update([
                'output' => $finalAnswer,
                'completed_at' => now(),
                'metadata' => array_merge($execution->metadata ?? [], [
                    'total_sources' => $totalSources,
                    'thread_count' => $threadCount,
                    'completed_threads' => $completedThreads,
                    'duration_seconds' => $durationSeconds,
                    'synthesis_duration_ms' => $synthesisExecution->created_at->diffInMilliseconds(now()),
                    'source_links' => $sourceLinks,
                ]),
            ]);

            // Update interaction with final results
            $interaction->update([
                'answer' => $finalAnswer,
                'metadata' => array_merge($interaction->metadata ?? [], [
                    'execution_strategy' => $plan['execution_strategy'],
                    'research_threads' => $threadCount,
                    'total_sources' => $totalSources,
                    'duration_seconds' => $durationSeconds,
                    'synthesis_duration_ms' => $synthesisExecution->created_at->diffInMilliseconds(now()),
                    'holistic_research' => true,
                ]),
            ]);

            // Dispatch event for side effect listeners (Phase 3: side effects via events only)
            // Listener: TrackResearchUrls
            \App\Events\ResearchWorkflowCompleted::dispatch(
                $interaction,
                $finalAnswer,
                [
                    'research_threads_completed' => $completedThreads,
                    'total_sources' => $totalSources,
                ],
                'research_command'
            );

            $statusReporter->report('research_synthesis_complete',
                "Research complete! Found {$totalSources} sources across {$completedThreads}/{$threadCount} research threads.");

            // Broadcast completion event
            $this->broadcastCompletion($finalAnswer, [
                'total_sources' => $totalSources,
                'thread_count' => $threadCount,
                'completed_threads' => $completedThreads,
                'duration_seconds' => $durationSeconds,
                'source_links' => $sourceLinks,
            ], $interactionId, $execution->id);

        } catch (\Exception $e) {
            Log::error('ResearchJobCommand: Synthesis failed', [
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark execution as failed
            $execution->markAsFailed("Research synthesis failed: {$e->getMessage()}");

            // Update interaction with error
            $interaction = ChatInteraction::find($interactionId);
            if ($interaction) {
                $interaction->update([
                    'answer' => "❌ Research failed during synthesis: {$e->getMessage()}",
                ]);
            }

            // Report failure
            $statusReporter->report('synthesis_failed', "Synthesis failed: {$e->getMessage()}");
            $this->broadcastFailure("Research synthesis failed: {$e->getMessage()}", $interactionId, $execution->id);

            throw $e;
        }
    }

    /**
     * Launch asynchronous research thread execution command
     *
     * Spawns a background process using nohup to execute a single research thread.
     * This avoids serialization issues with complex objects by passing IDs instead.
     *
     * @param  int  $executionId  Parent agent execution ID
     * @param  int  $interactionId  Chat interaction ID for result linking
     * @param  int  $threadIndex  Index of this thread (0-based)
     * @param  string  $query  Research query for this thread
     */
    protected function runExecuteCommand(int $executionId, int $interactionId, int $threadIndex, string $query): void
    {
        // Run the command asynchronously using nohup
        $command = sprintf(
            'nohup php %s/artisan research:execute %d execute --interaction_id=%d --thread_index=%d --thread_query="%s" > /dev/null 2>&1 &',
            base_path(),
            $executionId,
            $interactionId,
            $threadIndex,
            addslashes($query)
        );

        exec($command);

        Log::info('ResearchJobCommand: Launched execute command', [
            'execution_id' => $executionId,
            'thread_index' => $threadIndex,
            'query' => $query,
        ]);
    }

    /**
     * Run multiple execute commands for batch processing
     */
    protected function runExecuteBatch(AgentExecution $execution, ResearchPlan $plan, int $interactionId): void
    {
        // Update execution state to reflect that we're batching threads
        $execution->transitionTo(AgentExecution::STATE_EXECUTING);

        foreach ($plan->subQueries as $index => $subQuery) {
            $this->runExecuteCommand($execution->id, $interactionId, $index, $subQuery);
        }

        Log::info('ResearchJobCommand: Dispatched execute batch', [
            'execution_id' => $execution->id,
            'thread_count' => count($plan->subQueries),
        ]);
    }

    /**
     * Run a synthesis command
     */
    protected function runSynthesizeCommand(int $executionId, int $interactionId): void
    {
        // Run the command asynchronously using nohup
        $command = sprintf(
            'nohup php %s/artisan research:execute %d synthesize --interaction_id=%d > /dev/null 2>&1 &',
            base_path(),
            $executionId,
            $interactionId
        );

        exec($command);

        Log::info('ResearchJobCommand: Launched synthesize command', [
            'execution_id' => $executionId,
        ]);

        // Report via StatusReporter if available
        if (app()->has('status_reporter')) {
            $statusReporter = app('status_reporter');
            $statusReporter->report('synthesis_queued', 'All research threads completed. Synthesizing results...');
        }
    }

    /**
     * Extract source count from research result text
     */
    protected function extractSourceCount(string $result): int
    {
        // Count markdown links [text](url) as sources
        $linkCount = preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $result, $matches);

        return $linkCount ?: 0;
    }

    /**
     * Extract markdown source links from research result text.
     *
     * @param  string  $result  Research result containing markdown links
     * @return array{text: string, url: string}[] Array of source link objects
     */
    protected function extractSourceLinks(string $result): array
    {
        $links = [];
        preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $result, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $text = $match[1];
            $url = $match[2];

            // Basic URL validation
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                $links[] = [
                    'text' => $text,
                    'url' => $url,
                ];
            }
        }

        return $links;
    }

    /**
     * Generate title for session if needed (first interaction with answer)
     */
    protected function generateTitleIfNeeded(ChatInteraction $interaction): void
    {
        $session = $interaction->session;
        if (! $session || $session->title) {
            return; // Session already has a title
        }

        // Check if this is the first interaction with an answer
        $firstInteraction = $session->interactions()->orderBy('created_at')->first();
        if ($firstInteraction && $firstInteraction->id === $interaction->id && $interaction->answer) {
            $this->generateTitle($session->id, $interaction->question, $interaction->answer);
        }
    }

    /**
     * Generate AI-powered title for chat session
     *
     * Uses TitleGenerator service to create a concise, descriptive title
     * based on the first question-answer pair.
     *
     * @param  int  $sessionId  Chat session ID
     * @param  string  $question  User's question
     * @param  string  $answer  Agent's answer
     */
    protected function generateTitle($sessionId, $question, $answer): void
    {
        try {
            $session = \App\Models\ChatSession::find($sessionId);
            if (! $session) {
                return;
            }

            // Use the centralized TitleGenerator service
            $titleGenerator = new \App\Services\TitleGenerator;
            $title = $titleGenerator->generateFromContent($question, $answer);

            if ($title) {
                $session->update(['title' => $title]);
                Log::info('ResearchJobCommand: Generated title using TitleGenerator', [
                    'session_id' => $sessionId,
                    'title' => $title,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('ResearchJobCommand: Failed to generate title using TitleGenerator', [
                'session_id' => $sessionId,
                'error' => $e->getMessage(),
            ]);

            // Fallback: use first question
            $title = \Illuminate\Support\Str::words($question, 5, '');
            if ($title) {
                $session = \App\Models\ChatSession::find($sessionId);
                if ($session) {
                    $session->update(['title' => trim($title)]);
                }
            }
        }
    }

    /**
     * Broadcast successful completion to UI
     */
    protected function broadcastCompletion(string $result, array $metadata, int $interactionId, int $executionId): void
    {
        Log::info('ResearchJobCommand: Broadcasting completion event', [
            'interaction_id' => $interactionId,
            'execution_id' => $executionId,
        ]);

        try {
            // Get execution steps to include in broadcast
            $execution = AgentExecution::find($executionId);
            $steps = [];
            if ($execution && isset($execution->metadata['execution_steps'])) {
                $steps = $execution->metadata['execution_steps'];
            }

            // Broadcast completion event for Livewire to catch
            \Illuminate\Support\Facades\Broadcast::event('holistic-workflow-completed', [
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
                'result' => $result,
                'metadata' => $metadata,
                'sources' => $metadata['source_links'] ?? [],
                'steps' => $steps,
            ]);
        } catch (\Throwable $e) {
            Log::error('ResearchJobCommand: Failed to broadcast completion event', [
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Broadcast failure to UI
     */
    protected function broadcastFailure(string $error, int $interactionId, int $executionId): void
    {
        Log::info('ResearchJobCommand: Broadcasting failure event', [
            'interaction_id' => $interactionId,
            'execution_id' => $executionId,
            'error' => $error,
        ]);

        try {
            // Broadcast failure event for Livewire to catch
            \Illuminate\Support\Facades\Broadcast::event('holistic-workflow-failed', [
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
                'error' => $error,
            ]);
        } catch (\Throwable $e) {
            Log::error('ResearchJobCommand: Failed to broadcast failure event', [
                'interaction_id' => $interactionId,
                'execution_id' => $executionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create backup research plan when AI generation fails.
     *
     * Attempts to extract complexity and sub-queries from failed plan text,
     * falling back to single-query simple plan if parsing fails.
     *
     * @param  string  $planResult  The failed plan result text
     * @param  string  $query  Original user query
     * @return ResearchPlan Fallback plan with extracted or default values
     */
    protected function createBackupResearchPlan(string $planResult, string $query): ResearchPlan
    {
        // Try to determine complexity from the plan result text
        $complexity = 'standard';
        if (stripos($planResult, 'complex') !== false || stripos($planResult, 'multiple') !== false) {
            $complexity = 'complex';
        } elseif (stripos($planResult, 'simple') !== false || stripos($planResult, 'straightforward') !== false) {
            $complexity = 'simple';
        }

        // Try to extract sub-queries or create a single one from the original query
        $subQueries = [$query];
        if (preg_match_all('/(?:sub-question|sub-query|research question)s?:?\\s*\\n?(.*?)(?=\\n\\n|$)/is', $planResult, $matches)) {
            $extractedQueries = [];
            foreach ($matches[1] as $match) {
                // Split by numbered lists, bullet points or new lines
                if (preg_match_all('/(?:\\d+\\.\\s*|\\*\\s*|\\n)([^\\n\\d\\*]+)/i', $match, $subMatches)) {
                    foreach ($subMatches[1] as $subMatch) {
                        $trimmed = trim($subMatch);
                        if (! empty($trimmed)) {
                            $extractedQueries[] = $trimmed;
                        }
                    }
                } else {
                    $trimmed = trim($match);
                    if (! empty($trimmed)) {
                        $extractedQueries[] = $trimmed;
                    }
                }
            }

            if (count($extractedQueries) > 0) {
                $subQueries = array_slice($extractedQueries, 0, min(count($extractedQueries), 5)); // Limit to 5 sub-queries
            }
        }

        // Create synthesis instructions
        $synthesisInstructions = "Synthesize the research findings into a comprehensive response that addresses the original query: '{$query}'";

        // Estimate duration based on complexity and number of sub-queries
        $estimatedDurationSeconds = 90; // Default to standard
        if ($complexity === 'simple') {
            $estimatedDurationSeconds = 30;
        } elseif ($complexity === 'complex') {
            $estimatedDurationSeconds = 180;
        }

        Log::info('ResearchJobCommand: Created backup research plan due to parsing failure', [
            'complexity' => $complexity,
            'sub_query_count' => count($subQueries),
            'estimated_duration' => $estimatedDurationSeconds,
        ]);

        return new ResearchPlan(
            $query,
            $complexity,
            $subQueries,
            $synthesisInstructions,
            $estimatedDurationSeconds
        );
    }
}
