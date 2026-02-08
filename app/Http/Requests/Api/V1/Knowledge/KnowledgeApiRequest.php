<?php

namespace App\Http\Requests\Api\V1\Knowledge;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base request class for all Knowledge API requests
 *
 * Provides common authorization logic based on OAuth scopes
 * and shared validation rules for knowledge management endpoints.
 */
abstract class KnowledgeApiRequest extends FormRequest
{
    /**
     * Get the required OAuth scope for this request
     */
    abstract protected function requiredAbility(): string;

    /**
     * Determine if the user is authorized to make this request
     */
    public function authorize(): bool
    {
        return $this->user()->tokenCan($this->requiredAbility());
    }

    /**
     * Common validation rules shared across knowledge requests
     */
    protected function commonRules(): array
    {
        return [
            'tags' => 'array',
            'tags.*' => 'string|max:50',
            'privacy_level' => 'in:private,public',
            'ttl_hours' => 'nullable|integer|min:1|max:8760',
        ];
    }

    /**
     * Common filter rules for list/search endpoints
     */
    protected function filterRules(): array
    {
        return [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
            'search' => 'string|max:255',
            'content_type' => 'in:text,file,external',
            'status' => 'in:pending,processing,completed,failed',
            'include_expired' => 'boolean',
        ];
    }

    /**
     * Get custom messages for validation errors
     */
    public function messages(): array
    {
        return [
            'tags.array' => 'Tags must be provided as an array',
            'tags.*.string' => 'Each tag must be a string',
            'tags.*.max' => 'Tag names cannot exceed 50 characters',
            'privacy_level.in' => 'Privacy level must be either "private" or "public"',
            'ttl_hours.integer' => 'TTL must be an integer',
            'ttl_hours.min' => 'TTL must be at least 1 hour',
            'ttl_hours.max' => 'TTL cannot exceed 1 year (8760 hours)',
        ];
    }
}
