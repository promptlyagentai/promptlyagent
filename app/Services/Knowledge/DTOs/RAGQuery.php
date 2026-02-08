<?php

namespace App\Services\Knowledge\DTOs;

class RAGQuery
{
    public function __construct(
        public readonly string $query,
        public readonly ?int $userId = null,
        public readonly ?int $agentId = null,
        public readonly array $documentIds = [],
        public readonly array $tagIds = [],
        public readonly bool $includeExpired = false,
        public readonly int $limit = 10,
        public readonly array $filters = [],
        public readonly ?string $privacyLevel = null,
        public readonly ?float $relevanceThreshold = null,
        public readonly bool $hybridSearch = true,
        public readonly array $metadata = [],
    ) {}

    public static function create(
        string $query,
        array $options = []
    ): self {
        return new self(
            query: $query,
            userId: $options['userId'] ?? null,
            agentId: $options['agentId'] ?? null,
            documentIds: $options['documentIds'] ?? [],
            tagIds: $options['tagIds'] ?? [],
            includeExpired: $options['includeExpired'] ?? false,
            limit: $options['limit'] ?? 10,
            filters: $options['filters'] ?? [],
            privacyLevel: $options['privacyLevel'] ?? null,
            relevanceThreshold: $options['relevanceThreshold'] ?? null,
            hybridSearch: $options['hybridSearch'] ?? true,
            metadata: $options['metadata'] ?? [],
        );
    }

    public function forAgent(int $agentId): self
    {
        return new self(
            query: $this->query,
            userId: $this->userId,
            agentId: $agentId,
            documentIds: $this->documentIds,
            tagIds: $this->tagIds,
            includeExpired: $this->includeExpired,
            limit: $this->limit,
            filters: $this->filters,
            privacyLevel: $this->privacyLevel,
            relevanceThreshold: $this->relevanceThreshold,
            hybridSearch: $this->hybridSearch,
            metadata: $this->metadata,
        );
    }

    public function forUser(int $userId): self
    {
        return new self(
            query: $this->query,
            userId: $userId,
            agentId: $this->agentId,
            documentIds: $this->documentIds,
            tagIds: $this->tagIds,
            includeExpired: $this->includeExpired,
            limit: $this->limit,
            filters: $this->filters,
            privacyLevel: $this->privacyLevel,
            relevanceThreshold: $this->relevanceThreshold,
            hybridSearch: $this->hybridSearch,
            metadata: $this->metadata,
        );
    }

    public function withDocuments(array $documentIds): self
    {
        return new self(
            query: $this->query,
            userId: $this->userId,
            agentId: $this->agentId,
            documentIds: $documentIds,
            tagIds: $this->tagIds,
            includeExpired: $this->includeExpired,
            limit: $this->limit,
            filters: $this->filters,
            privacyLevel: $this->privacyLevel,
            relevanceThreshold: $this->relevanceThreshold,
            hybridSearch: $this->hybridSearch,
            metadata: $this->metadata,
        );
    }

    public function withTags(array $tagIds): self
    {
        return new self(
            query: $this->query,
            userId: $this->userId,
            agentId: $this->agentId,
            documentIds: $this->documentIds,
            tagIds: $tagIds,
            includeExpired: $this->includeExpired,
            limit: $this->limit,
            filters: $this->filters,
            privacyLevel: $this->privacyLevel,
            relevanceThreshold: $this->relevanceThreshold,
            hybridSearch: $this->hybridSearch,
            metadata: $this->metadata,
        );
    }

    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'userId' => $this->userId,
            'agentId' => $this->agentId,
            'documentIds' => $this->documentIds,
            'tagIds' => $this->tagIds,
            'includeExpired' => $this->includeExpired,
            'limit' => $this->limit,
            'filters' => $this->filters,
            'privacyLevel' => $this->privacyLevel,
            'relevanceThreshold' => $this->relevanceThreshold,
            'hybridSearch' => $this->hybridSearch,
            'metadata' => $this->metadata,
        ];
    }
}
