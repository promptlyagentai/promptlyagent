<?php

namespace App\Services\Agents;

/**
 * Research Plan Data Structure with Direct Mapping
 */
class ResearchPlan
{
    public ?array $suggestedValues = [];

    public array $subQueriesWithAgents = [];

    public function __construct(
        public string $originalQuery,
        public string $executionStrategy,
        public array $subQueries,
        public string $synthesisInstructions,
        public int $estimatedDurationSeconds
    ) {}

    public function isSimple(): bool
    {
        return $this->executionStrategy === 'simple';
    }

    public function requiresParallelExecution(): bool
    {
        return in_array($this->executionStrategy, ['standard', 'complex']);
    }

    /**
     * Get agent assignment for a specific query index
     */
    public function getAgentForQuery(int $queryIndex): ?array
    {
        if (! isset($this->subQueriesWithAgents[$queryIndex])) {
            return null;
        }

        $subQueryData = $this->subQueriesWithAgents[$queryIndex];

        return [
            'agent_id' => $subQueryData['agent_id'],
            'agent_name' => $subQueryData['agent_name'],
            'rationale' => $subQueryData['rationale'],
        ];
    }

    /**
     * Check if specialized agents are assigned
     */
    public function hasSpecializedAgents(): bool
    {
        foreach ($this->subQueriesWithAgents as $subQuery) {
            if (isset($subQuery['agent_name']) && $subQuery['agent_name'] !== 'Research Assistant') {
                return true;
            }
        }

        return false;
    }

    public function getSelectedAgents(): array
    {
        $agents = [];
        $agentIds = [];

        foreach ($this->subQueriesWithAgents as $subQuery) {
            $agentId = $subQuery['agent_id'];
            if (! in_array($agentId, $agentIds)) {
                $agents[] = [
                    'id' => $agentId,
                    'name' => $subQuery['agent_name'],
                    'rationale' => $subQuery['rationale'],
                ];
                $agentIds[] = $agentId;
            }
        }

        return $agents;
    }
}
