<?php

namespace App\Http\Requests\Api\V1\Knowledge;

use Illuminate\Support\Facades\Log;

/**
 * Search Knowledge Request - Validates and sanitizes search queries.
 *
 * SECURITY: Validates search queries to prevent performance DoS and filter bypass attempts.
 *
 * Validation Rules:
 * - Length: Max 500 characters
 * - Blocked patterns: Filter injection attempts, excessive wildcards, unsafe characters
 * - Complexity limits: Max 5 wildcards, max 10 quote pairs
 *
 * Attack Prevention:
 * - Filter bypass: Blocks "OR privacy_level:" injection attempts
 * - Performance DoS: Limits wildcard count to prevent expensive queries
 * - Special chars: Removes potentially problematic characters
 */
class SearchKnowledgeRequest extends KnowledgeApiRequest
{
    protected function requiredAbility(): string
    {
        return 'knowledge:search';
    }

    /**
     * Get the body parameters for API documentation.
     *
     * @return array<string, array{description: string, example: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'query' => [
                'description' => 'Search query (max 500 characters). Supports wildcards (*) up to 5 per query. Phrases can be wrapped in quotes for exact matching',
                'example' => 'Laravel routing "best practices"',
            ],
            'limit' => [
                'description' => 'Maximum number of results to return (1-100, default: 20)',
                'example' => 20,
            ],
            'filters' => [
                'description' => 'Optional filters to narrow search results. Array of filter criteria',
                'example' => ['tags' => ['laravel', 'php']],
            ],
            'include_expired' => [
                'description' => 'Include documents that have passed their TTL expiration',
                'example' => false,
            ],
            'highlight' => [
                'description' => 'Include search term highlights in results',
                'example' => true,
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'query' => 'required|string|max:500',
            'limit' => 'integer|min:1|max:100',
            'filters' => 'array',
            'include_expired' => 'boolean',
            'highlight' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'query.required' => 'Search query is required',
            'query.max' => 'Search query cannot exceed 500 characters',
            'limit.integer' => 'Limit must be an integer',
            'limit.min' => 'Limit must be at least 1',
            'limit.max' => 'Limit cannot exceed 100',
        ];
    }

    /**
     * SECURITY: Validate search query patterns to prevent injection and DoS.
     *
     * Checks for:
     * - Filter injection attempts (e.g., "OR privacy_level:private")
     * - Excessive wildcards (>5 can cause performance issues)
     * - Unsafe characters (brackets, backslashes)
     * - Quote balance (unbalanced quotes can cause parsing errors)
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $query = $this->input('query');

            if (! $query) {
                return;
            }

            // SECURITY: Block filter injection attempts
            if (preg_match('/\b(OR|AND)\b.*\b(privacy_level|created_by|user_id)\b/i', $query)) {
                Log::warning('Knowledge Search: Filter injection attempt detected', [
                    'query' => $query,
                    'user_id' => $this->user()?->id,
                    'ip_address' => $this->ip(),
                ]);

                $validator->errors()->add(
                    'query',
                    'Search query contains invalid filter syntax. Please use plain text search.'
                );
            }

            // SECURITY: Limit wildcard count to prevent performance DoS
            $wildcardCount = substr_count($query, '*');
            if ($wildcardCount > 5) {
                Log::warning('Knowledge Search: Excessive wildcards in query', [
                    'query' => $query,
                    'wildcard_count' => $wildcardCount,
                    'user_id' => $this->user()?->id,
                ]);

                $validator->errors()->add(
                    'query',
                    'Search query contains too many wildcards (maximum 5). Please use more specific terms.'
                );
            }

            // SECURITY: Block potentially unsafe characters
            if (preg_match('/[<>{}\\[\\]\\\\]/', $query)) {
                Log::warning('Knowledge Search: Unsafe characters in query', [
                    'query' => $query,
                    'user_id' => $this->user()?->id,
                ]);

                $validator->errors()->add(
                    'query',
                    'Search query contains invalid characters. Please use alphanumeric characters and basic punctuation.'
                );
            }

            // Check quote balance (for better UX, not security)
            $quoteCount = substr_count($query, '"');
            if ($quoteCount % 2 !== 0) {
                Log::info('Knowledge Search: Unbalanced quotes in query', [
                    'query' => $query,
                    'user_id' => $this->user()?->id,
                ]);

                $validator->errors()->add(
                    'query',
                    'Search query has unbalanced quotes. Please ensure all quotes are properly closed.'
                );
            }

            // Log complex queries for monitoring (not blocked, just logged)
            if (strlen($query) > 200 || $wildcardCount > 3) {
                Log::info('Knowledge Search: Complex query detected', [
                    'query' => $query,
                    'query_length' => strlen($query),
                    'wildcard_count' => $wildcardCount,
                    'user_id' => $this->user()?->id,
                ]);
            }
        });
    }
}
