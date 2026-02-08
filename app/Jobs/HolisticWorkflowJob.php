<?php

namespace App\Jobs;

use App\Jobs\Concerns\BroadcastsWorkflowEvents;
use App\Models\AgentExecution;
use App\Models\ChatInteraction;
use App\Services\Agents\AgentExecutor;
use App\Services\StatusReporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class HolisticWorkflowJob implements ShouldQueue
{
    use BroadcastsWorkflowEvents, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Agent execution ID for the holistic research workflow
     *
     * @var int
     */
    protected $executionId;

    /**
     * Chat interaction ID for result linking and source tracking
     *
     * @var int
     */
    protected $interactionId;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1; // Never retry this job

    /**
     * The number of seconds the job can run before timing out.
     * Increased to accommodate complex research workflows with multiple threads.
     *
     * @var int
     */
    public $timeout = 900; // 15 minutes, much higher than individual thread timeout

    /**
     * Create a new job instance.
     */
    public function __construct(int $executionId, int $interactionId)
    {
        $this->executionId = $executionId;
        $this->interactionId = $interactionId;

        // Set the queue name to ensure proper processing
        $this->onQueue('research-agents');
    }

    public function handle(): void
    {
        Log::info('HolisticWorkflowJob: Starting job execution', [
            'execution_id' => $this->executionId,
            'interaction_id' => $this->interactionId,
        ]);

        try {
            // Get execution and interaction models
            $execution = AgentExecution::findOrFail($this->executionId);
            // Need to explicitly select metadata since it's excluded by default
            $interaction = ChatInteraction::where('id', $this->interactionId)
                ->addSelect('metadata')
                ->firstOrFail();

            // Set up StatusReporter to report progress with both interaction and execution IDs
            $statusReporter = new StatusReporter($this->interactionId, $this->executionId);
            app()->instance('status_reporter', $statusReporter);

            Log::info('HolisticWorkflowJob: Set up StatusReporter', [
                'interaction_id' => $this->interactionId,
                'agent_execution_id' => $this->executionId,
            ]);

            // Create an agent executor
            $executor = app(AgentExecutor::class);

            // Execute the holistic workflow
            $result = $executor->executeHolisticWorkflow($execution);

            Log::info('HolisticWorkflowJob: Workflow completed', [
                'execution_id' => $this->executionId,
                'success' => $result['success'] ?? false,
            ]);

            if ($result['success']) {
                // Update interaction with successful results
                $interaction->update([
                    'answer' => $result['final_answer'] ?? 'Research completed',
                    'metadata' => array_merge($interaction->metadata ?? [], [
                        'execution_strategy' => $result['metadata']['execution_strategy'] ?? 'unknown',
                        'research_threads' => $result['metadata']['research_threads'] ?? 1,
                        'total_sources' => $result['metadata']['total_sources'] ?? 0,
                        'duration_seconds' => $result['metadata']['duration_seconds'] ?? 0,
                        'holistic_research' => true,
                    ]),
                ]);

                // Mark execution as completed
                $execution->markAsCompleted($result['final_answer'] ?? 'Research completed', $result['metadata'] ?? []);

                // Dispatch event for side effect listeners (Phase 3: side effects via events only)
                // Listener: TrackResearchUrls
                \App\Events\ResearchWorkflowCompleted::dispatch(
                    $interaction,
                    $result['final_answer'] ?? '',
                    $result['metadata'] ?? [],
                    'holistic_workflow_job'
                );

                // Broadcast completion event to UI
                // Source links are tracked via event listener, use metadata source_links for broadcast
                $sourceLinks = $result['metadata']['source_links'] ?? [];
                $this->broadcastCompletion($interaction->id, $execution->id, $result['final_answer'] ?? 'Research completed', $result['metadata'] ?? [], $sourceLinks);

            } else {
                // Broadcast failure event to UI
                $this->broadcastFailure($interaction->id, $execution->id, $result['error'] ?? 'Unknown error in holistic research');

                throw new \Exception($result['error'] ?? 'Unknown error in holistic research');
            }

        } catch (\Exception $e) {
            Log::error('HolisticWorkflowJob: Failed to execute holistic research', [
                'execution_id' => $this->executionId,
                'interaction_id' => $this->interactionId,
                'error' => $e->getMessage(),
            ]);

            // Update interaction and execution with error
            $execution = AgentExecution::find($this->executionId);
            $interaction = ChatInteraction::find($this->interactionId);

            if ($interaction) {
                $interaction->update([
                    'answer' => '❌ Research failed: '.$e->getMessage(),
                ]);
            }

            if ($execution) {
                $execution->markAsFailed($e->getMessage());
            }

            // Broadcast failure event to UI
            if ($interaction) {
                $this->broadcastFailure($interaction->id, $this->executionId, $e->getMessage());
            }

            // Re-throw the exception to mark the job as failed
            throw $e;
        }
    }

    /**
     * Broadcast successful completion to UI using proper event class
     */
    protected function broadcastCompletion(int $interactionId, int $executionId, string $result, array $metadata, array $sources): void
    {
        // Use trait method for holistic workflow completion
        $this->broadcastHolisticCompletion($interactionId, $executionId, $result, $metadata, $sources);
    }

    /**
     * Broadcast failure to UI using proper event class
     */
    protected function broadcastFailure(int $interactionId, int $executionId, string $error): void
    {
        // Use trait method for holistic workflow failure
        $this->broadcastHolisticFailure($interactionId, $executionId, $error);
    }

    /**
     * Broadcast update to UI
     *
     * @deprecated This method is not currently used and should be removed if no future need is identified
     */
    protected function broadcastUpdate(int $interactionId, int $executionId, array $data): void
    {
        Log::warning('HolisticWorkflowJob: broadcastUpdate method called but is deprecated', [
            'interaction_id' => $interactionId,
            'execution_id' => $executionId,
        ]);
    }

    /**
     * Generate title for session if needed (first interaction with answer)
     */
    protected function generateTitleIfNeeded(ChatInteraction $interaction): void
    {
        \App\Services\SessionTitleService::generateTitleIfNeeded($interaction);
    }

    /**
     * Generate a title for the session using AI
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
                Log::info('HolisticWorkflowJob: Generated title using TitleGenerator', [
                    'session_id' => $sessionId,
                    'title' => $title,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('HolisticWorkflowJob: Failed to generate title using TitleGenerator', [
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
}
