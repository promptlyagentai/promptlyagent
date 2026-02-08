<?php

namespace App\Livewire;

use App\Models\KnowledgeDocument;
use App\Models\KnowledgeTag;
use App\Services\Integrations\Contracts\KnowledgeSourceProvider;
use App\Services\Integrations\ProviderRegistry;
use App\Services\Knowledge\ExternalKnowledgeManager;
use App\Services\Knowledge\FileAnalyzer;
use App\Services\Knowledge\KnowledgeManager as KnowledgeManagerService;
use App\Services\LinkValidator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Livewire\WithFileUploads;

class KnowledgeEditor extends Component
{
    use WithFileUploads;

    public ?KnowledgeDocument $document = null;

    public bool $showModal = true;

    public bool $isEditing = false;

    // Form fields
    public string $title = '';

    public string $description = '';

    public string $content = '';

    public string $privacy_level = 'private';

    public array $tags = [];

    public ?int $ttl_hours = null;

    public string $content_type = 'text';

    // File upload
    public $uploaded_file = null;

    public bool $analyzing_file = false;

    public array $ai_suggestions = [];

    // Archive handling
    public bool $is_archive = false;

    public bool $show_archive_warning = false;

    public array $archive_info = [];

    public bool $archive_confirmed = false;

    // External knowledge source
    public string $external_source_url = '';

    public bool $validating_url = false;

    public array $url_validation_results = [];

    public bool $auto_refresh_enabled = false;

    public int $refresh_interval_minutes = 60;

    // Integration sources (dynamically loaded)
    public array $available_knowledge_integrations = [];

    // New tag input
    public string $new_tag = '';

    // Import progress tracking
    public bool $show_import_progress = false;

    public int $import_total = 0;

    public int $import_processed = 0;

    public int $import_successful = 0;

    public int $import_failed = 0;

    public array $import_results = [];

    // Notion preview state
    public array $notion_preview_data = [];

    public bool $has_notion_preview = false;

    protected $listeners = [
        'openDocumentEditor' => 'openEditor',
        'integration-import-selected' => 'handleIntegrationImport',
        'notion-preview-ready' => 'handleNotionPreview',
    ];

    public function boot()
    {
        // Ensure proper state on component boot
        if (! $this->document) {
            $this->isEditing = false;
        }
    }

    public function booted()
    {
        // Double-check state after component is fully booted
        if (! $this->document && $this->isEditing) {
            $this->isEditing = false;
            $this->resetForm();
        }
    }

    public function mount(?KnowledgeDocument $document = null)
    {
        $this->document = $document;

        // More robust editing detection
        $this->isEditing = $document && $document->exists && isset($document->id);

        if ($this->isEditing) {
            $this->loadDocument();
        } else {
            $this->resetForm();
        }

        // Load available knowledge source integrations
        $this->loadAvailableKnowledgeIntegrations();
    }

    public function openEditor($documentId = null)
    {
        if ($documentId) {
            $this->document = KnowledgeDocument::find($documentId);
            $this->isEditing = true;
            $this->loadDocument();
        }
        $this->showModal = true;
    }

    protected function loadDocument()
    {
        if (! $this->document) {
            return;
        }

        $this->title = $this->document->title ?? '';
        $this->description = $this->document->description ?? '';
        $this->content = $this->document->content ?? '';
        $this->privacy_level = $this->document->privacy_level;
        $this->content_type = $this->document->content_type;
        $this->tags = $this->document->tags->pluck('name')->toArray();

        // Calculate TTL hours if expires_at is set
        if ($this->document->ttl_expires_at) {
            $this->ttl_hours = now()->diffInHours($this->document->ttl_expires_at, false);
            if ($this->ttl_hours < 0) {
                $this->ttl_hours = null; // Document already expired
            }
        }
    }

    protected function resetForm()
    {
        $this->title = '';
        $this->description = '';
        $this->content = '';
        $this->privacy_level = 'private';
        $this->content_type = 'text';
        $this->tags = [];
        $this->ttl_hours = null;
        $this->uploaded_file = null;
        $this->analyzing_file = false;
        $this->ai_suggestions = [];
        $this->is_archive = false;
        $this->show_archive_warning = false;
        $this->archive_info = [];
        $this->archive_confirmed = false;
        $this->external_source_url = '';
        $this->validating_url = false;
        $this->url_validation_results = [];
        $this->auto_refresh_enabled = false;
        $this->refresh_interval_minutes = 60;
        $this->new_tag = '';
    }

    /**
     * Load available knowledge source integrations for the current user
     * Creates separate entries for each integration token (supporting multiple connections per provider)
     */
    protected function loadAvailableKnowledgeIntegrations(): void
    {
        $this->available_knowledge_integrations = [];

        try {
            $providerRegistry = app(ProviderRegistry::class);
            $user = Auth::user();

            // Get all registered providers
            $providers = $providerRegistry->all();

            foreach ($providers as $provider) {
                // Check if provider supports knowledge import
                if (! ($provider instanceof KnowledgeSourceProvider) || ! $provider->supportsKnowledgeImport()) {
                    continue;
                }

                // Check if provider requires authentication
                $requiresAuth = method_exists($provider, 'requiresAuthentication') && $provider->requiresAuthentication();

                if ($requiresAuth) {
                    // Get ALL active integrations for this provider (not just tokens)
                    $integrations = \App\Models\Integration::whereHas('integrationToken', function ($query) use ($provider) {
                        $query->where('provider_id', $provider->getProviderId())
                            ->where('status', 'active');
                    })
                        ->where('user_id', $user->id)
                        ->where('status', 'active')
                        ->with('integrationToken')
                        ->get();

                    if ($integrations->isEmpty()) {
                        continue; // Skip providers that don't have any active integrations
                    }

                    // Add one entry per integration to support multiple connections
                    foreach ($integrations as $integration) {
                        $info = $provider->getKnowledgeSourceInfo();

                        // Use integration name to distinguish between multiple connections
                        $label = $integration->name ?? $integration->integrationToken->provider_name ?? ($info['label'] ?? $provider->getProviderName());

                        // Evaluate capabilities for the underlying token (determines availability)
                        $evaluation = $provider->evaluateTokenCapabilities($integration->integrationToken);

                        // Check if Knowledge:add is available (token has permissions) and enabled (integration setting)
                        // Skip if not available or not enabled - they should manage this in integration settings
                        if (! in_array('Knowledge:add', $evaluation['available']) ||
                            ! $integration->isCapabilityEnabled('Knowledge:add')) {
                            continue;
                        }

                        // Available and enabled - show normally
                        $this->available_knowledge_integrations[] = [
                            'provider_id' => $provider->getProviderId(),
                            'integration_id' => $integration->id,
                            'label' => $label,
                            'description' => $info['description'] ?? $provider->getDescription(),
                            'icon' => $info['icon'] ?? 'link',
                            'component_class' => $provider->getKnowledgeBrowserComponent(),
                        ];
                    }
                } else {
                    // Provider doesn't require auth - add single entry
                    $info = $provider->getKnowledgeSourceInfo();

                    $this->available_knowledge_integrations[] = [
                        'provider_id' => $provider->getProviderId(),
                        'token_id' => null, // No token needed
                        'label' => $info['label'] ?? $provider->getProviderName(),
                        'description' => $info['description'] ?? $provider->getDescription(),
                        'icon' => $info['icon'] ?? 'link',
                        'component_class' => $provider->getKnowledgeBrowserComponent(),
                    ];
                }
            }

        } catch (\Exception $e) {
            Log::error('Failed to load knowledge source integrations', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);
        }
    }

    /**
     * Get the label for the currently selected integration
     * This is used during loading states to show the correct integration name
     */
    public function getSelectedIntegrationLabel(): string
    {
        if (! str_starts_with($this->content_type, 'integration:')) {
            return 'integration';
        }

        // Extract provider ID and integration ID from content_type
        // Format: "integration:provider_id" or "integration:provider_id:integration_id"
        $afterPrefix = substr($this->content_type, 12); // Remove 'integration:' prefix
        $parts = explode(':', $afterPrefix);
        $providerId = $parts[0] ?? null;
        $integrationId = $parts[1] ?? null; // UUID string or null

        if (! $providerId) {
            return 'integration';
        }

        // Find the matching integration entry
        $selectedIntegration = collect($this->available_knowledge_integrations)->first(function ($integration) use ($providerId, $integrationId) {
            if ($integration['provider_id'] !== $providerId) {
                return false;
            }

            // Match integration_id exactly (null matches null, UUID string matches UUID string)
            // Use null coalescing to handle both integration_id and token_id keys
            return ($integration['integration_id'] ?? $integration['token_id'] ?? null) === $integrationId;
        });

        return $selectedIntegration['label'] ?? 'integration';
    }

    protected function rules()
    {
        $rules = [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'privacy_level' => 'required|in:private,public',
            'content_type' => ['required', 'string', function ($attribute, $value, $fail) {
                // Allow standard types
                $allowedTypes = ['text', 'file', 'external'];
                if (in_array($value, $allowedTypes)) {
                    return;
                }

                // Allow integration:provider_id or integration:provider_id:integration_id format
                if (str_starts_with($value, 'integration:')) {
                    $afterPrefix = substr($value, 12); // Remove 'integration:' prefix
                    $parts = explode(':', $afterPrefix);
                    $providerId = $parts[0] ?? '';

                    if (! empty($providerId)) {
                        return;
                    }
                }

                $fail('The selected content type is invalid.');
            }],
            'ttl_hours' => 'nullable|integer|min:0|max:8760', // 0 = never expire, max 1 year
            'tags' => 'array|max:10',
            'tags.*' => 'string|max:50',
        ];

        if ($this->content_type === 'text') {
            $rules['content'] = 'required|string|min:10';
        } elseif ($this->content_type === 'file' && ! $this->isEditing) {
            $rules['uploaded_file'] = [
                'required',
                'file',
                'max:51200', // 50MB
                'mimes:pdf,doc,docx,txt,md,csv,xlsx,xls,zip,rar,7z,tar,gz,json,xml,html,ppt,pptx',
            ];
        } elseif ($this->content_type === 'external' && ! $this->isEditing) {
            $rules['external_source_url'] = 'required|url|max:2048';
            $rules['auto_refresh_enabled'] = 'boolean';
            $rules['refresh_interval_minutes'] = 'integer|min:5|max:10080'; // Min 5 minutes, max 1 week
        }

        return $rules;
    }

    protected $messages = [
        'title.required' => 'Please provide a title for your knowledge document.',
        'content.required' => 'Please provide content for your text document.',
        'content.min' => 'Content must be at least 10 characters long.',
        'uploaded_file.required' => 'Please select a file to upload.',
        'uploaded_file.max' => 'File size cannot exceed 50MB.',
        'uploaded_file.mimes' => 'File type not allowed. Supported formats: PDF, Word, Excel, PowerPoint, Text, Markdown, CSV, JSON, XML, HTML, and archives (ZIP, RAR, 7Z, TAR, GZ).',
        'external_source_url.required' => 'Please provide a valid URL for the external source.',
        'external_source_url.url' => 'Please provide a valid URL format.',
        'external_source_url.max' => 'URL cannot exceed 2048 characters.',
        'refresh_interval_minutes.min' => 'Refresh interval must be at least 5 minutes.',
        'refresh_interval_minutes.max' => 'Refresh interval cannot exceed 1 week (10080 minutes).',
        'ttl_hours.max' => 'TTL cannot exceed 1 year (8760 hours).',
    ];

    public function updatedContentType()
    {
        // Clear validation errors when switching content type
        $this->resetValidation(['content', 'uploaded_file', 'external_source_url']);
    }

    public function updatedUploadedFile()
    {
        if ($this->uploaded_file && ! $this->isEditing) {
            $this->checkForArchive();
            if (! $this->is_archive) {
                $this->analyzeUploadedFile();
            }
        }
    }

    public function checkForArchive()
    {
        if (! $this->uploaded_file) {
            return;
        }

        try {
            $knowledgeService = app(KnowledgeManagerService::class);

            $this->is_archive = $knowledgeService->isArchiveFile($this->uploaded_file);

            if ($this->is_archive) {
                $this->archive_info = $knowledgeService->analyzeArchive($this->uploaded_file);
                $this->show_archive_warning = true;
                $this->archive_confirmed = false;
            } else {
                $this->resetArchiveState();
            }

        } catch (\Exception $e) {
            $this->is_archive = false;
            $this->resetArchiveState();
            $this->dispatch('error', 'Failed to analyze file: '.$e->getMessage());
        }
    }

    public function confirmArchiveProcessing()
    {
        error_log('confirmArchiveProcessing method called');
        Log::info('Archive processing confirmed', [
            'archive_name' => $this->uploaded_file?->getClientOriginalName(),
            'user_id' => Auth::id(),
        ]);

        $this->archive_confirmed = true;
        $this->show_archive_warning = false;

        try {
            $knowledgeService = app(KnowledgeManagerService::class);

            // Call createFromArchive directly - this processes individual files without creating a document for the archive itself
            $baseTitle = pathinfo($this->uploaded_file->getClientOriginalName(), PATHINFO_FILENAME);

            $result = $knowledgeService->createFromArchive(
                file: $this->uploaded_file,
                baseTitle: $baseTitle,
                description: $this->description ?: null,
                tags: $this->tags,
                privacyLevel: $this->privacy_level,
                ttlHours: $this->ttl_hours,
                userId: Auth::id()
            );

            // Handle the archive result
            $this->handleArchiveResult($result);

        } catch (\Exception $e) {
            Log::error('Archive processing failed', [
                'archive_name' => $this->uploaded_file?->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
            $this->dispatch('error', 'Failed to process archive: '.$e->getMessage());
        }
    }

    public function cancelArchiveProcessing()
    {
        $this->uploaded_file = null;
        $this->resetArchiveState();
    }

    protected function resetArchiveState()
    {
        $this->is_archive = false;
        $this->show_archive_warning = false;
        $this->archive_info = [];
        $this->archive_confirmed = false;
    }

    protected function handleArchiveResult(array $result)
    {
        $documentsCreated = $result['documents'] ?? [];
        $errors = $result['errors'] ?? [];
        $successfulCount = $result['successful_count'] ?? 0;
        $errorCount = $result['error_count'] ?? 0;
        $totalFiles = $result['total_files'] ?? 0;

        // Create success message
        $messages = [];
        if ($successfulCount > 0) {
            $messages[] = "Successfully created {$successfulCount} knowledge document(s) from archive.";
        }
        if ($errorCount > 0) {
            $messages[] = "Failed to process {$errorCount} file(s) from archive.";
            foreach ($errors as $error) {
                $messages[] = "- {$error['file']}: {$error['error']}";
            }
        }

        $message = implode("\n", $messages);

        if ($successfulCount > 0) {
            $this->dispatch('success', $message);
        } else {
            $this->dispatch('error', $message);
        }

        // Notify parent to refresh and close modal
        $this->dispatch('document-saved', $documentsCreated[0]->id ?? null);
        $this->closeEditor();
    }

    public function analyzeUploadedFile()
    {
        if (! $this->uploaded_file) {
            return;
        }

        try {
            $this->analyzing_file = true;
            $this->ai_suggestions = [];

            $this->validateOnly('uploaded_file');

            if ($this->isArchiveFile($this->uploaded_file)) {
                $this->analyzing_file = false;

                return;
            }

            // Use FileAnalyzer to extract metadata
            $analyzer = new FileAnalyzer;

            if (! $analyzer->isEnabled()) {
                $this->analyzing_file = false;

                return;
            }

            $analysis = $analyzer->analyzeFile($this->uploaded_file);
            $this->ai_suggestions = $analysis;

            // Auto-fill empty fields with AI suggestions and preselect tags
            $this->applyAISuggestions($analysis, preselectTags: true);

            $this->analyzing_file = false;

        } catch (\Exception $e) {
            $this->analyzing_file = false;
            $this->dispatch('error', 'Failed to analyze file: '.$e->getMessage());
        }
    }

    public function analyzeTextContent()
    {
        $this->validate([
            'content' => 'required|string|min:10',
        ]);

        try {
            $this->analyzing_file = true;

            $analyzer = new FileAnalyzer;

            if (! $analyzer->isEnabled()) {
                $this->dispatch('notify', [
                    'type' => 'warning',
                    'message' => 'AI analysis is currently disabled.',
                ]);

                return;
            }

            $contextName = $this->title ?: 'text document';
            $analysis = $analyzer->analyzeTextContent($this->content, $contextName);

            if (! empty($analysis)) {
                $this->ai_suggestions = $analysis;
                $this->applyAISuggestions($analysis, preselectTags: true);

                $this->dispatch('notify', [
                    'type' => 'success',
                    'message' => 'AI analysis completed. Suggestions have been applied.',
                ]);
            }

        } catch (\Exception $e) {
            Log::error('KnowledgeEditor: Failed to analyze text content', [
                'error' => $e->getMessage(),
            ]);

            $this->dispatch('notify', [
                'type' => 'error',
                'message' => 'Failed to analyze content. Please try again.',
            ]);
        } finally {
            $this->analyzing_file = false;
        }
    }

    protected function applyAISuggestions(array $suggestions, bool $preselectTags = false)
    {
        if (empty($this->title) && ! empty($suggestions['suggested_title'])) {
            $this->title = $suggestions['suggested_title'];
        }

        if (empty($this->description) && ! empty($suggestions['suggested_description'])) {
            $this->description = $suggestions['suggested_description'];
        }

        // Enhanced tag handling with preselection support
        if (! empty($suggestions['suggested_tags'])) {
            if ($preselectTags) {
                // Auto-add AI-suggested tags (user can remove unwanted ones)
                $newTags = array_diff($suggestions['suggested_tags'], $this->tags);
                $availableSlots = 10 - count($this->tags);
                if ($availableSlots > 0) {
                    $tagsToAdd = array_slice($newTags, 0, $availableSlots);
                    $this->tags = array_merge($this->tags, $tagsToAdd);

                    Log::debug('KnowledgeEditor: Auto-preselected AI tags', [
                        'tags_added' => $tagsToAdd,
                        'total_tags' => count($this->tags),
                    ]);
                }
            } elseif (empty($this->tags)) {
                // Original behavior: only fill if empty
                $this->tags = $suggestions['suggested_tags'];
            }
        }

        if (is_null($this->ttl_hours) && ! empty($suggestions['suggested_ttl_hours']) && $suggestions['suggested_ttl_hours'] > 0) {
            $this->ttl_hours = $suggestions['suggested_ttl_hours'];
        }
    }

    public function validateUrl()
    {
        if (empty($this->external_source_url)) {
            return;
        }

        try {
            $this->validating_url = true;
            $this->resetValidation('external_source_url');

            $linkValidator = app(LinkValidator::class);
            $linkInfo = $linkValidator->validateAndExtractLinkInfo($this->external_source_url);

            // Transform LinkValidator response to our expected format
            $isValid = ! empty($linkInfo['status']) && $linkInfo['status'] < 400;

            $this->url_validation_results = [
                'isValid' => $isValid,
                'metadata' => [
                    'title' => $linkInfo['title'] ?? null,
                    'description' => $linkInfo['description'] ?? null,
                    'favicon' => $linkInfo['favicon'] ?? null,
                ],
                'suggestedTags' => $this->generateTagsFromUrl($this->external_source_url, $linkInfo),
                'error' => $isValid ? null : 'Failed to fetch URL content (HTTP '.$linkInfo['status'].')',
            ];

            // Auto-fill fields if they're empty and validation was successful
            if ($isValid) {
                if (empty($this->title) && ! empty($linkInfo['title'])) {
                    $this->title = $linkInfo['title'];
                }

                if (empty($this->description) && ! empty($linkInfo['description'])) {
                    $this->description = $linkInfo['description'];
                }

                if (empty($this->tags) && ! empty($this->url_validation_results['suggestedTags'])) {
                    $this->tags = array_slice($this->url_validation_results['suggestedTags'], 0, 5); // Limit to first 5 tags
                }
            }

            $this->validating_url = false;

        } catch (\Exception $e) {
            $this->validating_url = false;
            $this->addError('external_source_url', 'Failed to validate URL: '.$e->getMessage());
        }
    }

    /**
     * Generate smart tags from URL and metadata
     */
    private function generateTagsFromUrl(string $url, array $linkInfo): array
    {
        $tags = [];
        $domain = parse_url($url, PHP_URL_HOST);

        // Domain-based tags
        if ($domain) {
            if (str_contains($domain, 'github.com')) {
                $tags[] = 'github';
                $tags[] = 'code';
            } elseif (str_contains($domain, 'stackoverflow.com')) {
                $tags[] = 'stackoverflow';
                $tags[] = 'programming';
            } elseif (str_contains($domain, 'docs.') || str_contains($domain, 'documentation')) {
                $tags[] = 'documentation';
            } elseif (str_contains($domain, 'blog') || str_contains($domain, 'medium.com')) {
                $tags[] = 'blog';
                $tags[] = 'article';
            } elseif (str_contains($domain, 'news') || str_contains($domain, 'bbc.com') || str_contains($domain, 'cnn.com')) {
                $tags[] = 'news';
            }

            // Add clean domain as tag
            $cleanDomain = str_replace(['www.', '.com', '.org', '.net'], '', $domain);
            if (strlen($cleanDomain) > 2 && ! in_array($cleanDomain, $tags)) {
                $tags[] = $cleanDomain;
            }
        }

        // Content-based tags from title
        if (! empty($linkInfo['title'])) {
            $title = strtolower($linkInfo['title']);
            if (str_contains($title, 'api')) {
                $tags[] = 'api';
            }
            if (str_contains($title, 'tutorial')) {
                $tags[] = 'tutorial';
            }
            if (str_contains($title, 'guide')) {
                $tags[] = 'guide';
            }
            if (str_contains($title, 'documentation') || str_contains($title, 'docs')) {
                $tags[] = 'documentation';
            }
            if (str_contains($title, 'reference')) {
                $tags[] = 'reference';
            }
        }

        return array_unique(array_slice($tags, 0, 5));
    }

    public function addTag()
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

    public function removeTag($index)
    {
        unset($this->tags[$index]);
        $this->tags = array_values($this->tags); // Re-index array
    }

    public function save()
    {
        // If we have Notion preview data, create from preview
        if ($this->has_notion_preview) {
            $this->saveFromNotionPreview();

            return;
        }

        Log::info('Save method called', [
            'content_type' => $this->content_type,
            'archive_confirmed' => $this->archive_confirmed,
            'uploaded_file' => $this->uploaded_file?->getClientOriginalName(),
            'user_id' => Auth::id(),
        ]);

        try {
            $this->validate();
            Log::info('Validation passed for save');
        } catch (\Exception $e) {
            Log::error('Validation failed in save', [
                'error' => $e->getMessage(),
                'validation_errors' => $e->validator ?? null,
            ]);
            throw $e;
        }

        try {
            $knowledgeService = app(KnowledgeManagerService::class);

            // Force correct mode based on actual document presence
            $actuallyEditing = $this->isEditing && $this->document && $this->document->exists;

            if ($actuallyEditing) {
                // Update existing document
                $updateData = [
                    'title' => $this->title,
                    'description' => $this->description,
                    'privacy_level' => $this->privacy_level,
                    'tags' => $this->tags,
                ];

                if ($this->ttl_hours) {
                    $updateData['ttl_hours'] = $this->ttl_hours;
                } else {
                    // Clear TTL if not specified
                    $updateData['ttl_expires_at'] = null;
                }

                // Only update content for text documents
                if ($this->document->content_type === 'text') {
                    $updateData['content'] = $this->content;
                }

                $document = $knowledgeService->updateDocument($this->document, $updateData);

            } else {
                if ($this->content_type === 'text') {
                    $document = $knowledgeService->createFromText(
                        content: $this->content,
                        title: $this->title,
                        description: $this->description ?: null,
                        tags: $this->tags,
                        privacyLevel: $this->privacy_level,
                        ttlHours: $this->ttl_hours,
                        userId: Auth::id()
                    );
                } elseif ($this->content_type === 'file') {
                    $result = $knowledgeService->createFromFile(
                        file: $this->uploaded_file,
                        title: $this->title,
                        description: $this->description ?: null,
                        tags: $this->tags,
                        privacyLevel: $this->privacy_level,
                        ttlHours: $this->ttl_hours,
                        userId: Auth::id()
                    );

                    if (is_array($result)) {
                        $this->handleArchiveResult($result);

                        return;
                    } else {
                        $document = $result;
                    }
                } elseif ($this->content_type === 'external') {
                    $externalKnowledgeManager = app(ExternalKnowledgeManager::class);

                    $document = $externalKnowledgeManager->addExternalSource(
                        sourceIdentifier: $this->external_source_url,
                        sourceType: 'url',
                        title: $this->title,
                        description: $this->description ?: null,
                        tags: $this->tags,
                        privacyLevel: $this->privacy_level,
                        ttlHours: $this->ttl_hours,
                        autoRefresh: $this->auto_refresh_enabled,
                        refreshIntervalMinutes: $this->auto_refresh_enabled ? $this->refresh_interval_minutes : null,
                        authCredentials: [],
                        userId: Auth::id()
                    );
                }
            }

            $action = $actuallyEditing ? 'updated' : 'created';
            $this->dispatch('success', "Knowledge document '{$document->title}' has been {$action} successfully.");

            // Notify parent to refresh and close modal
            $this->dispatch('document-saved', $document->id);
            $this->closeEditor();

        } catch (\Exception $e) {
            $this->dispatch('error', 'Failed to save document: '.$e->getMessage());
        }
    }

    /**
     * Handle import from integration sources
     */
    public function handleIntegrationImport(array $data)
    {
        $providerId = $data['provider_id'] ?? null;
        $selectedItems = $data['selected_items'] ?? [];

        if (! $providerId || empty($selectedItems)) {
            $this->dispatch('error', 'Invalid import data received');

            return;
        }

        try {
            $providerRegistry = app(ProviderRegistry::class);
            $provider = $providerRegistry->get($providerId);

            if (! ($provider instanceof KnowledgeSourceProvider)) {
                $this->dispatch('error', 'Provider does not support knowledge import');

                return;
            }

            if ($providerId === 'notion') {
                $this->handleNotionConsolidatedImport($selectedItems);

                return;
            }

            $isSingleImport = count($selectedItems) === 1;

            $this->resetImportProgress();
            $this->import_total = count($selectedItems);
            $this->show_import_progress = true;

            // Prepare metadata options
            $metadataOptions = [
                'title_prefix' => $isSingleImport ? null : trim($this->title),
                'description_prefix' => $isSingleImport ? null : trim($this->description),
                'tags' => $this->tags,
                'privacy_level' => $this->privacy_level,
                'ttl_hours' => $this->ttl_hours,
            ];

            foreach ($selectedItems as $item) {
                try {
                    $pageId = is_array($item) ? ($item['page_id'] ?? $item) : $item;
                    $importChildren = is_array($item) ? ($item['import_children'] ?? false) : false;

                    $result = $provider->importAsKnowledge(
                        Auth::user(),
                        [$pageId],
                        array_merge($metadataOptions, ['import_children' => $importChildren])
                    );

                    if (! empty($result['success'])) {
                        $successCount = count($result['success']);

                        if ($successCount > 1 && $importChildren) {
                            $childrenCount = $successCount - 1;
                            $this->import_total += $childrenCount;
                        }

                        $this->import_processed += $successCount;
                        $this->import_successful += $successCount;

                        foreach ($result['success'] as $importedDocument) {
                            $this->import_results[] = [
                                'status' => 'success',
                                'item' => $importedDocument['title'] ?? 'Item',
                            ];
                        }

                        if ($isSingleImport && ! empty($result['success'][0])) {
                            $firstDocument = $result['success'][0];
                            $this->title = $firstDocument['title'] ?? '';
                            $this->description = $firstDocument['description'] ?? '';
                        }
                    }

                    if (! empty($result['failed'])) {
                        $failedCount = count($result['failed']);
                        $this->import_processed += $failedCount;
                        $this->import_failed += $failedCount;

                        if ($failedCount > 1 && $importChildren) {
                            $this->import_total += ($failedCount - 1);
                        }

                        foreach ($result['failed'] as $failedItem) {
                            $this->import_results[] = [
                                'status' => 'failed',
                                'item' => $failedItem['page_id'] ?? 'Unknown',
                                'error' => $failedItem['error'] ?? 'Unknown error',
                            ];
                        }
                    }

                    $this->dispatch('import-progress-updated');

                } catch (\Exception $e) {
                    $this->import_processed++;
                    $this->import_failed++;
                    $this->import_results[] = [
                        'status' => 'failed',
                        'item' => is_array($item) ? ($item['page_id'] ?? 'Unknown') : $item,
                        'error' => $e->getMessage(),
                    ];

                    Log::error('Failed to import integration item', [
                        'provider_id' => $providerId,
                        'item' => $item,
                        'error' => $e->getMessage(),
                        'user_id' => Auth::id(),
                    ]);

                    $this->dispatch('import-progress-updated');
                }
            }

            if ($this->import_successful > 0) {
                $this->dispatch('success', "Successfully imported {$this->import_successful} item(s) from {$provider->getProviderName()}");
            }

            if ($this->import_failed > 0) {
                $this->dispatch('error', "Failed to import {$this->import_failed} item(s)");
            }

            $this->dispatch('document-saved');
            $this->dispatch('close-all-import-modals');

        } catch (\Exception $e) {
            $this->resetImportProgress();

            Log::error('Integration import failed', [
                'provider_id' => $providerId,
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            $this->dispatch('error', 'Import failed: '.$e->getMessage());
        }
    }

    /**
     * Handle Notion preview data from NotionBrowser
     */
    public function handleNotionPreview(array $previewData): void
    {
        // Store preview data
        $this->notion_preview_data = $previewData;
        $this->has_notion_preview = true;

        // Populate form with AI suggestions
        if (empty($this->title)) {
            $this->title = $previewData['suggested_title'] ?? '';
        }
        if (empty($this->description) && ! empty($previewData['ai_metadata']['description'])) {
            $this->description = $previewData['ai_metadata']['description'];
        }
        if (empty($this->tags) && ! empty($previewData['ai_metadata']['tags'])) {
            $this->tags = $previewData['ai_metadata']['tags'];
        }

        $this->dispatch('success', "Collected {$previewData['page_count']} page(s). Review and save.");
    }

    /**
     * Save document from Notion preview data
     * Resolves the service dynamically via container for pluggability
     */
    protected function saveFromNotionPreview(): void
    {
        try {
            // Get integration to resolve the correct provider
            $integrationId = $this->notion_preview_data['integration_id'] ?? null;

            if (! $integrationId) {
                throw new \Exception('Integration ID not found in preview data');
            }

            $integration = \App\Models\Integration::findOrFail($integrationId);

            // Get provider from registry
            $providerRegistry = app(\App\Services\Integrations\ProviderRegistry::class);
            $provider = $providerRegistry->get($integration->integrationToken->provider_id);

            if (! $provider) {
                throw new \Exception('Integration provider not found');
            }

            // Get the service class from provider's knowledge source
            // This is a temporary workaround until we have a standardized preview interface
            $serviceClass = '\\PromptlyAgentAI\\NotionIntegration\\Services\\NotionService';

            if (! class_exists($serviceClass)) {
                throw new \Exception('Service class not found - integration may not be installed');
            }

            $service = app($serviceClass);

            // Create document from preview data with user-edited metadata
            $document = $service->createDocumentFromPreviewData(
                Auth::user(),
                $this->notion_preview_data,
                [
                    'title' => $this->title,
                    'description' => $this->description,
                    'tags' => $this->tags,
                    'privacy_level' => $this->privacy_level,
                    'ttl_hours' => $this->ttl_hours,
                ]
            );

            $this->dispatch('success', "Knowledge document '{$document->title}' has been created successfully.");

            // Notify parent to refresh and close modal
            $this->dispatch('document-saved', $document->id);
            $this->closeEditor();

        } catch (\Exception $e) {
            Log::error('Failed to save Notion document from preview', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
            ]);

            $this->dispatch('error', 'Failed to save document: '.$e->getMessage());
        }
    }

    protected function resetImportProgress()
    {
        $this->show_import_progress = false;
        $this->import_total = 0;
        $this->import_processed = 0;
        $this->import_successful = 0;
        $this->import_failed = 0;
        $this->import_results = [];
    }

    public function closeEditor()
    {
        $this->showModal = false;
        $this->resetForm();
        $this->dispatch('closeDocumentEditor');
    }

    /**
     * Check if file is an archive that should be extracted
     */
    protected function isArchiveFile($file): bool
    {
        if (! $file || ! config('knowledge.archives.enabled', true)) {
            return false;
        }

        $supportedTypes = config('knowledge.archives.supported_types', []);
        $mimeType = $file->getMimeType();

        // Check MIME type
        if (in_array($mimeType, $supportedTypes)) {
            return true;
        }

        // Check file extension as fallback
        $extensions = config('knowledge.archives.file_extensions', []);
        $fileName = strtolower($file->getClientOriginalName());

        foreach ($extensions as $ext) {
            if (str_ends_with($fileName, $ext)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get scope-relevant tags from documents that share the same scope tags
     */
    protected function getScopeRelevantTags(array $scopeTags): array
    {
        if (empty($scopeTags)) {
            return [];
        }

        try {
            // Find documents that share any of the scope tags
            $documents = KnowledgeDocument::whereHas('tags', function ($query) use ($scopeTags) {
                $query->whereIn('name', $scopeTags);
            })
                ->forUser(Auth::id())
                ->where('processing_status', 'completed')
                ->with('tags')
                ->limit(50)
                ->get();

            if ($documents->isEmpty()) {
                return [];
            }

            // Extract unique tags, excluding scope tags themselves
            $relevantTags = $documents->pluck('tags')
                ->flatten()
                ->pluck('name')
                ->unique()
                ->reject(fn ($tag) => in_array($tag, $scopeTags))
                ->sort()
                ->values()
                ->toArray();

            Log::debug('KnowledgeEditor: Found scope-relevant tags', [
                'scope_tags' => $scopeTags,
                'documents_found' => $documents->count(),
                'relevant_tags_count' => count($relevantTags),
            ]);

            return $relevantTags;

        } catch (\Exception $e) {
            Log::warning('KnowledgeEditor: Failed to get scope-relevant tags', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    public function render()
    {
        // Get content-aware tag suggestions instead of random alphabetical tags
        $availableTags = collect();
        $tagSuggestionLabel = 'Suggested tags:';

        // Strategy 1: Edit mode - use scope-relevant tags from similar documents
        if ($this->isEditing && ! empty($this->tags)) {
            $scopeRelevantTags = $this->getScopeRelevantTags($this->tags);
            $availableTags = KnowledgeTag::whereIn('name', $scopeRelevantTags)
                ->select('name', 'color')
                ->limit(20)
                ->get();

            $tagSuggestionLabel = 'Related tags:';

            Log::debug('KnowledgeEditor: Using scope-relevant tags', [
                'scope_tags' => $this->tags,
                'suggested_count' => $availableTags->count(),
            ]);
        }

        // Strategy 2: Create mode with AI suggestions - prioritize AI tags
        if ($availableTags->isEmpty() && ! empty($this->ai_suggestions['suggested_tags'])) {
            $aiTags = $this->ai_suggestions['suggested_tags'];
            $availableTags = KnowledgeTag::whereIn('name', $aiTags)
                ->select('name', 'color')
                ->limit(20)
                ->get();

            $tagSuggestionLabel = 'Related tags:';

            Log::debug('KnowledgeEditor: Using AI-suggested tags', [
                'ai_tags' => $aiTags,
                'suggested_count' => $availableTags->count(),
            ]);
        }

        // Strategy 3: Fallback - most popular tags across all documents
        if ($availableTags->isEmpty()) {
            $availableTags = KnowledgeTag::select('knowledge_tags.name', 'knowledge_tags.color')
                ->join('knowledge_document_tags', 'knowledge_tags.id', '=', 'knowledge_document_tags.knowledge_tag_id')
                ->selectRaw('COUNT(knowledge_document_tags.knowledge_document_id) as usage_count')
                ->groupBy('knowledge_tags.id', 'knowledge_tags.name', 'knowledge_tags.color')
                ->orderByDesc('usage_count')
                ->limit(20)
                ->get();

            $tagSuggestionLabel = 'Popular tags:';

            Log::debug('KnowledgeEditor: Using popular tags fallback', [
                'suggested_count' => $availableTags->count(),
            ]);
        }

        return view('livewire.knowledge-editor', [
            'availableTags' => $availableTags,
            'tagSuggestionLabel' => $tagSuggestionLabel,
        ]);
    }
}
