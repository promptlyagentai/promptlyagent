<?php

namespace App\Livewire\Components;

use App\Models\Artifact;
use App\Models\ArtifactIntegration;
use App\Models\Integration;
use App\Models\KnowledgeDocument;
use App\Services\Artifacts\ArtifactIntegrationManager;
use App\Services\Integrations\Contracts\ArtifactStorageProvider;
use App\Services\Integrations\ProviderRegistry;
use Livewire\Component;

class ArtifactDrawer extends Component
{
    public ?int $artifactId = null;

    public ?Artifact $artifact = null;

    public string $mode = 'preview'; // 'preview' | 'edit' | 'execute'

    public string $editContent = '';

    public string $originalContent = '';

    public bool $show = false;

    public bool $showDeleteConfirmation = false;

    public bool $softWrap = false;

    public $versions = [];

    public ?int $currentVersionId = null;

    public $viewingVersion = null;

    // Integration properties
    public bool $showIntegrationSelector = false;

    public array $availableIntegrations = [];

    public array $artifactIntegrations = [];

    public ?string $selectedIntegrationId = null;

    public bool $showPageSelector = false;

    public string $pageSearchQuery = '';

    public array $pageSearchResults = [];

    public bool $isSearchingPages = false;

    public ?string $selectedParentPageId = null;

    public ?string $selectedParentPageTitle = null;

    public bool $isSyncingToIntegration = false;

    public bool $hasAnySyncedIntegration = false;

    // Knowledge document references
    public array $knowledgeReferences = [];

    // Pandoc conversions
    public array $conversions = [];

    public bool $queuePdfConversion = false;

    // Title editing
    public bool $editingTitle = false;

    public string $titleInput = '';

    // PDF Template selection
    public string $selectedTemplate = '';

    public bool $showTemplateSelector = false;

    protected $listeners = [
        'open-artifact-drawer' => 'openDrawer',
        'artifact-deleted' => 'handleArtifactDeleted',
    ];

    public function openDrawer($data = [])
    {
        // Handle both array and direct parameters for backwards compatibility
        if (is_array($data)) {
            $artifactId = $data['artifactId'] ?? null;
            $mode = $data['mode'] ?? 'preview';
        } else {
            $artifactId = $data;
            $mode = func_num_args() > 1 ? func_get_arg(1) : 'preview';
        }

        $this->artifactId = $artifactId;
        $this->artifact = Artifact::find($artifactId);

        if (! $this->artifact) {
            $this->dispatch('notify', [
                'message' => 'Artifact not found',
                'type' => 'error',
            ]);

            return;
        }

        $this->mode = $mode;
        $this->editContent = $this->artifact->content ?? '';
        $this->originalContent = $this->artifact->content ?? '';
        $this->show = true;
        $this->showDeleteConfirmation = false;

        // Initialize title editing
        $this->editingTitle = false;
        $this->titleInput = '';

        // Initialize template from artifact metadata, fallback to user preferences or default
        $user = auth()->user();
        $preferences = $user->preferences ?? [];
        $artifactMetadata = $this->artifact->metadata ?? [];
        $this->selectedTemplate = $artifactMetadata['pdf_template'] ?? $preferences['pdf_export']['default_template'] ?? config('pandoc.default_template');

        // Load versions
        $this->loadVersions();

        // Load integrations - safe now because drawer only opens on user click, not auto-opened
        // The freeze was caused by auto-opening during creation, not by loading integrations
        $this->loadIntegrations();

        // Load knowledge document references
        $this->loadKnowledgeReferences();

        // Load conversions
        $this->loadConversions();
    }

    public function loadVersions()
    {
        if (! $this->artifact) {
            $this->versions = [];
            $this->currentVersionId = null;
            $this->viewingVersion = null;

            return;
        }

        $this->versions = $this->artifact->versions()
            ->with('createdBy')
            ->orderBy('created_at', 'desc')
            ->limit(20)  // Limit to recent 20 versions for performance
            ->get()
            ->toArray();

        // Currently viewing the latest (current) version
        $this->currentVersionId = null;
        $this->viewingVersion = null;
    }

    public function closeDrawer()
    {
        // Just close - Alpine.js already handles confirmation
        $this->reset(['artifactId', 'artifact', 'mode', 'editContent', 'originalContent', 'show', 'showDeleteConfirmation']);
    }

    public function forceCloseDrawer()
    {
        $this->reset(['artifactId', 'artifact', 'mode', 'editContent', 'originalContent', 'show', 'showDeleteConfirmation']);
    }

    public function switchMode($mode)
    {
        if ($this->mode === 'edit' && $this->hasUnsavedChanges()) {
            // Will be handled by Alpine.js confirmation
            $this->dispatch('confirm-switch-mode', mode: $mode);

            return;
        }

        $this->mode = $mode;

        // When switching to edit mode, ensure editContent is synced with current content
        if ($mode === 'edit') {
            $this->editContent = $this->artifact->content ?? '';
            $this->originalContent = $this->artifact->content ?? '';
        }
    }

    public function forceSwitchMode($mode)
    {
        $this->mode = $mode;
        if ($mode === 'edit') {
            // Reset to original content when forcing switch (discarding changes)
            $this->editContent = $this->originalContent;
        }
    }

    public function toggleSoftWrap()
    {
        $this->softWrap = ! $this->softWrap;
        $this->dispatch('soft-wrap-toggled');
    }

    public function saveEdit()
    {
        $this->validate([
            'editContent' => 'required|string',
        ]);

        // Create version before modifying content
        try {
            $this->artifact->createVersion();
        } catch (\Illuminate\Database\QueryException $e) {
            // Handle duplicate version constraint violation gracefully
            if ($e->errorInfo[1] === 1062) { // MySQL duplicate entry error
                \Log::warning('ArtifactDrawer: Duplicate version detected', [
                    'artifact_id' => $this->artifact->id,
                    'error' => $e->getMessage(),
                ]);
            } else {
                throw $e;
            }
        }

        $this->artifact->update(['content' => $this->editContent]);
        $this->originalContent = $this->editContent;

        // Reload versions to show the newly created version
        $this->loadVersions();

        $this->dispatch('artifact-updated', artifactId: $this->artifact->id);
        $this->dispatch('notify', [
            'message' => 'Artifact saved successfully',
            'type' => 'success',
        ]);

        $this->switchMode('preview');
    }

    public function cancelEdit()
    {
        $this->editContent = $this->originalContent;
        $this->switchMode('preview');
    }

    public function startEditingTitle()
    {
        $this->editingTitle = true;
        $this->titleInput = $this->artifact->title ?? '';
    }

    public function saveTitle()
    {
        $this->validate([
            'titleInput' => 'required|string|max:255',
        ]);

        $this->artifact->update(['title' => $this->titleInput]);
        $this->editingTitle = false;

        $this->dispatch('notify', [
            'message' => 'Title updated successfully',
            'type' => 'success',
        ]);
    }

    public function cancelEditingTitle()
    {
        $this->editingTitle = false;
        $this->titleInput = '';
    }

    public function toggleTemplateSelector()
    {
        $this->showTemplateSelector = ! $this->showTemplateSelector;
    }

    public function updateSelectedTemplate()
    {
        // Save template to artifact metadata
        $metadata = $this->artifact->metadata ?? [];
        $metadata['pdf_template'] = $this->selectedTemplate;
        $this->artifact->update(['metadata' => $metadata]);

        $this->dispatch('notify', [
            'message' => 'PDF template updated for this artifact',
            'type' => 'success',
        ]);
    }

    public function confirmDelete()
    {
        $this->showDeleteConfirmation = true;
    }

    public function cancelDelete()
    {
        $this->showDeleteConfirmation = false;
    }

    public function delete()
    {
        $artifactId = $this->artifact->id;

        // Step 1: Immediately hide the drawer via Alpine.js to prevent any UI sync issues
        $this->js('
            $wire.show = false;
            $wire.showDeleteConfirmation = false;
        ');

        // Step 2: Delete the artifact from database
        $this->artifact->delete();

        // Step 3: Dispatch native JavaScript events to update parent components
        $this->js("
            setTimeout(() => {
                window.dispatchEvent(new CustomEvent('artifact-deleted', {
                    detail: { artifactId: {$artifactId} }
                }));
                window.dispatchEvent(new CustomEvent('notify', {
                    detail: {
                        message: 'Artifact deleted successfully',
                        type: 'success'
                    }
                }));
            }, 100);
        ");

        // Step 4: Reset component state completely
        $this->artifactId = null;
        $this->artifact = null;
        $this->mode = 'preview';
        $this->editContent = '';
        $this->originalContent = '';
        $this->show = false;
        $this->showDeleteConfirmation = false;
        $this->versions = [];
        $this->currentVersionId = null;
        $this->viewingVersion = null;

        // Don't return anything - let Alpine handle the UI
    }

    public function download()
    {
        return redirect()->route('artifacts.download', $this->artifact);
    }

    public function saveAsKnowledge(): void
    {
        \Illuminate\Support\Facades\Log::info('ArtifactDrawer: saveAsKnowledge called', [
            'artifact_id' => $this->artifact?->id,
            'user_id' => auth()->id(),
        ]);

        try {
            // Create knowledge document from artifact
            $knowledgeDocument = \App\Models\KnowledgeDocument::create([
                'title' => $this->artifact->title,
                'description' => $this->artifact->description ?? 'Created from artifact',
                'content' => $this->artifact->content,
                'content_type' => 'text',  // ENUM: file, text, external
                'source_type' => 'artifact',
                'privacy_level' => $this->artifact->privacy_level ?? 'private',
                'processing_status' => 'completed',  // Artifact content is already available, no processing needed
                'created_by' => auth()->id(),
                'metadata' => [
                    'source_artifact_id' => $this->artifact->id,
                    'original_filetype' => $this->artifact->filetype,
                    'mime_type' => $this->artifact->filetype ? "text/{$this->artifact->filetype}" : 'text/plain',
                    'converted_at' => now()->toISOString(),
                ],
                'word_count' => $this->artifact->word_count,
            ]);

            \Illuminate\Support\Facades\Log::info('Knowledge document created successfully', [
                'knowledge_document_id' => $knowledgeDocument->id,
                'artifact_id' => $this->artifact->id,
            ]);

            // Close the integration selector first
            $this->closeIntegrationSelector();

            // Reload knowledge references to show the newly created document
            $this->loadKnowledgeReferences();

            // Dispatch success notification via JavaScript to avoid Livewire cascade
            $this->js("
                window.dispatchEvent(new CustomEvent('notify', {
                    detail: {
                        message: 'Artifact saved as knowledge document: {$knowledgeDocument->title}',
                        type: 'success'
                    }
                }));
            ");

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to save artifact as knowledge', [
                'artifact_id' => $this->artifact->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Dispatch error notification via JavaScript to avoid Livewire cascade
            $errorMessage = 'Failed to save as knowledge. Please try again.';
            $this->js("
                window.dispatchEvent(new CustomEvent('notify', {
                    detail: {
                        message: '{$errorMessage}',
                        type: 'error'
                    }
                }));
            ");
        }
    }

    public function saveAsDocx(): void
    {
        try {
            \Illuminate\Support\Facades\Log::info('ArtifactDrawer: saveAsDocx called', [
                'artifact_id' => $this->artifact?->id,
                'user_id' => auth()->id(),
            ]);

            // Redirect to download route
            $this->redirect(route('artifacts.download-docx', ['artifact' => $this->artifact->id]));

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to export artifact as DOCX', [
                'artifact_id' => $this->artifact->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->dispatch('notify', [
                'message' => 'Failed to export as DOCX. Please try again.',
                'type' => 'error',
            ]);
        }
    }

    public function hasUnsavedChanges()
    {
        // Only check for unsaved changes when in edit mode
        if ($this->mode !== 'edit') {
            return false;
        }

        // Normalize empty values (null, empty string, whitespace-only) for comparison
        $editContent = trim($this->editContent ?? '');
        $originalContent = trim($this->originalContent ?? '');

        return $editContent !== $originalContent;
    }

    public function handleArtifactDeleted($artifactId)
    {
        if ($this->artifactId === $artifactId) {
            $this->reset(['artifactId', 'artifact', 'mode', 'editContent', 'originalContent', 'show', 'showDeleteConfirmation']);
        }
    }

    public function updatedCurrentVersionId($value)
    {
        if (empty($value)) {
            $this->viewCurrentVersion();
        } else {
            $this->viewVersion($value);
        }
    }

    public function viewVersion($versionId)
    {
        $version = collect($this->versions)->firstWhere('id', $versionId);

        if (! $version) {
            $this->dispatch('notify', [
                'message' => 'Version not found',
                'type' => 'error',
            ]);

            return;
        }

        $this->currentVersionId = $versionId;
        $this->viewingVersion = $version;
    }

    public function viewCurrentVersion()
    {
        $this->currentVersionId = null;
        $this->viewingVersion = null;
    }

    public function restoreVersion($versionId)
    {
        if (! $this->artifact) {
            return;
        }

        $version = \App\Models\ArtifactVersion::find($versionId);

        if (! $version || $version->artifact_id !== $this->artifact->id) {
            $this->dispatch('notify', [
                'message' => 'Version not found',
                'type' => 'error',
            ]);

            return;
        }

        // Restore the version
        $this->artifact->restoreVersion($version);
        $this->artifact->refresh();

        // Reload versions and reset to current
        $this->loadVersions();
        $this->viewCurrentVersion();

        // Update edit content if in edit mode
        if ($this->mode === 'edit') {
            $this->editContent = $this->artifact->content ?? '';
            $this->originalContent = $this->artifact->content ?? '';
        }

        $this->dispatch('notify', [
            'message' => 'Version restored successfully',
            'type' => 'success',
        ]);

        $this->dispatch('artifact-updated', artifactId: $this->artifact->id);
    }

    public function getDisplayContent()
    {
        $content = $this->viewingVersion
            ? $this->viewingVersion['content']
            : ($this->artifact->content ?? '');

        // Resolve internal URLs (asset:// and attachment:) if artifact is markdown
        if ($this->artifact && in_array($this->artifact->filetype, ['md', 'markdown'])) {
            $resolver = app(MarkdownUrlResolver::class);

            // Use artifact author for user-scoped lookups (artifacts may not have interaction context)
            $userId = $this->artifact->author_id ?? auth()->id();
            $resolver->setUser($userId);

            // If artifact has chat_interaction_id, use it for more precise lookups
            if ($this->artifact->chat_interaction_id) {
                $resolver->setInteractionId($this->artifact->chat_interaction_id);
            }

            $content = $resolver->resolve($content);
        }

        return $content;
    }

    // Integration Methods

    public function loadIntegrations(): void
    {
        if (! $this->artifact) {
            return;
        }

        $manager = app(ArtifactIntegrationManager::class);

        // Get all available integrations
        $allIntegrations = $manager->getAvailableIntegrations(auth()->user());

        // Get artifact's existing integrations indexed by integration_id
        $existingIntegrations = $this->artifact->integrations()
            ->with('integration.integrationToken')
            ->get()
            ->keyBy('integration_id');

        // Build unified integration list with sync status
        $this->availableIntegrations = $allIntegrations
            ->map(function ($integration) use ($existingIntegrations) {
                $token = $integration->integrationToken;
                $artifactIntegration = $existingIntegrations->get($integration->id);

                if ($artifactIntegration) {
                    // Already synced - include integration details
                    return [
                        'id' => $integration->id,
                        'provider_id' => $token->provider_id,
                        'provider_name' => $integration->name,
                        'is_synced' => true,
                        'integration_id' => $artifactIntegration->id,
                        'external_url' => $artifactIntegration->external_url,
                        'external_id' => $artifactIntegration->external_id,
                        'parent_title' => $artifactIntegration->sync_metadata['parent_page_title'] ?? 'Unknown Page',
                        'last_sync_status' => $artifactIntegration->last_sync_status,
                        'auto_sync_enabled' => $artifactIntegration->auto_sync_enabled,
                    ];
                } else {
                    // Not synced yet
                    return [
                        'id' => $integration->id,
                        'provider_id' => $token->provider_id,
                        'provider_name' => $integration->name,
                        'is_synced' => false,
                    ];
                }
            })
            ->values()
            ->toArray();

        // Set flag for modal title
        $this->hasAnySyncedIntegration = collect($this->availableIntegrations)->contains('is_synced', true);

        // Load current artifact integrations for footer display
        $this->artifactIntegrations = $existingIntegrations
            ->map(function ($artifactIntegration) {
                return [
                    'id' => $artifactIntegration->id,
                    'integration_id' => $artifactIntegration->integration_id,
                    'provider_id' => $artifactIntegration->integration->integrationToken->provider_id,
                    'provider_name' => $artifactIntegration->integration->integrationToken->provider_name,
                    'external_url' => $artifactIntegration->external_url,
                    'external_id' => $artifactIntegration->external_id,
                    'parent_title' => $artifactIntegration->sync_metadata['parent_page_title'] ?? 'Unknown Page',
                    'auto_sync_enabled' => $artifactIntegration->auto_sync_enabled,
                    'last_sync_status' => $artifactIntegration->last_sync_status,
                    'last_synced_at' => $artifactIntegration->last_synced_at?->diffForHumans(),
                ];
            })
            ->values()
            ->toArray();
    }

    public function loadKnowledgeReferences(): void
    {
        if (! $this->artifact) {
            return;
        }

        // Query knowledge documents where metadata contains this artifact's ID
        $this->knowledgeReferences = KnowledgeDocument::where('created_by', auth()->id())
            ->whereJsonContains('metadata->source_artifact_id', $this->artifact->id)
            ->get()
            ->map(function ($doc) {
                return [
                    'id' => $doc->id,
                    'title' => $doc->title,
                    'created_at' => $doc->created_at->diffForHumans(),
                ];
            })
            ->toArray();
    }

    public function openIntegrationSelector(): void
    {
        $this->loadIntegrations();
        $this->showIntegrationSelector = true;
    }

    public function closeIntegrationSelector(): void
    {
        $this->showIntegrationSelector = false;
        $this->selectedIntegrationId = null;
        $this->resetPageSelector();
    }

    public function selectIntegration(string $integrationId): void
    {
        $this->selectedIntegrationId = $integrationId;

        // Find the integration in our list to check if it's synced
        $selectedIntegration = collect($this->availableIntegrations)->firstWhere('id', $integrationId);

        if (! $selectedIntegration) {
            $this->dispatch('notify', [
                'message' => 'Integration not found',
                'type' => 'error',
            ]);

            return;
        }

        // If already synced, update it directly
        if ($selectedIntegration['is_synced']) {
            $this->updateSyncedIntegration($selectedIntegration['integration_id']);

            return;
        }

        // Not synced yet - check if parent selection is needed
        $integration = Integration::with('integrationToken')->find($integrationId);

        if (! $integration) {
            $this->dispatch('notify', [
                'message' => 'Integration not found',
                'type' => 'error',
            ]);

            return;
        }

        $token = $integration->integrationToken;

        // Get provider and check if parent selection is needed
        $registry = app(ProviderRegistry::class);
        $provider = $registry->get($token->provider_id);

        if ($provider instanceof ArtifactStorageProvider && $provider->needsParentSelection()) {
            $this->openPageSelector();
        } else {
            // Save directly without parent selection
            $this->isSyncingToIntegration = true;

            try {
                $this->saveToIntegration();
            } finally {
                $this->isSyncingToIntegration = false;
            }
        }
    }

    public function openPageSelector(): void
    {
        $this->showPageSelector = true;
        $this->pageSearchQuery = '';
        $this->pageSearchResults = [];

        // Load default parent if configured
        if ($this->selectedIntegrationId) {
            $integration = Integration::with('integrationToken')->find($this->selectedIntegrationId);

            if ($integration) {
                $token = $integration->integrationToken;
                $registry = app(ProviderRegistry::class);
                $provider = $registry->get($token->provider_id);

                if ($provider instanceof ArtifactStorageProvider) {
                    $defaultParentId = $provider->getDefaultParentId($integration);

                    if ($defaultParentId) {
                        // Pre-select the default parent
                        $parentDetails = $provider->getParentDetails($integration, $defaultParentId);

                        if ($parentDetails) {
                            $this->selectedParentPageId = $parentDetails['id'];
                            $this->selectedParentPageTitle = $parentDetails['title'];
                        }
                    }
                }
            }
        }

        $this->searchPages();
    }

    public function closePageSelector(): void
    {
        $this->resetPageSelector();
    }

    protected function resetPageSelector(): void
    {
        $this->showPageSelector = false;
        $this->pageSearchQuery = '';
        $this->pageSearchResults = [];
        $this->selectedParentPageId = null;
        $this->selectedParentPageTitle = null;
    }

    public function updatedPageSearchQuery(): void
    {
        $this->searchPages();
    }

    public function searchPages(): void
    {
        if (! $this->selectedIntegrationId) {
            return;
        }

        $this->isSearchingPages = true;

        try {
            $integration = Integration::with('integrationToken')->find($this->selectedIntegrationId);

            if (! $integration) {
                return;
            }

            $token = $integration->integrationToken;
            $registry = app(ProviderRegistry::class);
            $provider = $registry->get($token->provider_id);

            // Use provider-agnostic searchParents method
            if ($provider instanceof ArtifactStorageProvider) {
                $results = $provider->searchParents(
                    $integration,
                    $this->pageSearchQuery ?: null,
                    20 // Show top 20 results
                );

                // Results are already in standardized format
                $this->pageSearchResults = $results['results'] ?? [];
            } else {
                $this->pageSearchResults = [];
            }
        } catch (\Exception $e) {
            $this->pageSearchResults = [];
            $this->dispatch('notify', [
                'message' => 'Failed to search pages: '.$e->getMessage(),
                'type' => 'error',
            ]);
        } finally {
            $this->isSearchingPages = false;
        }
    }

    public function selectParentPage(string $pageId, string $pageTitle): void
    {
        $this->selectedParentPageId = $pageId;
        $this->selectedParentPageTitle = $pageTitle;
    }

    public function confirmSaveToIntegration(): void
    {
        if (! $this->selectedIntegrationId) {
            $this->dispatch('notify', [
                'message' => 'Please select an integration',
                'type' => 'error',
            ]);

            return;
        }

        if (! $this->selectedParentPageId) {
            $this->dispatch('notify', [
                'message' => 'Please select a parent page',
                'type' => 'error',
            ]);

            return;
        }

        $this->isSyncingToIntegration = true;

        try {
            $this->saveToIntegration();
        } finally {
            // Ensure loading state is always reset, even if saveToIntegration() fails
            $this->isSyncingToIntegration = false;
        }
    }

    protected function saveToIntegration(): void
    {
        try {
            $integration = Integration::with('integrationToken')->find($this->selectedIntegrationId);

            if (! $integration) {
                throw new \Exception('Integration not found');
            }

            $manager = app(ArtifactIntegrationManager::class);

            $manager->syncToIntegration($this->artifact, $integration, [
                'parent_page_id' => $this->selectedParentPageId,
                'parent_page_title' => $this->selectedParentPageTitle,
                'auto_sync_enabled' => false, // Default to manual sync
            ]);

            // Reload integrations to show updated state
            $this->loadIntegrations();

            $this->dispatch('notify', [
                'message' => "Artifact saved to {$integration->name} successfully",
                'type' => 'success',
            ]);

            $this->closeIntegrationSelector();

        } catch (\Exception $e) {
            $this->dispatch('notify', [
                'message' => 'Failed to save artifact: '.$e->getMessage(),
                'type' => 'error',
            ]);
            // Re-throw to ensure caller's finally block runs
            throw $e;
        }
    }

    public function toggleAutoSync(int $integrationId): void
    {
        try {
            $integration = ArtifactIntegration::find($integrationId);

            if (! $integration || $integration->artifact_id !== $this->artifact->id) {
                throw new \Exception('Integration not found');
            }

            $manager = app(ArtifactIntegrationManager::class);
            $manager->toggleAutoSync($integration);

            // Reload integrations
            $this->loadIntegrations();

            $status = $integration->fresh()->auto_sync_enabled ? 'enabled' : 'disabled';

            $this->dispatch('notify', [
                'message' => "Auto-sync {$status} successfully",
                'type' => 'success',
            ]);

        } catch (\Exception $e) {
            $this->dispatch('notify', [
                'message' => 'Failed to toggle auto-sync: '.$e->getMessage(),
                'type' => 'error',
            ]);
        }
    }

    public function removeFromIntegration(int $integrationId, bool $deleteExternal = false): void
    {
        try {
            $artifactIntegration = ArtifactIntegration::with('integration')->find($integrationId);

            if (! $artifactIntegration || $artifactIntegration->artifact_id !== $this->artifact->id) {
                throw new \Exception('Integration not found');
            }

            $integration = $artifactIntegration->integration;
            $manager = app(ArtifactIntegrationManager::class);

            $manager->removeFromIntegration($this->artifact, $integration, $deleteExternal);

            // Reload integrations
            $this->loadIntegrations();

            $this->dispatch('notify', [
                'message' => 'Artifact removed from integration successfully',
                'type' => 'success',
            ]);

        } catch (\Exception $e) {
            $this->dispatch('notify', [
                'message' => 'Failed to remove artifact: '.$e->getMessage(),
                'type' => 'error',
            ]);
        }
    }

    public function updateSyncedIntegration(int $integrationId): void
    {
        try {
            $integration = ArtifactIntegration::find($integrationId);

            if (! $integration || $integration->artifact_id !== $this->artifact->id) {
                throw new \Exception('Integration not found');
            }

            $manager = app(ArtifactIntegrationManager::class);

            // Manually trigger update
            $manager->updateInIntegration($this->artifact, $integration);

            // Reload integrations
            $this->loadIntegrations();

            // Close modal
            $this->closeIntegrationSelector();

            $this->dispatch('notify', [
                'message' => "Updated in {$integration->integrationToken->provider_name} successfully",
                'type' => 'success',
            ]);

        } catch (\Exception $e) {
            $this->dispatch('notify', [
                'message' => 'Failed to update artifact: '.$e->getMessage(),
                'type' => 'error',
            ]);
        }
    }

    public function loadConversions(): void
    {
        if (! $this->artifact) {
            return;
        }

        $this->conversions = $this->artifact->conversions()
            ->with('asset')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($conversion) {
                $downloadUrl = null;
                if ($conversion->asset_id && $conversion->asset) {
                    try {
                        $downloadUrl = route('artifacts.conversion.download', [
                            'artifact' => $this->artifact->id,
                            'conversion' => $conversion->id,
                        ]);
                    } catch (\Exception $e) {
                        // Ignore if route doesn't exist yet
                    }
                }

                return [
                    'id' => $conversion->id,
                    'format' => strtoupper($conversion->output_format),
                    'template' => $conversion->template ?? 'default',
                    'status' => $conversion->status,
                    'created_at' => $conversion->created_at->diffForHumans(),
                    'file_size' => $conversion->formatted_file_size ?? 'N/A',
                    'error' => $conversion->error_message,
                    'asset_id' => $conversion->asset_id,
                    'download_url' => $downloadUrl,
                ];
            })
            ->toArray();
    }

    public function exportPdf(): void
    {
        if (! $this->artifact) {
            return;
        }

        // If queueing is enabled, queue the conversion
        if ($this->queuePdfConversion) {
            try {
                // Check for existing pending/processing conversion
                $existing = $this->artifact->conversions()
                    ->where('output_format', 'pdf')
                    ->where('template', $this->selectedTemplate ?? config('pandoc.default_template'))
                    ->whereIn('status', ['pending', 'processing'])
                    ->first();

                if ($existing) {
                    $this->dispatch('notify', [
                        'message' => 'A PDF conversion is already in progress for this artifact.',
                        'type' => 'info',
                    ]);

                    return;
                }

                // Create conversion record
                $conversion = $this->artifact->conversions()->create([
                    'output_format' => 'pdf',
                    'template' => $this->selectedTemplate ?? config('pandoc.default_template'),
                    'created_by' => auth()->id(),
                    'status' => 'pending',
                ]);

                // Dispatch job
                \App\Jobs\ConvertArtifactToPandoc::dispatch($conversion);

                $this->dispatch('notify', [
                    'message' => 'PDF conversion queued! You\'ll receive a notification with a download link when it\'s ready.',
                    'type' => 'success',
                ]);

                $this->loadConversions();
                $this->showIntegrationSelector = false;
            } catch (\Exception $e) {
                $this->dispatch('notify', [
                    'message' => 'Failed to queue conversion: '.$e->getMessage(),
                    'type' => 'error',
                ]);
            }
        } else {
            // Sync download - redirect to download URL
            $this->redirect(route('artifacts.download-pdf', [
                'artifact' => $this->artifact->id,
                'template' => $this->selectedTemplate ?? config('pandoc.default_template'),
            ]));
        }
    }

    public function refreshConversions(): void
    {
        $this->loadConversions();

        $this->dispatch('notify', [
            'message' => 'Conversions refreshed',
            'type' => 'success',
        ]);
    }

    public function render()
    {
        return view('livewire.components.artifact-drawer');
    }
}
