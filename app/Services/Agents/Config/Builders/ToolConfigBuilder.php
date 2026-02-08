<?php

namespace App\Services\Agents\Config\Builders;

use App\Services\Agents\Config\Presets\ToolPresets;

/**
 * Tool Configuration Builder
 *
 * Fluent API for composing tool configurations for agents. Provides methods
 * for adding preset tool collections, individual tools, and modifying existing
 * tool configurations.
 *
 * **Usage:**
 * ```php
 * $tools = (new ToolConfigBuilder())
 *     ->withKnowledgeCore()
 *     ->withWebResearch()
 *     ->withArtifacts()
 *     ->overrideTool('searxng_search', ['config' => ['default_results' => 20]])
 *     ->removeTool('bulk_link_validator')
 *     ->build();
 * ```
 *
 * **Fluent Methods:**
 * - **addTool()** - Add single tool configuration
 * - **merge()** - Merge array of tool configurations
 * - **withKnowledgeCore()** - Add knowledge base tools
 * - **withWebResearch()** - Add web search and validation tools
 * - **withSourceTracking()** - Add research source tracking tools
 * - **withAttachments()** - Add attachment management tools
 * - **withArtifacts()** - Add artifact CRUD tools
 * - **withVisualization()** - Add diagram generation tools
 * - **withFullResearch()** - Add complete research stack
 * - **withQuickResearch()** - Add lightweight research tools
 * - **overrideTool()** - Modify specific tool configuration
 * - **removeTool()** - Remove tool from configuration
 * - **build()** - Return final tool configuration array
 *
 * **Chaining:**
 * All methods return $this to enable method chaining.
 *
 * @see \App\Services\Agents\Config\Presets\ToolPresets
 */
class ToolConfigBuilder
{
    /**
     * @var array<string, array> Tool configurations
     */
    protected array $tools = [];

    /**
     * Add a single tool configuration
     *
     * @param  string  $name  Tool name
     * @param  array  $config  Tool configuration
     */
    public function addTool(string $name, array $config): self
    {
        $this->tools[$name] = $config;

        return $this;
    }

    /**
     * Merge tool configurations
     *
     * @param  array<string, array>  $tools  Tool configurations to merge
     */
    public function merge(array $tools): self
    {
        $this->tools = array_merge($this->tools, $tools);

        return $this;
    }

    /**
     * Add knowledge core tools
     *
     * Tools: knowledge_search, retrieve_full_document, list_knowledge_documents
     */
    public function withKnowledgeCore(): self
    {
        return $this->merge(ToolPresets::knowledgeCore());
    }

    /**
     * Add web research tools
     *
     * Tools: searxng_search, bulk_link_validator, link_validator, markitdown
     */
    public function withWebResearch(): self
    {
        return $this->merge(ToolPresets::webResearch());
    }

    /**
     * Add source tracking tools
     *
     * Tools: research_sources, source_content, chat_interaction_lookup
     */
    public function withSourceTracking(): self
    {
        return $this->merge(ToolPresets::sourceTracking());
    }

    /**
     * Add attachment tools
     *
     * Tools: list_chat_attachments, create_chat_attachment
     */
    public function withAttachments(): self
    {
        return $this->merge(ToolPresets::attachments());
    }

    /**
     * Add artifact tools
     *
     * Tools: Full artifact CRUD (9 tools)
     */
    public function withArtifacts(): self
    {
        return $this->merge(ToolPresets::artifacts());
    }

    /**
     * Add visualization tools
     *
     * Tools: generate_mermaid_diagram
     */
    public function withVisualization(): self
    {
        return $this->merge(ToolPresets::visualization());
    }

    /**
     * Add full research stack
     *
     * Includes: knowledge core, web research, source tracking, attachments,
     *           artifacts, visualization
     */
    public function withFullResearch(): self
    {
        return $this->merge(ToolPresets::fullResearch());
    }

    /**
     * Add quick research stack
     *
     * Includes: knowledge core, basic web search, visualization
     */
    public function withQuickResearch(): self
    {
        return $this->merge(ToolPresets::quickResearch());
    }

    /**
     * Override specific tool configuration
     *
     * Merges overrides into existing tool config. If tool doesn't exist,
     * this method has no effect.
     *
     * @param  string  $name  Tool name
     * @param  array  $overrides  Configuration overrides
     */
    public function overrideTool(string $name, array $overrides): self
    {
        if (isset($this->tools[$name])) {
            $this->tools[$name] = array_merge($this->tools[$name], $overrides);

            // Handle nested config array
            if (isset($overrides['config']) && isset($this->tools[$name]['config'])) {
                $this->tools[$name]['config'] = array_merge(
                    $this->tools[$name]['config'],
                    $overrides['config']
                );
            }
        }

        return $this;
    }

    /**
     * Disable a specific tool
     *
     * Sets enabled = false for the tool. If tool doesn't exist,
     * this method has no effect.
     *
     * @param  string  $name  Tool name
     */
    public function disableTool(string $name): self
    {
        if (isset($this->tools[$name])) {
            $this->tools[$name]['enabled'] = false;
        }

        return $this;
    }

    /**
     * Enable a specific tool
     *
     * Sets enabled = true for the tool. If tool doesn't exist,
     * this method has no effect.
     *
     * @param  string  $name  Tool name
     */
    public function enableTool(string $name): self
    {
        if (isset($this->tools[$name])) {
            $this->tools[$name]['enabled'] = true;
        }

        return $this;
    }

    /**
     * Remove a tool from configuration
     *
     * @param  string  $name  Tool name
     */
    public function removeTool(string $name): self
    {
        unset($this->tools[$name]);

        return $this;
    }

    /**
     * Remove multiple tools from configuration
     *
     * @param  array<string>  $names  Tool names to remove
     */
    public function removeTools(array $names): self
    {
        foreach ($names as $name) {
            $this->removeTool($name);
        }

        return $this;
    }

    /**
     * Set execution order for a tool
     *
     * @param  string  $name  Tool name
     * @param  int  $order  Execution order
     */
    public function setExecutionOrder(string $name, int $order): self
    {
        if (isset($this->tools[$name])) {
            $this->tools[$name]['execution_order'] = $order;
        }

        return $this;
    }

    /**
     * Set priority level for a tool
     *
     * @param  string  $name  Tool name
     * @param  string  $priority  Priority level (preferred, standard, low)
     */
    public function setPriority(string $name, string $priority): self
    {
        if (isset($this->tools[$name])) {
            $this->tools[$name]['priority_level'] = $priority;
        }

        return $this;
    }

    /**
     * Check if a tool exists in the configuration
     *
     * @param  string  $name  Tool name
     * @return bool True if tool exists
     */
    public function hasTool(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * Get count of configured tools
     *
     * @return int Number of tools
     */
    public function count(): int
    {
        return count($this->tools);
    }

    /**
     * Get list of configured tool names
     *
     * @return array<string> Tool names
     */
    public function getToolNames(): array
    {
        return array_keys($this->tools);
    }

    /**
     * Clear all tool configurations
     */
    public function clear(): self
    {
        $this->tools = [];

        return $this;
    }

    /**
     * Build and return final tool configuration array
     *
     * @return array<string, array> Tool configurations
     */
    public function build(): array
    {
        return $this->tools;
    }

    /**
     * Create a new builder with preset tools
     *
     * @param  string  $preset  Preset name (full, quick, knowledge, web, etc.)
     * @return self New builder instance
     */
    public static function withPreset(string $preset): self
    {
        $builder = new self;

        return match ($preset) {
            'full' => $builder->withFullResearch(),
            'fullResearch' => $builder->withFullResearch(),
            'quick' => $builder->withQuickResearch(),
            'quickResearch' => $builder->withQuickResearch(),
            'knowledge' => $builder->withKnowledgeCore(),
            'knowledgeCore' => $builder->withKnowledgeCore(),
            'web' => $builder->withWebResearch(),
            'webResearch' => $builder->withWebResearch(),
            'sources' => $builder->withSourceTracking(),
            'sourceTracking' => $builder->withSourceTracking(),
            'attachments' => $builder->withAttachments(),
            'artifacts' => $builder->withArtifacts(),
            'visualization' => $builder->withVisualization(),
            default => $builder,
        };
    }
}
