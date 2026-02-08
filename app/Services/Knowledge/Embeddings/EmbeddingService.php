<?php

namespace App\Services\Knowledge\Embeddings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;

/**
 * AI Embedding Generation Service
 *
 * Generates semantic vector embeddings for text content using various AI providers (OpenAI, Voyage, Bedrock, etc.).
 * Handles text preprocessing, chunking, batching, caching, and rate limiting for embedding operations.
 *
 * Architecture:
 * - Provider Support: OpenAI, Anthropic, Bedrock (string), Mistral, Groq, Ollama, Voyage, XAI, DeepSeek
 * - Caching Strategy: MD5-based cache keys with configurable TTL to reduce API costs
 * - Chunking: Splits large texts with overlap to preserve context across boundaries
 * - Batching: Processes multiple texts efficiently with configurable batch size
 * - Rate Limiting: Exponential backoff retry logic with configurable max retries
 *
 * Embedding Models & Dimensions:
 * - OpenAI text-embedding-3-small: 1536 dimensions (default)
 * - Voyage models: 1536 dimensions
 * - Ollama models: 768 dimensions
 * - Bedrock (Titan): Custom dimensions from config
 *
 * Configuration (config/knowledge.embeddings):
 * - enabled: Toggle embedding generation
 * - provider: AI provider name
 * - model: Specific model to use
 * - cache_ttl: Cache duration in seconds
 * - batch_size: Number of texts per batch
 * - max_retries: Retry attempts for failed requests
 *
 * Chunking Strategy:
 * - Default chunk size: 1000 characters
 * - Default overlap: 200 characters (preserves context)
 * - Smart boundary detection: Breaks at sentence/word boundaries when possible
 * - Averaging: Multiple chunk embeddings averaged into single document vector
 *
 * @see config/knowledge.php
 */
class EmbeddingService
{
    protected array $config;

    protected Provider|string $provider;

    protected string $model;

    protected int $dimensions;

    public function __construct()
    {
        $this->config = config('knowledge.embeddings');
        $this->setProvider($this->config['provider']);
        $this->model = $this->config['model'];
        $this->dimensions = $this->getModelDimensions();
    }

    /**
     * Generate embeddings for a single text input
     */
    public function generateEmbedding(string $text): array
    {
        if (! $this->config['enabled']) {
            return [];
        }

        // Check cache first
        $cacheKey = $this->getCacheKey($text);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $response = app(\App\Services\AI\PrismWrapper::class)
                ->embeddings()
                ->using($this->provider, $this->model)
                ->fromInput($this->preprocessText($text))
                ->withContext([
                    'operation' => 'generate_embedding',
                    'text_length' => strlen($text),
                    'source' => 'EmbeddingService::generate',
                ])
                ->asEmbeddings();

            $embedding = $response->embeddings[0]->embedding;

            // Cache the result
            Cache::put($cacheKey, $embedding, $this->config['cache_ttl']);

            return $embedding;

        } catch (PrismException $e) {
            Log::error('EmbeddingService: Failed to generate embedding', [
                'provider' => is_string($this->provider) ? $this->provider : $this->provider->value,
                'model' => $this->model,
                'error' => $e->getMessage(),
                'text_preview' => substr($text, 0, 100),
            ]);

            return [];
        }
    }

    /**
     * Generate embeddings for multiple texts in batches
     */
    public function generateBatchEmbeddings(array $texts): array
    {
        if (! $this->config['enabled'] || empty($texts)) {
            return [];
        }

        $embeddings = [];
        $batchSize = $this->config['batch_size'];

        // Process in batches to avoid API limits
        $batches = array_chunk($texts, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            $batchEmbeddings = $this->processBatch($batch);
            $embeddings = array_merge($embeddings, $batchEmbeddings);

            // Small delay between batches to avoid rate limits
            if ($batchIndex < count($batches) - 1) {
                usleep(100000); // 100ms delay
            }
        }

        return $embeddings;
    }

    /**
     * Generate embeddings for a long text by chunking it with overlap
     */
    public function generateChunkedEmbeddings(string $text, int $chunkSize = 1000, int $overlap = 200): array
    {
        if (! $this->config['enabled'] || empty($text)) {
            return [];
        }

        $chunks = $this->chunkText($text, $chunkSize, $overlap);

        if (empty($chunks)) {
            return [];
        }

        // Generate embeddings for each chunk
        $chunkEmbeddings = [];
        foreach ($chunks as $index => $chunk) {
            $embedding = $this->generateEmbedding($chunk);
            if (! empty($embedding)) {
                $chunkEmbeddings[] = $embedding;
            }
        }

        if (empty($chunkEmbeddings)) {
            return [];
        }

        // Average all chunk embeddings to create a single document embedding
        $averagedEmbedding = $this->averageEmbeddings($chunkEmbeddings);

        Log::info('EmbeddingService: Successfully generated chunked embeddings', [
            'text_length' => strlen($text),
            'chunk_count' => count($chunks),
            'embedding_chunks_generated' => count($chunkEmbeddings),
            'final_embedding_dimensions' => count($averagedEmbedding),
        ]);

        return $averagedEmbedding;
    }

    /**
     * Split text into chunks with overlap
     */
    public function chunkText(string $text, int $chunkSize, int $overlap): array
    {
        if (strlen($text) <= $chunkSize) {
            return [$text];
        }

        $chunks = [];
        $position = 0;
        $textLength = strlen($text);

        while ($position < $textLength) {
            // Extract chunk
            $chunk = substr($text, $position, $chunkSize);

            // If this isn't the last chunk, try to break at a sentence or word boundary
            if ($position + $chunkSize < $textLength) {
                $chunk = $this->breakAtBoundary($chunk);
            }

            $chunks[] = trim($chunk);

            // Move position forward, accounting for overlap
            $position += $chunkSize - $overlap;

            // Prevent infinite loop
            if ($position <= 0) {
                $position = $chunkSize;
            }
        }

        return array_filter($chunks, fn ($chunk) => ! empty(trim($chunk)));
    }

    /**
     * Average multiple embeddings into a single embedding
     */
    protected function averageEmbeddings(array $embeddings): array
    {
        if (empty($embeddings)) {
            return [];
        }

        if (count($embeddings) === 1) {
            return $embeddings[0];
        }

        $dimensions = count($embeddings[0]);
        $averaged = array_fill(0, $dimensions, 0.0);

        // Sum all embeddings
        foreach ($embeddings as $embedding) {
            for ($i = 0; $i < $dimensions; $i++) {
                $averaged[$i] += $embedding[$i];
            }
        }

        // Average by dividing by count
        $count = count($embeddings);
        for ($i = 0; $i < $dimensions; $i++) {
            $averaged[$i] /= $count;
        }

        return $averaged;
    }

    /**
     * Try to break chunk at sentence or word boundary
     */
    protected function breakAtBoundary(string $chunk): string
    {
        // Try to break at sentence boundary first
        if (preg_match('/^(.*[.!?])\s+/', $chunk, $matches)) {
            return $matches[1];
        }

        // Try to break at word boundary
        if (preg_match('/^(.*)\s+\S*$/', $chunk, $matches)) {
            return $matches[1];
        }

        // Fallback: return original chunk
        return $chunk;
    }

    /**
     * Get similarity score between two embeddings
     */
    public function cosineSimilarity(array $embedding1, array $embedding2): float
    {
        if (count($embedding1) !== count($embedding2)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $magnitude1 = 0.0;
        $magnitude2 = 0.0;

        for ($i = 0; $i < count($embedding1); $i++) {
            $dotProduct += $embedding1[$i] * $embedding2[$i];
            $magnitude1 += $embedding1[$i] * $embedding1[$i];
            $magnitude2 += $embedding2[$i] * $embedding2[$i];
        }

        if ($magnitude1 == 0.0 || $magnitude2 == 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($magnitude1) * sqrt($magnitude2));
    }

    /**
     * Get embedding dimensions for the current model
     */
    public function getDimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * Get the current provider
     */
    public function getProvider(): Provider|string
    {
        return $this->provider;
    }

    /**
     * Get the current model
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Check if embeddings are enabled
     */
    public function isEnabled(): bool
    {
        return $this->config['enabled'];
    }

    /**
     * Set the embedding provider
     * Note: Bedrock is handled as a string provider, not in the Provider enum
     */
    protected function setProvider(string $providerName): void
    {
        // Bedrock is a custom provider that uses string 'bedrock', not a Provider enum
        $this->provider = match (strtolower($providerName)) {
            'openai' => Provider::OpenAI,
            'anthropic' => Provider::Anthropic,
            'bedrock' => 'bedrock', // Bedrock uses string provider
            'mistral' => Provider::Mistral,
            'groq' => Provider::Groq,
            'ollama' => Provider::Ollama,
            'voyage' => Provider::VoyageAI,
            'xai' => Provider::XAI,
            'deepseek' => Provider::DeepSeek,
            default => Provider::OpenAI
        };
    }

    /**
     * Get model dimensions from configuration
     */
    protected function getModelDimensions(): int
    {
        $providerName = strtolower($this->config['provider']);
        $modelConfig = $this->config['models'][$providerName][$this->model] ?? null;

        if ($modelConfig && isset($modelConfig['dimensions'])) {
            return $modelConfig['dimensions'];
        }

        // Default dimensions based on provider
        return match ($this->provider) {
            Provider::OpenAI => 1536,
            Provider::Voyage => 1536,
            Provider::Ollama => 768,
            default => 1536
        };
    }

    /**
     * Process a batch of texts for embedding
     */
    protected function processBatch(array $batch): array
    {
        $embeddings = [];

        foreach ($batch as $index => $text) {
            // Check cache first
            $cacheKey = $this->getCacheKey($text);
            if ($cached = Cache::get($cacheKey)) {
                $embeddings[] = $cached;

                continue;
            }

            // Generate new embedding with retry logic
            $embedding = $this->generateWithRetry($text);
            if (! empty($embedding)) {
                $embeddings[] = $embedding;
                Cache::put($cacheKey, $embedding, $this->config['cache_ttl']);
            } else {
                $embeddings[] = [];
            }
        }

        return $embeddings;
    }

    /**
     * Generate embedding with retry logic
     */
    protected function generateWithRetry(string $text, int $attempts = 0): array
    {
        try {
            $response = app(\App\Services\AI\PrismWrapper::class)
                ->embeddings()
                ->using($this->provider, $this->model)
                ->fromInput($this->preprocessText($text))
                ->withContext([
                    'operation' => 'generate_embedding_retry',
                    'text_length' => strlen($text),
                    'source' => 'EmbeddingService::generateWithRetry',
                    'attempt' => $attempts + 1,
                ])
                ->asEmbeddings();

            return $response->embeddings[0]->embedding;

        } catch (PrismException $e) {
            $attempts++;

            if ($attempts < $this->config['max_retries']) {
                Log::warning('EmbeddingService: Retrying embedding generation', [
                    'attempt' => $attempts,
                    'max_retries' => $this->config['max_retries'],
                    'error' => $e->getMessage(),
                ]);

                // Exponential backoff
                sleep(pow(2, $attempts));

                return $this->generateWithRetry($text, $attempts);
            }

            Log::error('EmbeddingService: Max retries exceeded', [
                'attempts' => $attempts,
                'error' => $e->getMessage(),
                'text_preview' => substr($text, 0, 100),
            ]);

            return [];
        }
    }

    /**
     * Preprocess text before generating embeddings
     */
    protected function preprocessText(string $text): string
    {
        // Remove excessive whitespace
        $text = preg_replace('/\s+/', ' ', trim($text));

        // Normalize Unicode
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        // Validate text length - should never reach here with oversized content
        // If it does, it means chunking logic upstream failed
        $maxTokens = $this->getMaxTokens();
        $maxChars = $maxTokens * 3; // Conservative: 1 token ≈ 3 characters
        $estimatedTokens = strlen($text) / 3;

        if (strlen($text) > $maxChars) {
            Log::error('EmbeddingService: Text exceeds token limit in preprocessText - this should have been handled by chunking', [
                'text_length' => strlen($text),
                'estimated_tokens' => round($estimatedTokens),
                'max_tokens' => $maxTokens,
                'max_chars' => $maxChars,
                'text_preview' => substr($text, 0, 200),
                'trace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
            ]);

            throw new \Exception(
                "Text content is too large for embedding ({$estimatedTokens} estimated tokens vs {$maxTokens} max). ".
                'This should have been handled by chunking upstream. Content length: '.strlen($text)
            );
        }

        return $text;
    }

    /**
     * Get maximum tokens for the current model
     */
    public function getMaxTokens(): int
    {
        $providerName = strtolower($this->config['provider']);
        $modelConfig = $this->config['models'][$providerName][$this->model] ?? null;

        if ($modelConfig && isset($modelConfig['max_tokens'])) {
            return $modelConfig['max_tokens'];
        }

        return 8191; // Default for OpenAI models
    }

    /**
     * Generate cache key for text
     */
    protected function getCacheKey(string $text): string
    {
        $providerKey = is_string($this->provider) ? $this->provider : $this->provider->value;

        return 'embedding:'.$providerKey.':'.$this->model.':'.md5($text);
    }
}
