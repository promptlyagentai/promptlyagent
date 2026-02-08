<?php

namespace App\Http\Requests\Api\V1\Knowledge;

use Illuminate\Foundation\Http\FormRequest;

class CreateTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->tokenCan('knowledge:tags:manage');
    }

    /**
     * Get the body parameters for API documentation.
     *
     * @return array<string, array{description: string, example: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'name' => [
                'description' => 'Tag name (max 255 characters, must be unique)',
                'example' => 'laravel-best-practices',
            ],
            'description' => [
                'description' => 'Optional tag description (max 500 characters)',
                'example' => 'Best practices and conventions for Laravel development',
            ],
            'color' => [
                'description' => 'Optional tag color name (max 20 characters, default: zinc)',
                'example' => 'blue',
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:knowledge_tags,name',
            'description' => 'nullable|string|max:500',
            'color' => 'nullable|string|max:20',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Tag name is required',
            'name.unique' => 'A tag with this name already exists',
            'description.max' => 'Description cannot exceed 500 characters',
        ];
    }
}
