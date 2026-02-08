<?php

namespace App\Services\Agents\Schemas;

use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * Structured output schema for Research Planner agent
 * Used with Prism-PHP to ensure reliable JSON parsing
 * Includes agent selection for intelligent task routing
 */
class ResearchPlanSchema extends ObjectSchema
{
    public function __construct()
    {
        parent::__construct(
            name: 'research_plan',
            description: 'A structured research plan with agent assignments',
            properties: [
                new StringSchema(name: 'original_query', description: 'The original research query'),
                new EnumSchema(name: 'execution_strategy', description: 'Research complexity and execution strategy', options: ['simple', 'standard', 'complex']),
                new ArraySchema(
                    name: 'sub_queries',
                    description: 'Research questions with direct agent assignments',
                    items: new ObjectSchema(
                        name: 'sub_query_with_agent',
                        description: 'A research sub-query with its assigned agent',
                        properties: [
                            new StringSchema(name: 'query', description: 'The research question'),
                            new NumberSchema(name: 'agent_id', description: 'ID of the assigned agent'),
                            new StringSchema(name: 'agent_name', description: 'Name of the assigned agent'),
                            new StringSchema(name: 'rationale', description: 'Why this agent was selected for this query'),
                        ],
                        requiredFields: ['query', 'agent_id', 'agent_name', 'rationale']
                    )
                ),
                new StringSchema(name: 'synthesis_instructions', description: 'Instructions for combining research findings'),
            ],
            requiredFields: ['original_query', 'execution_strategy', 'sub_queries', 'synthesis_instructions']
        );
    }

    /**
     * Convert structured output result to ResearchPlan object
     */
    public static function toResearchPlan(array $structuredData): \App\Services\Agents\ResearchPlan
    {
        $estimatedDuration = match ($structuredData['execution_strategy']) {
            'simple' => 30,
            'standard' => 90,
            'complex' => 180,
            default => 90
        };

        $queries = [];
        foreach ($structuredData['sub_queries'] as $subQueryData) {
            $queries[] = $subQueryData['query'];
        }

        $plan = new \App\Services\Agents\ResearchPlan(
            originalQuery: $structuredData['original_query'],
            executionStrategy: $structuredData['execution_strategy'],
            subQueries: $queries,
            synthesisInstructions: $structuredData['synthesis_instructions'],
            estimatedDurationSeconds: $estimatedDuration
        );

        $plan->subQueriesWithAgents = $structuredData['sub_queries'];

        return $plan;
    }
}
