<?php

namespace App\Services\Agents\Config\Presets;

use App\Services\AI\ModelSelector;

/**
 * AI Configuration Presets
 *
 * Provides reusable AI parameter configurations for common agent profiles.
 * Presets define temperature, top_p, max_tokens, and other provider-specific
 * parameters for different agent behaviors.
 *
 * **Available Presets:**
 * - **creative()** - High temperature for creative/exploratory tasks
 * - **precise()** - Low temperature for factual/analytical tasks
 * - **balanced()** - Medium temperature for general-purpose tasks
 * - **fast()** - Uses ModelSelector's fast/low-cost model
 * - **medium()** - Uses ModelSelector's medium model (default)
 * - **deepReasoning()** - Uses ModelSelector's complex reasoning model
 *
 * **Usage in Agent Configs:**
 * ```php
 * public function getAIConfig(): array
 * {
 *     $config = AIConfigPresets::balanced();
 *     return [
 *         'provider' => $config['provider'],
 *         'model' => $config['model'],
 *     ];
 * }
 *
 * public function getAIParameters(): ?array
 * {
 *     return AIConfigPresets::balanced()['parameters'];
 * }
 * ```
 *
 * **Customization:**
 * ```php
 * $config = AIConfigPresets::creative();
 * $config['parameters']['temperature'] = 0.9; // Override specific parameter
 * ```
 *
 * **Parameter Reference:**
 * - temperature: 0.0-1.0 (creativity/randomness)
 * - top_p: 0.0-1.0 (nucleus sampling threshold)
 * - max_tokens: Maximum response length
 * - frequency_penalty: 0.0-2.0 (reduce repetition)
 * - presence_penalty: 0.0-2.0 (encourage topic diversity)
 *
 * @see \App\Services\AI\ModelSelector
 */
class AIConfigPresets
{
    /**
     * Creative preset - High temperature for exploratory tasks
     *
     * Use for: Creative writing, brainstorming, ideation, open-ended research
     *
     * @return array{provider: string, model: string, parameters: array{temperature: float, top_p: float, max_tokens?: int}}
     */
    public static function creative(): array
    {
        $modelConfig = app(ModelSelector::class)->getMediumModel();

        return [
            'provider' => $modelConfig['provider'],
            'model' => $modelConfig['model'],
            'parameters' => [
                'temperature' => 0.8,
                'top_p' => 0.9,
                'max_tokens' => $modelConfig['max_tokens'] ?? null,
            ],
        ];
    }

    /**
     * Precise preset - Low temperature for factual/analytical tasks
     *
     * Use for: Research, fact-checking, technical analysis, data extraction
     *
     * @return array{provider: string, model: string, parameters: array{temperature: float, top_p: float, max_tokens?: int}}
     */
    public static function precise(): array
    {
        $modelConfig = app(ModelSelector::class)->getMediumModel();

        return [
            'provider' => $modelConfig['provider'],
            'model' => $modelConfig['model'],
            'parameters' => [
                'temperature' => 0.3,
                'top_p' => 0.85,
                'max_tokens' => $modelConfig['max_tokens'] ?? null,
            ],
        ];
    }

    /**
     * Balanced preset - Medium temperature for general-purpose tasks
     *
     * Use for: Chat, Q&A, general research, mixed creative/analytical tasks
     *
     * @return array{provider: string, model: string, parameters: array{temperature: float, top_p: float, max_tokens?: int}}
     */
    public static function balanced(): array
    {
        $modelConfig = app(ModelSelector::class)->getMediumModel();

        return [
            'provider' => $modelConfig['provider'],
            'model' => $modelConfig['model'],
            'parameters' => [
                'temperature' => 0.5,
                'top_p' => 0.9,
                'max_tokens' => $modelConfig['max_tokens'] ?? null,
            ],
        ];
    }

    /**
     * Fast preset - Uses fast/low-cost model for simple tasks
     *
     * Use for: Simple queries, titles, summaries, quick responses
     *
     * @return array{provider: string, model: string, parameters: array{temperature: float, top_p: float, max_tokens?: int}}
     */
    public static function fast(): array
    {
        $modelConfig = app(ModelSelector::class)->getLowCostModel();

        return [
            'provider' => $modelConfig['provider'],
            'model' => $modelConfig['model'],
            'parameters' => [
                'temperature' => 0.5,
                'top_p' => 0.9,
                'max_tokens' => $modelConfig['max_tokens'] ?? null,
            ],
        ];
    }

    /**
     * Medium preset - Uses medium model (default)
     *
     * Use for: Standard agent interactions, balanced cost/performance
     *
     * @return array{provider: string, model: string, parameters: array{temperature: float, top_p: float, max_tokens?: int}}
     */
    public static function medium(): array
    {
        $modelConfig = app(ModelSelector::class)->getMediumModel();

        return [
            'provider' => $modelConfig['provider'],
            'model' => $modelConfig['model'],
            'parameters' => [
                'temperature' => 0.5,
                'top_p' => 0.9,
                'max_tokens' => $modelConfig['max_tokens'] ?? null,
            ],
        ];
    }

    /**
     * Deep reasoning preset - Uses complex model for advanced reasoning
     *
     * Use for: Complex analysis, multi-step reasoning, workflow synthesis
     *
     * @return array{provider: string, model: string, parameters: array{temperature: float, top_p: float, max_tokens?: int}}
     */
    public static function deepReasoning(): array
    {
        $modelConfig = app(ModelSelector::class)->getComplexModel();

        return [
            'provider' => $modelConfig['provider'],
            'model' => $modelConfig['model'],
            'parameters' => [
                'temperature' => 0.4,
                'top_p' => 0.9,
                'max_tokens' => $modelConfig['max_tokens'] ?? null,
            ],
        ];
    }

    /**
     * Custom preset - Build custom configuration
     *
     * @param  string  $modelTier  Model tier (low_cost, medium, complex)
     * @param  float  $temperature  Temperature (0.0-1.0)
     * @param  float  $topP  Top-p (0.0-1.0)
     * @param  int|null  $maxTokens  Max tokens or null for default
     * @return array{provider: string, model: string, parameters: array{temperature: float, top_p: float, max_tokens?: int}}
     */
    public static function custom(
        string $modelTier = ModelSelector::MEDIUM,
        float $temperature = 0.5,
        float $topP = 0.9,
        ?int $maxTokens = null
    ): array {
        $modelConfig = app(ModelSelector::class)->getModelProfile($modelTier);

        return [
            'provider' => $modelConfig['provider'],
            'model' => $modelConfig['model'],
            'parameters' => [
                'temperature' => $temperature,
                'top_p' => $topP,
                'max_tokens' => $maxTokens ?? ($modelConfig['max_tokens'] ?? null),
            ],
        ];
    }

    /**
     * Get provider and model only (no parameters)
     *
     * @param  string  $modelTier  Model tier (low_cost, medium, complex)
     * @return array{provider: string, model: string}
     */
    public static function providerAndModel(string $modelTier = ModelSelector::MEDIUM): array
    {
        $modelConfig = app(ModelSelector::class)->getModelProfile($modelTier);

        return [
            'provider' => $modelConfig['provider'],
            'model' => $modelConfig['model'],
        ];
    }
}
