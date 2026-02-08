<?php

namespace App\Livewire;

use App\Jobs\RefreshExternalKnowledgeJob;
use App\Models\KnowledgeDocument;
use App\Models\KnowledgeTag;
use App\Services\Knowledge\KnowledgeManager as KnowledgeManagerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ExternalKnowledgeEditor extends Component
{
    public $showModal = false;

    public ?KnowledgeDocument $document = null;

    // Form fields
    public $sourceIdentifier = '';

    public $title = '';

    public $description = '';

    public $notes = '';

    public $autoRefreshEnabled = false;

    public $refreshIntervalMinutes = 60;

    public $ttlHours = 24;

    public array $tags = [];

    public string $new_tag = '';

    public $selectedTab = 'settings'; // settings, metadata, preview

    // Refresh status tracking
    public $isRefreshing = false;

    public $refreshProgress = '';

    public $hasUnsavedIntegrationChanges = false;

    protected $listeners = [
        'open-external-knowledge-editor' => 'openEditor',
        'integration-content-changed' => 'handleIntegrationContentChanges',
    ];

    protected function rules(): array
    {
        // For integration-based documents, source identifier is managed by the integration
        $isIntegration = $this->document && $this->document->integration_id;

        return [
            'sourceIdentifier' => $isIntegration ? 'nullable|string' : ['required', 'string', 'url', 'regex:/^https?:\/\/.+/'],
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:50000',
            'autoRefreshEnabled' => 'boolean',
            'refreshIntervalMinutes' => 'required|integer|min:1|max:43200',
            'ttlHours' => 'nullable|integer|min:0|max:8760', // 0 = never expire
            'tags' => 'array|max:10',
            'tags.*' => 'string|max:50',
        ];
    }

    public function openEditor($documentId): void
    {
        $this->document = KnowledgeDocument::with(['creator', 'tags', 'integration.integrationToken'])->findOrFail($documentId);

        // Check if this is a refreshable document (external or text with URL)
        if (! in_array($this->document->content_type, ['external', 'text'])) {
            $this->dispatch('error', 'This document is not a refreshable knowledge source.');

            return;
        }

        // For text documents, require external_source_identifier (URL)
        if ($this->document->content_type === 'text' && ! $this->document->external_source_identifier) {
            $this->dispatch('error', 'This text document does not have an external source URL and cannot be managed here.');

            return;
        }

        // Check permissions
        if (! Gate::allows('update', $this->document)) {
            $this->dispatch('error', 'You do not have permission to edit this document.');

            return;
        }

        // Load document data into form fields
        $this->sourceIdentifier = $this->document->external_source_identifier ?? '';
        $this->title = $this->document->title;
        $this->description = $this->document->description ?? '';

        // Load notes from metadata (for text documents)
        $this->notes = $this->document->metadata['notes'] ?? '';

        $this->autoRefreshEnabled = $this->document->auto_refresh_enabled ?? false;
        $this->refreshIntervalMinutes = $this->document->refresh_interval_minutes ?? 60;

        // Calculate TTL in hours
        if ($this->document->ttl_expires_at) {
            $this->ttlHours = max(1, (int) round(now()->diffInHours($this->document->ttl_expires_at, false)));
        } else {
            // ttl_expires_at is null = never expire = 0
            $this->ttlHours = 0;
        }

        // Load tags
        $this->tags = $this->document->tags->pluck('name')->toArray();

        $this->selectedTab = 'settings';
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->document = null;
        $this->reset(['sourceIdentifier', 'title', 'description', 'notes', 'autoRefreshEnabled', 'refreshIntervalMinutes', 'ttlHours', 'selectedTab', 'isRefreshing', 'refreshProgress', 'hasUnsavedIntegrationChanges', 'tags', 'new_tag']);
    }

    public function handleIntegrationContentChanges($hasChanges): void
    {
        $this->hasUnsavedIntegrationChanges = $hasChanges;
    }

    public function switchTab($tab): void
    {
        $this->selectedTab = $tab;
    }

    public function editContent(): void
    {
        if (! $this->document) {
            $this->dispatch('error', 'No document loaded.');

            return;
        }

        if ($this->document->content_type !== 'text') {
            $this->dispatch('error', 'Only text documents can have their content edited.');

            return;
        }

        // Dispatch event to open the regular knowledge editor
        $this->dispatch('openDocumentEditor', $this->document->id);

        // Close this modal
        $this->closeModal();
    }

    public function addTag(): void
    {
        $tagName = trim($this->new_tag);

        if (empty($tagName)) {
            return;
        }

        if (in_array($tagName, $this->tags)) {
            $this->addError('new_tag', 'This tag is already added.');

            return;
        }

        if (count($this->tags) >= 10) {
            $this->addError('new_tag', 'Maximum 10 tags allowed.');

            return;
        }

        $this->tags[] = $tagName;
        $this->new_tag = '';
        $this->resetValidation('new_tag');
    }

    public function removeTag(int $index): void
    {
        unset($this->tags[$index]);
        $this->tags = array_values($this->tags); // Re-index array
    }

    public function save(): void
    {
        if (! $this->document) {
            $this->dispatch('error', 'No document loaded.');

            return;
        }

        if (! Gate::allows('update', $this->document)) {
            $this->dispatch('error', 'You do not have permission to edit this document.');

            return;
        }

        // Check for unsaved integration content changes
        if ($this->hasUnsavedIntegrationChanges) {
            $this->dispatch('error', 'Please update the content first by clicking "Update Content" in the Manage Content section, or revert your changes.');

            return;
        }

        $this->validate();

        try {
            // Prepare update data
            $updateData = [
                'title' => $this->title,
                'description' => $this->description,
                'auto_refresh_enabled' => $this->autoRefreshEnabled,
                'refresh_interval_minutes' => (int) $this->refreshIntervalMinutes,
                'ttl_expires_at' => $this->ttlHours > 0 ? now()->addHours((int) $this->ttlHours) : null,
                'next_refresh_at' => $this->autoRefreshEnabled ?
                    now()->addMinutes((int) $this->refreshIntervalMinutes) : null,
            ];

            // Only update external_source_identifier for non-integration documents
            if (! $this->document->integration_id) {
                $updateData['external_source_identifier'] = $this->sourceIdentifier;
            }

            // Update notes in metadata (for text documents)
            if ($this->document->content_type === 'text') {
                $metadata = $this->document->metadata ?? [];
                $metadata['notes'] = $this->notes;
                $updateData['metadata'] = $metadata;
            }

            // Update document
            $this->document->update($updateData);

            // Sync tags using KnowledgeManager
            $knowledgeManager = app(KnowledgeManagerService::class);
            $userId = $this->document->created_by ?? Auth::id();
            $knowledgeManager->attachTags($this->document, $this->tags, $userId);

            $this->dispatch('success', 'External knowledge source updated successfully.');
            $this->dispatch('external-knowledge-updated', $this->document->id);

            // Refresh the document instance
            $this->document->refresh();

            // Close the modal after successful save
            $this->closeModal();

        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to update document: '.$e->getMessage());
        }
    }

    public function triggerManualRefresh(): void
    {
        if (! $this->document) {
            $this->dispatch('error', 'No document loaded.');

            return;
        }

        if (! Gate::allows('refresh', $this->document)) {
            $this->dispatch('error', 'You do not have permission to refresh this document.');

            return;
        }

        try {
            $this->isRefreshing = true;
            $this->refreshProgress = 'Queuing refresh job...';

            // Update status to in_progress before dispatching job
            $this->document->update([
                'last_refresh_attempted_at' => now(),
                'last_refresh_status' => 'in_progress',
                'last_refresh_error' => null,
            ]);

            // Dispatch refresh job
            RefreshExternalKnowledgeJob::dispatch($this->document);

            $this->refreshProgress = 'Refresh job queued successfully.';
            $this->dispatch('success', 'Manual refresh initiated. The document will be updated shortly.');

            // Refresh the document instance
            $this->document->refresh();

            // Reset refreshing state after a short delay
            $this->dispatch('$refresh');

        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to trigger refresh: '.$e->getMessage());
        } finally {
            $this->isRefreshing = false;
            $this->refreshProgress = '';
        }
    }

    public function getRefreshHistory(): array
    {
        if (! $this->document) {
            return [];
        }

        $history = [];

        if ($this->document->last_refresh_attempted_at) {
            $history[] = [
                'attempted_at' => $this->document->last_refresh_attempted_at,
                'status' => $this->document->last_refresh_status ?? 'unknown',
                'error' => $this->document->last_refresh_error,
                'attempt_count' => $this->document->refresh_attempt_count ?? 0,
            ];
        }

        return $history;
    }

    public function getMetadata(): array
    {
        if (! $this->document || ! $this->document->external_metadata) {
            return [];
        }

        return $this->document->external_metadata;
    }

    public function getBacklinkUrl(): ?string
    {
        if (! $this->document) {
            return null;
        }

        // For URL sources, the external_source_identifier is the URL
        // For other sources, we might need to construct the backlink differently
        if ($this->document->source_type === 'url') {
            return $this->document->external_source_identifier;
        }

        // For other source types, try to get from metadata
        return $this->document->metadata['backlink_url'] ?? null;
    }

    public function getDocumentEmbeddingStatus($document): array
    {
        $knowledgeManager = app(\App\Services\Knowledge\KnowledgeManager::class);

        return $knowledgeManager->getDocumentEmbeddingStatus($document);
    }

    public function render()
    {
        $availableTags = KnowledgeTag::select('name', 'color')
            ->orderBy('name')
            ->limit(20)
            ->get();

        return view('livewire.external-knowledge-editor', [
            'availableTags' => $availableTags,
        ]);
    }
}
