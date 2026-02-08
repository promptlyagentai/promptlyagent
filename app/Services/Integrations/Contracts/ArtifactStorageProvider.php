<?php

namespace App\Services\Integrations\Contracts;

use App\Models\Artifact;
use App\Models\ArtifactIntegration;
use App\Models\Integration;
use App\Models\IntegrationToken;

/**
 * Interface for integrations that support artifact storage
 * Allows integrations to store and sync artifacts externally (e.g., Notion pages, Google Docs)
 */
interface ArtifactStorageProvider
{
    /**
     * Store a artifact in the integration for the first time
     *
     * @param  Artifact  $artifact  The artifact to store
     * @param  IntegrationToken  $token  The integration token to use
     * @param  array  $options  Provider-specific options (e.g., parent_id for Notion)
     * @return array ['external_id' => string, 'external_url' => string, 'metadata' => array]
     *
     * @throws \Exception if storage fails
     */
    public function storeArtifact(Artifact $artifact, IntegrationToken $token, array $options = []): array;

    /**
     * Update an existing artifact in the integration
     *
     * @param  Artifact  $artifact  The artifact with updated content
     * @param  ArtifactIntegration  $integration  The existing integration record
     * @return array ['external_id' => string, 'external_url' => string, 'metadata' => array]
     *
     * @throws \Exception if update fails
     */
    public function updateArtifact(Artifact $artifact, ArtifactIntegration $integration): array;

    /**
     * Delete/archive a artifact from the integration
     *
     * @param  ArtifactIntegration  $integration  The integration record
     * @return bool True if deletion succeeded
     *
     * @throws \Exception if deletion fails
     */
    public function deleteArtifact(ArtifactIntegration $integration): bool;

    /**
     * Get the URL to view the artifact in the integration
     *
     * @param  ArtifactIntegration  $integration  The integration record
     * @return string|null Direct URL to the artifact, or null if unavailable
     */
    public function getArtifactUrl(ArtifactIntegration $integration): ?string;

    /**
     * Check if parent/location selection is required before storing
     * Return true if the user needs to select a parent/location (e.g., Notion page, Google Drive folder)
     *
     * @return bool True if parent selection UI should be shown
     */
    public function needsParentSelection(): bool;

    /**
     * Get the default parent ID from integration configuration
     * This is used to pre-select a parent when the user opens the parent selector
     *
     * @param  Integration  $integration  The integration (use-case specific config)
     * @return string|null The default parent ID, or null if not configured
     */
    public function getDefaultParentId(Integration $integration): ?string;

    /**
     * Get parent details by ID for displaying pre-selected parent
     *
     * @param  Integration  $integration  The integration (provides credentials via token)
     * @param  string  $parentId  The parent ID to fetch details for
     * @return array|null ['id' => string, 'title' => string, 'url' => string|null] or null if not found
     */
    public function getParentDetails(Integration $integration, string $parentId): ?array;

    /**
     * Search for available parents/locations where the artifact can be stored
     * Return standardized array format for generic UI rendering
     *
     * @param  Integration  $integration  The integration (provides credentials via token)
     * @param  string|null  $query  Optional search query
     * @param  int  $limit  Maximum number of results to return
     * @return array ['results' => [['id' => string, 'title' => string, 'url' => string|null], ...]]
     */
    public function searchParents(Integration $integration, ?string $query = null, int $limit = 20): array;

    /**
     * Get Livewire component name for parent/location selector
     * Used when initially saving a artifact to allow user to choose location
     *
     * @deprecated Use needsParentSelection() and searchParents() instead for provider-agnostic implementation
     *
     * @return string|null Livewire component name (e.g., 'notion.artifact-parent-selector') or null for no selector
     */
    public function getParentSelectorComponent(): ?string;

    /**
     * Check if the integration supports storing artifacts
     * This method can check capabilities and permissions
     *
     * @param  Integration  $integration  The integration with enabled capabilities
     * @return bool True if artifact storage is available
     */
    public function supportsArtifactStorage(Integration $integration): bool;
}
