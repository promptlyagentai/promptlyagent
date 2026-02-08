<?php

namespace App\Traits;

use App\Models\AgentExecution;
use Illuminate\Support\Facades\Log;

/**
 * Handles Execution Failures Trait
 *
 * Provides safe, consistent error marking logic for AgentExecution failures.
 * Consolidates duplicate try-catch wrapper patterns found across:
 * - Job failed() methods (ExecuteAgentJob, SynthesizeWorkflowJob, ResearchJob)
 * - AgentExecutor error handling
 * - StreamingController error handling
 *
 * Benefits:
 * - Single source of truth for error marking logic
 * - Consistent error logging with contextual information
 * - Prevents cascading errors during failure handling
 * - Reduces code duplication (~150+ lines across codebase)
 *
 * Usage:
 * ```php
 * use App\Traits\HandlesExecutionFailures;
 *
 * class MyJob implements ShouldQueue
 * {
 *     use HandlesExecutionFailures;
 *
 *     public function failed(Throwable $exception): void
 *     {
 *         $this->safeMarkAsFailed(
 *             $this->execution,
 *             "Job failed: {$exception->getMessage()}",
 *             ['attempts' => $this->attempts()]
 *         );
 *     }
 * }
 * ```
 */
trait HandlesExecutionFailures
{
    /**
     * Safely mark an execution as failed with error handling.
     *
     * This method wraps AgentExecution::markAsFailed() with:
     * - Automatic refresh from database (optional, for stale instances)
     * - Duplicate failure prevention (checks if already failed)
     * - Exception handling to prevent cascading failures
     * - Contextual error logging
     *
     * @param  AgentExecution  $execution  The execution to mark as failed
     * @param  string  $errorMessage  Human-readable error description
     * @param  array|null  $logContext  Additional context for error log
     * @param  bool  $refresh  Whether to refresh execution from database before checking status
     * @return bool True if successfully marked as failed, false otherwise
     */
    protected function safeMarkAsFailed(
        AgentExecution $execution,
        string $errorMessage,
        ?array $logContext = null,
        bool $refresh = true
    ): bool {
        try {
            // Refresh from database to ensure we have latest status
            if ($refresh) {
                $execution->refresh();
            }

            // Prevent marking as failed if already in a failed state
            if ($execution->isFailed()) {
                Log::debug('HandlesExecutionFailures: Execution already marked as failed, skipping', array_merge([
                    'execution_id' => $execution->id,
                    'current_status' => $execution->status,
                    'current_state' => $execution->state,
                ], $logContext ?? []));

                return false;
            }

            // Mark as failed using model method
            $execution->markAsFailed($errorMessage);

            Log::info('HandlesExecutionFailures: Successfully marked execution as failed', array_merge([
                'execution_id' => $execution->id,
                'error_message' => $errorMessage,
            ], $logContext ?? []));

            return true;

        } catch (\Exception $e) {
            // Log failure to mark as failed, but don't throw - prevents cascading errors
            Log::error('HandlesExecutionFailures: Failed to mark execution as failed', array_merge([
                'execution_id' => $execution->id,
                'attempted_error_message' => $errorMessage,
                'marking_error' => $e->getMessage(),
                'marking_error_class' => get_class($e),
            ], $logContext ?? []));

            return false;
        }
    }

    /**
     * Safely mark an execution as failed using execution ID.
     *
     * Convenience method that finds the execution by ID before marking as failed.
     * Useful when you have an execution ID but not the model instance.
     *
     * @param  int  $executionId  The ID of the execution to mark as failed
     * @param  string  $errorMessage  Human-readable error description
     * @param  array|null  $logContext  Additional context for error log
     * @return bool True if successfully marked as failed, false otherwise
     */
    protected function safeMarkAsFailedById(
        int $executionId,
        string $errorMessage,
        ?array $logContext = null
    ): bool {
        try {
            $execution = AgentExecution::find($executionId);

            if (! $execution) {
                Log::warning('HandlesExecutionFailures: Execution not found by ID', array_merge([
                    'execution_id' => $executionId,
                    'error_message' => $errorMessage,
                ], $logContext ?? []));

                return false;
            }

            return $this->safeMarkAsFailed($execution, $errorMessage, $logContext, false);

        } catch (\Exception $e) {
            Log::error('HandlesExecutionFailures: Exception while finding execution by ID', array_merge([
                'execution_id' => $executionId,
                'attempted_error_message' => $errorMessage,
                'exception' => $e->getMessage(),
            ], $logContext ?? []));

            return false;
        }
    }
}
