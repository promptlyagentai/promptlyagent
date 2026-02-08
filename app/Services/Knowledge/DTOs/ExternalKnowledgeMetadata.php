<?php

namespace App\Services\Knowledge\DTOs;

use Carbon\Carbon;

class ExternalKnowledgeMetadata
{
    public function __construct(
        public readonly string $sourceIdentifier,
        public readonly string $sourceType,
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly ?string $favicon = null,
        public readonly array $categories = [],
        public readonly array $tags = [],
        public readonly ?string $author = null,
        public readonly ?Carbon $publishedAt = null,
        public readonly ?Carbon $lastModified = null,
        public readonly ?string $language = null,
        public readonly ?string $contentType = null,
        public readonly ?int $wordCount = null,
        public readonly ?int $readingTime = null,
        public readonly array $customFields = [],
        public readonly bool $requiresAuth = false,
        public readonly array $permissions = [],
        public readonly ?string $thumbnail = null,
        public readonly array $relatedSources = [],
    ) {}

    /**
     * Create from array data.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceIdentifier: $data['source_identifier'],
            sourceType: $data['source_type'],
            title: $data['title'],
            description: $data['description'] ?? null,
            favicon: $data['favicon'] ?? null,
            categories: $data['categories'] ?? [],
            tags: $data['tags'] ?? [],
            author: $data['author'] ?? null,
            publishedAt: isset($data['published_at']) ? Carbon::parse($data['published_at']) : null,
            lastModified: isset($data['last_modified']) ? Carbon::parse($data['last_modified']) : null,
            language: $data['language'] ?? null,
            contentType: $data['content_type'] ?? null,
            wordCount: $data['word_count'] ?? null,
            readingTime: $data['reading_time'] ?? null,
            customFields: $data['custom_fields'] ?? [],
            requiresAuth: $data['requires_auth'] ?? false,
            permissions: $data['permissions'] ?? [],
            thumbnail: $data['thumbnail'] ?? null,
            relatedSources: $data['related_sources'] ?? [],
        );
    }

    /**
     * Convert to array for storage or API responses.
     */
    public function toArray(): array
    {
        return [
            'source_identifier' => $this->sourceIdentifier,
            'source_type' => $this->sourceType,
            'title' => $this->title,
            'description' => $this->description,
            'favicon' => $this->favicon,
            'categories' => $this->categories,
            'tags' => $this->tags,
            'author' => $this->author,
            'published_at' => $this->publishedAt?->toISOString(),
            'last_modified' => $this->lastModified?->toISOString(),
            'language' => $this->language,
            'content_type' => $this->contentType,
            'word_count' => $this->wordCount,
            'reading_time' => $this->readingTime,
            'custom_fields' => $this->customFields,
            'requires_auth' => $this->requiresAuth,
            'permissions' => $this->permissions,
            'thumbnail' => $this->thumbnail,
            'related_sources' => $this->relatedSources,
        ];
    }

    /**
     * Check if required metadata is present.
     */
    public function hasRequiredMetadata(): bool
    {
        return ! empty($this->title) && ! empty($this->description);
    }

    /**
     * Get missing required metadata fields.
     */
    public function getMissingRequiredFields(): array
    {
        $missing = [];

        if (empty($this->title)) {
            $missing[] = 'title';
        }

        if (empty($this->description)) {
            $missing[] = 'description';
        }

        if (empty($this->categories)) {
            $missing[] = 'categories';
        }

        return $missing;
    }

    /**
     * Estimate reading time based on word count.
     */
    public function getEstimatedReadingTime(): ?int
    {
        if ($this->readingTime) {
            return $this->readingTime;
        }

        if ($this->wordCount) {
            // Average reading speed: 200 words per minute
            return (int) ceil($this->wordCount / 200);
        }

        return null;
    }
}
