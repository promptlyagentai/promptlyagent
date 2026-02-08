<?php

namespace App\Providers;

use App\Events\QueueStatusUpdated;
use App\Services\Queue\JobStatusManager;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Queue event service provider for job lifecycle tracking
 *
 * Tracks queue job execution across the application and provides real-time
 * status updates via WebSockets for interactive chat sessions.
 *
 * Architecture:
 * - Listens to Laravel Queue events (JobProcessing, JobProcessed, JobFailed)
 * - Updates job status in Redis via JobStatusManager
 * - Broadcasts status changes to frontend via Reverb WebSocket (QueueStatusUpdated event)
 * - Extracts interaction IDs from job payloads to associate jobs with chat sessions
 *
 * Tracked jobs:
 * - ResearchJob, ExecuteAgentJob, SynthesizeWorkflowJob, ResearchThreadJob
 * - Uses job uniqueId() when available for consistent tracking
 *
 * Error handling:
 * - Truncates error messages to 500 chars to prevent Pusher payload limits
 * - Logs failures but doesn't throw to prevent queue disruption
 */
class QueueEventServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $jobStatusManager = app(JobStatusManager::class);

        // Job queued event
        Queue::before(function (JobProcessing $event) use ($jobStatusManager) {
            $this->handleJobStarted($event, $jobStatusManager);
        });

        // Job processed successfully
        Queue::after(function (JobProcessed $event) use ($jobStatusManager) {
            $this->handleJobCompleted($event, $jobStatusManager);
        });

        // Job failed
        Queue::failing(function (JobFailed $event) use ($jobStatusManager) {
            $this->handleJobFailed($event, $jobStatusManager);
        });
    }

    /**
     * Handle job started event
     */
    private function handleJobStarted(JobProcessing $event, JobStatusManager $jobStatusManager): void
    {
        try {
            $jobData = $this->extractJobData($event->job);

            if (! $jobData['interactionId']) {
                return; // Skip jobs not related to interactions
            }

            // Update job status in Redis
            $jobStatusManager->jobStarted(
                $jobData['interactionId'],
                $jobData['jobId'],
                [
                    'queue' => $event->job->getQueue() ?? 'default',
                    'class' => $jobData['class'],
                    'attempts' => $event->job->attempts(),
                ]
            );

            // Broadcast status update via WebSocket
            event(new QueueStatusUpdated($jobData['interactionId'], [
                'type' => 'queue_status_update',
                'job_id' => $jobData['jobId'],
                'status' => 'running',
                'queue' => $event->job->getQueue() ?? 'default',
                'class' => $jobData['class'],
                'message' => "Job {$jobData['class']} started processing",
            ]));

        } catch (\Exception $e) {
            Log::error('Failed to handle job started event', [
                'error' => $e->getMessage(),
                'job' => $event->job->getJobId(),
            ]);
        }
    }

    /**
     * Handle job completed event
     */
    private function handleJobCompleted(JobProcessed $event, JobStatusManager $jobStatusManager): void
    {
        try {
            $jobData = $this->extractJobData($event->job);

            if (! $jobData['interactionId']) {
                return; // Skip jobs not related to interactions
            }

            // Update job status in Redis
            $jobStatusManager->jobCompleted(
                $jobData['interactionId'],
                $jobData['jobId'],
                [
                    'queue' => $event->job->getQueue() ?? 'default',
                    'class' => $jobData['class'],
                ]
            );

            // Broadcast status update via WebSocket
            event(new QueueStatusUpdated($jobData['interactionId'], [
                'type' => 'queue_status_update',
                'job_id' => $jobData['jobId'],
                'status' => 'completed',
                'queue' => $event->job->getQueue() ?? 'default',
                'class' => $jobData['class'],
                'message' => "Job {$jobData['class']} completed successfully",
            ]));

        } catch (\Exception $e) {
            Log::error('Failed to handle job completed event', [
                'error' => $e->getMessage(),
                'job' => $event->job->getJobId(),
            ]);
        }
    }

    /**
     * Handle job failed event
     */
    private function handleJobFailed(JobFailed $event, JobStatusManager $jobStatusManager): void
    {
        try {
            $jobData = $this->extractJobData($event->job);

            if (! $jobData['interactionId']) {
                return; // Skip jobs not related to interactions
            }

            // Truncate error message to prevent Pusher payload size issues
            $fullError = $event->exception->getMessage() ?? 'Unknown error';
            $truncatedError = strlen($fullError) > 500 ? substr($fullError, 0, 500).'... (truncated)' : $fullError;

            // Update job status in Redis
            $jobStatusManager->jobFailed(
                $jobData['interactionId'],
                $jobData['jobId'],
                [
                    'queue' => $event->job->getQueue() ?? 'default',
                    'class' => $jobData['class'],
                    'exception' => $truncatedError,
                ]
            );

            // Broadcast status update via WebSocket with truncated error
            event(new QueueStatusUpdated($jobData['interactionId'], [
                'type' => 'queue_status_update',
                'job_id' => $jobData['jobId'],
                'status' => 'failed',
                'queue' => $event->job->getQueue() ?? 'default',
                'class' => $jobData['class'],
                'message' => "Job {$jobData['class']} failed: ".$truncatedError,
                'error' => $truncatedError,
            ]));

            Log::error('Job failed tracking', [
                'job_id' => $jobData['jobId'],
                'interaction_id' => $jobData['interactionId'],
                'class' => $jobData['class'],
                'error' => $event->exception->getMessage(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to handle job failed event', [
                'error' => $e->getMessage(),
                'job' => $event->job->getJobId(),
            ]);
        }
    }

    /**
     * Extract job data from job instance for tracking.
     *
     * Unserializes job payload and attempts to extract interaction ID from various
     * job types. Uses uniqueId() method when available for consistent tracking.
     *
     * @param  mixed  $job  The job instance from queue event
     * @return array{jobId: string, class: string, interactionId: int|null} Job metadata
     */
    private function extractJobData($job): array
    {
        $payload = $job->payload();
        $class = $payload['displayName'] ?? 'Unknown';

        // Use consistent job ID - prefer uniqueId() for jobs that have it
        $jobId = $job->getJobId(); // Default to Laravel's job ID

        // Try to extract interaction ID from job payload
        $interactionId = null;

        // Decode the job data to look for interaction ID
        if (isset($payload['data']['command'])) {
            $command = unserialize($payload['data']['command']);

            // Handle ResearchJob specifically
            if ($command instanceof \App\Jobs\ResearchJob) {
                // Use the job's uniqueId() for consistency with tracking
                if (method_exists($command, 'uniqueId')) {
                    $jobId = $command->uniqueId();
                }
                $interactionId = $command->getInteractionId();
            }
            // Handle ExecuteAgentJob specifically
            elseif ($command instanceof \App\Jobs\ExecuteAgentJob) {
                // Use the job's uniqueId() for consistency with tracking
                if (method_exists($command, 'uniqueId')) {
                    $jobId = $command->uniqueId();
                }
                $interactionId = $command->getInteractionId();
            }
            // Handle SynthesizeWorkflowJob specifically
            elseif ($command instanceof \App\Jobs\SynthesizeWorkflowJob) {
                // Use the job's uniqueId() for consistency with tracking
                if (method_exists($command, 'uniqueId')) {
                    $jobId = $command->uniqueId();
                }
                $interactionId = $command->getInteractionId();
            }
            // Handle ResearchThreadJob specifically
            elseif ($command instanceof \App\Jobs\ResearchThreadJob) {
                // Use the job's uniqueId() for consistency with tracking
                if (method_exists($command, 'uniqueId')) {
                    $jobId = $command->uniqueId();
                }
                $interactionId = $command->interactionId;
            }
            // Check various properties that might contain interaction ID for other jobs
            elseif (property_exists($command, 'interactionId')) {
                $interactionId = $command->interactionId;
            } elseif (property_exists($command, 'chatInteraction') && $command->chatInteraction) {
                $interactionId = is_object($command->chatInteraction) ? $command->chatInteraction->id : $command->chatInteraction;
            } elseif (method_exists($command, 'getChatInteractionId')) {
                $interactionId = $command->getChatInteractionId();
            }
        }

        return [
            'jobId' => $jobId,
            'class' => $class,
            'interactionId' => $interactionId,
        ];
    }
}
