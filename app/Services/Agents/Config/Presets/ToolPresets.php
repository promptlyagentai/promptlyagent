<?php

namespace App\Services\Agents\Config\Presets;

/**
 * Tool Configuration Presets
 *
 * Provides reusable tool configuration collections for common agent patterns.
 * Each preset returns an array of tool configurations with execution order,
 * priority levels, execution strategies, and tool-specific settings.
 *
 * **Tool Configuration Structure:**
 * ```php
 * 'tool_name' => [
 *     'enabled' => true|false,
 *     'execution_order' => int,         // Lower = earlier execution
 *     'priority_level' => string,       // 'preferred', 'standard', 'low'
 *     'execution_strategy' => string,   // 'always', 'if_no_preferred_results', 'adaptive'
 *     'min_results_threshold' => int,   // Minimum results before moving to next tool
 *     'max_execution_time' => int,      // Timeout in milliseconds
 *     'config' => array                 // Tool-specific configuration
 * ]
 * ```
 *
 * **Available Presets:**
 * - **knowledgeCore()** - Internal knowledge base access (search, retrieve, list)
 * - **webResearch()** - External web search and validation
 * - **sourceTracking()** - Previous research and source management
 * - **attachments()** - Multi-media attachment management
 * - **artifacts()** - Full artifact CRUD operations
 * - **visualization()** - Mermaid diagram generation
 * - **fullResearch()** - Complete research stack (combines all above)
 * - **quickResearch()** - Lightweight research (knowledge + basic web)
 *
 * **Usage in Agent Configs:**
 * ```php
 * public function getToolConfiguration(): array
 * {
 *     return array_merge(
 *         ToolPresets::knowledgeCore(),
 *         ToolPresets::webResearch(),
 *         ToolPresets::artifacts()
 *     );
 * }
 * ```
 *
 * @see \App\Services\Agents\Config\Builders\ToolConfigBuilder for fluent API
 */
class ToolPresets
{
    /**
     * Knowledge core tools - Internal knowledge base access
     *
     * Tools: knowledge_search, retrieve_full_document, list_knowledge_documents
     *
     * @return array<string, array> Tool configurations
     */
    public static function knowledgeCore(): array
    {
        return [
            'knowledge_search' => [
                'enabled' => true,
                'execution_order' => 10,
                'priority_level' => 'preferred',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 15000,
                'config' => [
                    'relevance_threshold' => 0.3,
                    'credibility_weight' => 0.9,
                ],
            ],
            'retrieve_full_document' => [
                'enabled' => true,
                'execution_order' => 15,
                'priority_level' => 'preferred',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 15000,
                'config' => [],
            ],
            'list_knowledge_documents' => [
                'enabled' => true,
                'execution_order' => 16,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 15000,
                'config' => [],
            ],
        ];
    }

    /**
     * Web research tools - External search and content extraction
     *
     * Tools: searxng_search, bulk_link_validator, link_validator, markitdown
     *
     * @return array<string, array> Tool configurations
     */
    public static function webResearch(): array
    {
        return [
            'searxng_search' => [
                'enabled' => true,
                'execution_order' => 20,
                'priority_level' => 'standard',
                'execution_strategy' => 'if_no_preferred_results',
                'min_results_threshold' => 5,
                'max_execution_time' => 45000,
                'config' => [
                    'credibility_weight' => 0.7,
                    'default_results' => 15,
                ],
            ],
            'bulk_link_validator' => [
                'enabled' => true,
                'execution_order' => 30,
                'priority_level' => 'preferred',
                'execution_strategy' => 'always',
                'min_results_threshold' => 8,
                'max_execution_time' => 45000,
                'config' => [
                    'batch_size' => 30,
                    'validate_minimum' => 8,
                    'validate_maximum' => 12,
                    'retry_logic' => true,
                ],
            ],
            'link_validator' => [
                'enabled' => true,
                'execution_order' => 40,
                'priority_level' => 'standard',
                'execution_strategy' => 'if_no_preferred_results',
                'min_results_threshold' => 1,
                'max_execution_time' => 30000,
                'config' => [],
            ],
            'markitdown' => [
                'enabled' => true,
                'execution_order' => 50,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 4,
                'max_execution_time' => 30000,
                'config' => [
                    'target_sources' => 6,
                ],
            ],
        ];
    }

    /**
     * Source tracking tools - Previous research and source management
     *
     * Tools: research_sources, source_content, chat_interaction_lookup
     *
     * @return array<string, array> Tool configurations
     */
    public static function sourceTracking(): array
    {
        return [
            'research_sources' => [
                'enabled' => true,
                'execution_order' => 100,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 15000,
                'config' => [],
            ],
            'source_content' => [
                'enabled' => true,
                'execution_order' => 110,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 20000,
                'config' => [],
            ],
            'chat_interaction_lookup' => [
                'enabled' => true,
                'execution_order' => 120,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 15000,
                'config' => [],
            ],
        ];
    }

    /**
     * Attachment tools - Multi-media support
     *
     * Tools: list_chat_attachments, create_chat_attachment
     *
     * @return array<string, array> Tool configurations
     */
    public static function attachments(): array
    {
        return [
            'list_chat_attachments' => [
                'enabled' => true,
                'execution_order' => 121,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 15000,
                'config' => [],
            ],
            'create_chat_attachment' => [
                'enabled' => true,
                'execution_order' => 122,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 30000,
                'config' => [],
            ],
        ];
    }

    /**
     * Artifact tools - Full CRUD operations
     *
     * Tools: list_artifacts, read_artifact, create_artifact, append_artifact_content,
     *        update_artifact_content, patch_artifact_content, insert_artifact_content,
     *        update_artifact_metadata, delete_artifact
     *
     * @return array<string, array> Tool configurations
     */
    public static function artifacts(): array
    {
        return [
            'list_artifacts' => [
                'enabled' => true,
                'execution_order' => 130,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 15000,
                'config' => [],
            ],
            'read_artifact' => [
                'enabled' => true,
                'execution_order' => 140,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 15000,
                'config' => [],
            ],
            'create_artifact' => [
                'enabled' => true,
                'execution_order' => 150,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 20000,
                'config' => [],
            ],
            'append_artifact_content' => [
                'enabled' => true,
                'execution_order' => 160,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 20000,
                'config' => [],
            ],
            'update_artifact_content' => [
                'enabled' => true,
                'execution_order' => 165,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 20000,
                'config' => [],
            ],
            'patch_artifact_content' => [
                'enabled' => true,
                'execution_order' => 170,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 20000,
                'config' => [],
            ],
            'insert_artifact_content' => [
                'enabled' => true,
                'execution_order' => 180,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 20000,
                'config' => [],
            ],
            'update_artifact_metadata' => [
                'enabled' => true,
                'execution_order' => 190,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 15000,
                'config' => [],
            ],
            'delete_artifact' => [
                'enabled' => true,
                'execution_order' => 200,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 15000,
                'config' => [],
            ],
        ];
    }

    /**
     * Visualization tools - Mermaid diagram generation
     *
     * Tools: generate_mermaid_diagram
     *
     * @return array<string, array> Tool configurations
     */
    public static function visualization(): array
    {
        return [
            'generate_mermaid_diagram' => [
                'enabled' => true,
                'execution_order' => 60,
                'priority_level' => 'standard',
                'execution_strategy' => 'always',
                'min_results_threshold' => 1,
                'max_execution_time' => 30000,
                'config' => [],
            ],
        ];
    }

    /**
     * Full research stack - All research, source tracking, and content tools
     *
     * Combines: knowledgeCore, webResearch, sourceTracking, attachments,
     *           artifacts, visualization
     *
     * @return array<string, array> Tool configurations
     */
    public static function fullResearch(): array
    {
        return array_merge(
            self::knowledgeCore(),
            self::webResearch(),
            self::sourceTracking(),
            self::attachments(),
            self::artifacts(),
            self::visualization()
        );
    }

    /**
     * Quick research stack - Lightweight research without full toolchain
     *
     * Combines: knowledgeCore, basic web search (no validation), visualization
     *
     * @return array<string, array> Tool configurations
     */
    public static function quickResearch(): array
    {
        return array_merge(
            self::knowledgeCore(),
            [
                'searxng_search' => [
                    'enabled' => true,
                    'execution_order' => 20,
                    'priority_level' => 'standard',
                    'execution_strategy' => 'if_no_preferred_results',
                    'min_results_threshold' => 5,
                    'max_execution_time' => 30000,
                    'config' => [
                        'credibility_weight' => 0.7,
                        'default_results' => 10,
                    ],
                ],
            ],
            self::visualization()
        );
    }

    /**
     * Custom tool configuration
     *
     * @param  string  $name  Tool name
     * @param  int  $executionOrder  Execution order
     * @param  string  $priorityLevel  Priority level (preferred, standard, low)
     * @param  string  $executionStrategy  Execution strategy
     * @param  array  $config  Tool-specific configuration
     * @return array<string, array> Tool configuration
     */
    public static function custom(
        string $name,
        int $executionOrder = 100,
        string $priorityLevel = 'standard',
        string $executionStrategy = 'always',
        array $config = []
    ): array {
        return [
            $name => [
                'enabled' => true,
                'execution_order' => $executionOrder,
                'priority_level' => $priorityLevel,
                'execution_strategy' => $executionStrategy,
                'min_results_threshold' => 1,
                'max_execution_time' => 15000,
                'config' => $config,
            ],
        ];
    }
}
