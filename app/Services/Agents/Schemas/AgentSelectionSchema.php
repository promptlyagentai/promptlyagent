<?php

namespace App\Services\Agents\Schemas;

use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

class AgentSelectionSchema extends ObjectSchema
{
    public function __construct()
    {
        parent::__construct(
            name: 'AgentSelection',
            description: 'AI analysis and selection of the best agent for a given query',
            properties: [
                new StringSchema(
                    name: 'analysis',
                    description: 'Brief analysis of the query requirements and why this agent is the best match'
                ),
                new NumberSchema(
                    name: 'selectedAgentId',
                    description: 'ID of the selected agent to execute the query'
                ),
                new StringSchema(
                    name: 'selectedAgentName',
                    description: 'Name of the selected agent for confirmation'
                ),
                new NumberSchema(
                    name: 'confidence',
                    description: 'Confidence level in this selection (0.0 to 1.0)'
                ),
                new StringSchema(
                    name: 'reasoning',
                    description: 'Specific reasons why this agent is optimal (capabilities, tools, domain match)'
                ),
            ],
            requiredFields: ['analysis', 'selectedAgentId', 'selectedAgentName', 'confidence', 'reasoning']
        );
    }

    /**
     * Convert structured output to simple array
     */
    public static function extractSelectionData(array $structured): array
    {
        return [
            'analysis' => $structured['analysis'],
            'agent_id' => $structured['selectedAgentId'],
            'agent_name' => $structured['selectedAgentName'],
            'confidence' => $structured['confidence'],
            'reasoning' => $structured['reasoning'],
        ];
    }
}
