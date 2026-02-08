<?php

namespace App\Jobs;

use App\Models\OutputAction;
use App\Models\OutputActionLog;
use App\Services\OutputAction\OutputActionRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Execute Output Action Job - Webhook and Integration Output Handler.
 *
 * Handles asynchronous execution of configurable output actions triggered by
 * agent completions, input triggers, or manual invocations. Supports webhooks,
 * API calls, and integration-specific outputs with comprehensive logging.
 *
 * Execution Flow:
 * 1. Queue job when output action triggered
 * 2. Resolve provider from OutputActionRegistry
 * 3. Execute action with template variable context
 * 4. Update usage statistics (success/failure count)
 * 5. Log detailed execution results to OutputActionLog
 *
 * Provider Integration:
 * - Providers registered in OutputActionRegistry
 * - Each provider implements execute() method
 * - Supports HTTP webhooks, Slack, Discord, email, etc.
 * - Template variables interpolated from context
 *
 * Error Handling:
 * - Single attempt (no retries) per requirements
 * - Graceful failure with error logging
 * - Usage statistics updated on failure
 * - Execution logged with error details
 *
 * Timeout: 120 seconds (configurable)
 * Retries: None (single-shot execution)
 * Queue: Default (inherits from triggerable)
 *
 * @see \App\Services\OutputAction\OutputActionRegistry
 * @see \App\Models\OutputAction
 * @see \App\Models\OutputActionLog
 */
class ExecuteOutputActionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 1; // No retry - fire once as per requirements

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * Create a new job instance.
     *
     * @param  OutputAction  $action  The output action to execute
     * @param  array  $context  Template variable context
     * @param  int|null  $userId  User ID for logging
     * @param  string|null  $triggerableType  Type of triggerable (e.g., 'App\Models\AgentExecution')
     * @param  int|null  $triggerableId  ID of triggerable
     */
    public function __construct(
        public OutputAction $action,
        public array $context,
        public ?int $userId = null,
        public ?string $triggerableType = null,
        public ?int $triggerableId = null
    ) {}

    /**
     * Execute the output action via registered provider.
     *
     * Resolves the provider from the registry, executes the action with
     * template context, updates usage statistics, and logs the result.
     * Handles provider-not-found and execution failures gracefully.
     *
     * @param  OutputActionRegistry  $registry  Provider registry for resolving action providers
     */
    public function handle(OutputActionRegistry $registry): void
    {
        Log::info('ExecuteOutputActionJob: Starting execution', [
            'action_id' => $this->action->id,
            'action_name' => $this->action->name,
            'provider_id' => $this->action->provider_id,
        ]);

        // Get the provider
        $provider = $registry->getProvider($this->action->provider_id);

        if (! $provider) {
            Log::error('ExecuteOutputActionJob: Provider not found', [
                'action_id' => $this->action->id,
                'provider_id' => $this->action->provider_id,
            ]);

            $this->logExecution(
                status: 'failed',
                errorMessage: "Provider not found: {$this->action->provider_id}"
            );

            return;
        }

        try {
            // Execute the action
            $result = $provider->execute($this->action, $this->context);

            // Update action usage statistics
            $this->action->incrementUsage($result['success']);

            // Log the execution
            $this->logExecution(
                url: $result['url'] ?? null,
                method: $result['method'] ?? null,
                headers: $result['headers'] ?? null,
                body: $result['body'] ?? null,
                status: $result['status'] ?? 'failed',
                responseCode: $result['response_code'] ?? null,
                responseBody: $result['response_body'] ?? null,
                errorMessage: $result['error_message'] ?? null,
                durationMs: $result['duration_ms'] ?? null
            );

            Log::info('ExecuteOutputActionJob: Execution completed', [
                'action_id' => $this->action->id,
                'success' => $result['success'],
                'status' => $result['status'],
                'duration_ms' => $result['duration_ms'] ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('ExecuteOutputActionJob: Execution failed', [
                'action_id' => $this->action->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Update action usage statistics
            $this->action->incrementUsage(false);

            // Log the failure
            $this->logExecution(
                status: 'failed',
                errorMessage: $e->getMessage()
            );
        }
    }

    /**
     * Log the execution result
     */
    protected function logExecution(
        ?string $url = null,
        ?string $method = null,
        ?array $headers = null,
        mixed $body = null,
        string $status = 'failed',
        ?int $responseCode = null,
        ?string $responseBody = null,
        ?string $errorMessage = null,
        ?int $durationMs = null
    ): void {
        OutputActionLog::create([
            'output_action_id' => $this->action->id,
            'user_id' => $this->userId ?? $this->action->user_id,
            'triggerable_type' => $this->triggerableType,
            'triggerable_id' => $this->triggerableId,
            'url' => $url,
            'method' => $method,
            'headers' => $headers,
            'body' => is_array($body) ? json_encode($body) : $body,
            'status' => $status,
            'response_code' => $responseCode,
            'response_body' => $responseBody,
            'error_message' => $errorMessage,
            'duration_ms' => $durationMs,
            'executed_at' => now(),
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ExecuteOutputActionJob: Job failed', [
            'action_id' => $this->action->id,
            'exception' => $exception->getMessage(),
        ]);

        // Update action usage statistics
        $this->action->incrementUsage(false);

        // Log the failure
        $this->logExecution(
            status: 'failed',
            errorMessage: 'Job failed: '.$exception->getMessage()
        );
    }
}
