<?php

namespace App\Http\Requests\Api\V1\Knowledge;

class BulkOperationRequest extends KnowledgeApiRequest
{
    protected function requiredAbility(): string
    {
        return 'knowledge:bulk:manage';
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
                'description' => 'Array of document IDs to perform bulk operation on (1-100 documents)',
                'example' => [1, 5, 12, 23],
            ],
            'confirm' => [
                'description' => 'Confirmation flag for bulk delete operations. Must be `true` to proceed with deletion',
                'example' => true,
            ],
            'tag_name' => [
                'description' => 'Tag name to assign to all selected documents (required for bulk tag assignment)',
                'example' => 'important',
            ],
            'agent_id' => [
                'description' => 'Agent ID to assign all selected documents to (required for bulk agent assignment)',
                'example' => 3,
            ],
            'config' => [
                'description' => 'Optional configuration for bulk agent assignment',
                'example' => ['priority' => 'high'],
            ],
        ];
    }

    public function rules(): array
    {
        $rules = [
            'document_ids' => 'required|array|min:1|max:100',
            'document_ids.*' => 'integer|exists:knowledge_documents,id',
        ];

        // Additional rules based on operation type
        if ($this->isMethod('POST') && str_contains($this->path(), 'bulk/delete')) {
            $rules['confirm'] = 'required|boolean|accepted';
        }

        if (str_contains($this->path(), 'bulk/assign-tag')) {
            $rules['tag_name'] = 'required|string|max:255';
        }

        if (str_contains($this->path(), 'bulk/assign-agent')) {
            $rules['agent_id'] = 'required|integer';
            $rules['config'] = 'array';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'document_ids.required' => 'Document IDs are required for bulk operations',
            'document_ids.array' => 'Document IDs must be provided as an array',
            'document_ids.min' => 'At least one document ID is required',
            'document_ids.max' => 'Cannot process more than 100 documents at once',
            'document_ids.*.exists' => 'One or more document IDs do not exist',
            'confirm.required' => 'Confirmation is required for bulk delete operations',
            'confirm.accepted' => 'You must confirm the bulk delete operation',
            'tag_name.required' => 'Tag name is required for bulk tag assignment',
            'agent_id.required' => 'Agent ID is required for bulk agent assignment',
        ];
    }
}
