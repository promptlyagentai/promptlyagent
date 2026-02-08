<?php

namespace App\Jobs;

use App\Models\Agent;
use App\Models\AgentExecution;
use App\Services\Agents\AgentExecutor;
use App\Services\StatusReporter;
use App\Traits\TracksJobStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Individual research thread job for parallel execution
 * Universal workers will handle these jobs automatically
 */
class ResearchThreadJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksJobStatus;

    /**
     * The number of seconds after which the job's unique lock will be released.
     *
     * @var int
     */
    public $uniqueFor = 300; // 5 minute lock expiration

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return "research-thread-{$this->parentExecutionId}-{$this->threadIndex}";
    }

    public $timeout = 180; // 3 minutes max per thread

    public $tries = 1; // Never retry - research failures should not be retried automatically

    public function __construct(
        public string $subQuery,
        public int $threadIndex,
        public int $parentExecutionId,
        public int $userId, // Always require user context
        public ?int $interactionId = null,
        public ?int $specializedAgentId = null
    ) {
        // Assign to research-agents queue for universal workers
        $this->onQueue('research-agents');

        // Track job as queued if we have an interaction ID
        $this->trackJobQueued();
    }

    public function handle(): void
    {
        $statusReporter = app(StatusReporter::class);

        // Set interaction ID if available to ensure proper context in StatusReporter
        if (isset($this->interactionId) && $this->interactionId !== null) {
            // Set interaction ID directly without type conversion since it's already an integer
            $statusReporter->setInteractionId($this->interactionId);

            \Log::info('ResearchThreadJob: Set interaction ID in StatusReporter', [
                'thread_index' => $this->threadIndex,
                'interaction_id' => $this->interactionId,
                'interaction_id_type' => gettype($this->interactionId),
            ]);
        } else {
            \Log::warning('ResearchThreadJob: No interaction ID available', [
                'thread_index' => $this->threadIndex,
            ]);
        }

        $statusReporter->report('research_thread_start',
            "Thread {$this->threadIndex}: Starting focused research");

        try {
            // Select appropriate agent
            $researchAgent = null;

            if ($this->specializedAgentId) {
                $researchAgent = Agent::find($this->specializedAgentId);

                // Validate agent is still available for research
                if (! $researchAgent || ! $researchAgent->available_for_research || $researchAgent->status !== 'active') {
                    Log::warning("Specialized agent {$this->specializedAgentId} not available, falling back", [
                        'thread_index' => $this->threadIndex,
                        'parent_execution_id' => $this->parentExecutionId,
                    ]);
                    $researchAgent = null;
                }
            }

            // Fallback to default Research Assistant
            if (! $researchAgent) {
                $researchAgent = Agent::where('name', 'Research Assistant')
                    ->where('status', 'active')
                    ->first();
            }

            if (! $researchAgent) {
                throw new \Exception('No research agent available');
            }

            Log::info("Research thread {$this->threadIndex} using agent: {$researchAgent->name}", [
                'agent_id' => $researchAgent->id,
                'specialized_agent_id' => $this->specializedAgentId,
                'parent_execution_id' => $this->parentExecutionId,
            ]);

            // Execute focused research on this sub-question
            $executor = app(AgentExecutor::class);

            // Create a temporary execution for this research thread
            // Use the explicitly provided user ID - no fallbacks or lookups needed
            // Get parent execution to inherit chat_session_id for context consistency
            $parentExecution = AgentExecution::find($this->parentExecutionId);

            $execution = new \App\Models\AgentExecution([
                'agent_id' => $researchAgent->id,
                'user_id' => $this->userId,
                'chat_session_id' => $parentExecution ? $parentExecution->chat_session_id : null, // Include chat session for context
                'input' => $this->subQuery,
                'max_steps' => $researchAgent->max_steps,
                'status' => 'running',
                'parent_agent_execution_id' => $this->parentExecutionId,
            ]);
            $execution->save();

            // Link to original ChatInteraction for attachment access (reuse $parentExecution from above)
            if ($parentExecution && $parentExecution->chatInteraction) {
                $execution->setRelation('chatInteraction', $parentExecution->chatInteraction);

                Log::info('ResearchThreadJob: Linked research thread execution to ChatInteraction', [
                    'thread_execution_id' => $execution->id,
                    'thread_index' => $this->threadIndex,
                    'parent_execution_id' => $this->parentExecutionId,
                    'interaction_id' => $parentExecution->chatInteraction->id,
                    'attachments_count' => $parentExecution->chatInteraction->attachments ? $parentExecution->chatInteraction->attachments->count() : 0,
                ]);
            }

            $result = $executor->executeSingleAgent($execution);

            // Store result for synthesis
            $threadResult = [
                'sub_query' => $this->subQuery,
                'findings' => $result,
                'source_count' => $this->extractSourceCount($result),
                'thread_index' => $this->threadIndex,
                'completion_time' => now()->toISOString(),
            ];

            // Store in Redis with 5 minute expiry
            Redis::setex("research_thread_{$this->parentExecutionId}_{$this->threadIndex}",
                300, json_encode($threadResult));

            $statusReporter->report('research_thread_success',
                "Thread {$this->threadIndex}: Research completed successfully");

        } catch (\Exception $e) {
            Log::error('Research thread failed', [
                'thread_index' => $this->threadIndex,
                'sub_query' => $this->subQuery,
                'parent_execution_id' => $this->parentExecutionId,
                'error' => $e->getMessage(),
            ]);

            // Store error result so synthesis can handle partial failures
            $errorResult = [
                'sub_query' => $this->subQuery,
                'findings' => "Research failed: {$e->getMessage()}",
                'source_count' => 0,
                'thread_index' => $this->threadIndex,
                'error' => true,
                'completion_time' => now()->toISOString(),
            ];

            Redis::setex("research_thread_{$this->parentExecutionId}_{$this->threadIndex}",
                300, json_encode($errorResult));

            $statusReporter->report('research_thread_error',
                "Thread {$this->threadIndex}: Research failed - {$e->getMessage()}");
        }
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
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Research thread job failed completely', [
            'thread_index' => $this->threadIndex,
            'sub_query' => $this->subQuery,
            'parent_execution_id' => $this->parentExecutionId,
            'exception' => $exception->getMessage(),
        ]);

        // Store failure result
        $failureResult = [
            'sub_query' => $this->subQuery,
            'findings' => "Research thread failed after all retries: {$exception->getMessage()}",
            'source_count' => 0,
            'thread_index' => $this->threadIndex,
            'error' => true,
            'completion_time' => now()->toISOString(),
        ];

        Redis::setex("research_thread_{$this->parentExecutionId}_{$this->threadIndex}",
            300, json_encode($failureResult));
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
            'type' => 'research_thread',
            'queue' => 'research-agents',
            'class' => static::class,
            'thread_index' => $this->threadIndex,
            'parent_execution_id' => $this->parentExecutionId,
            'sub_query' => $this->subQuery,
        ];
    }
}
