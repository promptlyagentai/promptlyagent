<?php

namespace App\Http\Requests\Api\V1\Knowledge;

class RegenerateEmbeddingsRequest extends KnowledgeApiRequest
{
    protected function requiredAbility(): string
    {
        return 'knowledge:embeddings:regenerate';
    }

    /**
     * Get the body parameters for API documentation.
     *
     * @return array<string, array{description: string, example: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'document_ids' => [
                'description' => 'Optional array of specific document IDs to regenerate embeddings for. If not provided, regenerates embeddings for all documents',
                'example' => [1, 5, 12],
            ],
            'limit' => [
                'description' => 'Maximum number of documents to process (1-100). Used when document_ids is not specified',
                'example' => 50,
            ],
            'force' => [
                'description' => 'Force regeneration even if embeddings already exist. Default: false',
                'example' => false,
            ],
            'batch_size' => [
                'description' => 'Number of documents to process in each batch (1-50, default: 10)',
                'example' => 10,
            ],
            'async' => [
                'description' => 'Process regeneration asynchronously in the background. Returns immediately with job ID. Default: true',
                'example' => true,
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'document_ids' => 'array',
            'document_ids.*' => 'integer|exists:knowledge_documents,id',
            'limit' => 'integer|min:1|max:100',
            'force' => 'boolean',
            'batch_size' => 'integer|min:1|max:50',
            'async' => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'document_ids.array' => 'Document IDs must be provided as an array',
            'document_ids.*.integer' => 'Each document ID must be an integer',
            'document_ids.*.exists' => 'One or more document IDs do not exist',
            'limit.max' => 'Cannot regenerate more than 100 documents at once',
            'batch_size.max' => 'Batch size cannot exceed 50',
        ];
    }
}
