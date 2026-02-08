<?php

namespace App\Services\Agents\Config;

use App\Services\Agents\Config\Builders\SystemPromptBuilder;
use App\Services\Agents\Config\Builders\ToolConfigBuilder;

/**
 * Abstract Agent Configuration
 *
 * Base class defining the complete specification for an agent configuration.
 * Each agent extends this class to provide its unique settings, tools, and behavior.
 *
 * **Core Responsibilities:**
 * - Define agent identity (identifier, name, description)
 * - Specify system prompt (directly or via SystemPromptBuilder)
 * - Configure AI provider and parameters
 * - Define tool configuration (directly or via ToolConfigBuilder)
 * - Set agent metadata (type, visibility, streaming, etc.)
 * - Provide knowledge assignments (optional)
 * - Track configuration version for change management
 *
 * **Usage Patterns:**
 *
 * 1. Simple Override Pattern (direct configuration):
 * ```php
 * class SimpleAgentConfig extends AbstractAgentConfig
 * {
 *     public function getIdentifier(): string { return 'simple-agent'; }
 *     public function getName(): string { return 'Simple Agent'; }
 *     public function getSystemPrompt(): string { return 'You are a simple agent.'; }
 *     public function getAIConfig(): array { return ['provider' => 'openai', 'model' => 'gpt-4']; }
 * }
 * ```
 *
 * 2. Builder Pattern (composable configuration):
 * ```php
 * class AdvancedAgentConfig extends AbstractAgentConfig
 * {
 *     protected function getSystemPromptBuilder(): SystemPromptBuilder {
 *         return (new SystemPromptBuilder())
 *             ->addSection('You are an advanced agent.')
 *             ->withAntiHallucinationProtocol()
 *             ->withKnowledgeFirstEmphasis();
 *     }
 *
 *     protected function getToolConfigBuilder(): ToolConfigBuilder {
 *         return (new ToolConfigBuilder())
 *             ->withFullResearch()
 *             ->withArtifacts();
 *     }
 * }
 * ```
 *
 * **Configuration Output:**
 * The `toArray()` method converts the configuration into an array suitable for
 * Agent::create() or Agent::updateOrCreate(), including all agent properties
 * and metadata. Tool configuration is accessed separately via getToolConfiguration().
 *
 * **Version Tracking:**
 * Each configuration should increment its version when making significant changes.
 * This helps track configuration evolution and enables rollback strategies.
 *
 * @see \App\Services\Agents\Config\Builders\SystemPromptBuilder
 * @see \App\Services\Agents\Config\Builders\ToolConfigBuilder
 * @see \App\Services\Agents\Config\AgentConfigRegistry
 */
abstract class AbstractAgentConfig
{
    /**
     * Get unique identifier for the agent (slug)
     *
     * @return string Agent slug (e.g., 'research-assistant')
     */
    abstract public function getIdentifier(): string;

    /**
     * Get human-readable agent name
     *
     * @return string Agent display name (e.g., 'Research Assistant')
     */
    abstract public function getName(): string;

    /**
     * Get agent description for UI
     *
     * @return string Brief description of agent capabilities
     */
    abstract public function getDescription(): string;

    /**
     * Get system prompt (override this OR use getSystemPromptBuilder)
     *
     * @return string Complete system prompt
     */
    public function getSystemPrompt(): string
    {
        $builder = $this->getSystemPromptBuilder();

        return $builder ? $builder->build() : '';
    }

    /**
     * Get system prompt builder (override this OR getSystemPrompt)
     *
     * @return SystemPromptBuilder|null Prompt builder or null
     */
    protected function getSystemPromptBuilder(): ?SystemPromptBuilder
    {
        return null;
    }

    /**
     * Get AI configuration (provider and model)
     *
     * @return array{provider: string, model: string} AI provider config
     */
    abstract public function getAIConfig(): array;

    /**
     * Get AI parameters (temperature, max_tokens, etc.)
     *
     * @return array{temperature?: float, max_tokens?: int, top_p?: float, frequency_penalty?: float, presence_penalty?: float, stop?: array<string>}|null
     */
    public function getAIParameters(): ?array
    {
        return null;
    }

    /**
     * Get tool configuration (override this OR use getToolConfigBuilder)
     *
     * @return array<string, array> Tool name => configuration array
     */
    public function getToolConfiguration(): array
    {
        $builder = $this->getToolConfigBuilder();

        return $builder ? $builder->build() : [];
    }

    /**
     * Get tool configuration builder (override this OR getToolConfiguration)
     *
     * @return ToolConfigBuilder|null Tool builder or null
     */
    protected function getToolConfigBuilder(): ?ToolConfigBuilder
    {
        return null;
    }

    /**
     * Get knowledge configuration (optional)
     *
     * @return array{type: 'all'|'documents'|'tags', document_ids?: array<int>, tag_ids?: array<int>}|null
     */
    public function getKnowledgeConfig(): ?array
    {
        return null;
    }

    /**
     * Get workflow configuration (optional)
     *
     * @return array{steps?: array, parallel?: bool, aggregation?: string, enforce_link_validation?: bool, knowledge_first_strategy?: bool, credibility_scoring?: bool}|null
     */
    public function getWorkflowConfig(): ?array
    {
        return null;
    }

    /**
     * Get agent type
     *
     * @return string Agent type (direct, promptly, synthesizer, integration)
     */
    public function getAgentType(): string
    {
        return 'direct';
    }

    /**
     * Get maximum execution steps
     *
     * @return int Max steps before execution timeout
     */
    public function getMaxSteps(): int
    {
        return 25;
    }

    /**
     * Get agent status
     *
     * @return string Status (active, inactive)
     */
    public function getStatus(): string
    {
        return 'active';
    }

    /**
     * Check if agent is public
     *
     * @return bool True if available to all users
     */
    public function isPublic(): bool
    {
        return true;
    }

    /**
     * Check if agent shows in chat interface
     *
     * @return bool True if visible in chat selector
     */
    public function showInChat(): bool
    {
        return true;
    }

    /**
     * Check if agent is available for research workflows
     *
     * @return bool True if can be used by research orchestrators
     */
    public function isAvailableForResearch(): bool
    {
        return false;
    }

    /**
     * Check if streaming is enabled
     *
     * @return bool True if responses should stream in real-time
     */
    public function isStreamingEnabled(): bool
    {
        return true;
    }

    /**
     * Check if thinking/reasoning streaming is enabled
     *
     * @return bool True if thinking process should be streamed
     */
    public function isThinkingEnabled(): bool
    {
        return false;
    }

    /**
     * Check if response language enforcement is enabled
     *
     * @return bool True if agent should enforce user's language preference
     */
    public function enforceResponseLanguage(): bool
    {
        return false;
    }

    /**
     * Get configuration version
     *
     * @return string Version string (e.g., '1.0.0', '2.1.3')
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * Convert configuration to array for Agent::create/updateOrCreate
     *
     * @return array<string, mixed> Agent data array
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->getName(),
            'slug' => $this->getIdentifier(),
            'agent_type' => $this->getAgentType(),
            'description' => $this->getDescription(),
            'system_prompt' => $this->getSystemPrompt(),
            'status' => $this->getStatus(),
            'is_public' => $this->isPublic(),
            'show_in_chat' => $this->showInChat(),
            'available_for_research' => $this->isAvailableForResearch(),
            'streaming_enabled' => $this->isStreamingEnabled(),
            'thinking_enabled' => $this->isThinkingEnabled(),
            'enforce_response_language' => $this->enforceResponseLanguage(),
            'max_steps' => $this->getMaxSteps(),
        ];

        // Add AI configuration
        $aiConfig = $this->getAIConfig();
        $data['ai_provider'] = $aiConfig['provider'];
        $data['ai_model'] = $aiConfig['model'];

        // Add AI parameters if provided
        $aiParameters = $this->getAIParameters();
        if ($aiParameters) {
            $data['ai_config'] = $aiParameters;
        }

        // Add workflow configuration if provided
        $workflowConfig = $this->getWorkflowConfig();
        if ($workflowConfig) {
            $data['workflow_config'] = $workflowConfig;
        }

        return $data;
    }

    /**
     * Get agent categories/tags for filtering (optional)
     *
     * @return array<string> Category tags
     */
    public function getCategories(): array
    {
        return [];
    }

    /**
     * Check if this is a user-facing agent
     *
     * @return bool True if agent is intended for direct user interaction
     */
    public function isUserFacing(): bool
    {
        return $this->showInChat();
    }

    /**
     * Check if this is a system/internal agent
     *
     * @return bool True if agent is for internal workflows only
     */
    public function isSystemAgent(): bool
    {
        return ! $this->showInChat();
    }

    /**
     * Validate configuration (override for custom validation)
     *
     * @return array<string> Validation errors (empty if valid)
     */
    public function validate(): array
    {
        $errors = [];

        if (empty($this->getIdentifier())) {
            $errors[] = 'Identifier is required';
        }

        if (empty($this->getName())) {
            $errors[] = 'Name is required';
        }

        if (empty($this->getDescription())) {
            $errors[] = 'Description is required';
        }

        $aiConfig = $this->getAIConfig();
        if (empty($aiConfig['provider']) || empty($aiConfig['model'])) {
            $errors[] = 'AI provider and model are required';
        }

        return $errors;
    }
}
