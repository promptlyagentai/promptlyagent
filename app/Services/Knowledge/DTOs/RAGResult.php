<?php

namespace App\Services\Knowledge\DTOs;

use Illuminate\Support\Collection;

class RAGResult
{
    public function __construct(
        public readonly string $query,
        public readonly Collection $documents,
        public readonly string $context,
        public readonly float $totalScore = 0.0,
        public readonly int $totalResults = 0,
        public readonly array $metadata = [],
        public readonly ?float $processingTime = null,
        public readonly array $expiredDocuments = [],
        public readonly array $usedFilters = [],
    ) {}

    public static function create(
        string $query,
        Collection $documents,
        array $options = []
    ): self {
        // Use custom context if provided, otherwise generate default context
        $context = $options['context'] ?? self::generateContextFromDocuments($documents, $options['maxContextLength'] ?? 4000);

        return new self(
            query: $query,
            documents: $documents,
            context: $context,
            totalScore: $options['totalScore'] ?? 0.0,
            totalResults: $documents->count(),
            metadata: $options['metadata'] ?? [],
            processingTime: $options['processingTime'] ?? null,
            expiredDocuments: $options['expiredDocuments'] ?? [],
            usedFilters: $options['usedFilters'] ?? [],
        );
    }

    public static function empty(string $query): self
    {
        return new self(
            query: $query,
            documents: collect(),
            context: '',
            totalResults: 0,
        );
    }

    private static function generateContextFromDocuments(Collection $documents, int $maxLength): string
    {
        if ($documents->isEmpty()) {
            return '';
        }

        $context = [];
        $currentLength = 0;

        foreach ($documents as $doc) {
            $content = $doc['content'] ?? $doc['summary'] ?? '';
            $title = $doc['title'] ?? 'Untitled';

            $formatted = "## {$title}\n{$content}\n\n";
            $formattedLength = mb_strlen($formatted);

            if ($currentLength + $formattedLength > $maxLength) {
                // Try to fit a truncated version
                $remaining = $maxLength - $currentLength - mb_strlen("## {$title}\n\n...\n\n");
                if ($remaining > 50) {
                    $truncated = mb_substr($content, 0, $remaining);
                    $context[] = "## {$title}\n{$truncated}...\n";
                }
                break;
            }

            $context[] = $formatted;
            $currentLength += $formattedLength;
        }

        return implode('', $context);
    }

    public function hasResults(): bool
    {
        return $this->totalResults > 0;
    }

    public function hasExpiredDocuments(): bool
    {
        return ! empty($this->expiredDocuments);
    }

    public function getHighScoreDocuments(float $threshold = 0.7): Collection
    {
        return $this->documents->filter(function ($doc) use ($threshold) {
            return ($doc['score'] ?? 0) >= $threshold;
        });
    }

    public function getDocumentsByType(string $type): Collection
    {
        return $this->documents->filter(function ($doc) use ($type) {
            return ($doc['source_type'] ?? null) === $type;
        });
    }

    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'documents' => $this->documents->toArray(),
            'context' => $this->context,
            'totalScore' => $this->totalScore,
            'totalResults' => $this->totalResults,
            'metadata' => $this->metadata,
            'processingTime' => $this->processingTime,
            'expiredDocuments' => $this->expiredDocuments,
            'usedFilters' => $this->usedFilters,
            'hasExpiredDocuments' => $this->hasExpiredDocuments(),
        ];
    }

    public function getContextLength(): int
    {
        return mb_strlen($this->context);
    }
}
