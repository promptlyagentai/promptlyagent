<?php

namespace App\Services\Knowledge\DTOs;

class SearchResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $content,
        public readonly float $score,
        public readonly array $metadata = [],
        public readonly ?string $title = null,
        public readonly ?string $summary = null,
        public readonly array $highlights = [],
        public readonly ?int $documentId = null,
        public readonly ?bool $isExpired = null,
        public readonly ?string $source = null,
        public readonly ?string $sourceType = null,
        public readonly mixed $ttl_expires_at = null,
    ) {}

    public static function fromMeilisearchHit(array $hit): self
    {
        return new self(
            id: $hit['id'],
            content: $hit['content'] ?? '',
            score: $hit['_rankingScore'] ?? 0.0,
            metadata: $hit['metadata'] ?? [],
            title: $hit['title'] ?? null,
            summary: $hit['summary'] ?? null,
            highlights: $hit['_formatted'] ?? [],
            documentId: $hit['document_id'] ?? null,
            isExpired: isset($hit['ttl_expires_at']) && $hit['ttl_expires_at'] !== null
                ? (is_numeric($hit['ttl_expires_at'])
                    ? now()->isAfter(\Carbon\Carbon::createFromTimestamp($hit['ttl_expires_at']))
                    : now()->isAfter(\Carbon\Carbon::parse($hit['ttl_expires_at'])))
                : null,
            source: $hit['source'] ?? null,
            sourceType: $hit['source_type'] ?? null,
            ttl_expires_at: $hit['ttl_expires_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'content' => $this->content,
            'score' => $this->score,
            'metadata' => $this->metadata,
            'title' => $this->title,
            'summary' => $this->summary,
            'highlights' => $this->highlights,
            'documentId' => $this->documentId,
            'isExpired' => $this->isExpired,
            'source' => $this->source,
            'sourceType' => $this->sourceType,
        ];
    }

    public function hasHighScore(float $threshold = 0.7): bool
    {
        return $this->score >= $threshold;
    }

    public function getContentPreview(int $length = 200): string
    {
        $content = strip_tags($this->content);

        return mb_strlen($content) > $length
            ? mb_substr($content, 0, $length).'...'
            : $content;
    }

    public function hasHighlights(): bool
    {
        return ! empty($this->highlights);
    }

    public function getHighlightedContent(): string
    {
        if (! $this->hasHighlights()) {
            return $this->content;
        }

        return $this->highlights['content'] ?? $this->content;
    }
}
