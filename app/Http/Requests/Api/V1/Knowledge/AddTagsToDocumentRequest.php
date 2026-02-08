<?php

namespace App\Http\Requests\Api\V1\Knowledge;

use Illuminate\Foundation\Http\FormRequest;

class AddTagsToDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
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
            'tag_ids' => [
                'description' => 'Array of tag IDs to add to the document (tags will be attached without detaching existing ones)',
                'example' => [1, 3, 5],
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'tag_ids' => 'required|array',
            'tag_ids.*' => 'integer|exists:knowledge_tags,id',
        ];
    }

    public function messages(): array
    {
        return [
            'tag_ids.required' => 'Tag IDs are required',
            'tag_ids.array' => 'Tag IDs must be provided as an array',
            'tag_ids.*.exists' => 'One or more tag IDs do not exist',
        ];
    }
}
