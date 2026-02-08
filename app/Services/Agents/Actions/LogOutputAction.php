<?php

namespace App\Services\Agents\Actions;

use Illuminate\Support\Facades\Log;

/**
 * Log Output Action
 *
 * Logs workflow execution data for debugging and audit trails.
 * Supports different log levels and customizable output.
 */
class LogOutputAction implements ActionInterface
{
    public function execute(string $data, array $context, array $params): string
    {
        // SAFETY: Wrap in try-catch to ensure we always return data unchanged
        // Logging failures should never break the execution chain
        try {
            $level = $params['level'] ?? 'info';
            $message = $params['message'] ?? 'Workflow node execution';
            $includeData = $params['includeData'] ?? true;
            $maxLength = $params['maxLength'] ?? 500;

            $logData = [
                'agent_id' => $context['agent']->id ?? null,
                'agent_name' => $context['agent']->name ?? 'Unknown',
                'node_rationale' => $context['node']?->rationale ?? null,
            ];

            // Include the data being processed (input or output)
            if ($includeData) {
                $logData['data'] = $this->truncate($data, $maxLength);
                $logData['data_length'] = strlen($data);
            }

            // Add execution ID if available
            if (isset($context['execution'])) {
                $logData['execution_id'] = $context['execution']->id;
            }

            // Log at specified level
            match ($level) {
                'debug' => Log::debug($message, $logData),
                'info' => Log::info($message, $logData),
                'warning' => Log::warning($message, $logData),
                'error' => Log::error($message, $logData),
                default => Log::info($message, $logData),
            };
        } catch (\Throwable $e) {
            // Log the logging failure (meta!) but don't break execution
            Log::error('LogOutputAction: Failed to log workflow data', [
                'error' => $e->getMessage(),
                'data_length' => strlen($data),
            ]);
        }

        // ALWAYS return data unchanged (logging is side effect only)
        return $data;
    }

    public function validate(array $params): bool
    {
        // Validate log level if provided
        if (isset($params['level'])) {
            $validLevels = ['debug', 'info', 'warning', 'error'];
            if (! in_array($params['level'], $validLevels)) {
                return false;
            }
        }

        // Validate message if provided
        if (isset($params['message']) && ! is_string($params['message'])) {
            return false;
        }

        // Validate boolean flags
        if (isset($params['includeData']) && ! is_bool($params['includeData'])) {
            return false;
        }

        // Validate maxLength
        if (isset($params['maxLength'])) {
            if (! is_int($params['maxLength']) || $params['maxLength'] <= 0) {
                return false;
            }
        }

        return true;
    }

    public function getDescription(): string
    {
        return 'Log workflow execution data for debugging and audit trails';
    }

    public function getParameterSchema(): array
    {
        return [
            'level' => [
                'type' => 'string',
                'required' => false,
                'default' => 'info',
                'options' => ['debug', 'info', 'warning', 'error'],
                'description' => 'Log level',
            ],
            'message' => [
                'type' => 'string',
                'required' => false,
                'default' => 'Workflow node execution',
                'description' => 'Log message',
            ],
            'includeData' => [
                'type' => 'bool',
                'required' => false,
                'default' => true,
                'description' => 'Include the data being processed in log',
            ],
            'maxLength' => [
                'type' => 'int',
                'required' => false,
                'default' => 500,
                'description' => 'Maximum length for data in log',
                'min' => 1,
            ],
        ];
    }

    public function shouldQueue(): bool
    {
        // Logging should be synchronous to capture immediate execution context
        return false;
    }

    /**
     * Truncate text for logging
     */
    protected function truncate(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, $maxLength).'... [truncated]';
    }
}
