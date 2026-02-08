<?php

namespace App\Http\Requests\Api\V1\Knowledge;

class UpdateKnowledgeRequest extends KnowledgeApiRequest
{
    protected function requiredAbility(): string
    {
        return 'knowledge:update';
    }

    /**
     * Get the body parameters for API documentation.
     *
     * @return array<string, array{description: string, example: mixed}>
     */
    public function bodyParameters(): array
    {
        return [
            'title' => [
                'description' => 'Document title (max 255 characters)',
                'example' => 'Updated Laravel Best Practices Guide',
            ],
            'description' => [
                'description' => 'Optional document description (max 1000 characters)',
                'example' => 'Updated comprehensive guide covering Laravel development best practices',
            ],
            'notes' => [
                'description' => 'Custom notes about the document (max 50,000 characters)',
                'example' => 'Updated with Laravel 12 changes',
            ],
            'auto_refresh_enabled' => [
                'description' => 'Enable automatic refresh for external sources',
                'example' => true,
            ],
            'refresh_interval_minutes' => [
                'description' => 'Refresh interval in minutes (required if auto_refresh_enabled is true). Min: 15 minutes, Max: 30 days (43,200 minutes)',
                'example' => 1440,
            ],
            'tags' => [
                'description' => 'Array of tag names to associate with document (max 50 characters per tag)',
                'example' => ['laravel', 'best-practices', 'updated'],
            ],
            'privacy_level' => [
                'description' => 'Document visibility. `private` (user-only) or `public` (shared)',
                'example' => 'private',
            ],
            'ttl_hours' => [
                'description' => 'Optional time-to-live in hours (1-8760 = 1 year). Document expires after this period',
                'example' => 720,
            ],
        ];
    }

    public function rules(): array
    {
        return array_merge([
            'title' => 'string|max:255',
            'description' => 'nullable|string|max:1000',
            // Custom notes field
            'notes' => 'nullable|string|max:50000',
            // Auto-refresh settings
            'auto_refresh_enabled' => 'boolean',
            'refresh_interval_minutes' => [
                'required_if:auto_refresh_enabled,true',
                'nullable',
                'integer',
                'min:15',      // Minimum 15 minutes
                'max:43200',   // Maximum 30 days
            ],
        ], $this->commonRules());
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'notes.max' => 'Notes cannot exceed 50,000 characters',
            'refresh_interval_minutes.required_if' => 'Refresh interval is required when auto-refresh is enabled',
            'refresh_interval_minutes.min' => 'Refresh interval must be at least 15 minutes',
            'refresh_interval_minutes.max' => 'Refresh interval cannot exceed 30 days (43,200 minutes)',
        ]);
    }
}
