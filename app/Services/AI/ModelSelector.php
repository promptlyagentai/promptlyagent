<?php

namespace App\Services\AI;

use InvalidArgumentException;
use Prism\Prism\Enums\Provider;

/**
 * Model Selector - Configuration-Driven AI Model Selection.
 *
 * Provides centralized AI model configuration with complexity-based selection
 * tiers. Enables easy provider/model switching via environment variables
 * without code changes.
 *
 * Complexity Profiles:
 * - **LOW_COST**: Fast, cheap models for simple tasks (summaries, titles)
 * - **STANDARD**: Balanced performance for general tasks (chat, research)
 * - **ADVANCED**: High capability for complex reasoning (workflow synthesis)
 *
 * Configuration Pattern:
 * - Environment variables control provider and model per profile
 * - Example: PRISM_LOW_COST_PROVIDER=anthropic, PRISM_LOW_COST_MODEL=claude-haiku-3
 * - Fallback chain: env → defaults → exception
 *
 * Provider Abstraction:
 * - Supports Anthropic, OpenAI, xAI via Prism providers
 * - Provider-agnostic interface for swappable backends
 *
 * @see \App\Services\AI\PrismWrapper
 * @see \Prism\Prism\Enums\Provider
 */
class ModelSelector
{
    /**
     * Available complexity profiles
     */
    public const LOW_COST = 'low_cost';

    public const MEDIUM = 'medium';

    public const COMPLEX = 'complex';

    /**
     * Get model configuration for a specific complexity tier
     */
    public function getModelProfile(string $complexity): array
    {
        $profiles = config('prism.model_profiles', []);

        if (! isset($profiles[$complexity])) {
            throw new InvalidArgumentException("Unknown model complexity profile: {$complexity}");
        }

        return $profiles[$complexity];
    }

    /**
     * Get low-cost model configuration (fast, cost-efficient)
     */
    public function getLowCostModel(): array
    {
        return $this->getModelProfile(self::LOW_COST);
    }

    /**
     * Get medium complexity model configuration (balanced)
     */
    public function getMediumModel(): array
    {
        return $this->getModelProfile(self::MEDIUM);
    }

    /**
     * Get complex reasoning model configuration (advanced)
     */
    public function getComplexModel(): array
    {
        return $this->getModelProfile(self::COMPLEX);
    }

    /**
     * Convert string provider name to Provider enum or string for custom providers
     */
    public function getProviderEnum(string $providerName): Provider|string
    {
        return match (strtolower($providerName)) {
            'openai' => Provider::OpenAI,
            'anthropic' => Provider::Anthropic,
            'bedrock' => 'bedrock',
            'google' => Provider::Google,
            'mistral' => Provider::Mistral,
            'groq' => Provider::Groq,
            'ollama' => Provider::Ollama,
            'xai' => Provider::XAI,
            'gemini' => Provider::Gemini,
            'deepseek' => Provider::DeepSeek,
            'voyageai' => Provider::VoyageAI,
            'openrouter' => Provider::OpenRouter,
            default => throw new InvalidArgumentException("Unknown provider: {$providerName}")
        };
    }

    /**
     * Get provider enum (or string) and model name for a complexity tier
     */
    public function getProviderAndModel(string $complexity): array
    {
        $profile = $this->getModelProfile($complexity);
        $provider = $this->getProviderEnum($profile['provider']);

        return [
            'provider' => $provider,
            'model' => $profile['model'],
            'max_tokens' => $profile['max_tokens'] ?? null,
        ];
    }

    /**
     * Check if a complexity profile is available
     */
    public function hasProfile(string $complexity): bool
    {
        $profiles = config('prism.model_profiles', []);

        return isset($profiles[$complexity]);
    }

    /**
     * Get all available complexity profiles
     */
    public function getAvailableProfiles(): array
    {
        return config('prism.model_profiles', []);
    }

    /**
     * Get description for a complexity profile
     */
    public function getProfileDescription(string $complexity): string
    {
        $profile = $this->getModelProfile($complexity);

        return $profile['description'] ?? '';
    }

    /**
     * Get max tokens for a complexity profile
     */
    public function getMaxTokens(string $complexity): ?int
    {
        $profile = $this->getModelProfile($complexity);

        return $profile['max_tokens'] ?? null;
    }
}
