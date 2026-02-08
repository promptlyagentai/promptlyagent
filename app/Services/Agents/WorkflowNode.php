<?php

namespace App\Services\Agents;

/**
 * Workflow Node
 *
 * Represents a single agent execution within a workflow stage.
 * Contains all information needed to execute an agent with context,
 * including optional actions that can both transform data and perform side effects.
 *
 * Execution Flow:
 * 1. inputActions (executed in priority order, can transform input and/or perform side effects)
 * 2. Agent execution
 * 3. outputActions (executed in priority order, can transform output and/or perform side effects)
 *
 * Actions are unified - each action can:
 * - Transform data (e.g., normalizeText, truncateText)
 * - Perform side effects (e.g., logOutput, sendWebhook)
 * - Or both (e.g., validateAndLog)
 */
class WorkflowNode
{
    /**
     * @param  int  $agentId  ID of agent to execute
     * @param  string  $agentName  Name of agent for confirmation
     * @param  string  $input  Input query/task for this agent
     * @param  string  $rationale  Why this agent was selected
     * @param  array  $inputActions  ActionConfig[] - Execute before agent (priority-based)
     * @param  array  $outputActions  ActionConfig[] - Execute after agent (priority-based)
     */
    public function __construct(
        public int $agentId,
        public string $agentName,
        public string $input,
        public string $rationale,
        public array $inputActions = [],
        public array $outputActions = []
    ) {}

    /**
     * Get input actions sorted by priority (ascending)
     * Lower priority values execute first (e.g., 10 before 20)
     */
    public function getSortedInputActions(): array
    {
        return collect($this->inputActions)
            ->sortBy('priority')
            ->values()
            ->toArray();
    }

    /**
     * Get output actions sorted by priority (ascending)
     * Lower priority values execute first (e.g., 10 before 20)
     */
    public function getSortedOutputActions(): array
    {
        return collect($this->outputActions)
            ->sortBy('priority')
            ->values()
            ->toArray();
    }

    /**
     * Check if node has any actions
     */
    public function hasActions(): bool
    {
        return ! empty($this->inputActions) || ! empty($this->outputActions);
    }

    /**
     * Get a summary of this node for logging
     */
    public function getSummary(): array
    {
        return [
            'agent_id' => $this->agentId,
            'agent_name' => $this->agentName,
            'input_length' => strlen($this->input),
            'rationale' => substr($this->rationale, 0, 100),
            'input_actions_count' => count($this->inputActions),
            'output_actions_count' => count($this->outputActions),
        ];
    }

    /**
     * Check if node input is non-empty
     */
    public function hasInput(): bool
    {
        return ! empty(trim($this->input));
    }

    /**
     * Get input preview (first 100 characters)
     */
    public function getInputPreview(): string
    {
        return substr($this->input, 0, 100).(strlen($this->input) > 100 ? '...' : '');
    }
}
