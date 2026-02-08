<?php

namespace App\Services\Agents\Actions;

/**
 * Action Interface
 *
 * Defines the contract for workflow actions that can both transform data
 * and perform side effects. Actions receive data and context, can modify
 * the data (e.g., normalizeText, truncateText), perform side effects
 * (e.g., logging, webhooks, notifications), and return the (potentially
 * modified) data.
 *
 * This unified approach eliminates the need for separate "filters" and
 * "actions", providing maximum flexibility with minimal complexity.
 */
interface ActionInterface
{
    /**
     * Execute the action and return transformed data
     *
     * SAFETY CONTRACT:
     * - MUST return a non-empty string (PHP enforces string type, we validate non-empty)
     * - MUST return $data unchanged if action cannot process it
     * - MUST return $data unchanged on any internal error (catch exceptions internally)
     * - NEVER return empty string, false, null, or throw exceptions for non-critical failures
     * - Actions in a chain depend on previous actions returning valid data
     *
     * @param  string  $data  Input or output data to process/transform
     * @param  array  $context  Execution context with relevant data
     *                          - 'input': Original agent input
     *                          - 'output': Agent output (if post-execution)
     *                          - 'agent': Agent model
     *                          - 'node': WorkflowNode instance (if available)
     *                          - 'execution': AgentExecution instance
     * @param  array  $params  Action-specific parameters
     * @return string Transformed data (return $data unchanged if no transformation needed or on error)
     *
     * @throws \InvalidArgumentException if params are invalid (validation failures)
     * @throws \Exception if action execution fails critically (use sparingly)
     */
    public function execute(string $data, array $context, array $params): string;

    /**
     * Validate action parameters
     *
     * @param  array  $params  Parameters to validate
     * @return bool True if valid, false otherwise
     */
    public function validate(array $params): bool;

    /**
     * Get action description for documentation/UI
     *
     * @return string Human-readable description
     */
    public function getDescription(): string;

    /**
     * Get parameter schema for validation and documentation
     *
     * @return array Parameter schema with types and descriptions
     *
     * Example:
     * [
     *     'url' => ['type' => 'string', 'required' => true, 'description' => 'Webhook URL'],
     *     'method' => ['type' => 'string', 'required' => false, 'default' => 'POST', 'options' => ['POST', 'PUT']],
     * ]
     */
    public function getParameterSchema(): array;

    /**
     * Check if action should run asynchronously
     *
     * @return bool True if action should be queued, false for synchronous execution
     */
    public function shouldQueue(): bool;
}
