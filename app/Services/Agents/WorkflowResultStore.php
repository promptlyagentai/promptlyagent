<?php

namespace App\Services\Agents;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Workflow Result Store - Redis-Based Job Result Coordination.
 *
 * Manages temporary storage and collection of workflow job results in Redis.
 * Enables asynchronous job coordination for multi-agent workflows by providing
 * a central result collection mechanism.
 *
 * Redis Key Strategy:
 * - Pattern: `workflow_result_{batchId}_{jobIndex}`
 * - TTL: 1 hour (auto-cleanup after synthesis)
 * - Storage: JSON-encoded result arrays
 *
 * Usage Flow:
 * 1. ExecuteAgentJob stores result via storeJobResult()
 * 2. SynthesizeWorkflowJob collects all results via collectAllResults()
 * 3. Results automatically expire after 1 hour
 *
 * Result Structure:
 * - response: Agent's text response
 * - error: Error message if job failed
 * - agent_name: Name of agent that produced result
 * - execution_id: AgentExecution ID for traceability
 *
 * @see \App\Jobs\ExecuteAgentJob
 * @see \App\Jobs\SynthesizeWorkflowJob
 * @see \App\Services\Agents\WorkflowOrchestrator
 */
class WorkflowResultStore
{
    protected int $ttl = 3600;

    /**
     * Store job result in Redis
     */
    public function storeJobResult(string $batchId, int $jobIndex, array $result): void
    {
        $key = $this->getResultKey($batchId, $jobIndex);

        Redis::setex(
            $key,
            $this->ttl,
            json_encode($result)
        );

        Log::debug('WorkflowResultStore: Stored workflow result', [
            'batch_id' => $batchId,
            'job_index' => $jobIndex,
            'key' => $key,
            'result_has_error' => isset($result['error']),
        ]);
    }

    /**
     * Collect all results for a batch
     *
     * PERFORMANCE: Uses Redis MGET for batch retrieval instead of N individual GET calls
     */
    public function collectBatchResults(string $batchId, int $totalJobs): array
    {
        // Build array of keys for batch retrieval
        $keys = [];
        for ($i = 0; $i < $totalJobs; $i++) {
            $keys[] = $this->getResultKey($batchId, $i);
        }

        // PERFORMANCE: Single MGET call instead of N GET calls
        // Before: N × network_latency (e.g., 10 jobs × 2ms = 20ms)
        // After: 1 × network_latency (e.g., 1 × 2ms = 2ms)
        $values = Redis::mget($keys);

        // Decode results with error handling
        $results = [];
        foreach ($values as $i => $value) {
            if ($value !== null && $value !== false) {
                $decoded = json_decode($value, true);

                // Validate JSON decode succeeded
                if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                    $results[$i] = $decoded;
                } else {
                    Log::warning('WorkflowResultStore: Failed to decode result JSON', [
                        'batch_id' => $batchId,
                        'job_index' => $i,
                        'json_error' => json_last_error_msg(),
                    ]);
                }
            } else {
                Log::warning('WorkflowResultStore: Missing result for job', [
                    'batch_id' => $batchId,
                    'job_index' => $i,
                    'key' => $keys[$i],
                ]);
            }
        }

        Log::info('WorkflowResultStore: Collected batch results', [
            'batch_id' => $batchId,
            'expected_jobs' => $totalJobs,
            'collected_results' => count($results),
            'success_count' => count(array_filter($results, fn ($r) => ! isset($r['error']))),
            'error_count' => count(array_filter($results, fn ($r) => isset($r['error']))),
        ]);

        return $results;
    }

    /**
     * Get job result from Redis (alias for getResult for naming consistency)
     */
    public function getJobResult(string $batchId, int $jobIndex): ?array
    {
        return $this->getResult($batchId, $jobIndex);
    }

    /**
     * Get single result
     */
    public function getResult(string $batchId, int $jobIndex): ?array
    {
        $key = $this->getResultKey($batchId, $jobIndex);

        if ($cached = Redis::get($key)) {
            return json_decode($cached, true);
        }

        return null;
    }

    /**
     * Check if result exists
     */
    public function hasResult(string $batchId, int $jobIndex): bool
    {
        $key = $this->getResultKey($batchId, $jobIndex);

        return Redis::exists($key) > 0;
    }

    /**
     * Cleanup all results for a batch
     *
     * PERFORMANCE: Uses single Redis DEL with multiple keys instead of N individual DEL calls
     */
    public function cleanup(string $batchId, int $totalJobs): void
    {
        // Build array of keys for batch deletion
        $keys = [];
        for ($i = 0; $i < $totalJobs; $i++) {
            $keys[] = $this->getResultKey($batchId, $i);
        }

        // PERFORMANCE: Single DEL call with multiple keys
        // Redis DEL supports multiple keys natively: DEL key1 key2 key3
        $deletedCount = 0;
        if (! empty($keys)) {
            $deletedCount = Redis::del($keys);
        }

        Log::debug('WorkflowResultStore: Cleaned up workflow results', [
            'batch_id' => $batchId,
            'total_jobs' => $totalJobs,
            'deleted_count' => $deletedCount,
        ]);
    }

    /**
     * Generate Redis key for result
     */
    protected function getResultKey(string $batchId, int $jobIndex): string
    {
        return "workflow_result_{$batchId}_{$jobIndex}";
    }

    /**
     * Get TTL for results (mainly for testing)
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }

    /**
     * Set TTL for results (mainly for testing)
     */
    public function setTtl(int $ttl): void
    {
        $this->ttl = $ttl;
    }
}
