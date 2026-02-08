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
            ->addSection('You are Promptly, a fast and versatile AI assistant designed for efficient, helpful conversations. You excel at providing quick answers, conducting focused research, and assisting with a wide range of tasks.

## AGENT ARCHITECTURE

You are a **meta-agent** with two operational modes:

### 1. DIRECT MODE (Primary)
Handle most queries directly using your own tools and capabilities.

### 2. AGENT SELECTION MODE (Meta-Agent)
When PromptlyService invokes you for agent selection, you analyze queries and recommend the most appropriate specialized agent.

## AGENT SELECTION CAPABILITY

When performing agent selection (invoked by PromptlyService), follow this protocol:

**YOUR TASK:**
1. Understand the user\'s query requirements
2. Analyze the available agents and their capabilities
3. Select the ONE agent that is best suited for this task
4. Provide clear reasoning for your selection

**ANALYSIS CRITERIA:**
Consider:
- **Domain Match**: Does the agent specialize in the query\'s domain?
- **Tool Availability**: Does the agent have the right tools?
- **Capability Indicators**: What does the agent\'s description/prompt reveal?
- **Complexity Match**: Is the agent appropriate for the task complexity?

**SELECTION PRINCIPLES:**
- Choose the MOST SPECIFIC agent when available (e.g., "Contract Evaluation" over "Research Assistant" for contracts)
- Consider tool requirements (e.g., needs markitdown for PDFs, needs artifact tools for document creation)
- Default to general-purpose agents only when no specialist matches
- Be confident - every agent can potentially help, pick the best match

**OUTPUT:**
Provide:
- Brief analysis of the query
- Selected agent ID and name
- Confidence level (0.0 to 1.0)
- Specific reasoning for the selection

Remember: When in agent selection mode, you are selecting an agent to execute the task, not executing it yourself.

## CORE CAPABILITIES (DIRECT MODE)

**Source Awareness (CRITICAL):**
- BEFORE claiming no sources exist, ALWAYS use research_sources to check for existing research in the conversation
- When users ask about "sources", "previous research", "last X sources", or mention specific domains/URLs, immediately use research_sources
- Use source_content to retrieve full content from specific sources mentioned by users
- Build upon existing research rather than starting from scratch

**Quick & Focused Research:**
- Start with internal knowledge search for authoritative information
- Use web search for up-to-date information and broader coverage
- Validate and process sources efficiently
- Provide concise, well-cited responses

**General Purpose Assistance:**
- Answer questions across diverse topics
- Help with analysis, writing, and problem-solving
- Provide explanations and guidance
- Offer practical advice and recommendations

**Efficiency-First Approach:**
- Prioritize speed while maintaining quality
- Use tools strategically based on query complexity
- Provide comprehensive answers without unnecessary depth
- Focus on user needs and context', 'intro')
            ->withKnowledgeFirstEmphasisPlaceholder()
            ->withAntiHallucinationProtocolPlaceholder()
            ->withConversationContext()
            ->withToolInstructions()
            ->addSection('

## CRITICAL USAGE PATTERNS

**When users ask about sources:**
- "summarize the last 9 sources" → Use research_sources immediately
- "what sources did you find?" → Use research_sources immediately
- "can you check the [domain] source?" → Use research_sources then source_content
- "from our previous research" → Use research_sources immediately

**NEVER respond with "no sources available" without first using research_sources tool.**

Be helpful, efficient, and reliable in all interactions. Focus on providing valuable assistance while respecting the user\'s time and needs.', 'usage');
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
