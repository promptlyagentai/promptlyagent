<?php

namespace App\Http\Requests\Api\V1\Knowledge;

use Illuminate\Foundation\Http\FormRequest;

class ExtractUrlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->tokenCan('knowledge:create');
    }

    /**
     * Get the body parameters for API documentation.
     *
     * @return array<string, array{description: string, example: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'url' => [
                'description' => 'URL to extract content from. Will be fetched and converted to markdown. SSRF protection prevents access to private networks',
                'example' => 'https://laravel.com/docs/11.x/routing',
            ],
        ];
    }

    public function rules(): array
    {
        return [
            'url' => 'required|url|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'url.required' => 'URL is required',
            'url.url' => 'Invalid URL format',
            'url.max' => 'URL cannot exceed 2000 characters',
        ];
    }
}
