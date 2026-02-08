<?php

namespace App\Http\Requests\Api\V1\Knowledge;

class StoreKnowledgeRequest extends KnowledgeApiRequest
{
    protected function requiredAbility(): string
    {
        return 'knowledge:create';
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
                'example' => 'Laravel Best Practices Guide',
            ],
            'description' => [
                'description' => 'Optional document description (max 1000 characters)',
                'example' => 'Comprehensive guide covering Laravel development best practices',
            ],
            'content_type' => [
                'description' => 'Type of content. Must be one of: `text`, `file`, `external`',
                'example' => 'text',
            ],
            'content' => [
                'description' => 'Text content (required if content_type is `text`). Maximum '.round(config('knowledge.max_text_length') / 1024).'KB to prevent memory exhaustion',
                'example' => '# Laravel Best Practices\n\n## Routing\nAlways use named routes...',
            ],
            'file' => [
                'description' => 'File upload (required if content_type is `file`). Maximum 50MB. Supports PDF, Word, text, code files',
                'example' => null,
            ],
            'external_source' => [
                'description' => 'External URL (required if content_type is `external`). Will be fetched and converted to markdown',
                'example' => 'https://laravel.com/docs/11.x/routing',
            ],
            'async' => [
                'description' => 'Process document asynchronously (recommended for large files). Returns immediately with document ID',
                'example' => true,
            ],
            'external_source_identifier' => [
                'description' => 'Optional identifier for external source (e.g., article ID, page slug)',
                'example' => 'laravel-routing-docs',
            ],
            'author' => [
                'description' => 'Document author name',
                'example' => 'Laravel Team',
            ],
            'thumbnail_url' => [
                'description' => 'URL to document thumbnail image',
                'example' => 'https://example.com/thumbnail.jpg',
            ],
            'favicon_url' => [
                'description' => 'URL to source favicon',
                'example' => 'https://laravel.com/favicon.ico',
            ],
            'notes' => [
                'description' => 'Custom notes about the document (max 50,000 characters)',
                'example' => 'Important reference for routing patterns in our project',
            ],
            'screenshot' => [
                'description' => 'Base64-encoded PNG/JPEG screenshot of the source (max '.round(config('knowledge.max_screenshot_size') / 1024).'KB)',
                'example' => 'data:image/png;base64,iVBORw0KGgoAAAANS...',
            ],
            'auto_refresh_enabled' => [
                'description' => 'Enable automatic refresh for external sources',
                'example' => true,
            ],
            'refresh_interval_minutes' => [
                'description' => 'Refresh interval in minutes (required if auto_refresh_enabled is true). Min: 15 minutes, Max: 30 days (43,200 minutes)',
                'example' => 1440,
            ],
            'privacy_level' => [
                'description' => 'Document visibility. `private` (user-only) or `public` (shared)',
                'example' => 'private',
            ],
            'tags' => [
                'description' => 'Array of tag names to associate with document',
                'example' => ['laravel', 'routing', 'best-practices'],
            ],
            'agent_ids' => [
                'description' => 'Array of agent IDs to assign this document to for RAG context',
                'example' => [1, 3, 5],
            ],
        ];
    }

    public function rules(): array
    {
        return array_merge([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'content_type' => 'required|in:text,file,external',
            // PERFORMANCE: Limit text content to prevent memory exhaustion and high AI costs
            // 100KB ≈ 25K tokens ≈ 50 pages (configurable via KNOWLEDGE_MAX_TEXT_LENGTH)
            'content' => [
                'required_if:content_type,text',
                'string',
                'max:'.config('knowledge.max_text_length'),
            ],
            'file' => 'required_if:content_type,file|file|max:51200', // 50MB max
            'external_source' => 'required_if:content_type,external|url',
            'async' => 'boolean',
            // Optional metadata fields
            'external_source_identifier' => 'nullable|string|max:500',
            'author' => 'nullable|string|max:255',
            'thumbnail_url' => 'nullable|url|max:500',
            'favicon_url' => 'nullable|url|max:500',
            // Custom notes field
            'notes' => 'nullable|string|max:50000',
            // Screenshot data URL (base64 encoded PNG/JPEG)
            // Reduced from 2MB to 500KB for performance (configurable via KNOWLEDGE_MAX_SCREENSHOT_SIZE)
            'screenshot' => [
                'nullable',
                'string',
                'max:'.config('knowledge.max_screenshot_size'),
            ],
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
        $maxBytes = config('knowledge.max_text_length');
        $maxKB = round($maxBytes / 1024);

        return array_merge(parent::messages(), [
            'title.required' => 'Document title is required',
            'content_type.required' => 'Content type is required',
            'content_type.in' => 'Content type must be text, file, or external',
            'content.required_if' => 'Content is required for text documents',
            'content.max' => "Content cannot exceed {$maxKB}KB ({$maxBytes} bytes). Consider splitting into multiple documents.",
            'file.required_if' => 'File is required for file documents',
            'file.max' => 'File size cannot exceed 50MB',
            'external_source.required_if' => 'External source URL is required for external documents',
            'external_source.url' => 'External source must be a valid URL',
            'notes.max' => 'Notes cannot exceed 50,000 characters',
            'screenshot.max' => 'Screenshot size cannot exceed '.round(config('knowledge.max_screenshot_size') / 1024).'KB',
            'refresh_interval_minutes.required_if' => 'Refresh interval is required when auto-refresh is enabled',
            'refresh_interval_minutes.min' => 'Refresh interval must be at least 15 minutes',
            'refresh_interval_minutes.max' => 'Refresh interval cannot exceed 30 days (43,200 minutes)',
        ]);
    }

    /**
     * Add post-validation logging for large documents.
     *
     * PERFORMANCE: Log large documents for cost monitoring and abuse detection.
     * Helps identify users submitting documents near the size limits.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('content_type') === 'text' && $this->has('content')) {
                $content = $this->input('content');
                $contentSize = strlen($content);

                // Estimate token count (rough: 1 token ≈ 4 characters for English text)
                $estimatedTokens = (int) ceil($contentSize / 4);

                // Log large documents for monitoring
                if ($contentSize > config('knowledge.large_document_warning_threshold')) {
                    \Illuminate\Support\Facades\Log::info('Knowledge API: Large document submitted', [
                        'user_id' => $this->user()?->id,
                        'content_size_bytes' => $contentSize,
                        'content_size_kb' => round($contentSize / 1024, 2),
                        'estimated_tokens' => $estimatedTokens,
                        'title' => $this->input('title'),
                        'will_incur_cost' => true,
                    ]);
                }
            }
        });
    }
}
