<?php

namespace App\Services\CodeExecution;

/**
 * Data Transfer Object for code execution results
 */
class ExecutionResult
{
    public function __construct(
        public readonly ?string $stdout = null,
        public readonly ?string $stderr = null,
        public readonly ?string $compileOutput = null,
        public readonly ?int $exitCode = null,
        public readonly ?string $status = null,
        public readonly ?int $statusId = null,
        public readonly ?float $time = null,
        public readonly ?int $memory = null,
        public readonly ?string $token = null,
        public readonly bool $success = true,
        public readonly ?string $errorMessage = null,
    ) {}

    /**
     * Create ExecutionResult from Judge0 API response
     */
    public static function fromJudge0Response(array $response): self
    {
        $statusId = $response['status']['id'] ?? null;
        $statusDescription = $response['status']['description'] ?? null;

        // Status IDs: 3 = Accepted, 4 = Wrong Answer, 5 = Time Limit Exceeded, etc.
        // https://ce.judge0.com/#statuses-and-languages-statuses
        $success = in_array($statusId, [3, 4]); // 3 = Accepted, 4 = Wrong Answer (but executed)

        return new self(
            stdout: $response['stdout'] ?? null,
            stderr: $response['stderr'] ?? null,
            compileOutput: $response['compile_output'] ?? null,
            exitCode: $response['exit_code'] ?? null,
            status: $statusDescription,
            statusId: $statusId,
            time: $response['time'] ? (float) $response['time'] : null,
            memory: $response['memory'] ?? null,
            token: $response['token'] ?? null,
            success: $success,
            errorMessage: $statusId > 5 ? $statusDescription : null,
        );
    }

    /**
     * Get combined output (stdout + stderr)
     */
    public function getCombinedOutput(): string
    {
        $output = [];

        if ($this->compileOutput) {
            $output[] = "=== Compilation Output ===\n".$this->compileOutput;
        }

        if ($this->stdout) {
            $output[] = $this->stdout;
        }

        if ($this->stderr) {
            $output[] = "=== Errors ===\n".$this->stderr;
        }

        if ($this->errorMessage) {
            $output[] = "=== Execution Error ===\n".$this->errorMessage;
        }

        return implode("\n\n", $output) ?: '(No output)';
    }

    /**
     * Check if execution was successful
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Get execution time in seconds
     */
    public function getExecutionTime(): ?float
    {
        return $this->time;
    }

    /**
     * Get memory usage in KB
     */
    public function getMemoryUsage(): ?int
    {
        return $this->memory;
    }

    /**
     * Check if there are any errors
     */
    public function hasErrors(): bool
    {
        return ! empty($this->stderr) || ! empty($this->errorMessage);
    }
}
