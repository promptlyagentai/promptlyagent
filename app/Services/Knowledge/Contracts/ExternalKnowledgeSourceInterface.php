<?php

namespace App\Services\Knowledge\Contracts;

use App\Services\Knowledge\DTOs\ExternalKnowledgeDocument;
use App\Services\Knowledge\DTOs\ExternalKnowledgeMetadata;
use Carbon\Carbon;

interface ExternalKnowledgeSourceInterface
{
    /**
     * Get the unique identifier for this external knowledge source type.
     */
    public function getSourceType(): string;

    /**
     * Determine the Time-To-Live (TTL) for a document from this source.
     * Returns null for permanent documents, or a Carbon instance for expiration.
     */
    public function getTTL(string $sourceIdentifier): ?Carbon;

    /**
     * Create a backlink URL to the original source document.
     */
    public function createBacklink(string $sourceIdentifier): string;

    /**
     * Retrieve the document content and metadata for processing.
     * This can either return the full document content or just metadata
     * if vectors are provided separately via getVectors().
     */
    public function getDocument(string $sourceIdentifier): ExternalKnowledgeDocument;

    /**
     * Get pre-computed vectors for a document (optional).
     * Return null if vectors should be computed from document content.
     * Return array of vector embeddings if they're already available.
     */
    public function getVectors(string $sourceIdentifier): ?array;

    /**
     * Check if a document has been changed since last processed.
     * Compare against the last modified timestamp or hash.
     */
    public function hasChanged(string $sourceIdentifier, ?Carbon $lastProcessed = null, ?string $lastHash = null): bool;

    /**
     * Get metadata about the document (favicon, title, description, etc.).
     * This is used for display and categorization purposes.
     */
    public function getMetadata(string $sourceIdentifier): ExternalKnowledgeMetadata;

    /**
     * Generate or retrieve missing crucial metadata (title, categories).
     * This is called during ingestion if required metadata is missing.
     */
    public function generateMissingMetadata(string $sourceIdentifier, array $existingMetadata = []): array;

    /**
     * Determine if this document should be marked for "refresh before expiration".
     * Documents marked this way will have automatic refresh events triggered.
     */
    public function shouldRefreshBeforeExpiration(string $sourceIdentifier): bool;

    /**
     * Get the refresh interval for documents that should be refreshed automatically.
     * Returns null if no automatic refresh, or a Carbon interval specification.
     */
    public function getRefreshInterval(string $sourceIdentifier): ?string;

    /**
     * Validate that a source identifier is valid for this source type.
     */
    public function validateSourceIdentifier(string $sourceIdentifier): bool;

    /**
     * Handle webhook notifications for document updates.
     * Returns true if the webhook was processed successfully.
     */
    public function handleWebhook(array $webhookData): bool;

    /**
     * Get authentication requirements for accessing this source.
     * Returns array of required authentication parameters.
     */
    public function getAuthRequirements(): array;

    /**
     * Set authentication credentials for this source.
     */
    public function setAuthCredentials(array $credentials): void;

    /**
     * Test connection to the external source.
     * Returns true if connection is successful, false otherwise.
     */
    public function testConnection(): bool;

    /**
     * Get rate limiting information for this source.
     * Returns array with 'requests_per_minute', 'requests_per_hour', etc.
     */
    public function getRateLimits(): array;

    /**
     * Batch process multiple documents from this source.
     * More efficient than processing individually for some sources.
     */
    public function batchProcess(array $sourceIdentifiers): array;

    /**
     * Get additional configuration options for this source type.
     */
    public function getConfigurationSchema(): array;
}
