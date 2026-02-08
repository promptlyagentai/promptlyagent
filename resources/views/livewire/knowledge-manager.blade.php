{{--
    Knowledge Manager

    Manages knowledge documents with RAG support, embedding monitoring, and Meilisearch indexing.
    Handles text, file, external (URL), and integration sources with bulk operations and filtering.
--}}
<div class="rounded-xl border border-default bg-surface p-6 flex flex-col gap-6">
    <div class="flex items-start justify-between">
        <div>
            <flux:heading size="xl">Knowledge Manager</flux:heading>
            <flux:subheading>Manage your knowledge documents, files, and search capabilities.</flux:subheading>
        </div>
        <div class="flex items-center space-x-2">
            @if(!empty($selectedDocuments))
                <flux:button wire:click="bulkDelete" variant="danger" size="sm" icon="trash">
                    Delete Selected ({{ count($selectedDocuments) }})
                </flux:button>
                <flux:button wire:click="clearSelection" variant="ghost" size="sm">
                    Clear Selection
                </flux:button>
            @endif
            <flux:button wire:click="toggleEmbeddingStatus" variant="ghost" size="sm" icon="cpu-chip">
                {{ $showEmbeddingStatus ? 'Hide' : 'Show' }} Indexing Status
            </flux:button>
            <flux:button type="button" wire:click="createDocument" variant="primary" icon="plus">
                Add Knowledge
            </flux:button>
        </div>
    </div>
    <div class="rounded-xl border border-default bg-surface p-6">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-6">
            <div class="sm:col-span-2">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    label="Search Knowledge"
                    placeholder="Search by title, description, or content..."
                    icon="magnifying-glass" />
            </div>
            <div>
                <flux:field>
                    <flux:label>Content Type</flux:label>
                    <select wire:model.live="selectedContentType" class="w-full rounded-lg border-0 bg-surface text-primary px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent">
                        <option value="all">All Types</option>
                        <option value="text">Text</option>
                        <option value="file">File</option>
                        <option value="external">External</option>
                    </select>
                </flux:field>
            </div>
            <div>
                <flux:field>
                    <flux:label>Privacy</flux:label>
                    <select wire:model.live="selectedPrivacyLevel" class="w-full rounded-lg border-0 bg-surface text-primary px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent">
                        <option value="all">All Levels</option>
                        <option value="private">Private</option>
                        <option value="public">Public</option>
                    </select>
                </flux:field>
            </div>
            <div>
                <flux:field>
                    <flux:label>Status</flux:label>
                    <select wire:model.live="selectedStatus" class="w-full rounded-lg border-0 bg-surface text-primary px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent">
                        <option value="all">All Status</option>
                        <option value="completed">Processed</option>
                        <option value="pending">Pending</option>
                        <option value="processing">Processing</option>
                        <option value="failed">Failed</option>
                    </select>
                </flux:field>
            </div>
            <div class="space-y-2">
                <flux:field>
                    <flux:label>Options</flux:label>
                    <flux:checkbox wire:model.live="showOnlyMyDocuments" label="My documents only" />
                </flux:field>
                <flux:field>
                    <flux:checkbox wire:model.live="includeExpired" label="Include expired" />
                </flux:field>
            </div>
        </div>
        @if($availableTags->count() > 0)
            <div class="mt-4 pt-4 border-t border-default">
                <flux:label class="mb-2">Filter by Tags:</flux:label>
                <div class="flex flex-wrap gap-2">
                    @foreach($availableTags as $tag)
                        <label class="inline-flex items-center">
                            <input
                                type="checkbox"
                                wire:model.live="selectedTags"
                                value="{{ $tag->name }}"
                                class="rounded border-default text-accent shadow-sm focus:border-accent focus:ring focus:ring-accent/20">
                            <flux:badge 
                                color="{{ $tag->color ?? 'zinc' }}" 
                                size="sm" 
                                class="ml-2">
                                {{ $tag->name }}
                            </flux:badge>
                        </label>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
    @if($showEmbeddingStatus)
        <div class="rounded-xl border border-default bg-surface p-6">
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="lg">
                    <flux:icon.cpu-chip class="w-5 h-5 mr-2 inline" />
                    Indexing Status
                </flux:heading>
                <div class="flex items-center space-x-2">
                    <flux:button wire:click="loadEmbeddingStatistics" variant="ghost" size="sm" icon="arrow-path">
                        Refresh
                    </flux:button>
                    @if(!empty($embeddingStatistics) && $embeddingStatistics['embedding_service_enabled'] && $embeddingStatistics['without_embeddings'] > 0)
                        <flux:button wire:click="regenerateEmbeddings" variant="primary" size="sm" icon="play">
                            Generate Missing (Batch)
                        </flux:button>
                    @endif
                    @if(!empty($embeddingStatistics) && $embeddingStatistics['embedding_service_enabled'])
                        <flux:button wire:click="$set('showReindexConfirmModal', true)" variant="danger" size="sm" icon="arrow-path">
                            Reindex Everything
                        </flux:button>
                    @endif
                </div>
            </div>

            @if(!empty($embeddingStatistics))
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                    <!-- Total Documents -->
                    <div class="bg-surface rounded-lg p-4 ">
                        <div class="text-sm text-tertiary ">Total Documents</div>
                        <div class="text-2xl font-semibold text-primary">
                            {{ $embeddingStatistics['total_documents'] }}
                        </div>
                    </div>

                    <!-- With Embeddings -->
                    <div class="bg-[var(--palette-success-100)] rounded-lg p-4">
                        <div class="text-sm text-[var(--palette-success-700)]">With Embeddings</div>
                        <div class="text-2xl font-semibold text-[var(--palette-success-800)]">
                            {{ $embeddingStatistics['with_embeddings'] }}
                            <span class="text-sm text-[var(--palette-success-700)]">✅</span>
                        </div>
                    </div>

                    <!-- Missing Embeddings -->
                    <div class="bg-[var(--palette-warning-100)] rounded-lg p-4">
                        <div class="text-sm text-[var(--palette-warning-700)]">Missing Embeddings</div>
                        <div class="text-2xl font-semibold text-[var(--palette-warning-800)]">
                            {{ $embeddingStatistics['without_embeddings'] }}
                            @if($embeddingStatistics['without_embeddings'] > 0)
                                <span class="text-sm text-[var(--palette-warning-700)]">⚠️</span>
                            @endif
                        </div>
                    </div>

                    <!-- Completion Rate -->
                    <div class="bg-accent/10 rounded-lg p-4">
                        <div class="text-sm text-accent">Completion Rate</div>
                        <div class="text-2xl font-semibold text-accent">
                            {{ $embeddingStatistics['completion_rate'] }}%
                        </div>
                        <!-- Progress bar -->
                        <div class="w-full bg-surface rounded-full h-2 mt-2 border border-subtle">
                            <div class="bg-accent h-2 rounded-full transition-all duration-300" 
                                 style="width: {{ $embeddingStatistics['completion_rate'] }}%"></div>
                        </div>
                    </div>
                </div>

                <!-- Service Configuration -->
                <div class="bg-surface rounded-lg p-4 ">
                    <h4 class="text-sm font-medium text-primary mb-2">Service Configuration</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                        <div>
                            <span class="text-tertiary ">Status:</span>
                            <span class="ml-2 {{ $embeddingStatistics['embedding_service_enabled'] ? 'text-accent' : 'text-[var(--palette-error-700)]' }}">
                                {{ $embeddingStatistics['embedding_service_enabled'] ? '✅ Enabled' : '❌ Disabled' }}
                            </span>
                        </div>
                        @if($embeddingStatistics['embedding_service_enabled'])
                            <div>
                                <span class="text-tertiary ">Provider:</span>
                                <span class="ml-2 font-medium">{{ $embeddingStatistics['embedding_provider'] }}</span>
                            </div>
                            <div>
                                <span class="text-tertiary ">Model:</span>
                                <span class="ml-2 font-medium">{{ $embeddingStatistics['embedding_model'] }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                @if($embeddingStatistics['processing_failed'] > 0)
                    <div class="mt-4 bg-[var(--palette-error-100)] rounded-lg p-4">
                        <div class="text-sm text-[var(--palette-error-700)]">
                            ⚠️ {{ $embeddingStatistics['processing_failed'] }} documents failed processing
                        </div>
                    </div>
                @endif
            @else
                <div class="text-center py-4">
                    <flux:button wire:click="loadEmbeddingStatistics" variant="primary">
                        Load Embedding Statistics
                    </flux:button>
                </div>
            @endif
        </div>
    @endif

    <!-- Documents List -->
    @if($documents->count() > 0)
        <div class="rounded-xl border border-default bg-surface p-6">
            @foreach($documents as $document)
                <div class="flex items-start justify-between p-4 {{ !$loop->last ? 'border-b border-default' : '' }}">
                    <!-- Selection Checkbox -->
                    <div class="flex items-start space-x-4 flex-1 min-w-0">
                        <div class="flex-shrink-0 pt-1">
                            <input
                                type="checkbox"
                                wire:click="toggleDocumentSelection({{ $document->id }})"
                                @checked(in_array($document->id, $selectedDocuments))
                                class="rounded border-default text-accent shadow-sm focus:border-accent focus:ring focus:ring-accent/20">
                        </div>

                        <!-- Type Badges -->
                        <div class="flex-shrink-0">
                            <div class="space-y-1">
                                <!-- Processing Status Badge (for non-completed documents) -->
                                @if($document->processing_status !== 'completed')
                                    @if($document->processing_status === 'processing')
                                        <flux:badge color="yellow" size="sm">
                                            <flux:icon.clock class="w-3 h-3 mr-1" />
                                            Processing
                                        </flux:badge>
                                    @elseif($document->processing_status === 'pending')
                                        <flux:badge color="zinc" size="sm">
                                            <flux:icon.clock class="w-3 h-3 mr-1" />
                                            Pending
                                        </flux:badge>
                                    @elseif($document->processing_status === 'failed')
                                        <flux:badge color="red" size="sm">
                                            <flux:icon.x-circle class="w-3 h-3 mr-1" />
                                            Failed
                                        </flux:badge>
                                    @endif
                                @endif
                                
                                <!-- Content Type -->
                                @if($document->content_type === 'file')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-notify-200)] text-[var(--palette-notify-900)]">
                                        <flux:icon.document class="w-3 h-3 mr-1" />
                                        File
                                    </span>
                                @elseif($document->content_type === 'text')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-neutral-200)] text-[var(--palette-neutral-900)]">
                                        <flux:icon.document-text class="w-3 h-3 mr-1" />
                                        Text
                                    </span>
                                @elseif($document->content_type === 'external')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-notify-200)] text-[var(--palette-notify-900)]">
                                        <flux:icon.link class="w-3 h-3 mr-1" />
                                        External
                                    </span>
                                @endif
                            </div>
                        </div>

                        <!-- Document Details -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center space-x-2">
                                <flux:heading size="sm" class="truncate">{{ $document->title }}</flux:heading>
                                
                                @if($document->privacy_level === 'public')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-success-200)] text-[var(--palette-success-900)]">Public</span>
                                @elseif($document->privacy_level === 'group')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-notify-200)] text-[var(--palette-notify-900)]">Group</span>
                                @endif

                                @if($document->is_expired)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-error-200)] text-[var(--palette-error-900)]">
                                        <flux:icon.clock class="w-3 h-3 mr-1" />
                                        Expired
                                    </span>
                                @elseif($document->ttl_expires_at)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-warning-200)] text-[var(--palette-warning-900)]">
                                        <flux:icon.clock class="w-3 h-3 mr-1" />
                                        Expires {{ $document->ttl_expires_at->diffForHumans() }}
                                    </span>
                                @endif

                                @if($document->auto_refresh_enabled && $document->next_refresh_at)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-notify-200)] text-[var(--palette-notify-900)]">
                                        <flux:icon.arrow-path class="w-3 h-3 mr-1" />
                                        Refreshes {{ $document->next_refresh_at->diffForHumans() }}
                                    </span>
                                @endif
                            </div>
                            
                            @if($document->description)
                                <flux:subheading class="mt-1">{{ $document->description }}</flux:subheading>
                            @endif
                            
                            <!-- File Info -->
                            @if($document->content_type === 'file' && $document->asset)
                                <div class="mt-2 space-y-2">
                                    <flux:text size="xs" class="font-mono">
                                        {{ $document->asset->original_filename }}
                                        @if($document->asset->size_bytes)
                                            ({{ number_format($document->asset->size_bytes / 1024, 1) }} KB)
                                        @endif
                                        • {{ $document->source_type }}
                                    </flux:text>

                                    <!-- File Actions -->
                                    @if($document->asset->exists())
                                        <div class="flex flex-wrap items-center gap-2">
                                            <button
                                                wire:click="openPreviewModal({{ $document->id }})"
                                                class="inline-flex items-center px-2 py-1 text-xs font-medium text-accent bg-accent/10 rounded hover:bg-accent/20 text-accent">
                                                <flux:icon.eye class="w-3 h-3 mr-1 flex-shrink-0" />
                                                Preview
                                            </button>
                                            <a
                                                href="{{ route('knowledge.download', $document) }}"
                                                class="inline-flex items-center px-2 py-1 text-xs font-medium text-[var(--palette-success-900)] bg-[var(--palette-success-200)] rounded hover:bg-[var(--palette-success-300)]">
                                                <flux:icon.arrow-down-tray class="w-3 h-3 mr-1 flex-shrink-0" />
                                                Download
                                            </a>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            <!-- External Info -->
                            @if($document->content_type === 'external' && $document->external_source_identifier)
                                <div class="mt-2 space-y-2">
                                    <flux:text size="xs" class="font-mono">
                                        {{ parse_url($document->external_source_identifier, PHP_URL_HOST) ?: 'External Source' }}
                                        @if($document->word_count)
                                            ({{ number_format($document->word_count) }} words)
                                        @endif
                                        • {{ $document->source_type }}
                                    </flux:text>
                                    
                                    <!-- External Actions -->
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button
                                            wire:click="openPreviewModal({{ $document->id }})"
                                            class="inline-flex items-center px-2 py-1 text-xs font-medium text-accent bg-accent/10 rounded hover:bg-accent/20 text-accent">
                                            <flux:icon.eye class="w-3 h-3 mr-1 flex-shrink-0" />
                                            Preview
                                        </button>
                                        @php
                                            // Check if document uses an integration provider for rendering
                                            $viewOriginalLinks = [];
                                            if ($document->integration_id && $document->integration) {
                                                try {
                                                    $providerRegistry = app(\App\Services\Integrations\ProviderRegistry::class);
                                                    $provider = $providerRegistry->get($document->integration->integrationToken->provider_id);
                                                    if ($provider && $provider instanceof \App\Services\Integrations\Contracts\KnowledgeSourceProvider) {
                                                        $viewOriginalLinks = $provider->renderViewOriginalLinks($document);
                                                    }
                                                } catch (\Exception $e) {
                                                    // Fallback to default rendering
                                                }
                                            }
                                        @endphp

                                        @if(!empty($viewOriginalLinks))
                                            {{-- Integration-specific rendering (multiple links possible) --}}
                                            @foreach($viewOriginalLinks as $link)
                                                <a
                                                    href="{{ $link['url'] }}"
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="inline-flex items-center px-2 py-1 text-xs font-medium text-accent bg-accent/10 rounded hover:bg-accent/20 max-w-xs"
                                                    title="{{ $link['label'] }}">
                                                    <flux:icon.arrow-top-right-on-square class="w-3 h-3 mr-1 flex-shrink-0" />
                                                    <span class="truncate">{{ $link['label'] }}</span>
                                                </a>
                                            @endforeach
                                        @else
                                            {{-- Default external source rendering --}}
                                            <a
                                                href="{{ $document->external_source_identifier }}"
                                                target="_blank"
                                                rel="noopener"
                                                class="inline-flex items-center px-2 py-1 text-xs font-medium text-[var(--palette-notify-900)] bg-[var(--palette-notify-200)] rounded hover:bg-[var(--palette-notify-300)]">
                                                <flux:icon.arrow-top-right-on-square class="w-3 h-3 mr-1 flex-shrink-0" />
                                                View Original
                                            </a>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <!-- Text Content Info -->
                            @if($document->content && $document->content_type === 'text')
                                <div class="mt-2 space-y-2">
                                    <flux:text size="xs" class="font-mono">
                                        @if($document->external_source_identifier)
                                            {{ parse_url($document->external_source_identifier, PHP_URL_HOST) ?: 'Text Document' }}
                                        @else
                                            Text Document
                                        @endif
                                        @if($document->word_count)
                                            ({{ number_format($document->word_count) }} words)
                                        @endif
                                        • {{ $document->source_type }}
                                    </flux:text>

                                    <!-- Text Actions -->
                                    <div class="flex flex-wrap items-center gap-2">
                                        <button
                                            wire:click="openPreviewModal({{ $document->id }})"
                                            class="inline-flex items-center px-2 py-1 text-xs font-medium text-accent bg-accent/10 rounded hover:bg-accent/20 text-accent">
                                            <flux:icon.eye class="w-3 h-3 mr-1 flex-shrink-0" />
                                            Preview
                                        </button>
                                        @if($document->external_source_identifier)
                                            <a
                                                href="{{ $document->external_source_identifier }}"
                                                target="_blank"
                                                rel="noopener"
                                                class="inline-flex items-center px-2 py-1 text-xs font-medium text-[var(--palette-notify-900)] bg-[var(--palette-notify-200)] rounded hover:bg-[var(--palette-notify-300)]">
                                                <flux:icon.arrow-top-right-on-square class="w-3 h-3 mr-1 flex-shrink-0" />
                                                View Original
                                            </a>
                                        @endif
                                        <a
                                            href="{{ route('knowledge.download', $document) }}"
                                            class="inline-flex items-center px-2 py-1 text-xs font-medium text-[var(--palette-success-900)] bg-[var(--palette-success-200)] rounded hover:bg-[var(--palette-success-300)]">
                                            <flux:icon.arrow-down-tray class="w-3 h-3 mr-1 flex-shrink-0" />
                                            Download
                                        </a>
                                    </div>

                                    <!-- Content Preview -->
                                    <flux:text size="xs" class="text-tertiary ">
                                        {{ Str::limit(strip_tags($document->content), 150) }}
                                    </flux:text>
                                </div>
                            @endif
                            
                            <!-- Tags -->
                            @if($document->tags->count() > 0)
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach($document->tags->take(5) as $tag)
                                        <flux:badge color="{{ $tag->color ?? 'zinc' }}" size="sm">
                                            {{ $tag->name }}
                                        </flux:badge>
                                    @endforeach
                                    @if($document->tags->count() > 5)
                                        <flux:badge color="zinc" size="sm">
                                            +{{ $document->tags->count() - 5 }} more
                                        </flux:badge>
                                    @endif
                                </div>
                            @endif
                            
                            <div class="mt-2 flex items-center space-x-4 flex-wrap">
                                @if($document->processing_error)
                                    <flux:text size="xs" class="text-[var(--palette-error-700)]">
                                        Error: {{ Str::limit($document->processing_error, 100) }}
                                    </flux:text>
                                @endif
                                <flux:text size="xs">{{ $document->source_type }}</flux:text>
                                <flux:text size="xs">Created by {{ $document->creator->name ?? 'Unknown' }}</flux:text>
                                <flux:text size="xs">{{ $document->updated_at->diffForHumans() }}</flux:text>
                                
                                <!-- Unified Indexing Status -->
                                @if($document->processing_status === 'completed')
                                    @php
                                        $indexingStatus = $this->getDocumentIndexingStatus($document);
                                        $relevanceScore = $this->getDocumentRelevanceScore($document);
                                    @endphp
                                    
                                    <div class="flex items-center space-x-2">
                                        <flux:text size="xs" class="@if($indexingStatus['color'] === 'green') text-[var(--palette-success-700)] @elseif($indexingStatus['color'] === 'yellow') text-[var(--palette-warning-700)] @else text-[var(--palette-error-700)] @endif">
                                            @if($indexingStatus['icon'] === 'check-circle')
                                                <flux:icon.check class="w-3 h-3 inline mr-1" />
                                            @elseif($indexingStatus['icon'] === 'exclamation-triangle')
                                                <flux:icon.exclamation-triangle class="w-3 h-3 inline mr-1" />
                                            @elseif($indexingStatus['icon'] === 'x-circle')
                                                <flux:icon.x-circle class="w-3 h-3 inline mr-1" />
                                            @endif
                                            {{ $indexingStatus['label'] }}
                                        </flux:text>

                                        <!-- Search Relevance Score -->
                                        @if($relevanceScore !== null)
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-mono font-medium bg-[var(--palette-notify-200)] text-[var(--palette-notify-900)]">
                                                {{ number_format($relevanceScore, 2) }}
                                            </span>
                                        @endif
                                    </div>
                                @endif

                            </div>
                        </div>
                    </div>

                    <!-- Actions and Screenshot Column -->
                    <div class="flex flex-col items-end space-y-3 ml-4">
                        <!-- Actions -->
                        <div class="flex items-center space-x-2">
                            @if($document->processing_status === 'failed')
                                <!-- Reprocess -->
                                <flux:button
                                    wire:click="reprocessDocument({{ $document->id }})"
                                    variant="ghost"
                                    size="sm"
                                    icon="arrow-path">
                                    Reprocess
                                </flux:button>
                            @endif

                            <!-- Edit -->
                            <flux:button
                                wire:click="editDocument({{ $document->id }})"
                                variant="ghost"
                                size="sm"
                                icon="pencil">
                                Edit
                            </flux:button>

                            <!-- Duplicate -->
                            <flux:button
                                wire:click="duplicateDocument({{ $document->id }})"
                                variant="ghost"
                                size="sm"
                                icon="document-duplicate">
                                Duplicate
                            </flux:button>

                            <!-- Delete -->
                            <flux:button
                                wire:click="deleteDocument({{ $document->id }})"
                                onclick="return confirm('Are you sure you want to delete this document? This action cannot be undone.')"
                                variant="ghost"
                                size="sm"
                                icon="trash"
                                class="text-[var(--palette-error-700)] hover:text-[var(--palette-error-800)]">
                                Delete
                            </flux:button>
                        </div>

                        <!-- Screenshot Display (below action buttons) -->
                        @if($document->thumbnail_url || (!empty($document->metadata['screenshot'])))
                            <div>
                                @if($document->thumbnail_url)
                                    <img src="{{ $document->thumbnail_url }}"
                                         alt="Page screenshot"
                                         class="w-48 h-28 rounded-lg border border-default shadow-sm object-cover" />
                                @elseif(!empty($document->metadata['screenshot']))
                                    <img src="{{ $document->metadata['screenshot'] }}"
                                         alt="Page screenshot"
                                         class="w-48 h-28 rounded-lg border border-default shadow-sm object-cover" />
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Pagination -->
        @if($documents->hasPages())
            <div class="mt-6">
                {{ $documents->links() }}
            </div>
        @endif

        <!-- Bulk Selection Actions -->
        @if(!empty($selectedDocuments))
            <div class="mt-4 p-4 bg-accent/10 rounded-lg border border-accent/30">
                <div class="flex items-center justify-between">
                    <flux:text size="sm" class="text-accent">
                        {{ count($selectedDocuments) }} documents selected
                    </flux:text>
                    <div class="flex items-center space-x-2">
                        <flux:button wire:click="selectAllDocuments" variant="ghost" size="sm">
                            Select All on Page
                        </flux:button>
                        <flux:button wire:click="clearSelection" variant="ghost" size="sm">
                            Clear Selection
                        </flux:button>
                    </div>
                </div>
            </div>
        @endif
    @else
        <!-- Empty State -->
        <div class="rounded-xl border border-default bg-surface text-center py-12  ">
            <div class="flex flex-col items-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center">
                    <flux:icon.document-text class="h-12 w-12 text-tertiary" />
                </div>
                <flux:heading size="lg" class="mt-4">No knowledge documents found</flux:heading>
                <flux:subheading class="mt-2">
                    {{ $search || $selectedContentType !== 'all' || !$showOnlyMyDocuments 
                        ? 'Try adjusting your filters to find documents.' 
                        : 'Get started by adding your first knowledge document.' }}
                </flux:subheading>
                @if(!$search && $selectedContentType === 'all' && $showOnlyMyDocuments)
                    <div class="mt-6">
                        <flux:button type="button" wire:click="createDocument" variant="primary" icon="plus">
                            Add Knowledge
                        </flux:button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- Document Editor Modal -->
    @if($showCreateModal)
        @if($editingDocument)
            <livewire:knowledge-editor :document="$editingDocument" wire:key="knowledge-editor-edit-{{ $editingDocument->id }}" />
        @else
            <livewire:knowledge-editor wire:key="knowledge-editor-create" />
        @endif
    @endif

    <!-- External Knowledge Editor Modal -->
    <livewire:external-knowledge-editor />

    <!-- Reindex Everything Confirmation Modal -->
    @if($showReindexConfirmModal ?? false)
        <flux:modal wire:model="showReindexConfirmModal" class="max-w-md">
            <div>
                <flux:heading>Confirm Full Reindex</flux:heading>
                <flux:subheading class="mt-2">
                    <div class="space-y-2">
                        <p>This will:</p>
                        <ul class="list-disc list-inside text-sm space-y-1">
                            <li>Delete the entire Meilisearch index</li>
                            <li>Recreate the index with fresh settings</li>
                            <li>Re-import all documents</li>
                            <li>Regenerate all embeddings</li>
                        </ul>
                        <p class="text-orange-600 font-medium mt-3">This process may take several minutes and cannot be undone.</p>
                    </div>
                </flux:subheading>
                
                <div class="mt-6 flex justify-end space-x-3">
                    <flux:button wire:click="$set('showReindexConfirmModal', false)" variant="ghost">
                        Cancel
                    </flux:button>
                    <flux:button wire:click="reindexEverything" x-on:click="$wire.showReindexConfirmModal = false" variant="danger">
                        Reindex Everything
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    <!-- Bulk Delete Confirmation Modal -->
    @if($showBulkDeleteModal)
        <flux:modal wire:model="showBulkDeleteModal" class="max-w-md">
            <div>
                <flux:heading>Confirm Bulk Delete</flux:heading>
                <flux:subheading class="mt-2">
                    Are you sure you want to delete {{ count($selectedDocuments) }} selected documents? This action cannot be undone.
                </flux:subheading>

                <div class="mt-6 flex justify-end space-x-3">
                    <flux:button wire:click="closeModal" variant="ghost">
                        Cancel
                    </flux:button>
                    <flux:button wire:click="confirmBulkDelete" variant="danger">
                        Delete Documents
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    <!-- Preview Modal (80% screen size) -->
    @if($showPreviewModal && $previewDocument)
        <flux:modal wire:model="showPreviewModal" class="w-[80vw] h-[80vh] max-w-none">
            <div class="h-full overflow-hidden">
                @if($this->isMarkdownDocument($previewDocument))
                    {{-- Use unified markdown viewer for markdown documents --}}
                    <livewire:markdown-viewer :documentId="$previewDocument->id" wire:key="markdown-preview-{{ $previewDocument->id }}" />
                @else
                    {{-- Use iframe for non-markdown files (PDFs, images, etc.) --}}
                    <div class="flex flex-col h-full">
                        <div class="mb-4">
                            <flux:heading>{{ $previewDocument->title }}</flux:heading>
                        </div>
                        <div class="flex-1 overflow-hidden">
                            <iframe
                                src="{{ route('knowledge.preview', $previewDocument) }}"
                                class="w-full h-full border-0 rounded-lg"
                                title="Document Preview"
                            ></iframe>
                        </div>
                    </div>
                @endif
            </div>
        </flux:modal>
    @endif
</div>

@script
<script>
    // Listen for editor events
    $wire.on('document-saved', () => {
        // Refresh the documents list
        $wire.$refresh();
    });
</script>
@endscript