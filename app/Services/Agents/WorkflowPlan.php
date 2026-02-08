<?php

namespace App\Services\Agents;

/**
 * Workflow Plan Data Structure
 *
 * Defines a complete workflow with multiple execution strategies:
 * - simple: Single agent execution
 * - sequential: Chain of agents (A → B → C)
 * - parallel: Multiple agents simultaneously (A, B, C)
 * - mixed: Parallel branches with sequential steps
 * - nested: Complex nested structures (future)
 *
 * Workflow-Level Actions:
 * - initialActions: Run once at workflow start (before any agents execute)
 *   Examples: Log start, fetch external config, send "processing" notification
 * - finalActions: Run once at workflow completion (after synthesis)
 *   Examples: Send webhook, email results, cleanup resources, analytics
 */
class WorkflowPlan
{
    public function __construct(
        public string $originalQuery,
        public string $strategyType, // 'simple', 'sequential', 'parallel', 'mixed', 'nested'
        public array $stages, // Array of WorkflowStage objects
        public ?int $synthesizerAgentId = null,
        public bool $requiresQA = false,
        public int $estimatedDurationSeconds = 300,
        public array $initialActions = [], // ActionConfig[] - Run at workflow start
        public array $finalActions = []    // ActionConfig[] - Run at workflow completion
    ) {}

    /**
     * Check if workflow is simple (single agent)
     */
    public function isSimple(): bool
    {
        return $this->strategyType === 'simple';
    }

    /**
     * Check if workflow is sequential (chain)
     */
    public function isSequential(): bool
    {
        return $this->strategyType === 'sequential';
    }

    /**
     * Check if workflow is parallel (batch)
     */
    public function isParallel(): bool
    {
        return $this->strategyType === 'parallel';
    }

    /**
     * Check if workflow is mixed (parallel + sequential)
     */
    public function isMixed(): bool
    {
        return $this->strategyType === 'mixed';
    }

    /**
     * Check if workflow requires synthesis
     */
    public function requiresSynthesis(): bool
    {
        return $this->synthesizerAgentId !== null;
    }

    /**
     * Get total number of agent executions
     */
    public function getTotalJobs(): int
    {
        $total = 0;
        foreach ($this->stages as $stage) {
            $total += $stage->getJobCount();
        }

        return $total;
    }

    /**
     * Get all unique agents used in workflow
     */
    public function getUsedAgents(): array
    {
        $agents = [];
        foreach ($this->stages as $stage) {
            foreach ($stage->nodes as $node) {
                if (! in_array($node->agentId, $agents)) {
                    $agents[] = $node->agentId;
                }
            }
        }

        return $agents;
    }

    /**
     * Get all nodes in execution order
     */
    public function getAllNodes(): array
    {
        $nodes = [];
        foreach ($this->stages as $stage) {
            $nodes = array_merge($nodes, $stage->nodes);
        }

        return $nodes;
    }

    /**
     * Get workflow complexity estimate
     */
    public function getComplexityLevel(): string
    {
        $totalJobs = $this->getTotalJobs();
        $uniqueAgents = count($this->getUsedAgents());

        if ($totalJobs === 1) {
            return 'simple';
        } elseif ($totalJobs <= 3 && $uniqueAgents <= 2) {
            return 'moderate';
        } else {
            return 'complex';
        }
    }
}
