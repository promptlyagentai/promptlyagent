<?php

namespace App\Services\Agents\Config\Agents;

use App\Services\Agents\Config\AbstractAgentConfig;
use App\Services\Agents\Config\Builders\SystemPromptBuilder;
use App\Services\Agents\Config\Builders\ToolConfigBuilder;
use App\Services\Agents\Config\Presets\AIConfigPresets;
use App\Services\AI\ModelSelector;

/**
 * Promptly Agent Configuration
 *
 * Fast, versatile AI assistant for general-purpose conversations with
 * meta-agent capabilities for agent selection.
 */
class PromptlyAgentConfig extends AbstractAgentConfig
{
    public function getIdentifier(): string
    {
        return 'promptly-agent';
    }

    public function getName(): string
    {
        return 'Promptly Agent';
    }

    public function getDescription(): string
    {
        return 'Fast, versatile AI assistant for general-purpose conversations, quick research, and everyday tasks.';
    }

    protected function getSystemPromptBuilder(): SystemPromptBuilder
    {
        return (new SystemPromptBuilder)
            ->addSection('You are an intelligent agent selector. Your job is to analyze a user query and select the single best agent to handle it.

## YOUR TASK

1. Understand the user\'s query requirements
2. Analyze the available agents and their capabilities
3. Select the ONE agent that is best suited for this task
4. Provide clear reasoning for your selection

## ANALYSIS CRITERIA

Consider:
- **Domain Match**: Does the agent specialize in the query\'s domain?
- **Tool Availability**: Does the agent have the right tools?
- **Capability Indicators**: What does the agent\'s description/prompt reveal?
- **Complexity Match**: Is the agent appropriate for the task complexity?

## SELECTION PRINCIPLES

- Choose the MOST SPECIFIC agent when available (e.g., "Contract Evaluation" over "Research Assistant" for contracts)
- Consider tool requirements (e.g., needs markitdown for PDFs, needs artifact tools for document creation)
- Default to general-purpose agents only when no specialist matches
- Be confident - every agent can potentially help, pick the best match

## OUTPUT

Provide:
- Brief analysis of the query
- Selected agent ID and name
- Confidence level (0.0 to 1.0)
- Specific reasoning for the selection

Remember: You are selecting an agent to execute the task, not executing it yourself.

{DIRECT_ANSWER_GUIDANCE}', 'selection');
    }

    protected function getToolConfigBuilder(): ToolConfigBuilder
    {
        return (new ToolConfigBuilder)
            ->withQuickResearch()
            ->withSourceTracking()
            ->withArtifacts()
            ->overrideTool('knowledge_search', [
                'max_execution_time' => 10000, // Faster timeout
            ])
            ->overrideTool('searxng_search', [
                'min_results_threshold' => 3,
                'max_execution_time' => 25000,
                'config' => [
                    'default_results' => 8,
                ],
            ]);
    }

    public function getAIConfig(): array
    {
        return AIConfigPresets::providerAndModel(ModelSelector::MEDIUM);
    }

    public function getAgentType(): string
    {
        return 'promptly';
    }

    public function getMaxSteps(): int
    {
        return 25;
    }

    public function isPublic(): bool
    {
        return true;
    }

    public function showInChat(): bool
    {
        return true;
    }

    public function getWorkflowConfig(): ?array
    {
        return [
            'enforce_link_validation' => false,
            'efficiency_mode' => true,
            'max_sources_per_query' => 5,
        ];
    }

    public function getVersion(): string
    {
        return '2.0.0';
    }

    public function getCategories(): array
    {
        return ['chat', 'meta-agent', 'general-purpose'];
    }
}
