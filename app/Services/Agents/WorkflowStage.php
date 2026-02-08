<?php

namespace App\Services\Agents;

/**
 * Workflow Stage
 *
 * Represents a single stage in a workflow.
 * A stage can be:
 * - parallel: All nodes execute simultaneously
 * - sequential: Nodes execute one after another
 */
class WorkflowStage
{
    public function __construct(
        public string $type, // 'parallel' or 'sequential'
        public array $nodes, // Array of WorkflowNode objects
    ) {}

    /**
     * Check if stage is parallel
     */
    public function isParallel(): bool
    {
        return $this->type === 'parallel';
    }

    /**
     * Check if stage is sequential
     */
    public function isSequential(): bool
    {
        return $this->type === 'sequential';
    }

    /**
     * Get number of jobs in this stage
     */
    public function getJobCount(): int
    {
        return count($this->nodes);
    }

    /**
     * Get all agent IDs in this stage
     */
    public function getAgentIds(): array
    {
        return array_map(fn ($node) => $node->agentId, $this->nodes);
    }

    /**
     * Get node by index
     */
    public function getNode(int $index): ?WorkflowNode
    {
        return $this->nodes[$index] ?? null;
    }

    /**
     * Check if stage has multiple nodes
     */
    public function hasMultipleNodes(): bool
    {
        return count($this->nodes) > 1;
    }
}
