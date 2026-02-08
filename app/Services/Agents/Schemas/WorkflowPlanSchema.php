<?php

namespace App\Services\Agents\Schemas;

use App\Services\Agents\WorkflowNode;
use App\Services\Agents\WorkflowPlan;
use App\Services\Agents\WorkflowStage;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;

/**
 * Structured output schema for Workflow Planner (Deeply Agent)
 *
 * Defines complex workflow structures with:
 * - Multiple execution strategies (simple, sequential, parallel, mixed)
 * - Stages with parallel or sequential execution
 * - Nodes representing individual agent executions
 * - Optional synthesis step
 */
class WorkflowPlanSchema extends ObjectSchema
{
    public function __construct()
    {
        parent::__construct(
            name: 'workflow_plan',
            description: 'A structured workflow execution plan with stages and agent assignments',
            properties: [
                new StringSchema(
                    name: 'originalQuery',
                    description: 'The original user query being addressed'
                ),
                new EnumSchema(
                    name: 'strategyType',
                    description: 'Workflow execution strategy',
                    options: ['simple', 'sequential', 'parallel', 'mixed', 'nested']
                ),
                new ArraySchema(
                    name: 'stages',
                    description: 'Workflow stages to execute in order',
                    items: new ObjectSchema(
                        name: 'stage',
                        description: 'A single workflow stage',
                        properties: [
                            new EnumSchema(
                                name: 'type',
                                description: 'Stage execution type',
                                options: ['parallel', 'sequential']
                            ),
                            new ArraySchema(
                                name: 'nodes',
                                description: 'Agent execution nodes in this stage',
                                items: new ObjectSchema(
                                    name: 'node',
                                    description: 'A single agent execution node',
                                    properties: [
                                        new NumberSchema(
                                            name: 'agentId',
                                            description: 'ID of agent to execute'
                                        ),
                                        new StringSchema(
                                            name: 'agentName',
                                            description: 'Name of agent for confirmation'
                                        ),
                                        new StringSchema(
                                            name: 'input',
                                            description: 'Input query/task for this agent'
                                        ),
                                        new StringSchema(
                                            name: 'rationale',
                                            description: 'Why this agent was selected for this task'
                                        ),
                                    ],
                                    requiredFields: ['agentId', 'agentName', 'input', 'rationale']
                                )
                            ),
                        ],
                        requiredFields: ['type', 'nodes']
                    )
                ),
                new NumberSchema(
                    name: 'synthesizerAgentId',
                    description: 'ID of agent to synthesize final results (0 if no synthesis needed)'
                ),
                new BooleanSchema(
                    name: 'requiresQA',
                    description: 'Whether to run quality assurance validation after synthesis to ensure comprehensive coverage'
                ),
                new NumberSchema(
                    name: 'estimatedDurationSeconds',
                    description: 'Estimated time to complete workflow in seconds'
                ),
            ],
            requiredFields: ['originalQuery', 'strategyType', 'stages', 'synthesizerAgentId', 'requiresQA', 'estimatedDurationSeconds']
        );
    }

    /**
     * Convert structured output array to WorkflowPlan object
     */
    public static function toWorkflowPlan(array $structuredData): WorkflowPlan
    {
        $stages = [];

        foreach ($structuredData['stages'] as $stageData) {
            $nodes = [];

            foreach ($stageData['nodes'] as $nodeData) {
                // Validate and correct agent name if hallucinated
                $validated = self::validateAndCorrectAgentNode(
                    (int) $nodeData['agentId'],
                    $nodeData['agentName']
                );

                $nodes[] = new WorkflowNode(
                    agentId: $validated['agent_id'],
                    agentName: $validated['agent_name'],  // Use corrected name
                    input: $nodeData['input'],
                    rationale: $nodeData['rationale']
                );
            }

            $stages[] = new WorkflowStage(
                type: $stageData['type'],
                nodes: $nodes
            );
        }

        // Validate synthesizer agent if specified
        // Treat 0 as null (no synthesizer needed) since agent IDs start at 1
        $synthesizerAgentId = isset($structuredData['synthesizerAgentId'])
            ? (int) $structuredData['synthesizerAgentId']
            : null;

        // Normalize 0 to null (planner may return 0 to indicate no synthesis)
        if ($synthesizerAgentId === 0) {
            $synthesizerAgentId = null;
        }

        if ($synthesizerAgentId !== null) {
            $synthesizerAgentId = self::validateOrFallbackSynthesizerAgent($synthesizerAgentId);
        }

        return new WorkflowPlan(
            originalQuery: $structuredData['originalQuery'],
            strategyType: $structuredData['strategyType'],
            stages: $stages,
            synthesizerAgentId: $synthesizerAgentId,
            requiresQA: $structuredData['requiresQA'] ?? false,
            estimatedDurationSeconds: (int) ($structuredData['estimatedDurationSeconds'] ?? 300)
        );
    }

    /**
     * Validate that the specified agent exists and has the 'synthesizer' type.
     * If validation fails, fallback to the first available synthesizer agent.
     *
     * @return int Valid synthesizer agent ID
     */
    protected static function validateOrFallbackSynthesizerAgent(int $agentId): int
    {
        $agent = \App\Models\Agent::find($agentId);

        // If agent doesn't exist, fallback
        if (! $agent) {
            Log::warning("Synthesizer agent with ID {$agentId} not found. Falling back to first available synthesizer agent.");

            return self::getFirstAvailableSynthesizerAgent();
        }

        // If agent has wrong type, fallback
        if ($agent->agent_type !== 'synthesizer') {
            Log::warning(
                "Agent '{$agent->name}' (ID: {$agentId}) has type '{$agent->agent_type}' but synthesis requires agent_type='synthesizer'. ".
                'Falling back to first available synthesizer agent.'
            );

            return self::getFirstAvailableSynthesizerAgent();
        }

        return $agentId;
    }

    /**
     * Get the first available synthesizer agent ID
     *
     * @return int First synthesizer agent ID
     *
     * @throws \RuntimeException if no synthesizer agents exist in the system
     */
    protected static function getFirstAvailableSynthesizerAgent(): int
    {
        $firstSynthesizer = \App\Models\Agent::where('agent_type', 'synthesizer')
            ->orderBy('id')
            ->first();

        if (! $firstSynthesizer) {
            throw new \RuntimeException(
                'No synthesizer agents found in the system. Please create at least one synthesizer agent.'
            );
        }

        Log::info("Using fallback synthesizer agent: {$firstSynthesizer->name} (ID: {$firstSynthesizer->id})");

        return $firstSynthesizer->id;
    }

    /**
     * Validate that the agent exists and correct the name if hallucinated
     *
     * @return array{agent_id: int, agent_name: string, was_corrected: bool}
     *
     * @throws \InvalidArgumentException if agent doesn't exist
     */
    protected static function validateAndCorrectAgentNode(int $agentId, string $providedName): array
    {
        $agent = \App\Models\Agent::find($agentId);

        if (! $agent) {
            throw new \InvalidArgumentException(
                "Agent with ID {$agentId} not found in workflow plan node. ".
                'The workflow planner selected a non-existent agent.'
            );
        }

        // Check if name matches
        if ($agent->name !== $providedName) {
            Log::warning(
                'Workflow planner hallucinated agent name - auto-correcting',
                [
                    'agent_id' => $agentId,
                    'hallucinated_name' => $providedName,
                    'actual_name' => $agent->name,
                ]
            );

            // Return corrected name
            return [
                'agent_id' => $agentId,
                'agent_name' => $agent->name,
                'was_corrected' => true,
            ];
        }

        return [
            'agent_id' => $agentId,
            'agent_name' => $providedName,
            'was_corrected' => false,
        ];
    }

    /**
     * Create a simple workflow plan (single agent)
     */
    public static function createSimplePlan(
        string $query,
        int $agentId,
        string $agentName,
        string $rationale = 'Single agent execution',
        bool $requiresQA = false
    ): WorkflowPlan {
        return new WorkflowPlan(
            originalQuery: $query,
            strategyType: 'simple',
            stages: [
                new WorkflowStage(
                    type: 'sequential',
                    nodes: [
                        new WorkflowNode(
                            agentId: $agentId,
                            agentName: $agentName,
                            input: $query,
                            rationale: $rationale
                        ),
                    ]
                ),
            ],
            synthesizerAgentId: null,
            requiresQA: $requiresQA,
            estimatedDurationSeconds: 60
        );
    }

    /**
     * Create a parallel workflow plan (multiple agents simultaneously)
     */
    public static function createParallelPlan(
        string $query,
        array $nodes, // Array of ['agentId', 'agentName', 'input', 'rationale']
        ?int $synthesizerAgentId = null,
        bool $requiresQA = false,
        int $estimatedDuration = 180
    ): WorkflowPlan {
        $workflowNodes = array_map(
            fn ($nodeData) => new WorkflowNode(
                agentId: $nodeData['agentId'],
                agentName: $nodeData['agentName'],
                input: $nodeData['input'],
                rationale: $nodeData['rationale']
            ),
            $nodes
        );

        return new WorkflowPlan(
            originalQuery: $query,
            strategyType: 'parallel',
            stages: [
                new WorkflowStage(
                    type: 'parallel',
                    nodes: $workflowNodes
                ),
            ],
            synthesizerAgentId: $synthesizerAgentId,
            requiresQA: $requiresQA,
            estimatedDurationSeconds: $estimatedDuration
        );
    }
}
