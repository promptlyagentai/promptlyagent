<?php

namespace App\Services\Knowledge\DTOs;

class ProcessedKnowledge
{
    public function __construct(
        public readonly string $content,
        public readonly string $title,
        public readonly ?string $summary = null,
        public readonly array $metadata = [],
        public readonly ?string $language = null,
        public readonly ?int $wordCount = null,
        public readonly array $extractedEntities = [],
        public readonly array $keywords = [],
        public readonly ?float $confidence = null,
        public readonly ?string $processorName = null,
    ) {}

    public static function create(
        string $content,
        string $title,
        array $options = []
    ): self {
        return new self(
            content: $content,
            title: $title,
            summary: $options['summary'] ?? null,
            metadata: $options['metadata'] ?? [],
            language: $options['language'] ?? null,
            wordCount: $options['wordCount'] ?? str_word_count(strip_tags($content)),
            extractedEntities: $options['extractedEntities'] ?? [],
            keywords: $options['keywords'] ?? [],
            confidence: $options['confidence'] ?? null,
            processorName: $options['processorName'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'title' => $this->title,
            'summary' => $this->summary,
            'metadata' => $this->metadata,
            'language' => $this->language,
            'wordCount' => $this->wordCount,
            'extractedEntities' => $this->extractedEntities,
            'keywords' => $this->keywords,
            'confidence' => $this->confidence,
            'processorName' => $this->processorName,
        ];
    }

    public function hasHighConfidence(): bool
    {
        return $this->confidence !== null && $this->confidence > 0.8;
    }

    public function isEmpty(): bool
    {
        return empty(trim($this->content));
    }

    public function getContentLength(): int
    {
        return mb_strlen($this->content);
    }
}
