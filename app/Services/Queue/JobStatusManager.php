<?php

namespace App\Services\Queue;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;

/**
 * Job Status Manager - Redis-Based Job Tracking for UI Updates.
 *
 * Tracks Horizon job status changes in Redis to provide real-time job state
 * updates to the UI. Enables users to see when jobs are queued, running,
 * completed, or failed without polling the database.
 *
 * Redis Key Strategy:
 * - Pattern: `queue:status:interaction:{interactionId}`
 * - TTL: 1 hour (auto-cleanup for completed jobs)
 * - Storage: JSON-encoded job metadata
 *
 * Status Transitions:
 * - queued → started → completed
 * - queued → started → failed
 * - Any status → cancelled (manual intervention)
 *
 * Metadata Tracking:
 * - job_id: Horizon job ID
 * - status: Current job state
 * - queued_at, started_at, completed_at timestamps
 * - error: Error message if failed
 * - custom metadata per job type
 *
 * WebSocket Integration:
 * - Status changes broadcast via Laravel Echo
 * - UI subscribes to interaction channel
 * - Real-time progress updates without polling
 *
 * @see \App\Jobs\ExecuteAgentJob
 * @see \App\Services\StatusReporter
 */
class JobStatusManager
{
    private const TTL = 3600;

    private const KEY_PREFIX = 'queue:status:interaction:';

    /**
     * Record a job as queued
     */
    public function jobQueued(string $interactionId, string $jobId, array $metadata = []): void
    {
        $this->updateJobStatus($interactionId, $jobId, 'queued', $metadata);
    }

    /**
     * Record a job as running
     */
    public function jobStarted(string $interactionId, string $jobId, array $metadata = []): void
    {
        $metadata['started_at'] = Carbon::now()->toISOString();
        $this->updateJobStatus($interactionId, $jobId, 'running', $metadata);
    }

    /**
     * Record a job as completed
     */
    public function jobCompleted(string $interactionId, string $jobId, array $metadata = []): void
    {
        $metadata['completed_at'] = Carbon::now()->toISOString();
        $this->updateJobStatus($interactionId, $jobId, 'completed', $metadata);
    }

    /**
     * Record a job as failed
     */
    public function jobFailed(string $interactionId, string $jobId, array $metadata = []): void
    {
        $metadata['failed_at'] = Carbon::now()->toISOString();
        $this->updateJobStatus($interactionId, $jobId, 'failed', $metadata);
    }

    /**
     * Remove a job from tracking
     */
    public function removeJob(string $interactionId, string $jobId): void
    {
        $key = $this->getRedisKey($interactionId);
        Redis::hdel($key, $jobId);
    }

    /**
     * Get all jobs for an interaction
     */
    public function getJobs(string $interactionId): array
    {
        $key = $this->getRedisKey($interactionId);
        $jobs = Redis::hgetall($key);

        $result = [];
        foreach ($jobs as $jobId => $data) {
            $result[$jobId] = json_decode($data, true);
        }

        return $result;
    }

    /**
     * Get job counts by status for an interaction
     */
    public function getJobCounts(string $interactionId): array
    {
        $jobs = $this->getJobs($interactionId);

        $counts = [
            'total' => count($jobs),
            'queued' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
        ];

        foreach ($jobs as $job) {
            $status = $job['status'] ?? 'unknown';
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
        }

        return $counts;
    }

    /**
     * Check if there are active jobs (queued or running)
     */
    public function hasActiveJobs(string $interactionId): bool
    {
        $counts = $this->getJobCounts($interactionId);

        return $counts['queued'] > 0 || $counts['running'] > 0;
    }

    /**
     * Get jobs formatted for display
     */
    public function getJobStatusDisplay(string $interactionId): array
    {
        $counts = $this->getJobCounts($interactionId);
        $display = [];

        if ($counts['running'] > 0) {
            $display[] = [
                'count' => $counts['running'],
                'label' => 'running',
                'icon' => '⚡',
                'color' => 'text-tropical-teal-600 dark:text-tropical-teal-400',
            ];
        }

        if ($counts['queued'] > 0) {
            $display[] = [
                'count' => $counts['queued'],
                'label' => 'queued',
                'icon' => '⏳',
                'color' => 'text-yellow-600 dark:text-yellow-400',
            ];
        }

        if ($counts['completed'] > 0) {
            $display[] = [
                'count' => $counts['completed'],
                'label' => 'completed',
                'icon' => '✅',
                'color' => 'text-green-600 dark:text-green-400',
            ];
        }

        if ($counts['failed'] > 0) {
            $display[] = [
                'count' => $counts['failed'],
                'label' => 'failed',
                'icon' => '❌',
                'color' => 'text-red-600 dark:text-red-400',
            ];
        }

        return $display;
    }

    /**
     * Clear all jobs for an interaction
     */
    public function clearJobs(string $interactionId): void
    {
        $key = $this->getRedisKey($interactionId);
        Redis::del($key);
    }

    /**
     * Update job status in Redis
     */
    private function updateJobStatus(string $interactionId, string $jobId, string $status, array $metadata = []): void
    {
        $key = $this->getRedisKey($interactionId);

        // Get existing job data
        $existingData = Redis::hget($key, $jobId);
        $jobData = $existingData ? json_decode($existingData, true) : [];

        // Merge with new data
        $jobData = array_merge($jobData, [
            'status' => $status,
            'updated_at' => Carbon::now()->toISOString(),
        ], $metadata);

        // Add created_at if it doesn't exist
        if (! isset($jobData['created_at'])) {
            $jobData['created_at'] = Carbon::now()->toISOString();
        }

        // Store the updated job data
        Redis::hset($key, $jobId, json_encode($jobData));

        // Set TTL on the key
        Redis::expire($key, self::TTL);

        // Broadcast queue status update via WebSocket
        try {
            \App\Events\QueueStatusUpdated::dispatch($interactionId, $jobData);
        } catch (\Exception $e) {
            \Log::error('Failed to broadcast queue status', [
                'error' => $e->getMessage(),
                'interaction_id' => $interactionId,
                'job_id' => $jobId,
            ]);
        }
    }

    /**
     * Get Redis key for interaction
     */
    private function getRedisKey(string $interactionId): string
    {
        return self::KEY_PREFIX.$interactionId;
    }
}
