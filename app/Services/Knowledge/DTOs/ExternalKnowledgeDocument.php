<?php

namespace App\Services\Knowledge\DTOs;

use Carbon\Carbon;

class ExternalKnowledgeDocument
{
    public function __construct(
        public readonly string $sourceIdentifier,
        public readonly string $sourceType,
        public readonly string $content,
        public readonly string $title,
        public readonly ?string $description = null,
        public readonly string $contentType = 'text/plain',
        public readonly ?Carbon $lastModified = null,
        public readonly ?string $contentHash = null,
        public readonly array $metadata = [],
        public readonly ?array $vectors = null,
        public readonly array $tags = [],
        public readonly array $categories = [],
        public readonly ?string $language = null,
        public readonly ?int $wordCount = null,
        public readonly ?string $author = null,
        public readonly ?Carbon $publishedAt = null,
    ) {}

    /**
     * Create from array data.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sourceIdentifier: $data['source_identifier'],
            sourceType: $data['source_type'],
            content: $data['content'],
            title: $data['title'],
            description: $data['description'] ?? null,
            contentType: $data['content_type'] ?? 'text/plain',
            lastModified: isset($data['last_modified']) ? Carbon::parse($data['last_modified']) : null,
            contentHash: $data['content_hash'] ?? null,
            metadata: $data['metadata'] ?? [],
            vectors: $data['vectors'] ?? null,
            tags: $data['tags'] ?? [],
            categories: $data['categories'] ?? [],
            language: $data['language'] ?? null,
            wordCount: $data['word_count'] ?? null,
            author: $data['author'] ?? null,
            publishedAt: isset($data['published_at']) ? Carbon::parse($data['published_at']) : null,
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
            'content' => $this->content,
            'title' => $this->title,
            'description' => $this->description,
            'content_type' => $this->contentType,
            'last_modified' => $this->lastModified?->toISOString(),
            'content_hash' => $this->contentHash,
            'metadata' => $this->metadata,
            'vectors' => $this->vectors,
            'tags' => $this->tags,
            'categories' => $this->categories,
            'language' => $this->language,
            'word_count' => $this->wordCount,
            'author' => $this->author,
            'published_at' => $this->publishedAt?->toISOString(),
        ];
    }

    /**
     * Generate a content hash if not provided.
     */
    public function getContentHash(): string
    {
        return $this->contentHash ?? hash('sha256', $this->content);
    }

    /**
     * Check if the document has vectors available.
     */
    public function hasVectors(): bool
    {
        return $this->vectors !== null && ! empty($this->vectors);
    }

    /**
     * Estimate word count if not provided.
     */
    public function getWordCount(): int
    {
        return $this->wordCount ?? str_word_count(strip_tags($this->content));
    }
}
