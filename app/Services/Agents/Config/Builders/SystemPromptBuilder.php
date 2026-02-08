<?php

namespace App\Services\Agents\Config\Builders;

use App\Services\Agents\Config\Presets\PromptPresets;

/**
 * System Prompt Builder
 *
 * Fluent API for composing agent system prompts from reusable sections and presets.
 * Enables building complex system prompts by chaining preset components and custom
 * sections.
 *
 * **Usage:**
 * ```php
 * $prompt = (new SystemPromptBuilder())
 *     ->addSection('You are an advanced research assistant...')
 *     ->withAntiHallucinationProtocol()
 *     ->withKnowledgeFirstEmphasis()
 *     ->withResearchMethodology()
 *     ->withConversationContext()
 *     ->withToolInstructions()
 *     ->build();
 * ```
 *
 * **Fluent Methods:**
 * - **addSection()** - Add custom text section
 * - **withAntiHallucinationProtocol()** - Add anti-hallucination guidelines
 * - **withKnowledgeFirstEmphasis()** - Add knowledge-first protocol
 * - **withPreviousResearchAwareness()** - Add research_sources guidelines
 * - **withResearchMethodology()** - Add 8-step research process
 * - **withMermaidSupport()** - Add diagram creation guidelines
 * - **withConversationContext()** - Add {CONVERSATION_CONTEXT} placeholder
 * - **withToolInstructions()** - Add {TOOL_INSTRUCTIONS} placeholder
 * - **withAIPersona()** - Add {AI_PERSONA_CONTEXT} placeholder
 * - **removeSection()** - Remove section by key
 * - **build()** - Return final prompt string
 *
 * **Keyed Sections:**
 * Sections can be keyed for later removal or replacement:
 * ```php
 * $builder->addSection('Some text', 'intro')
 *         ->removeSection('intro')
 *         ->addSection('Different intro', 'intro');
 * ```
 *
 * **Section Order:**
 * Sections are joined in the order they're added. Common patterns:
 * 1. Agent introduction/role
 * 2. Core principles/strategy
 * 3. Anti-hallucination protocol
 * 4. Knowledge-first emphasis
 * 5. Methodology
 * 6. Tool-specific guidelines
 * 7. Placeholders (context, tools, persona)
 *
 * @see \App\Services\Agents\Config\Presets\PromptPresets
 */
class SystemPromptBuilder
{
    /**
     * @var array<string|int, string> Prompt sections (key => text)
     */
    protected array $sections = [];

    /**
     * @var int Auto-increment key for non-keyed sections
     */
    protected int $autoKey = 0;

    /**
     * Add a custom text section
     *
     * @param  string  $content  Section text
     * @param  string|int|null  $key  Optional key for later removal/replacement
     */
    public function addSection(string $content, string|int|null $key = null): self
    {
        if ($key === null) {
            $this->sections[$this->autoKey++] = $content;
        } else {
            $this->sections[$key] = $content;
        }

        return $this;
    }

    /**
     * Add anti-hallucination protocol
     */
    public function withAntiHallucinationProtocol(): self
    {
        return $this->addSection(
            PromptPresets::antiHallucinationProtocol(),
            'anti_hallucination'
        );
    }

    /**
     * Add knowledge-first emphasis
     */
    public function withKnowledgeFirstEmphasis(): self
    {
        return $this->addSection(
            PromptPresets::knowledgeFirstEmphasis(),
            'knowledge_first'
        );
    }

    /**
     * Add previous research awareness guidelines
     */
    public function withPreviousResearchAwareness(): self
    {
        return $this->addSection(
            PromptPresets::previousResearchAwareness(),
            'previous_research'
        );
    }

    /**
     * Add research methodology
     */
    public function withResearchMethodology(): self
    {
        return $this->addSection(
            PromptPresets::researchMethodology(),
            'research_methodology'
        );
    }

    /**
     * Add Mermaid diagram support
     */
    public function withMermaidSupport(): self
    {
        return $this->addSection(
            PromptPresets::mermaidDiagramSupport(),
            'mermaid_support'
        );
    }

    /**
     * Add conversation context placeholder
     *
     * Replaced at runtime with actual conversation history.
     */
    public function withConversationContext(): self
    {
        return $this->addSection(
            PromptPresets::conversationContextPlaceholder(),
            'conversation_context'
        );
    }

    /**
     * Add tool instructions placeholder
     *
     * Replaced at runtime with dynamically generated tool instructions.
     */
    public function withToolInstructions(): self
    {
        return $this->addSection(
            PromptPresets::toolInstructionsPlaceholder(),
            'tool_instructions'
        );
    }

    /**
     * Add AI persona placeholder
     *
     * Replaced at runtime with AI persona information.
     */
    public function withAIPersona(): self
    {
        return $this->addSection(
            PromptPresets::aiPersonaPlaceholder(),
            'ai_persona'
        );
    }

    /**
     * Add knowledge-first emphasis placeholder
     *
     * Replaced at runtime with knowledge-first protocol.
     */
    public function withKnowledgeFirstEmphasisPlaceholder(): self
    {
        return $this->addSection(
            PromptPresets::knowledgeFirstEmphasisPlaceholder(),
            'knowledge_first_placeholder'
        );
    }

    /**
     * Add anti-hallucination protocol placeholder
     *
     * Replaced at runtime with anti-hallucination protocol.
     */
    public function withAntiHallucinationProtocolPlaceholder(): self
    {
        return $this->addSection(
            PromptPresets::antiHallucinationProtocolPlaceholder(),
            'anti_hallucination_placeholder'
        );
    }

    /**
     * Remove a section by key
     *
     * @param  string|int  $key  Section key
     */
    public function removeSection(string|int $key): self
    {
        unset($this->sections[$key]);

        return $this;
    }

    /**
     * Check if a section exists
     *
     * @param  string|int  $key  Section key
     * @return bool True if section exists
     */
    public function hasSection(string|int $key): bool
    {
        return isset($this->sections[$key]);
    }

    /**
     * Get a section by key
     *
     * @param  string|int  $key  Section key
     * @return string|null Section text or null if not found
     */
    public function getSection(string|int $key): ?string
    {
        return $this->sections[$key] ?? null;
    }

    /**
     * Get count of sections
     *
     * @return int Number of sections
     */
    public function count(): int
    {
        return count($this->sections);
    }

    /**
     * Clear all sections
     */
    public function clear(): self
    {
        $this->sections = [];
        $this->autoKey = 0;

        return $this;
    }

    /**
     * Build and return final prompt string
     *
     * Joins all sections with double newlines.
     *
     * @param  string  $separator  Section separator (default: "\n\n")
     * @return string Complete system prompt
     */
    public function build(string $separator = "\n\n"): string
    {
        return implode($separator, $this->sections);
    }

    /**
     * Build with custom separator
     *
     * @param  string  $separator  Section separator
     * @return string Complete system prompt
     */
    public function buildWith(string $separator): string
    {
        return $this->build($separator);
    }

    /**
     * Get all sections as array
     *
     * @return array<string|int, string> Sections
     */
    public function toArray(): array
    {
        return $this->sections;
    }

    /**
     * Create builder from sections array
     *
     * @param  array<string|int, string>  $sections  Sections
     * @return self New builder instance
     */
    public static function fromArray(array $sections): self
    {
        $builder = new self;
        $builder->sections = $sections;
        $builder->autoKey = count($sections);

        return $builder;
    }

    /**
     * Create builder with standard research prompt structure
     *
     * Includes: anti-hallucination, knowledge-first, research methodology,
     *           conversation context, tool instructions
     *
     * @param  string  $intro  Agent introduction text
     * @return self Builder instance
     */
    public static function forResearchAgent(string $intro): self
    {
        return (new self)
            ->addSection($intro, 'intro')
            ->withAntiHallucinationProtocol()
            ->withKnowledgeFirstEmphasis()
            ->withResearchMethodology()
            ->withConversationContext()
            ->withToolInstructions();
    }

    /**
     * Create builder with chat agent prompt structure
     *
     * Includes: conversation context, tool instructions
     *
     * @param  string  $intro  Agent introduction text
     * @return self Builder instance
     */
    public static function forChatAgent(string $intro): self
    {
        return (new self)
            ->addSection($intro, 'intro')
            ->withConversationContext()
            ->withToolInstructions();
    }
}
