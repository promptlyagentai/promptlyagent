<?php

namespace App\Services\Agents;

use App\Jobs\ResearchThreadJob;
use App\Models\Agent;
use App\Models\AgentExecution;
use App\Services\StatusReporter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Parallel Research Coordinator - Multi-Thread Research Execution.
 *
 * Coordinates parallel execution of research sub-queries via Horizon job queue.
 * Manages job dispatching, result collection via Redis, and timeout handling
 * for multi-threaded research workflows.
 *
 * Execution Strategy:
 * - **Simple Research**: Single-thread execution (no job queue, immediate result)
 * - **Parallel Research**: Multi-thread execution via ResearchThreadJob queue
 *
 * Job Dispatch Flow:
 * 1. Create unique result queue in Redis (research_results_{executionId})
 * 2. Dispatch ResearchThreadJob for each sub-query
 * 3. Poll Redis queue for results (timeout: plan-specific or 10min default)
 * 4. Collect and return all thread results
 *
 * Timeout Calculation:
 * - Base timeout: 10 minutes (600 seconds)
 * - Per-thread allowance: 90 seconds
 * - Final timeout: max(base, thread_count * 90)
 * - Rationale: Complex queries with many threads need more time
 *
 * Redis Integration:
 * - Result queue: `research_results_{executionId}` (FIFO list)
 * - TTL: 1 hour (prevents memory leaks)
 * - Thread results pushed via rpush, collected via lrange
 *
 * @see \App\Jobs\ResearchThreadJob
 * @see \App\Services\Agents\HolisticResearchService
 */
class ParallelResearchCoordinator
{
    public function __construct(
        private StatusReporter $statusReporter,
        private AgentService $agentService
    ) {}

    /**
     * Execute research plan (simple or parallel)
     */
    public function executeResearchPlan(ResearchPlan $plan, AgentExecution $execution): array
    {
        if ($plan->isSimple()) {
            return $this->executeSimpleResearch($plan->subQueries[0], $execution);
        }

        return $this->executeParallelResearch($plan, $execution);
    }

    /**
     * Execute simple single-thread research
     */
    private function executeSimpleResearch(string $query, AgentExecution $execution): array
    {
        $this->statusReporter->report('simple_research_start',
            'Executing simple research strategy', true, true);

        try {
            // Use existing Research Assistant agent for focused research
            $researchAgent = Agent::where('name', 'Research Assistant')->first();

            if (! $researchAgent) {
                throw new \Exception('Research Assistant agent not found');
            }

            // Execute research directly (no job queue for simple queries)
            $executor = app(AgentExecutor::class);
            $result = $this->executeAgentWithQuery($executor, $researchAgent, $query, $execution->id);

            $this->statusReporter->report('simple_research_complete',
                'Simple research completed successfully', true, false);

            return [[
                'sub_query' => $query,
                'findings' => $result,
                'source_count' => $this->extractSourceCount($result),
                'thread_index' => 0,
                'completion_time' => now()->toISOString(),
            ]];

        } catch (\Exception $e) {
            Log::error('Simple research failed', [
                'query' => $query,
                'execution_id' => $execution->id,
                'error' => $e->getMessage(),
            ]);

            return [[
                'sub_query' => $query,
                'findings' => "Research failed: {$e->getMessage()}",
                'source_count' => 0,
                'thread_index' => 0,
                'error' => true,
            ]];
        }
    }

    /**
     * Execute parallel multi-thread research
     */
    private function executeParallelResearch(ResearchPlan $plan, AgentExecution $execution): array
    {
        $this->statusReporter->report('research_planning_complete',
            'Research plan created with '.count($plan->subQueries).' parallel threads', true, true);

        // Log if specialized agents selected
        if ($plan->hasSpecializedAgents()) {
            $agentNames = collect($plan->getSelectedAgents())->pluck('name')->join(', ');
            $this->statusReporter->report('specialized_agents_selected',
                "Using specialized agents: {$agentNames}", true, true);
        }

        // Dispatch research threads - universal workers will auto-balance load
        $threadJobs = [];

        // Direct dispatch without afterCommit wrapper since queue config handles timing
        \Log::info('Dispatching research jobs directly');

        foreach ($plan->subQueries as $index => $subQuery) {
            $agentAssignment = $plan->getAgentForQuery($index);

            $formattedQuery = $subQuery;
            if ($agentAssignment && $agentAssignment['input_format']) {
                $formattedQuery = "{$agentAssignment['input_format']}\n\n{$subQuery}";
            }

            $interactionId = $this->statusReporter->getInteractionId();

            \Log::info('Creating ResearchThreadJob with interaction context', [
                'index' => $index,
                'interaction_id' => $interactionId,
                'interaction_id_type' => gettype($interactionId),
                'has_status_reporter' => isset($this->statusReporter),
                'specialized_agent_id' => $agentAssignment ? $agentAssignment['agent_id'] : null,
            ]);

            $job = new ResearchThreadJob(
                $formattedQuery,
                $index,
                $execution->id,
                $execution->user_id, // Always provide user context
                $interactionId,
                $agentAssignment ? $agentAssignment['agent_id'] : null
            );

            \Log::info('Dispatching ResearchThreadJob', [
                'index' => $index,
                'subQuery' => $subQuery,
                'parentExecutionId' => $execution->id,
                'queue' => 'research-agents',
                'in_transaction' => \DB::transactionLevel() > 0,
            ]);

            $queueName = ($index < 2) ? 'high:research-agents' : 'research-agents';
            $dispatchResult = dispatch($job)->onQueue($queueName);

            $threadJobs[] = $dispatchResult;

            \Log::info('Job dispatched', [
                'index' => $index,
                'dispatchResult' => get_class($dispatchResult),
            ]);

            $this->statusReporter->report('research_thread_dispatched',
                "Thread {$index}: Researching '{$subQuery}'", false, false);
        }

        // Add queue monitoring
        \Log::info('Checking queue status after dispatch');
        // Check both database and Redis for queue size to diagnose discrepancy
        $dbQueueSize = \DB::table('jobs')->where('queue', 'research-agents')->count();

        // Get Redis queue size from Laravel Queue facade
        $redisQueueSize = \Queue::size('research-agents');

        \Log::info('Queue monitoring results', [
            'db_queue_size' => $dbQueueSize,
            'redis_queue_size' => $redisQueueSize,
            'jobs_dispatched' => count($threadJobs),
        ]);

        // Wait for jobs to actually enter the queue if they're not there already
        // This handles the case where jobs were dispatched with afterCommit but not in queue yet
        $maxWaitForQueue = 10; // seconds
        $startWait = time();
        $jobsInQueue = false;

        while (time() - $startWait < $maxWaitForQueue) {
            // Check if jobs are in queue
            $currentQueueSize = \Queue::size('research-agents');

            if ($currentQueueSize > 0) {
                \Log::info('Jobs detected in queue', [
                    'queue_size' => $currentQueueSize,
                    'wait_time' => time() - $startWait,
                ]);
                $jobsInQueue = true;
                break;
            }

            // Check if results are already being received (ultra-fast processing)
            $resultCount = 0;
            for ($i = 0; $i < count($threadJobs); $i++) {
                $resultKey = "research_thread_{$execution->id}_{$i}";
                if (Redis::exists($resultKey)) {
                    $resultCount++;
                }
            }

            if ($resultCount > 0) {
                \Log::info('Jobs already processing - results being received', [
                    'result_count' => $resultCount,
                    'wait_time' => time() - $startWait,
                ]);
                $jobsInQueue = true;
                break;
            }

            // Wait a bit before checking again
            \Log::info('Waiting for jobs to enter queue', [
                'elapsed' => time() - $startWait,
                'max_wait' => $maxWaitForQueue,
            ]);
            usleep(100000); // 100ms
        }

        if (! $jobsInQueue) {
            \Log::warning('Jobs not detected in queue after waiting - potential issue with job dispatching', [
                'wait_time' => $maxWaitForQueue,
                'jobs_dispatched' => count($threadJobs),
            ]);
        }

        // Get the maximum timeout from environment or use a safe default (15 minutes)
        $maxTimeoutSeconds = (int) env('RESEARCH_MAX_TIMEOUT_SECONDS', 900);

        // Wait for all threads to complete with appropriate timeout
        $results = $this->waitForThreadCompletion(
            $threadJobs,
            $execution->id,
            $plan->estimatedDurationSeconds,
            $maxTimeoutSeconds
        );

        $totalSources = array_sum(array_column($results, 'source_count'));
        $this->statusReporter->report('parallel_research_complete',
            "All research threads completed. Found {$totalSources} total sources", true, false);

        return $results;
    }

    /**
     * Wait for all research threads to complete
     *
     * @param  array  $jobs  The dispatched jobs
     * @param  int  $executionId  The parent execution ID
     * @param  int  $timeoutSeconds  Estimated timeout from research plan
     * @param  int  $maxTimeoutSeconds  Optional maximum timeout override
     * @return array Results from all threads
     */
    private function waitForThreadCompletion(
        array $jobs,
        int $executionId,
        int $timeoutSeconds,
        int $maxTimeoutSeconds = 600
    ): array {
        $results = [];

        // Calculate a safer timeout that scales with thread count but is capped
        // Base timeout is at least 3 minutes per thread or the estimated duration, whichever is greater
        $safeTimeout = max($timeoutSeconds, count($jobs) * 180);

        // Cap at maximum timeout (default 10 minutes)
        $safeTimeout = min($safeTimeout, $maxTimeoutSeconds);

        $timeout = time() + $safeTimeout;
        $threadCount = count($jobs);

        \Log::info('ParallelResearchCoordinator: Waiting for thread completion', [
            'thread_count' => $threadCount,
            'estimated_duration' => $timeoutSeconds,
            'calculated_safe_timeout' => $safeTimeout,
            'execution_id' => $executionId,
        ]);

        while (count($results) < $threadCount && time() < $timeout) {
            for ($i = 0; $i < $threadCount; $i++) {
                if (isset($results[$i])) {
                    continue;
                }

                $resultKey = "research_thread_{$executionId}_{$i}";
                if ($cached = Redis::get($resultKey)) {
                    $results[$i] = json_decode($cached, true);
                    Redis::del($resultKey); // Cleanup

                    $sourceCount = $results[$i]['source_count'] ?? 0;
                    $this->statusReporter->report('research_thread_complete',
                        "Thread {$i} completed with {$sourceCount} sources");
                }
            }

            sleep(1); // Poll every second
        }

        // Handle timeout - return partial results with better handling
        if (count($results) < $threadCount) {
            $missingThreads = $threadCount - count($results);
            $completedThreads = count($results);

            \Log::warning('ParallelResearchCoordinator: Timeout reached while waiting for threads', [
                'execution_id' => $executionId,
                'total_threads' => $threadCount,
                'completed_threads' => $completedThreads,
                'missing_threads' => $missingThreads,
                'wait_duration' => $safeTimeout,
            ]);

            $this->statusReporter->report('research_timeout_warning',
                "Research timeout reached after {$safeTimeout}s. {$completedThreads} threads completed, {$missingThreads} did not finish.", true, false);

            // Fill missing results with timeout errors but add context about partial success
            for ($i = 0; $i < $threadCount; $i++) {
                if (! isset($results[$i])) {
                    $results[$i] = [
                        'sub_query' => "Thread {$i}",
                        'findings' => 'Research thread did not complete within the allowed time. Using available results from other threads.',
                        'source_count' => 0,
                        'thread_index' => $i,
                        'error' => true,
                        'timeout' => true,
                    ];
                }
            }

            // Check if we have enough threads to proceed with partial results
            if ($completedThreads > 0) {
                $this->statusReporter->report('research_partial_success',
                    "Proceeding with partial results from {$completedThreads} completed threads.", true, false);
            }
        }

        return $results;
    }

    /**
     * Extract source count from research result text
     */
    private function extractSourceCount(string $result): int
    {
        // Count markdown links [text](url) as sources
        $linkCount = preg_match_all('/\[([^\]]+)\]\(([^)]+)\)/', $result, $matches);

        return $linkCount ?: 0;
    }

    /**
     * Helper method to execute an agent with a query string
     */
    private function executeAgentWithQuery(AgentExecutor $executor, Agent $agent, string $query, int $parentExecutionId): string
    {
        // Create a temporary execution for this agent
        // Get parent execution's user to maintain proper attribution chain
        $parentExecution = AgentExecution::find($parentExecutionId);
        if (! $parentExecution) {
            throw new \Exception("Parent execution {$parentExecutionId} not found - cannot determine user context");
        }
        $userId = $parentExecution->user_id;

        $execution = new AgentExecution([
            'agent_id' => $agent->id,
            'user_id' => $userId,
            'chat_session_id' => $parentExecution->chat_session_id, // Include chat session for context
            'input' => $query,
            'max_steps' => $agent->max_steps,
            'status' => 'running',
            'parent_agent_execution_id' => $parentExecutionId,
        ]);
        $execution->save();

        // Link to original ChatInteraction for attachment access
        $parentExecution = AgentExecution::find($parentExecutionId);
        if ($parentExecution && $parentExecution->chatInteraction) {
            $execution->setRelation('chatInteraction', $parentExecution->chatInteraction);

            Log::info('ParallelResearchCoordinator: Linked simple research execution to ChatInteraction', [
                'execution_id' => $execution->id,
                'parent_execution_id' => $parentExecutionId,
                'interaction_id' => $parentExecution->chatInteraction->id,
                'attachments_count' => $parentExecution->chatInteraction->attachments ? $parentExecution->chatInteraction->attachments->count() : 0,
            ]);
        }

        // Execute the agent
        return $executor->executeSingleAgent($execution);
    }
}
