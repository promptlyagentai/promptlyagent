<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Prism;
use Throwable;

/**
 * Prism Wrapper - Enhanced Error Logging and Provider Customization.
 *
 * Provides centralized Prism AI call orchestration with comprehensive error
 * logging, exception chain traversal, and provider-specific customizations.
 * Integrates with ModelSelector for consistent model configuration.
 *
 * Error Logging Strategy:
 * - Traverses full exception chain to find root cause
 * - Logs provider errors, HTTP errors, validation errors separately
 * - Includes context: model, provider, messages, error chain
 * - Critical for debugging API failures and rate limits
 *
 * Provider Customization Hooks:
 * - applyProviderSpecificSettings(): Hook for per-provider config
 * - Example: Anthropic thinking budget, OpenAI temperature defaults
 *
 * @see \App\Services\AI\ModelSelector
 * @see \Prism\Prism\Prism
 */
class PrismWrapper
{
    /**
     * Underlying Prism pending request instance
     * Can be Text\PendingRequest, Structured\PendingRequest, or Embeddings\PendingRequest
     */
    protected mixed $prism = null;

    /**
     * Provider being used (for logging)
     * Can be null for string providers like 'bedrock'
     */
    protected ?Provider $provider = null;

    /**
     * Model being used (for logging)
     */
    protected ?string $model = null;

    /**
     * Max steps configured (for logging)
     */
    protected ?int $maxSteps = null;

    /**
     * Max tokens configured (for logging)
     */
    protected ?int $maxTokens = null;

    /**
     * ModelSelector service
     */
    protected ?ModelSelector $modelSelector = null;

    /**
     * Context data for logging
     */
    protected array $context = [];

    public function __construct(?ModelSelector $modelSelector = null)
    {
        $this->modelSelector = $modelSelector ?? app(ModelSelector::class);
    }

    /**
     * Create text generation instance
     */
    public function text(): self
    {
        $this->prism = Prism::text();
        $this->context['operation'] = 'text';

        return $this;
    }

    /**
     * Create embeddings generation instance
     */
    public function embeddings(): self
    {
        $this->prism = Prism::embeddings();
        $this->context['operation'] = 'embeddings';

        return $this;
    }

    /**
     * Create structured output instance
     */
    public function structured(): self
    {
        $this->prism = Prism::structured();
        $this->context['operation'] = 'structured';

        return $this;
    }

    /**
     * Set provider and model
     */
    public function using(Provider|string $provider, string $model): self
    {
        if (is_string($provider)) {
            $convertedProvider = $this->modelSelector->getProviderEnum($provider);
            $this->provider = $convertedProvider instanceof Provider ? $convertedProvider : null;
            $provider = $convertedProvider;
        } else {
            $this->provider = $provider;
        }

        $this->model = $model;
        $this->prism = $this->prism->using($provider, $model);

        return $this;
    }

    /**
     * Use a ModelSelector complexity profile
     */
    public function usingProfile(string $complexity): self
    {
        $config = $this->modelSelector->getProviderAndModel($complexity);

        $this->provider = $config['provider'];
        $this->model = $config['model'];
        $this->prism = $this->prism->using($config['provider'], $config['model']);

        if (! empty($config['max_tokens'])) {
            $this->maxTokens = $config['max_tokens'];
            $this->prism = $this->prism->withMaxTokens($config['max_tokens']);
        }

        return $this;
    }

    /**
     * Set maximum tool call steps
     */
    public function withMaxSteps(int $steps): self
    {
        $this->maxSteps = $steps;
        $this->prism = $this->prism->withMaxSteps($steps);

        return $this;
    }

    /**
     * Set maximum tokens
     */
    public function withMaxTokens(int $tokens): self
    {
        $this->maxTokens = $tokens;
        $this->prism = $this->prism->withMaxTokens($tokens);

        return $this;
    }

    /**
     * Set messages for conversation
     */
    public function withMessages(array $messages): self
    {
        $this->prism = $this->prism->withMessages($messages);

        return $this;
    }

    /**
     * Set simple prompt with optional attachments
     */
    public function withPrompt(string $prompt, array $attachments = []): self
    {
        if (empty($attachments)) {
            $this->prism = $this->prism->withPrompt($prompt);
        } else {
            $this->prism = $this->prism->withPrompt($prompt, $attachments);
        }

        return $this;
    }

    /**
     * Set available tools
     */
    public function withTools(array $tools): self
    {
        $this->prism = $this->prism->withTools($tools);

        return $this;
    }

    /**
     * Set system prompt (required for Anthropic/Bedrock providers)
     */
    public function withSystemPrompt(string $systemPrompt): self
    {
        $this->prism = $this->prism->withSystemPrompt($systemPrompt);

        return $this;
    }

    /**
     * Set additional context for logging
     */
    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);

        return $this;
    }

    /**
     * Set schema for structured output
     */
    public function withSchema(object $schema): self
    {
        $this->prism = $this->prism->withSchema($schema);
        $this->context['schema'] = get_class($schema);

        return $this;
    }

    /**
     * Set input for embeddings generation
     *
     * @param  string|array  $input  Text or array of texts to embed
     */
    public function fromInput(string|array $input): self
    {
        $this->prism = $this->prism->fromInput($input);
        $this->context['input_length'] = is_array($input) ? count($input) : strlen($input);

        return $this;
    }

    /**
     * Execute and return structured response
     */
    public function asStructured(): mixed
    {
        try {
            $result = $this->prism->asStructured();
            $this->logTokenUsage($result);

            return $result;
        } catch (Throwable $e) {
            $this->logExceptionChain($e);
            throw $e;
        }
    }

    /**
     * Execute and return embeddings
     */
    public function asEmbeddings(): mixed
    {
        try {
            $result = $this->prism->asEmbeddings();
            $this->logTokenUsage($result);

            return $result;
        } catch (Throwable $e) {
            $this->logExceptionChain($e);
            throw $e;
        }
    }

    /**
     * Execute as streaming response
     */
    public function asStream()
    {
        try {
            $stream = $this->prism->asStream();

            return $this->wrapStreamWithErrorLogging($stream);
        } catch (Throwable $e) {
            $this->logExceptionChain($e);
            throw $e;
        }
    }

    /**
     * Wrap a stream generator with enhanced error logging
     */
    protected function wrapStreamWithErrorLogging($stream): \Generator
    {
        try {
            foreach ($stream as $chunk) {
                yield $chunk;
            }
        } catch (Throwable $e) {
            $this->logExceptionChain($e);
            throw $e;
        }
    }

    /**
     * Execute synchronously and return response
     */
    public function generate(): mixed
    {
        try {
            $result = $this->prism->generate();
            $this->logTokenUsage($result);

            return $result;
        } catch (Throwable $e) {
            $this->logExceptionChain($e);
            throw $e;
        }
    }

    /**
     * Execute and return text response
     */
    public function asText(): mixed
    {
        try {
            $result = $this->prism->asText();
            $this->logTokenUsage($result);

            return $result;
        } catch (Throwable $e) {
            $this->logExceptionChain($e);
            throw $e;
        }
    }

    /**
     * Log exception chain recursively
     */
    protected function logExceptionChain(Throwable $e, int $depth = 0): void
    {
        $logData = [
            'depth' => $depth,
            'exception_class' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'provider' => $this->provider?->value ?? 'unknown',
            'model' => $this->model ?? 'unknown',
        ];

        if ($this->maxSteps !== null) {
            $logData['max_steps'] = $this->maxSteps;
        }
        if ($this->maxTokens !== null) {
            $logData['max_tokens'] = $this->maxTokens;
        }

        if (! empty($this->context)) {
            $logData['context'] = $this->context;
        }

        if ($depth === 0) {
            $logData['stack_trace'] = $e->getTraceAsString();
        }

        if ($e instanceof PrismException) {
            $logData['is_prism_exception'] = true;

            if (preg_match('/Calling (\w+) tool failed/', $e->getMessage(), $matches)) {
                $logData['failed_tool'] = $matches[1];
            }

            if ($previous = $e->getPrevious()) {
                $logData['tool_error_details'] = [
                    'error_class' => get_class($previous),
                    'error_message' => $previous->getMessage(),
                    'error_code' => $previous->getCode(),
                    'error_file' => $previous->getFile(),
                    'error_line' => $previous->getLine(),
                ];

                if (str_contains($previous->getMessage(), '{')) {
                    try {
                        if (preg_match('/(\{.*\})/s', $previous->getMessage(), $jsonMatches)) {
                            $decoded = json_decode($jsonMatches[1], true);
                            if (json_last_error() === JSON_ERROR_NONE) {
                                $logData['tool_error_details']['json_data'] = $decoded;
                            }
                        }
                    } catch (\Exception $jsonEx) {
                    }
                }
            }
        }

        Log::error("PrismWrapper: Exception at depth {$depth}", $logData);

        if ($previous = $e->getPrevious()) {
            $this->logExceptionChain($previous, $depth + 1);
        }
    }

    /**
     * Log token usage for metrics and cost tracking
     */
    protected function logTokenUsage($response): void
    {
        $usage = $this->extractTokenUsage($response);

        if ($usage) {
            Log::info('PrismWrapper: Token usage', [
                'provider' => $this->provider?->value ?? 'unknown',
                'model' => $this->model ?? 'unknown',
                'operation' => $this->context['operation'] ?? 'unknown',
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens' => $usage['total_tokens'] ?? 0,
                'context' => $this->context,
            ]);
        }
    }

    /**
     * Extract token usage from various response types
     */
    protected function extractTokenUsage($response): ?array
    {
        if (isset($response->usage)) {
            return [
                'prompt_tokens' => $response->usage->promptTokens ?? 0,
                'completion_tokens' => $response->usage->completionTokens ?? 0,
                'total_tokens' => ($response->usage->promptTokens ?? 0) + ($response->usage->completionTokens ?? 0),
            ];
        }

        if (isset($response->tokenUsage)) {
            return [
                'prompt_tokens' => $response->tokenUsage->promptTokens ?? 0,
                'completion_tokens' => $response->tokenUsage->completionTokens ?? 0,
                'total_tokens' => ($response->tokenUsage->promptTokens ?? 0) + ($response->tokenUsage->completionTokens ?? 0),
            ];
        }

        return null;
    }

    /**
     * Get the underlying Prism pending request instance
     */
    public function getPrismInstance(): mixed
    {
        return $this->prism;
    }
}
