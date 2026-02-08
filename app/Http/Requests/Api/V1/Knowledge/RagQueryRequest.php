<?php

namespace App\Http\Requests\Api\V1\Knowledge;

class RagQueryRequest extends KnowledgeApiRequest
{
    protected function requiredAbility(): string
    {
        return 'knowledge:rag';
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
                'description' => 'Natural language query for semantic search (max 1000 characters)',
                'example' => 'How do I implement middleware in Laravel?',
            ],
            'agent_id' => [
                'description' => 'Optional agent ID to scope search to documents assigned to specific agent',
                'example' => 3,
            ],
            'limit' => [
                'description' => 'Maximum number of results to return (1-20, default: 5)',
                'example' => 10,
            ],
            'context_length' => [
                'description' => 'Maximum characters per result chunk (100-8000, default: 2000)',
                'example' => 2000,
            ],
            'include_sources' => [
                'description' => 'Include full source document metadata in response',
                'example' => true,
            ],
            'include_expired' => [
                'description' => 'Include expired TTL documents in search results',
                'example' => false,
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'query' => 'required|string|max:1000',
            'agent_id' => 'nullable|integer',
            'limit' => 'integer|min:1|max:20',
            'context_length' => 'integer|min:100|max:8000',
            'include_sources' => 'boolean',
            'include_expired' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'query.required' => 'Query is required for RAG operations',
            'query.max' => 'Query cannot exceed 1000 characters',
            'limit.max' => 'Result limit cannot exceed 20',
            'context_length.min' => 'Context length must be at least 100 characters',
            'context_length.max' => 'Context length cannot exceed 8000 characters',
        ];
    }
}
