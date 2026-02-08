<?php

namespace App\Livewire;

use App\Models\KnowledgeDocument;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class MarkdownViewer extends Component
{
    public $documentId;

    public $document;

    public $content;

    public $renderedContent;

    public $showSourceView = false;

    public function mount($documentId)
    {
        $this->documentId = $documentId;
        $this->loadDocument();
    }

    public function loadDocument()
    {
        $this->document = KnowledgeDocument::with(['creator', 'tags', 'asset', 'integration.integrationToken'])->find($this->documentId);

        if (! $this->document) {
            abort(404, 'Document not found');
        }

        // Check permissions
        if (! $this->canViewDocument($this->document)) {
            abort(403, 'You do not have permission to view this document.');
        }

        // Load content based on document type
        $rawContent = $this->getDocumentContent($this->document);

        // Client-side marked.js handles internal URL resolution (asset://, attachment://)
        $this->content = $rawContent;

        // Pre-render content for initial display (will be re-rendered client-side with syntax highlighting)
        $this->renderedContent = $this->content;
    }

    public function toggleView()
    {
        $this->showSourceView = ! $this->showSourceView;
    }

    protected function getDocumentContent(KnowledgeDocument $document): string
    {
        // Text documents - content is in the content field
        if ($document->content_type === 'text') {
            return $document->content ?? '';
        }

        // External documents - content is in the content field
        if ($document->content_type === 'external') {
            return $document->content ?? '';
        }

        // File documents - read from asset
        if ($document->content_type === 'file' && $document->asset) {
            if (! $document->asset->exists()) {
                return '';
            }

            return $document->asset->getContent() ?? '';
        }

        return '';
    }

    protected function canViewDocument(KnowledgeDocument $document): bool
    {
        $user = Auth::user();

        // Admin users can view any document
        if ($user->is_admin ?? false) {
            return true;
        }

        // Owner can always view
        if ($document->created_by === $user->id) {
            return true;
        }

        // Public documents can be viewed by anyone
        if ($document->privacy_level === 'public') {
            return true;
        }

        return false;
    }

    /**
     * Get view original links for the document
     * Returns an array of links for integration-based documents, or a single link for others
     */
    public function getViewOriginalLinks(): array
    {
        if (! $this->document) {
            return [];
        }

        // File documents - no view original (they download instead)
        if ($this->document->content_type === 'file') {
            return [];
        }

        // External documents - check if using an integration provider
        if ($this->document->content_type === 'external') {
            // Check if document uses an integration provider for rendering
            if ($this->document->integration_id && $this->document->integration) {
                try {
                    $providerRegistry = app(\App\Services\Integrations\ProviderRegistry::class);
                    $provider = $providerRegistry->get($this->document->integration->integrationToken->provider_id);
                    if ($provider && $provider instanceof \App\Services\Integrations\Contracts\KnowledgeSourceProvider) {
                        return $provider->renderViewOriginalLinks($this->document);
                    }
                } catch (\Exception $e) {
                    // Fallback to default rendering
                }
            }

            // Fallback: Use external_source_identifier directly
            if ($this->document->external_source_identifier) {
                return [[
                    'url' => $this->document->external_source_identifier,
                    'label' => 'View Original',
                ]];
            }
        }

        // No original URL for text documents
        return [];
    }

    public function getDownloadUrl()
    {
        if (! $this->document) {
            return null;
        }

        return route('knowledge.download', $this->document);
    }

    public function render()
    {
        return view('livewire.markdown-viewer');
    }
}
