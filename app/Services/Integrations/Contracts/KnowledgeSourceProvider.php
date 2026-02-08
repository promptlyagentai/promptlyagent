<?php

namespace App\Services\Integrations\Contracts;

use App\Models\User;

/**
 * Interface for integration providers that can serve as knowledge sources
 */
interface KnowledgeSourceProvider extends IntegrationProvider
{
    /**
     * Check if this provider supports knowledge import
     */
    public function supportsKnowledgeImport(): bool;

    /**
     * Get the Livewire component class for browsing and selecting content
     *
     * @return string|null Fully qualified Livewire component class name
     */
    public function getKnowledgeBrowserComponent(): ?string;

    /**
     * Import selected items as knowledge documents
     *
     * @param  User  $user  The user performing the import
     * @param  array  $selectedItems  Array of item identifiers selected by the user
     * @param  array  $options  Additional options (title prefix, tags, privacy level, etc.)
     * @return array Import results with success/failure details
     */
    public function importAsKnowledge(User $user, array $selectedItems, array $options = []): array;

    /**
     * Get display information for the knowledge source
     * Used in the UI to show the integration option
     *
     * @return array ['label' => '...', 'description' => '...', 'icon' => '...']
     */
    public function getKnowledgeSourceInfo(): array;

    /**
     * Render "View Original" links for a knowledge document
     * Returns array of links with labels (e.g., Notion page URLs)
     *
     * @param  \App\Models\KnowledgeDocument  $document
     * @return array Array of ['url' => '...', 'label' => '...'] or empty array if not applicable
     */
    public function renderViewOriginalLinks($document): array;

    /**
     * Get source summary for display in the knowledge document list
     * Returns a human-readable summary of the source (e.g., "3 Notion pages")
     *
     * @param  \App\Models\KnowledgeDocument  $document
     * @return string|null Human-readable source summary or null for default rendering
     */
    public function getSourceSummary($document): ?string;

    /**
     * Get Blade view path for rendering edit modal source information
     * Returns path to a Blade component that displays integration-specific metadata
     *
     * @param  \App\Models\KnowledgeDocument  $document
     * @return string|null Blade view path (e.g., 'components.knowledge.notion-edit-info') or null for default rendering
     */
    public function getEditModalView($document): ?string;

    /**
     * Get Livewire component for managing integration content in the edit modal
     * Returns fully qualified Livewire component class name that allows editing integration-specific content
     *
     * @return string|null Livewire component class (e.g., App\Livewire\NotionPageManager::class) or null for read-only
     */
    public function getPageManagerComponent(): ?string;

    /**
     * Update document content based on configuration changes
     * Called when user modifies integration-specific settings (e.g., changes page selection)
     *
     * @param  \App\Models\KnowledgeDocument  $document
     * @param  array  $config  New configuration data
     * @return array Result with 'success' boolean and optional 'message'
     */
    public function updateDocumentContent($document, array $config): array;

    /**
     * Get the knowledge source class for this provider
     * Used by ExternalKnowledgeManager to dynamically discover knowledge sources
     *
     * @return string|null Fully qualified class name implementing ExternalKnowledgeSourceInterface
     */
    public function getKnowledgeSourceClass(): ?string;
}
