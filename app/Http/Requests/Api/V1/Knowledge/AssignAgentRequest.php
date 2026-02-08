<?php

namespace App\Http\Requests\Api\V1\Knowledge;

use Illuminate\Foundation\Http\FormRequest;

class AssignAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()->tokenCan('knowledge:agents:manage')) {
            return false;
        }

        $document = $this->route('document');

        return $document && $this->user()->can('update', $document);
    }

    /**
     * Get the body parameters for API documentation.
     *
     * @return array<string, array{description: string, example: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'agent_id' => [
                'description' => 'Agent ID to assign this document to for RAG context',
                'example' => 3,
            ],
            'config' => [
                'description' => 'Optional configuration for agent assignment',
                'example' => ['priority' => 'high', 'context_window' => 2000],
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'agent_id' => 'required|integer',
            'config' => 'array',
        ];
    }

    public function messages(): array
    {
        return [
            'agent_id.required' => 'Agent ID is required',
            'agent_id.integer' => 'Agent ID must be an integer',
        ];
    }
}
