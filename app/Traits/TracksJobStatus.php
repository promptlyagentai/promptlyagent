<?php

namespace App\Traits;

use App\Services\Queue\JobStatusManager;
use Illuminate\Support\Facades\Log;

/**
 * Tracks Job Status Trait
 *
 * Provides consistent job status tracking for queued jobs via JobStatusManager.
 * Consolidates duplicate tracking logic found across:
 * - ExecuteAgentJob
 * - SynthesizeWorkflowJob
 * - ResearchJob
 * - ResearchThreadJob
 *
 * Benefits:
 * - Single source of truth for job tracking patterns
 * - Consistent error handling and logging
 * - Reduces code duplication (~120+ lines across codebase)
 * - Prevents job creation failures from tracking errors
 *
 * Requirements:
 * - Implementing class MUST have a public|protected $interactionId property
 * - Implementing class MUST implement getJobTrackingMetadata(): array
 * - Implementing class MUST implement uniqueId(): string (standard Laravel Job method)
 * - Implementing class SHOULD implement getInteractionId(): ?int for external access
 *
 * Usage:
 * ```php
 * use App\Traits\TracksJobStatus;
 *
 * class MyJob implements ShouldQueue
 * {
 *     use TracksJobStatus;
 *
 *     public ?int $interactionId = null;
 *
 *     public function __construct(int $interactionId)
 *     {
 *         $this->interactionId = $interactionId;
 *         $this->onQueue('my-queue');
 *
 *         // Track job as queued
 *         $this->trackJobQueued();
 *     }
 *
 *     protected function getJobTrackingMetadata(): array
 *     {
 *         return [
 *             'type' => 'my_job_type',
 *             'some_id' => $this->someId,
 *             'queue' => 'my-queue',
 *             'class' => static::class,
 *         ];
 *     }
 *
 *     public function getInteractionId(): ?int
 *     {
 *         return $this->interactionId;
 *     }
 * }
 * ```
 */
trait TracksJobStatus
{
    /**
     * Track this job as queued in JobStatusManager.
     *
     * This method is typically called in the job's constructor after setting
     * up queue configuration. It will silently fail if:
     * - No interaction ID is set (optional tracking)
     * - JobStatusManager throws an exception (logged but doesn't fail job creation)
     *
     * The metadata array returned by getJobTrackingMetadata() should include:
     * - 'type' or 'mode': Job type identifier
     * - 'queue': Queue name the job is running on
     * - 'class': Job class name (usually static::class)
     * - Any job-specific tracking data (execution_id, batch_id, etc.)
     */
    protected function trackJobQueued(): void
    {
        // Skip tracking if no interaction ID is set
        if (! isset($this->interactionId) || ! $this->interactionId) {
            return;
        }

        try {
            $jobStatusManager = app(JobStatusManager::class);
            $metadata = $this->getJobTrackingMetadata();

            $jobStatusManager->jobQueued(
                (string) $this->interactionId,
                $this->uniqueId(),
                $metadata
            );

        } catch (\Exception $e) {
            // Log error but don't fail job creation - tracking is non-critical
            $this->logTrackingError('queued', $e);
        }
    }

    /**
     * Log job tracking error with contextual information.
     *
     * This method provides consistent error logging across all tracking failures.
     * It includes the job class name and interaction ID for debugging.
     *
     * @param  string  $action  The tracking action that failed (e.g., 'queued', 'started')
     * @param  \Exception  $exception  The exception that was thrown
     */
    protected function logTrackingError(string $action, \Exception $exception): void
    {
        $jobClass = class_basename(static::class);

        Log::error("{$jobClass}: Failed to track job as {$action}", [
            'job_class' => static::class,
            'interaction_id' => $this->interactionId ?? null,
            'error' => $exception->getMessage(),
            'error_class' => get_class($exception),
        ]);
    }

    /**
     * Get job-specific tracking metadata.
     *
     * This method MUST be implemented by the using class to provide
     * job-specific tracking information. The returned array should include:
     *
     * Required fields:
     * - 'type' or 'mode': Job type identifier (e.g., 'agent_execution', 'synthesis')
     * - 'queue': Queue name where job is running
     * - 'class': Job class name (typically static::class)
     *
     * Optional fields (job-specific):
     * - 'execution_id': AgentExecution ID for execution tracking
     * - 'batch_id': Batch ID for workflow coordination
     * - 'agent_id', 'agent_name': Agent identification
     * - 'job_index', 'thread_index': Job sequence tracking
     * - Any other contextual data relevant to this job type
     *
     * Example:
     * ```php
     * protected function getJobTrackingMetadata(): array
     * {
     *     return [
     *         'type' => 'agent_execution',
     *         'agent_id' => $this->execution->agent_id,
     *         'agent_name' => $this->execution->agent->name ?? 'Unknown',
     *         'execution_id' => $this->execution->id,
     *         'queue' => $this->getQueueName(),
     *         'class' => static::class,
     *     ];
     * }
     * ```
     *
     * @return array<string, mixed> Job tracking metadata
     */
    abstract protected function getJobTrackingMetadata(): array;
}
