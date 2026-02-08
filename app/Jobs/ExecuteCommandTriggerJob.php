<?php

namespace App\Jobs;

use App\Models\InputTrigger;
use App\Services\OutputAction\OutputActionDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Execute Command Trigger Job
 *
 * Executes an Artisan command via the queue system for webhook/scheduled triggers.
 * Commands are dispatched to the research-coordinator queue for proper resource management.
 *
 * Workflow:
 * 1. Loads trigger configuration and command class
 * 2. Merges trigger-level and request-level parameters
 * 3. Executes command via Artisan::call()
 * 4. Tracks execution success/failure
 * 5. Logs output and exit code
 *
 * Example trigger configuration:
 * - trigger_target_type: 'command'
 * - command_class: 'App\Console\Commands\Research\DailyDigestCommand'
 * - command_parameters: ['topics' => ['AI', 'Climate'], 'webhook-url' => 'https://...']
 *
 * @see \App\Services\InputTrigger\TriggerExecutor
 * @see \App\Console\Commands\Concerns\RegistersAsInputTrigger
 */
class ExecuteCommandTriggerJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes max for command execution

    public $tries = 1; // Don't retry - commands should handle their own failures

    /**
     * Create a new job instance
     *
     * @param  string  $triggerId  Input trigger ID (UUID)
     * @param  array  $parameters  Command parameters from webhook payload
     * @param  array  $metadata  Additional execution metadata
     * @param  OutputActionDispatcher|null  $dispatcher  Output action dispatcher (auto-injected by Laravel)
     */
    public function __construct(
        public string $triggerId,
        public array $parameters = [],
        public array $metadata = [],
        protected ?OutputActionDispatcher $dispatcher = null
    ) {
        $this->onQueue('research-coordinator');
    }

    /**
     * Execute the job
     */
    public function handle(): void
    {
        $trigger = InputTrigger::find($this->triggerId);

        if (! $trigger) {
            Log::error('ExecuteCommandTriggerJob: Trigger not found', [
                'trigger_id' => $this->triggerId,
            ]);

            return;
        }

        if (! $trigger->isCommandTrigger()) {
            Log::error('ExecuteCommandTriggerJob: Trigger is not a command trigger', [
                'trigger_id' => $this->triggerId,
                'trigger_target_type' => $trigger->trigger_target_type,
            ]);

            return;
        }

        try {
            // Merge trigger-level parameters with payload parameters
            // Payload parameters override trigger defaults
            $mergedParameters = array_merge(
                $trigger->command_parameters ?? [],
                $this->parameters
            );

            // ALWAYS set user-id to the trigger owner's ID (cannot be overridden)
            // Remove any user-id from payload or trigger params first
            unset($mergedParameters['user-id']);
            unset($mergedParameters['--user-id']);
            // Set as option with trigger owner's ID
            $mergedParameters['--user-id'] = $trigger->user_id;

            Log::info('ExecuteCommandTriggerJob: Executing command', [
                'trigger_id' => $trigger->id,
                'command_class' => $trigger->command_class,
                'parameters' => array_keys($mergedParameters), // Log keys only for security
                'user_id' => $trigger->user_id,
            ]);

            // Instantiate command to get its name and definition
            $command = app($trigger->command_class);
            $commandName = $command->getName();

            // Build Artisan arguments array
            $commandArgs = $this->buildCommandArguments($mergedParameters, $command);

            // Execute command
            $exitCode = Artisan::call($commandName, $commandArgs);
            $output = Artisan::output();

            // Track success/failure
            if ($exitCode === 0) {
                $trigger->recordSuccess();
            } else {
                $trigger->recordFailure();
            }

            $logData = [
                'trigger_id' => $trigger->id,
                'command' => $commandName,
                'exit_code' => $exitCode,
                'output_length' => strlen($output),
                'success' => $exitCode === 0,
            ];

            // Include output if command failed
            if ($exitCode !== 0) {
                $logData['output'] = $output;
            }

            Log::info('ExecuteCommandTriggerJob: Command executed', $logData);

            // Dispatch output actions for this trigger
            if ($this->dispatcher) {
                try {
                    $status = $exitCode === 0 ? 'success' : 'failed';

                    $invocationData = [
                        'result' => $output,
                        'user_id' => $trigger->user_id,
                        'command' => $commandName,
                        'exit_code' => $exitCode,
                        'executed_at' => now()->toIso8601String(),
                        'metadata' => $this->metadata,
                    ];

                    $this->dispatcher->dispatchForTrigger($trigger, $invocationData, $status);

                    Log::info('ExecuteCommandTriggerJob: Output actions dispatched', [
                        'trigger_id' => $trigger->id,
                        'status' => $status,
                    ]);
                } catch (\Throwable $e) {
                    Log::error('ExecuteCommandTriggerJob: Failed to dispatch output actions', [
                        'trigger_id' => $trigger->id,
                        'error' => $e->getMessage(),
                    ]);
                    // Don't throw - output action failures shouldn't fail the command execution
                }
            }

        } catch (\Throwable $e) {
            Log::error('ExecuteCommandTriggerJob: Command execution failed', [
                'trigger_id' => $trigger->id,
                'command_class' => $trigger->command_class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $trigger->recordFailure();

            throw $e;
        }
    }

    /**
     * Build command arguments for Artisan::call()
     *
     * Maps parameter array to Artisan-compatible format by looking up
     * the command definition to determine which are arguments vs options:
     * - Arguments: {'topics': ['AI', 'Climate']}
     * - Options: {'--session-strategy': 'new', '--user-id': 1}
     *
     * @param  array  $parameters  Parameter name => value mappings (without -- prefix)
     * @param  \Illuminate\Console\Command  $command  Command instance with definition
     * @return array Artisan arguments array (with -- prefix for options)
     */
    protected function buildCommandArguments(array $parameters, $command): array
    {
        $args = [];

        // Get command definition to determine arguments vs options
        $definition = $command->getDefinition();

        foreach ($parameters as $key => $value) {
            // Skip if key already has prefix (shouldn't happen, but be safe)
            if (str_starts_with($key, '-')) {
                $args[$key] = $value;

                continue;
            }

            // Check if this is an option or argument by looking at command definition
            if ($definition->hasOption($key)) {
                // It's an option - add -- prefix
                $args["--{$key}"] = $value;
            } elseif ($definition->hasArgument($key)) {
                // It's an argument - no prefix
                $args[$key] = $value;
            } else {
                // Unknown parameter - log warning and skip
                Log::warning('ExecuteCommandTriggerJob: Unknown parameter', [
                    'parameter' => $key,
                    'command_class' => get_class($command),
                ]);
            }
        }

        return $args;
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ExecuteCommandTriggerJob: Job failed permanently', [
            'trigger_id' => $this->triggerId,
            'error' => $exception->getMessage(),
        ]);

        // Try to record failure on trigger
        $trigger = InputTrigger::find($this->triggerId);
        if ($trigger) {
            $trigger->recordFailure();
        }
    }

    /**
     * Get the unique ID for the job
     */
    public function uniqueId(): string
    {
        return "command-trigger-{$this->triggerId}";
    }
}
