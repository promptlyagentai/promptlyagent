<?php

namespace App\Traits;

use App\Services\AI\ModelSelector;
use App\Services\AI\PrismWrapper;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\ValueObjects\PartialCompletion;

/**
 * Trait for consistent AI model usage across the application.
 *
 * Provides convenient methods for using standardized model profiles
 * with Prism while maintaining DRY principles.
 */
trait UsesAIModels
{
    /**
     * Get the ModelSelector service instance
     */
    protected function getModelSelector(): ModelSelector
    {
        return app(ModelSelector::class);
    }

    /**
     * Create Prism instance configured with low-cost model using PrismWrapper
     */
    protected function useLowCostModel(): PartialCompletion|PendingRequest|PrismWrapper
    {
        $config = $this->getModelSelector()->getProviderAndModel(ModelSelector::LOW_COST);

        $prism = app(\App\Services\AI\PrismWrapper::class)
            ->text()
            ->using($config['provider'], $config['model'])
            ->withContext([
                'profile' => ModelSelector::LOW_COST,
                'source' => static::class,
            ]);

        if (! empty($config['max_tokens'])) {
            $prism = $prism->withMaxTokens($config['max_tokens']);
        }

        return $prism;
    }

    /**
     * Create Prism instance configured with medium complexity model using PrismWrapper
     */
    protected function useMediumModel(): PartialCompletion|PendingRequest|PrismWrapper
    {
        $config = $this->getModelSelector()->getProviderAndModel(ModelSelector::MEDIUM);

        $prism = app(\App\Services\AI\PrismWrapper::class)
            ->text()
            ->using($config['provider'], $config['model'])
            ->withContext([
                'profile' => ModelSelector::MEDIUM,
                'source' => static::class,
            ]);

        if (! empty($config['max_tokens'])) {
            $prism = $prism->withMaxTokens($config['max_tokens']);
        }

        return $prism;
    }

    /**
     * Create Prism instance configured with complex reasoning model using PrismWrapper
     */
    protected function useComplexModel(): PartialCompletion|PendingRequest|PrismWrapper
    {
        $config = $this->getModelSelector()->getProviderAndModel(ModelSelector::COMPLEX);

        $prism = app(\App\Services\AI\PrismWrapper::class)
            ->text()
            ->using($config['provider'], $config['model'])
            ->withContext([
                'profile' => ModelSelector::COMPLEX,
                'source' => static::class,
            ]);

        if (! empty($config['max_tokens'])) {
            $prism = $prism->withMaxTokens($config['max_tokens']);
        }

        return $prism;
    }

    /**
     * Create Prism instance with a specific complexity profile using PrismWrapper
     */
    protected function useModelProfile(string $complexity): PartialCompletion|PendingRequest|PrismWrapper
    {
        $config = $this->getModelSelector()->getProviderAndModel($complexity);

        $prism = app(\App\Services\AI\PrismWrapper::class)
            ->text()
            ->using($config['provider'], $config['model'])
            ->withContext([
                'profile' => $complexity,
                'source' => static::class,
            ]);

        if (! empty($config['max_tokens'])) {
            $prism = $prism->withMaxTokens($config['max_tokens']);
        }

        return $prism;
    }

    /**
     * Get model configuration for a specific complexity tier
     */
    protected function getModelConfig(string $complexity): array
    {
        return $this->getModelSelector()->getModelProfile($complexity);
    }

    /**
     * Get provider and model name for a complexity tier (for direct Prism usage)
     */
    protected function getProviderAndModelName(string $complexity): array
    {
        return $this->getModelSelector()->getProviderAndModel($complexity);
    }
}
