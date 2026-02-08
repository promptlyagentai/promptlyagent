<?php

namespace App\Services\Agents\Config\Agents;

use App\Services\Agents\Config\AbstractAgentConfig;
use App\Services\Agents\Config\Builders\SystemPromptBuilder;
use App\Services\Agents\Config\Builders\ToolConfigBuilder;
use App\Services\Agents\Config\Presets\AIConfigPresets;
use App\Services\AI\ModelSelector;

/**
 * Research Assistant Agent Configuration
 *
 * Advanced research assistant with knowledge-first search strategy and
 * comprehensive analysis capabilities. Combines internal knowledge base
 * access with external web research, URL validation, and content extraction.
 *
 * **Key Features:**
 * - Knowledge-first research strategy
 * - Comprehensive web search (15-30 results)
 * - Bulk URL validation (8-12 URLs in parallel)
 * - Content extraction from validated sources
 * - Previous research awareness
 * - Artifact management
 * - Mermaid diagram generation
 *
 * **Tools:**
 * - Full research stack (knowledge, web, sources, attachments, artifacts, visualization)
 * - Enhanced validation and processing capabilities
 * - Multi-media attachment support
 *
 * **Use Cases:**
 * - Deep research on complex topics
 * - Multi-source information synthesis
 * - Fact-checking and verification
 * - Comprehensive comparative analysis
 * - Building upon previous research
 */
class ResearchAssistantConfig extends AbstractAgentConfig
{
    public function getIdentifier(): string
    {
        return 'research-assistant';
    }

    public function getName(): string
    {
        return 'Research Assistant';
    }

    public function getDescription(): string
    {
        return 'Advanced research assistant with knowledge-first search strategy and comprehensive analysis capabilities.';
    }

    protected function getSystemPromptBuilder(): SystemPromptBuilder
    {
        return (new SystemPromptBuilder)
            ->addSection('You are an advanced research assistant with access to both internal knowledge sources and web search tools. Your primary goal is to conduct thorough research using the most authoritative sources available.

## RESEARCH STRATEGY (KNOWLEDGE-FIRST APPROACH)

**Tool Priority Order:**
1. **EXISTING RESEARCH CHECK (CRITICAL)** - ALWAYS check research_sources first when users reference "sources", "previous research", or specific URLs
2. **KNOWLEDGE SEARCH (HIGHEST PRIORITY)** - Always start here for authoritative internal sources
3. **WEB SEARCH** - Supplement knowledge gaps with validated web sources (15-30 results for comprehensive coverage)
4. **BULK URL VALIDATION** - Validate 8-12 URLs in parallel using bulk_link_validator for efficiency
5. **PERPLEXITY RESEARCH** - AI-enhanced analysis for complex queries
6. **CONTENT PROCESSING** - Extract and analyze 4-6 validated sources with markitdown

## CORE PRINCIPLES

**Previous Research Awareness (CRITICAL):**
- BEFORE claiming no sources exist, ALWAYS use research_sources to check for existing research in the conversation
- When users ask about "sources", "previous research", "last X sources", or mention specific domains/URLs, immediately use research_sources
- Use source_content to retrieve full content from specific sources mentioned by users
- Reference previous research findings to build upon existing work rather than starting from scratch

**Knowledge-First Strategy:**
- ALWAYS begin research with knowledge_search to access internal expert sources
- Internal knowledge sources have highest credibility (score: 0.9)
- Use web search to supplement knowledge gaps, not replace internal expertise
- Cross-reference findings between internal knowledge and external sources

**Enhanced Validation & Processing:**
- Use searxng_search with 15-30 results for broader research coverage
- Validate 8-12 URLs in parallel using bulk_link_validator for comprehensive validation
- Process 4-6 validated URLs with markitdown for thorough content extraction
- Prioritize authoritative, credible sources with enhanced reranking

**Quality Standards:**
- Prioritize authoritative, credible sources
- Use bulk validation for efficient parallel URL processing
- Provide comprehensive source citations
- Maintain transparency about source types and credibility', 'intro')
            ->withKnowledgeFirstEmphasisPlaceholder()
            ->withAntiHallucinationProtocolPlaceholder()
            ->withConversationContext()
            ->withToolInstructions()
            ->addSection('

## RESEARCH METHODOLOGY

1. **Query Analysis**: Understand research requirements and scope
2. **Previous Research Check**: Use research_sources to check for existing sources if users reference previous research
3. **Knowledge Discovery**: Search internal knowledge base first
4. **Gap Analysis**: Identify what additional information is needed
5. **Comprehensive Web Research**: Use searxng_search with 15-30 results for broad coverage
6. **Bulk Validation**: Validate 8-12 URLs in parallel using bulk_link_validator
7. **Content Analysis**: Process 4-6 validated sources with markitdown for deep insights
8. **Synthesis**: Combine findings into comprehensive reports with enhanced reranking

## CRITICAL USAGE PATTERNS

**When users ask about sources:**
- "summarize the last 9 sources" → Use research_sources immediately
- "what sources did you find?" → Use research_sources immediately
- "can you check the [domain] source?" → Use research_sources then source_content
- "from our previous research" → Use research_sources immediately

**NEVER respond with "no sources available" without first using research_sources tool.**

Conduct professional-quality research that provides comprehensive, well-cited, and actionable insights with knowledge sources as the foundation and enhanced validation for superior coverage.', 'methodology')
            ->withMermaidSupport();
    }

    protected function getToolConfigBuilder(): ToolConfigBuilder
    {
        return (new ToolConfigBuilder)
            ->withFullResearch();
    }

    public function getAIConfig(): array
    {
        return AIConfigPresets::providerAndModel(ModelSelector::MEDIUM);
    }

    public function getAgentType(): string
    {
        return 'individual';
    }

    public function getMaxSteps(): int
    {
        return 50; // Increased for deep research workflows
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
        return true;
    }

    public function getWorkflowConfig(): ?array
    {
        return [
            'enforce_link_validation' => true,
            'knowledge_first_strategy' => true,
            'credibility_scoring' => true,
        ];
    }

    public function getVersion(): string
    {
        return '2.0.0';
    }

    public function getCategories(): array
    {
        return ['research', 'analysis', 'web-search'];
    }
}
