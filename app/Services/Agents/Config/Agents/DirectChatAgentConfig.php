<?php

namespace App\Services\Agents\Config\Agents;

use App\Services\Agents\Config\AbstractAgentConfig;
use App\Services\Agents\Config\Builders\SystemPromptBuilder;
use App\Services\Agents\Config\Builders\ToolConfigBuilder;
use App\Services\Agents\Config\Presets\AIConfigPresets;
use App\Services\AI\ModelSelector;

/**
 * Direct Chat Agent Configuration
 *
 * Real-time streaming AI assistant for immediate, interactive conversations
 * with direct feedback. Optimized for speed and natural conversation flow.
 *
 * **Key Features:**
 * - Real-time streaming responses
 * - Efficiency-first approach
 * - Source awareness for previous research
 * - Quick research capabilities
 * - Natural, flowing dialogue
 *
 * **Tools:**
 * - Quick research stack (knowledge core, basic web search, visualization)
 * - Source tracking for conversation continuity
 * - Artifact management
 *
 * **Use Cases:**
 * - Real-time conversations
 * - Quick Q&A
 * - Interactive assistance
 * - Building on previous research
 * - General knowledge queries
 */
class DirectChatAgentConfig extends AbstractAgentConfig
{
    public function getIdentifier(): string
    {
        return 'direct-chat-agent';
    }

    public function getName(): string
    {
        return 'Direct Chat Agent';
    }

    public function getDescription(): string
    {
        return 'Real-time streaming AI assistant for immediate, interactive conversations with direct feedback.';
    }

    protected function getSystemPromptBuilder(): SystemPromptBuilder
    {
        return (new SystemPromptBuilder)
            ->addSection('You are a Direct Chat AI assistant designed for real-time, interactive conversations with immediate streaming responses. You provide direct feedback and engage in natural, flowing dialogue.

## CORE CAPABILITIES

**Real-Time Interaction:**
- Provide immediate, streaming responses for natural conversation flow
- Engage users with direct, clear, and conversational answers
- Adapt your tone and depth based on the conversation context
- Maintain conversation continuity across multiple exchanges

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

**Versatile Assistance:**
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

Be helpful, responsive, and engaging in all interactions. Focus on providing immediate value while maintaining conversation flow and context.', 'usage_patterns');
    }

    protected function getToolConfigBuilder(): ToolConfigBuilder
    {
        return (new ToolConfigBuilder)
            ->withQuickResearch()
            ->withSourceTracking()
            ->withArtifacts()
            ->overrideTool('searxng_search', [
                'config' => [
                    'default_results' => 10, // Fewer results for faster processing
                ],
            ]);
    }

    public function getAIConfig(): array
    {
        return AIConfigPresets::providerAndModel(ModelSelector::MEDIUM);
    }

    public function getAgentType(): string
    {
        return 'direct';
    }

    public function getMaxSteps(): int
    {
        return 30;
    }

    public function isPublic(): bool
    {
        return true;
    }

    public function showInChat(): bool
    {
        return true;
    }

    public function isAvailableForResearch(): bool
    {
        return false;
    }

    public function isStreamingEnabled(): bool
    {
        return true;
    }

    public function isThinkingEnabled(): bool
    {
        return false;
    }

    public function getWorkflowConfig(): ?array
    {
        return [
            'enforce_link_validation' => false,
            'efficiency_mode' => true,
            'max_sources_per_query' => 5,
            'direct_streaming' => true,
        ];
    }

    public function getVersion(): string
    {
        return '2.0.0';
    }

    public function getCategories(): array
    {
        return ['chat', 'streaming', 'quick-research'];
    }
}
