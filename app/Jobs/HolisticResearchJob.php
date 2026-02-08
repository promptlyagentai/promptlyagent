<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job for executing holistic research workflow
 */
class HolisticResearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes max for holistic research

    public $tries = 1; // Don't retry automatically - research should be idempotent

    public function __construct(
        public int $executionId,
        public int $interactionId
    ) {
        // Assign to research-agents queue for processing
        $this->onQueue('research-agents');
    }

    public function handle(): void
    {
        $execution = \App\Models\AgentExecution::find($this->executionId);
        // Need to explicitly select metadata since it's excluded by default
        $interaction = \App\Models\ChatInteraction::where('id', $this->interactionId)
            ->addSelect('metadata')
            ->first();

        if (! $execution || ! $interaction) {
            \Log::error('HolisticResearchJob: Missing execution or interaction', [
                'execution_id' => $this->executionId,
                'interaction_id' => $this->interactionId,
            ]);

            return;
        }

        try {
            // Create AgentExecutor with ToolRegistry
            $executor = new \App\Services\Agents\AgentExecutor(new \App\Services\Agents\ToolRegistry);

            \Log::info('HolisticResearchJob: Starting workflow execution', [
                'execution_id' => $this->executionId,
                'interaction_id' => $this->interactionId,
                'query' => $execution->input,
            ]);

            $result = $executor->executeHolisticWorkflow($execution);

            \Log::info('HolisticResearchJob: Workflow completed', [
                'execution_id' => $this->executionId,
                'interaction_id' => $this->interactionId,
                'success' => $result['success'] ?? false,
                'strategy' => $result['metadata']['execution_strategy'] ?? 'unknown',
                'duration' => $result['metadata']['duration_seconds'] ?? 0,
            ]);

            if ($result['success']) {
                // Update interaction with successful results
                $interaction->update([
                    'answer' => $result['answer'] ?? 'Research completed',
                    'metadata' => array_merge($interaction->metadata ?? [], [
                        'execution_strategy' => $result['metadata']['execution_strategy'] ?? 'unknown',
                        'research_threads' => $result['metadata']['research_threads'] ?? 1,
                        'total_sources' => $result['metadata']['total_sources'] ?? 0,
                        'duration_seconds' => $result['metadata']['duration_seconds'] ?? 0,
                        'holistic_research' => true,
                    ]),
                ]);

                // Mark execution as completed
                $execution->update([
                    'status' => 'completed',
                    'output' => $result['answer'] ?? 'Research completed',
                    'completed_at' => now(),
                ]);
            } else {
                // Handle failure
                throw new \Exception($result['error'] ?? 'Unknown error in holistic research');
            }

        } catch (\Exception $e) {
            \Log::error('HolisticResearchJob: Research failed', [
                'execution_id' => $this->executionId,
                'interaction_id' => $this->interactionId,
                'error' => $e->getMessage(),
            ]);

            // Update with error
            $interaction->update([
                'answer' => '❌ Research failed: '.$e->getMessage(),
            ]);

            $execution->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            // Re-throw to mark job as failed
            throw $e;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        \Log::error('HolisticResearchJob: Job failed completely', [
            'execution_id' => $this->executionId,
            'interaction_id' => $this->interactionId,
            'exception' => $exception->getMessage(),
        ]);

        // Try to update records with failure state if they exist
        try {
            $execution = \App\Models\AgentExecution::find($this->executionId);
            $interaction = \App\Models\ChatInteraction::find($this->interactionId);

            if ($execution) {
                $execution->update([
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                    'completed_at' => now(),
                ]);
            }

            if ($interaction) {
                $interaction->update([
                    'answer' => '❌ Research failed after all attempts: '.$exception->getMessage(),
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('HolisticResearchJob: Failed to update records in failure handler', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
