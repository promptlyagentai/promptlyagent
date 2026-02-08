<?php

namespace App\Services\Research;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Models\ChatInteraction;
use App\Services\Agents\AgentExecutor;
use App\Services\Agents\HolisticResearchService;
use App\Services\Agents\ResearchPlan;
use App\Services\Agents\ToolRegistry;
use App\Services\Agents\WorkflowPlan;
use App\Services\UrlExtractorService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Research Service - Multi-Phase Research Workflow Orchestration.
 *
 * Orchestrates comprehensive research workflows with three distinct phases:
 * plan generation, parallel thread execution, and result synthesis. Handles
 * both simple direct research and complex multi-threaded research scenarios.
 *
 * Research Workflow Phases:
 * 1. **Plan**: Generate research plan using HolisticResearchService
 * 2. **Execute**: Run parallel research threads via ParallelResearchCoordinator
 * 3. **Synthesize**: Combine findings into cohesive response
 *
 * State Machine Integration:
 * - Stores intermediate state in Redis for resumability
 * - Handles failures gracefully with error state tracking
 * - Supports workflow continuation after interruption
 *
 * Attachment Processing:
 * - Extracts URLs from ChatInteraction attachments
 * - Processes file uploads, URL lists, and knowledge documents
 * - Provides rich context for research agents
 *
 * Result Synthesis:
 * - Uses specialized Synthesis Agent for combining thread results
 * - Includes planning context and thread findings
 * - Generates comprehensive, well-structured responses
 *
 * @see \App\Services\Agents\HolisticResearchService
 * @see \App\Services\Agents\ParallelResearchCoordinator
 * @see \App\Jobs\ResearchThreadJob
 */
class ResearchService
{
    /**
     * Handle planning mode - analyze query and create research plan
     */
    public function handlePlanMode(AgentExecution $execution, ToolRegistry $toolRegistry, int $interactionId): ResearchPlan|WorkflowPlan
    {
        $statusReporter = app('status_reporter');
        $statusReporter->report('research_planning_start', 'Analyzing query complexity and planning research approach...');

        // Store user context in container for tools to access
        app()->instance('current_user_id', $execution->user_id);

        // Get the interaction for the query text
        $interaction = ChatInteraction::findOrFail($interactionId);

        // Update execution state using new state management
        $execution->transitionTo(AgentExecution::STATE_PLANNING);

        // Create research planner agent
        $plannerAgent = Agent::where('name', 'Research Planner')->first();
        if (! $plannerAgent) {
            throw new \Exception('Research Planner agent not found');
        }

        // Execute planner agent to analyze query
        $executor = app(AgentExecutor::class);

        // Create a temporary execution for the planner with proper input
        $holisticService = app(HolisticResearchService::class);
        $plannerInput = $holisticService->prepareResearchPlannerInput($interaction);

        $plannerExecution = AgentExecution::create([
            'agent_id' => $plannerAgent->id,
            'user_id' => $execution->user_id,
            'parent_agent_execution_id' => $execution->id,
            'input' => $plannerInput, // Use the properly formatted input with available agents
            'status' => 'running',
            'state' => AgentExecution::STATE_PLANNING,
            'max_steps' => $plannerAgent->max_steps ?? 5,
            'metadata' => [
                'planning_start_time' => now()->toISOString(),
                'parent_execution_id' => $execution->id,
                'input_options' => [], // Initialize with empty array to avoid serialization issues
            ],
        ]);

        $statusReporter->report('query_analysis_start', 'Analyzing query complexity...');

        try {
            // Use structured output approach with existing execution - no more duplicates!
            try {
                $plan = $holisticService->executeResearchPlannerWithStructuredOutput($plannerAgent, $plannerInput, $execution->id, $plannerExecution);

                // The execution is already marked as completed by executeResearchPlannerWithStructuredOutput
            } catch (\Throwable $e) {
                Log::error('ResearchService: Error during structured research plan generation', [
                    'execution_id' => $execution->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Mark planner execution as failed
                $plannerExecution->markAsFailed($e->getMessage());

                // Create a simplified plan from scratch if structured output failed
                $plan = $this->createBackupResearchPlan('Structured output failed: '.$e->getMessage(), $interaction->question);
            }

            // Check if we got a WorkflowPlan or ResearchPlan and handle accordingly
            if ($plan instanceof WorkflowPlan) {
                // NEW SYSTEM: WorkflowPlan - convert to metadata format
                $researchPlanData = [
                    'type' => 'workflow_plan',
                    'strategy_type' => $plan->strategyType,
                    'stages' => array_map(function ($stage) {
                        return [
                            'type' => $stage->type,
                            'nodes' => array_map(function ($node) {
                                return [
                                    'agent_id' => $node->agentId,
                                    'agent_name' => $node->agentName,
                                    'input' => $node->input,
                                    'rationale' => $node->rationale,
                                ];
                            }, $stage->nodes),
                        ];
                    }, $plan->stages),
                    'original_query' => $plan->originalQuery,
                    'synthesizer_agent_id' => $plan->synthesizerAgentId,
                    'estimated_duration_seconds' => $plan->estimatedDurationSeconds,
                ];

                // Store workflow plan in parent execution metadata
                $execution->transitionTo(AgentExecution::STATE_PLANNED);
                $execution->update([
                    'metadata' => array_merge($execution->metadata ?? [], [
                        'workflow_plan' => $researchPlanData,
                        'thread_count' => $plan->getTotalJobs(),
                        'planning_duration_ms' => $plannerExecution->created_at->diffInMilliseconds(now()),
                    ]),
                ]);

                // Update interaction with execution strategy
                $interaction->update([
                    'metadata' => array_merge($interaction->metadata ?? [], [
                        'execution_strategy' => $plan->strategyType,
                        'research_threads' => $plan->getTotalJobs(),
                        'estimated_duration' => $this->formatDurationEstimate($plan->estimatedDurationSeconds),
                    ]),
                ]);

                // Report workflow plan details
                $statusReporter->report('workflow_plan_created',
                    "Strategy: {$plan->strategyType} | Stages: ".count($plan->stages).' | Total jobs: '.$plan->getTotalJobs());

            } else {
                // OLD SYSTEM: ResearchPlan - handle as before
                $researchPlanData = [
                    'execution_strategy' => $plan->executionStrategy,
                    'sub_queries' => array_map(function ($query) {
                        return is_string($query) ? $query : (string) $query;
                    }, $plan->subQueries),
                    'synthesis_instructions' => $plan->synthesisInstructions,
                    'estimated_duration_seconds' => $plan->estimatedDurationSeconds,
                ];

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

                // Provide detailed human-readable update about the research plan
                $this->reportResearchPlanDetails($statusReporter, $plan);
            }

            // Return the plan for further processing by the job
            return $plan;
        } catch (\Exception $e) {
            Log::error('ResearchService: Planning failed', [
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

            // Create a minimal error plan instead of throwing
            return new ResearchPlan(
                $interaction->question,
                'error',
                [],
                "Research planning failed: {$e->getMessage()}",
                0
            );
        }
    }

    /**
     * Report detailed human-readable information about the research plan
     */
    private function reportResearchPlanDetails($statusReporter, ResearchPlan $plan): void
    {
        // Main plan summary
        $strategyLabel = match ($plan->executionStrategy) {
            'simple' => 'Simple Sequential Research',
            'standard' => 'Standard Parallel Research',
            'complex' => 'Complex Multi-Threaded Research',
            default => ucfirst($plan->executionStrategy).' Research'
        };

        $threadCount = count($plan->subQueries);
        $durationEstimate = $this->formatDurationEstimate($plan->estimatedDurationSeconds);

        $statusReporter->report('research_plan_summary',
            "📋 Research Plan Created: {$strategyLabel} with {$threadCount} ".
            ($threadCount === 1 ? 'research focus' : 'parallel research threads').
            " (est. {$durationEstimate})"
        );

        // Report the specific research questions with agent assignments
        if (! empty($plan->subQueries)) {
            $statusReporter->report('research_questions',
                '🔍 Research Focus Areas:'
            );

            foreach ($plan->subQueries as $index => $query) {
                $threadNumber = $index + 1;

                // Get agent assignment for this query
                $agentAssignment = $plan->getAgentForQuery($index);
                $agentName = $agentAssignment ? $agentAssignment['agent_name'] : 'Research Assistant';

                $statusReporter->report("research_thread_{$threadNumber}",
                    "   {$threadNumber}. {$query} [{$agentName}]"
                );
            }
        }

        // Report synthesis approach
        if (! empty($plan->synthesisInstructions)) {
            $synthesisPreview = strlen($plan->synthesisInstructions) > 100
                ? substr($plan->synthesisInstructions, 0, 100).'...'
                : $plan->synthesisInstructions;

            $statusReporter->report('research_synthesis_plan',
                "🧠 Synthesis Approach: {$synthesisPreview}"
            );
        }

        // Report execution timeline
        $statusReporter->report('research_execution_start',
            "⚡ Starting {$strategyLabel} - {$threadCount} ".
            ($plan->executionStrategy === 'simple' ? 'sequential research step' : 'research agents working in parallel')
        );
    }

    /**
     * Handle execute mode - run a single research thread
     */
    public function handleExecuteMode(AgentExecution $execution, ToolRegistry $toolRegistry, int $interactionId, int $threadIndex, string $threadQuery): array
    {
        $statusReporter = app('status_reporter');

        // Store user context in container for tools to access
        app()->instance('current_user_id', $execution->user_id);

        // Update execution state if it's not already executing
        if (! $execution->isInState(AgentExecution::STATE_EXECUTING)) {
            $execution->transitionTo(AgentExecution::STATE_EXECUTING);
        }

        // For the parent execution, add this thread to progress data
        $parentExecution = $execution->parent_agent_execution_id ?
            AgentExecution::find($execution->parent_agent_execution_id) :
            $execution;

        if ($parentExecution && $parentExecution->id !== $execution->id) {
        }

        $statusReporter->report('research_thread_start',
            "Thread {$threadIndex}: Starting research on '{$threadQuery}'");

        try {
            // Get or create research agent
            $researchAgent = Agent::where('name', 'Research Assistant')->first();
            if (! $researchAgent) {
                throw new \Exception('Research Assistant agent not found');
            }

            // Create agent executor
            $executor = app(AgentExecutor::class);

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

            // Link child execution to original ChatInteraction for attachment access
            if ($parentExecution->chatInteraction) {
                $threadExecution->setRelation('chatInteraction', $parentExecution->chatInteraction);

                Log::info('ResearchService: Linked Research Assistant thread to ChatInteraction', [
                    'thread_execution_id' => $threadExecution->id,
                    'thread_index' => $threadIndex,
                    'parent_execution_id' => $parentExecution->id,
                    'interaction_id' => $parentExecution->chatInteraction->id,
                    'attachments_count' => $parentExecution->chatInteraction->attachments ? $parentExecution->chatInteraction->attachments->count() : 0,
                ]);
            }

            // Execute the research thread
            $result = $executor->executeSingleAgent($threadExecution);

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
            Redis::setex($resultKey, 86400, json_encode($resultData));

            $statusReporter->report('research_thread_complete',
                "Thread {$threadIndex}: Research complete with {$sourceCount} sources");

            // Update thread execution status
            $threadExecution->markAsCompleted($result, [
                'source_count' => $sourceCount,
                'completion_time' => now()->toISOString(),
            ]);

            // Update parent execution's progress data
            if ($parentExecution && $parentExecution->id !== $execution->id) {
            }

            // Return the result data
            return [
                'resultData' => $resultData,
                'sourceCount' => $sourceCount,
                'threadExecution' => $threadExecution,
            ];

        } catch (\Throwable $e) {
            Log::error('ResearchService: Thread execution failed', [
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
            Redis::setex($resultKey, 86400, json_encode($resultData));

            $statusReporter->report('research_thread_failed',
                "Thread {$threadIndex}: Research failed: {$e->getMessage()}");

            // Return error result instead of throwing
            return [
                'error' => true,
                'message' => "Research thread failed: {$e->getMessage()}",
                'resultData' => $resultData,
            ];
        }
    }

    /**
     * Handle synthesize mode - combine results and create final answer
     */
    public function handleSynthesizeMode(AgentExecution $execution, ToolRegistry $toolRegistry, int $interactionId): array
    {
        $statusReporter = app('status_reporter');

        // Store user context in container for tools to access
        app()->instance('current_user_id', $execution->user_id);

        $statusReporter->report('research_synthesis_start', 'Synthesizing research findings...');

        // Update execution state using new state management only if not already in a terminal state
        // This prevents the "Cannot transition from completed to synthesizing" error
        try {
            if (! $execution->isTerminalState()) {
                $execution->transitionTo(AgentExecution::STATE_SYNTHESIZING);
            } else {
                // Already in terminal state (completed/failed/cancelled), log this situation but continue
                Log::info('ResearchService: Execution already in terminal state, skipping transition to synthesizing', [
                    'execution_id' => $execution->id,
                    'current_state' => $execution->state,
                ]);
            }
        } catch (\Exception $e) {
            // Log but continue processing - don't let state transition issues block synthesis
            Log::warning('ResearchService: State transition error but continuing with synthesis', [
                'execution_id' => $execution->id,
                'current_state' => $execution->state,
                'error' => $e->getMessage(),
            ]);
            // We'll continue processing despite the transition error
        }

        try {
            // Get the interaction for updating with the final answer
            $interaction = ChatInteraction::findOrFail($interactionId);

            // Collect all thread results from Redis
            $threadCount = $execution->metadata['thread_count'] ?? 1;
            $results = [];
            $threadExecutionIds = [];
            $allSourceLinks = [];
            $missingThreadCount = 0;

            for ($i = 0; $i < $threadCount; $i++) {
                $resultKey = "research_thread_{$execution->id}_{$i}";
                if ($cached = Redis::get($resultKey)) {
                    $resultData = json_decode($cached, true);
                    $results[$i] = $resultData;

                    // Extract source links from thread findings
                    if (isset($resultData['findings']) && ! empty($resultData['findings'])) {
                        $threadSourceLinks = $this->extractSourceLinks($resultData['findings']);
                        if (! empty($threadSourceLinks)) {
                            // Store source links with thread index for traceability
                            foreach ($threadSourceLinks as $url) {
                                $allSourceLinks[] = [
                                    'url' => $url,
                                    'thread_index' => $i,
                                    'sub_query' => $resultData['sub_query'] ?? "Thread {$i}",
                                ];
                            }
                        }
                    }

                    // Track thread execution ID if available
                    if (isset($resultData['thread_execution_id'])) {
                        $threadExecutionIds[] = $resultData['thread_execution_id'];
                    }

                    // Don't delete from Redis immediately - keep for potential recovery
                    // Instead, update TTL to clean up later
                    Redis::expire($resultKey, 3600); // 1 hour TTL after synthesis
                } else {
                    $missingThreadCount++;
                    // Handle missing results
                    $results[$i] = [
                        'sub_query' => "Thread {$i}",
                        'findings' => 'Research thread did not complete or results were not found.',
                        'source_count' => 0,
                        'thread_index' => $i,
                        'error' => true,
                        'missing' => true,
                    ];

                    Log::warning('ResearchService: Missing thread result during synthesis', [
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
            $synthesisInput = $this->prepareEnhancedSynthesisInput(
                $query,
                new ResearchPlan(
                    $query,
                    $plan['execution_strategy'],
                    $plan['sub_queries'],
                    $plan['synthesis_instructions'],
                    $plan['estimated_duration_seconds']
                ),
                $results,
                $interactionId
            );

            // Get or create synthesis agent
            $synthesisAgent = Agent::where('name', 'Research Synthesizer')->first();

            if (! $synthesisAgent) {
                throw new \Exception('Synthesis Agent not found');
            }

            $statusReporter->report('synthesis_agent_start', 'Creating comprehensive research report from findings...');

            // Execute synthesis agent
            $executor = app(AgentExecutor::class);

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

            // Link child execution to original ChatInteraction for attachment access
            if ($execution->chatInteraction) {
                $synthesisExecution->setRelation('chatInteraction', $execution->chatInteraction);

                Log::info('ResearchService: Linked Research Synthesizer to ChatInteraction', [
                    'synthesis_execution_id' => $synthesisExecution->id,
                    'parent_execution_id' => $execution->id,
                    'interaction_id' => $execution->chatInteraction->id,
                    'attachments_count' => $execution->chatInteraction->attachments ? $execution->chatInteraction->attachments->count() : 0,
                ]);
            }

            // Execute the synthesis with enhanced error handling
            try {
                $finalAnswer = $executor->executeSingleAgent($synthesisExecution);
            } catch (\Error $e) {
                // Handle PHP Fatal Errors (like DOMEntityReference::getAttribute())
                if ($this->isDOMParsingError($e)) {
                    Log::warning('ResearchService: DOM parsing error during synthesis, providing fallback result', [
                        'execution_id' => $execution->id,
                        'error' => $e->getMessage(),
                    ]);

                    // Create a fallback synthesis result from available data
                    $finalAnswer = $this->createFallbackSynthesis($results, $query);

                    // Mark synthesis execution with warning
                    $synthesisExecution->update([
                        'status' => 'completed',
                        'metadata' => array_merge($synthesisExecution->metadata ?? [], [
                            'dom_parsing_error' => true,
                            'fallback_synthesis' => true,
                        ]),
                    ]);
                } else {
                    throw $e; // Re-throw non-DOM errors
                }
            } catch (\Exception $e) {
                // Check for DOM-related exceptions
                if ($this->isDOMParsingError($e)) {
                    Log::warning('ResearchService: DOM parsing exception during synthesis, providing fallback result', [
                        'execution_id' => $execution->id,
                        'error' => $e->getMessage(),
                    ]);

                    $finalAnswer = $this->createFallbackSynthesis($results, $query);
                } else {
                    throw $e; // Re-throw non-DOM exceptions
                }
            }

            // Calculate stats
            $totalSources = array_sum(array_column($results, 'source_count'));
            $completedThreads = count(array_filter($results, fn ($r) => ! isset($r['error'])));
            $durationSeconds = $execution->created_at ? now()->diffInSeconds($execution->created_at) : 0;

            // Mark synthesis execution as completed
            $synthesisExecution->markAsCompleted($finalAnswer, [
                'total_sources' => $totalSources,
                'synthesis_duration_ms' => $synthesisExecution->created_at->diffInMilliseconds(now()),
                'thread_count' => $threadCount,
                'completed_threads' => $completedThreads,
            ]);

            // Extract source links from the final answer
            $sourceLinksFromAnswer = $this->extractSourceLinks($finalAnswer);

            // Combine source links from threads and final answer
            $uniqueSourceLinks = $sourceLinksFromAnswer;

            // Add sources from thread findings that might not have made it to the final answer
            if (! empty($allSourceLinks)) {
                foreach ($allSourceLinks as $sourceInfo) {
                    if (! in_array($sourceInfo['url'], $uniqueSourceLinks)) {
                        $uniqueSourceLinks[] = $sourceInfo['url'];
                    }
                }
            }

            // Prepare the final source links structure
            $sourceLinks = array_values(array_unique($uniqueSourceLinks));

            // Store detailed source metadata for potential future use
            $sourceMetadata = [
                'sources_from_answer' => $sourceLinksFromAnswer,
                'sources_from_threads' => $allSourceLinks,
                'missing_threads' => $missingThreadCount,
            ];

            // Update execution with final result
            // Skip transition if already in completed state to avoid errors
            try {
                if (! $execution->isInState(AgentExecution::STATE_COMPLETED)) {
                    $execution->transitionTo(AgentExecution::STATE_COMPLETED);
                } else {
                    Log::info('ResearchService: Execution already in completed state, skipping transition', [
                        'execution_id' => $execution->id,
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('ResearchService: State transition error when completing execution', [
                    'execution_id' => $execution->id,
                    'error' => $e->getMessage(),
                ]);
                // Continue processing despite the transition error
            }

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
                    'source_metadata' => $sourceMetadata,
                    'partial_result' => ($missingThreadCount > 0),
                ]),
            ]);

            // Update interaction with final results
            $interaction->update([
                'answer' => $finalAnswer,
                'metadata' => array_merge($interaction->metadata ?? [], [
                    'execution_strategy' => $plan['execution_strategy'],
                    'research_threads' => $threadCount,
                    'research_threads_completed' => $completedThreads,
                    'total_sources' => $totalSources,
                    'duration_seconds' => $durationSeconds,
                    'synthesis_duration_ms' => $synthesisExecution->created_at->diffInMilliseconds(now()),
                    'holistic_research' => true,
                    'source_links' => $sourceLinks,
                    'partial_result' => ($missingThreadCount > 0),
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
                    'source_links' => $sourceLinks,
                ],
                'research_service'
            );

            $statusReporter->report('research_synthesis_complete',
                "Research complete! Found {$totalSources} sources across {$completedThreads}/{$threadCount} research threads.");

            // Return synthesis results
            return [
                'finalAnswer' => $finalAnswer,
                'metadata' => [
                    'total_sources' => $totalSources,
                    'thread_count' => $threadCount,
                    'source_links' => $sourceLinks,
                    'partial_result' => ($missingThreadCount > 0),
                    'completed_threads' => $completedThreads,
                    'duration_seconds' => $durationSeconds,
                ],
            ];

        } catch (\Exception $e) {
            Log::error('ResearchService: Synthesis failed', [
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
                    'answer' => $interaction->answer."\n\n❌ Research synthesis failed: {$e->getMessage()}",
                ]);
            }

            // Report failure
            $statusReporter->report('synthesis_failed', "Synthesis failed: {$e->getMessage()}");

            // Return error result instead of throwing
            return [
                'error' => true,
                'message' => "Synthesis failed: {$e->getMessage()}",
                'finalAnswer' => "❌ Research synthesis failed: {$e->getMessage()}",
                'metadata' => [
                    'error' => true,
                    'error_message' => $e->getMessage(),
                ],
            ];
        }
    }

    /**
     * Create a simplified backup research plan when parsing fails
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
        if (preg_match_all('/(?:sub-question|sub-query|research question)s?:?\s*\n?(.*?)(?=\n\n|$)/is', $planResult, $matches)) {
            $extractedQueries = [];
            foreach ($matches[1] as $match) {
                // Split by numbered lists, bullet points or new lines
                if (preg_match_all('/(?:\d+\.\s*|\*\s*|\n)([^\n\d\*]+)/i', $match, $subMatches)) {
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

        Log::info('Created backup research plan due to parsing failure', [
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

    /**
     * Format duration estimate for display
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
     * Extract source count from result text
     */
    protected function extractSourceCount(string $result): int
    {
        // Use the dedicated UrlExtractorService to count sources
        return app(UrlExtractorService::class)->countSources($result);
    }

    /**
     * Extract source links from the result text using enhanced patterns
     */
    protected function extractSourceLinks(string $result): array
    {
        // Use the dedicated UrlExtractorService to extract URLs
        return app(UrlExtractorService::class)->extractUrls($result);
    }

    /**
     * Generate a title for the interaction if it doesn't have one
     */
    protected function generateTitleIfNeeded(ChatInteraction $interaction): void
    {
        // Only generate a title if none exists and we have an answer
        if (empty($interaction->title) && ! empty($interaction->answer)) {
            try {
                $title = (new \App\Services\TitleGenerator)->generateFromContent(
                    $interaction->question,
                    substr($interaction->answer, 0, 300)
                );

                if (! empty($title)) {
                    $interaction->update(['title' => $title]);
                }
            } catch (\Throwable $e) {
                Log::warning('Failed to generate title for interaction', [
                    'interaction_id' => $interaction->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Prepare enhanced synthesis input with tools to access source content
     */
    protected function prepareEnhancedSynthesisInput(
        string $query,
        ResearchPlan $plan,
        array $threadResults,
        int $interactionId
    ): string {
        // Get the base synthesis input from HolisticResearchService
        $holisticService = app(HolisticResearchService::class);
        $baseInput = $holisticService->prepareSynthesisInput($plan, $threadResults);

        // Count sources for this interaction
        $sourceCount = \App\Models\ChatInteractionSource::where('chat_interaction_id', $interactionId)
            ->count();

        if ($sourceCount === 0) {
            return $baseInput;
        }

        // Add source information section
        $sourceSection = "\n\n## ADDITIONAL KNOWLEDGE SOURCES\n\n";
        $sourceSection .= "You have access to {$sourceCount} additional knowledge sources discovered during research. ";
        $sourceSection .= "These sources contain valuable information that may be relevant to answering the query.\n\n";
        $sourceSection .= "To access these sources, you can use two special tools:\n\n";
        $sourceSection .= "1. `research_sources` - Lists all sources with their relevance scores and metadata\n";
        $sourceSection .= "   - Use this first to discover what sources are available\n";
        $sourceSection .= "   - Example: `research_sources(include_summaries=true, limit=10, min_relevance=6)`\n\n";
        $sourceSection .= "2. `source_content` - Retrieves full content or summaries from specific sources\n";
        $sourceSection .= "   - Use this to retrieve content from the most relevant sources\n";
        $sourceSection .= "   - Example: `source_content(source_id=123, summarize=true)` or `source_content(url=\"https://example.com/article\", summarize=false)`\n\n";
        $sourceSection .= 'IMPORTANT: When synthesizing your response, ensure you integrate relevant information from these knowledge sources. ';
        $sourceSection .= 'Always include proper attribution for any information used from these sources in your final answer.';

        return $baseInput.$sourceSection;
    }

    /**
     * Check if an exception is related to DOM parsing errors
     */
    protected function isDOMParsingError($exception): bool
    {
        $message = $exception->getMessage();
        $class = get_class($exception);

        // Check for specific DOM-related error patterns
        return $class === 'Error' && (
            str_contains($message, 'DOMEntityReference::getAttribute()') ||
            str_contains($message, 'DOMEntityReference') ||
            str_contains($message, 'DOM') && str_contains($message, 'getAttribute') ||
            str_contains($message, 'readability') && str_contains($message, 'DOM')
        );
    }

    /**
     * Create a fallback synthesis when DOM parsing fails
     */
    protected function createFallbackSynthesis(array $threadResults, string $query): string
    {
        // Extract key information from thread results
        $completedThreads = array_filter($threadResults, fn ($r) => ! isset($r['error']));
        $totalSources = array_sum(array_column($threadResults, 'source_count'));

        // Create a basic synthesis from available thread findings
        $synthesis = "# Research Results for: {$query}\n\n";
        $synthesis .= '**Note:** This response was compiled with limited processing due to technical limitations. ';
        $synthesis .= 'The research was completed across '.count($completedThreads).' research threads ';
        $synthesis .= "with {$totalSources} sources consulted.\n\n";

        // Include findings from each completed thread
        foreach ($completedThreads as $index => $result) {
            if (! empty($result['findings']) && ! isset($result['error'])) {
                $subQuery = $result['sub_query'] ?? 'Research Thread '.($index + 1);
                $synthesis .= "## {$subQuery}\n\n";

                // Clean and include the findings
                $findings = $result['findings'];
                if (strlen($findings) > 2000) {
                    $findings = substr($findings, 0, 2000)."...\n\n*[Truncated due to processing limitations]*";
                }

                $synthesis .= $findings."\n\n";

                // Add source count if available
                if (! empty($result['source_count'])) {
                    $synthesis .= '*Sources consulted: '.$result['source_count']."*\n\n";
                }
            }
        }

        // Add summary section
        $synthesis .= "## Summary\n\n";
        $synthesis .= 'This research was conducted across multiple specialized research threads to provide ';
        $synthesis .= 'comprehensive coverage of the topic. While some advanced processing features were ';
        $synthesis .= 'unavailable, the core research findings remain intact and provide valuable insights ';
        $synthesis .= "into the requested query.\n\n";

        // Add disclaimer about limitations
        if (count($threadResults) > count($completedThreads)) {
            $failedCount = count($threadResults) - count($completedThreads);
            $synthesis .= "**Technical Note:** {$failedCount} research thread(s) experienced processing issues. ";
            $synthesis .= "The information above represents the successfully completed research.\n\n";
        }

        return $synthesis;
    }
}
