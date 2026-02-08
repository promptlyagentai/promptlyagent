<div>
    @if($showModal && $document)
        <flux:modal wire:model="showModal" class="w-[70vw] max-w-[70vw]">
            <div>
                <flux:heading>
                    @if($document->content_type === 'external')
                        Edit External Knowledge Source
                    @else
                        Edit Refreshable Document
                    @endif
                </flux:heading>
                <flux:subheading class="mt-2">
                    Configure refresh settings and view metadata for: <strong>{{ $document->id }}: {{ $document->title }}</strong>
                </flux:subheading>

                <!-- Tab Navigation -->
                <div class="mt-6 border-b border-default">
                    <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                        <button
                            type="button"
                            wire:click="switchTab('settings')"
                            class="py-4 px-1 border-b-2 font-medium text-sm {{ $selectedTab === 'settings' ? 'border-accent text-accent' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' }}">
                            <flux:icon.cog-6-tooth class="w-4 h-4 inline mr-2" />
                            Settings
                        </button>
                        <button
                            type="button"
                            wire:click="switchTab('metadata')"
                            class="py-4 px-1 border-b-2 font-medium text-sm {{ $selectedTab === 'metadata' ? 'border-accent text-accent' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' }}">
                            <flux:icon.information-circle class="w-4 h-4 inline mr-2" />
                            Metadata
                        </button>
                        <button
                            type="button"
                            wire:click="switchTab('preview')"
                            class="py-4 px-1 border-b-2 font-medium text-sm {{ $selectedTab === 'preview' ? 'border-accent text-accent' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' }}">
                            <flux:icon.eye class="w-4 h-4 inline mr-2" />
                            Preview
                        </button>
                    </nav>
                </div>

                <!-- Tab Content -->
                <div class="mt-6 h-[60vh] overflow-y-auto">
                    <!-- Settings Tab -->
                    @if($selectedTab === 'settings')
                        <form wire:submit.prevent="save" class="space-y-6">
                            @php
                                // Check if this is an integration-based document
                                $isIntegration = $document->integration_id && $document->integration;
                                $integrationView = null;

                                if ($isIntegration) {
                                    try {
                                        $providerRegistry = app(\App\Services\Integrations\ProviderRegistry::class);
                                        $provider = $providerRegistry->get($document->integration->integrationToken->provider_id);

                                        if ($provider && $provider instanceof \App\Services\Integrations\Contracts\KnowledgeSourceProvider) {
                                            $integrationView = $provider->getEditModalView($document);
                                        }
                                    } catch (\Exception $e) {
                                        // Fallback to default
                                    }
                                }
                            @endphp

                            @php
                                // Check if refresh capability is available and enabled for integrations
                                $canRefresh = true;
                                if ($isIntegration && $document->integration) {
                                    try {
                                        $providerRegistry = app(\App\Services\Integrations\ProviderRegistry::class);
                                        $provider = $providerRegistry->get($document->integration->integrationToken->provider_id);

                                        if ($provider) {
                                            $evaluation = $provider->evaluateTokenCapabilities($document->integration->integrationToken);
                                            $canRefresh = in_array('Knowledge:refresh', $evaluation['available'])
                                                && $document->integration->isCapabilityEnabled('Knowledge:refresh');
                                        }
                                    } catch (\Exception $e) {
                                        // On error, allow refresh (fail open)
                                        $canRefresh = true;
                                    }
                                }
                            @endphp

                            @if(!$isIntegration)
                                <!-- Source Identifier (URL) - Only for non-integration sources -->
                                <flux:field>
                                    <flux:label>Source URL</flux:label>
                                    <flux:input
                                        wire:model="sourceIdentifier"
                                        type="url"
                                        placeholder="https://example.com/page"
                                        required />
                                    @error('sourceIdentifier')
                                        <flux:error>{{ $message }}</flux:error>
                                    @enderror
                                </flux:field>
                            @endif

                            <!-- Title -->
                            <flux:field>
                                <flux:label>Title</flux:label>
                                <flux:input
                                    wire:model="title"
                                    placeholder="Document title"
                                    required />
                                @error('title')
                                    <flux:error>{{ $message }}</flux:error>
                                @enderror
                            </flux:field>

                            <!-- Description -->
                            <flux:field>
                                <flux:label>Description</flux:label>
                                <flux:textarea
                                    wire:model="description"
                                    rows="3"
                                    placeholder="Optional description..." />
                                @error('description')
                                    <flux:error>{{ $message }}</flux:error>
                                @enderror
                            </flux:field>

                            <!-- Notes (for text documents) -->
                            @if($document->content_type === 'text')
                                <flux:field>
                                    <flux:label>Custom Notes</flux:label>
                                    <flux:textarea
                                        wire:model="notes"
                                        rows="4"
                                        placeholder="Add your personal notes or annotations..." />
                                    <flux:description>
                                        Your custom notes are preserved during content refresh and stored separately from the main content.
                                    </flux:description>
                                    @error('notes')
                                        <flux:error>{{ $message }}</flux:error>
                                    @enderror
                                </flux:field>
                            @endif

                            <!-- Tags Management -->
                            <flux:field>
                                <flux:label>Tags</flux:label>

                                <!-- Existing Tags -->
                                @if(!empty($tags))
                                    <div class="mb-3 flex flex-wrap gap-2">
                                        @foreach($tags as $index => $tag)
                                            <div class="inline-flex items-center bg-accent/20 text-accent px-3 py-1 rounded-full text-sm">
                                                <span>{{ $tag }}</span>
                                                <button
                                                    type="button"
                                                    wire:click="removeTag({{ $index }})"
                                                    class="ml-2 text-accent hover:text-accent">
                                                    <flux:icon.x-mark class="w-3 h-3" />
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                <!-- Add New Tag -->
                                <div class="flex items-center space-x-2">
                                    <input
                                        type="text"
                                        wire:model="new_tag"
                                        wire:keydown.enter.prevent="addTag"
                                        placeholder="Add a tag..."
                                        maxlength="50"
                                        class="flex-1 rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent   dark:focus:ring-accent" />
                                    <flux:button
                                        type="button"
                                        wire:click="addTag"
                                        variant="outline"
                                        size="sm"
                                        icon="plus">
                                        Add
                                    </flux:button>
                                </div>

                                @if($errors->has('new_tag'))
                                    <flux:error>{{ $errors->first('new_tag') }}</flux:error>
                                @endif

                                <flux:description>
                                    Add tags to organize and categorize your knowledge. Press Enter or click Add to add a tag. Maximum 10 tags.
                                </flux:description>

                                <!-- Suggested Tags -->
                                @if(!empty($availableTags) && $availableTags->count() > 0 && count($tags) < 10)
                                    <div class="mt-2">
                                        <flux:text size="xs" class="text-tertiary mb-1">Suggested tags:</flux:text>
                                        <div class="flex flex-wrap gap-1">
                                            @foreach($availableTags->take(10) as $suggestedTag)
                                                @if(!in_array($suggestedTag->name, $tags))
                                                    <button
                                                        type="button"
                                                        wire:click="$set('new_tag', '{{ $suggestedTag->name }}')"
                                                        class="text-xs px-2 py-1 bg-surface text-tertiary rounded hover:bg-surface-elevated">
                                                        {{ $suggestedTag->name }}
                                                    </button>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </flux:field>

                            <!-- Manage Content (for integration sources) -->
                            @if($isIntegration)
                                @php
                                    $pageManagerComponent = null;
                                    try {
                                        $providerRegistry = app(\App\Services\Integrations\ProviderRegistry::class);
                                        $provider = $providerRegistry->get($document->integration->integrationToken->provider_id);

                                        if ($provider && $provider instanceof \App\Services\Integrations\Contracts\KnowledgeSourceProvider) {
                                            $pageManagerComponent = $provider->getPageManagerComponent();
                                        }
                                    } catch (\Exception $e) {
                                        // Ignore
                                    }
                                @endphp

                                @if($pageManagerComponent)
                                    <div class="rounded-lg border border-default bg-surface p-4  ">
                                        <livewire:is :component="$pageManagerComponent" :document="$document" :key="'page-manager-'.$document->id" />
                                    </div>
                                @endif
                            @endif

                            <!-- Automatic Refresh (only if capability is enabled) -->
                            @if($canRefresh)
                                <div class="rounded-lg border border-default bg-surface p-4  ">
                                    <flux:field>
                                        <flux:checkbox
                                            wire:model.live="autoRefreshEnabled"
                                            label="Enable Automatic Refresh" />
                                        <flux:text size="xs" class="mt-1 text-tertiary ">
                                            Automatically check for updates to this external source
                                        </flux:text>
                                    </flux:field>

                                    @if($autoRefreshEnabled)
                                        <div class="mt-4 space-y-4">
                                            <!-- Refresh Interval -->
                                            <flux:field>
                                                <flux:label>Refresh Interval (minutes)</flux:label>
                                                <flux:input
                                                    wire:model="refreshIntervalMinutes"
                                                    type="number"
                                                    min="1"
                                                    max="43200"
                                                    required />
                                                <flux:text size="xs" class="mt-1">
                                                    How often to check for updates (1-43200 minutes)
                                                </flux:text>
                                                @error('refreshIntervalMinutes')
                                                    <flux:error>{{ $message }}</flux:error>
                                                @enderror
                                            </flux:field>
                                        </div>
                                    @endif
                                </div>
                            @else
                                <div class="rounded-lg border border-[var(--palette-warning-200)] bg-[var(--palette-warning-100)] p-4">
                                    <div class="flex items-start">
                                        <svg class="h-5 w-5 text-[var(--palette-warning-700)] mt-0.5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                        </svg>
                                        <div>
                                            <flux:heading size="xs">Refresh Capability Disabled</flux:heading>
                                            <flux:text size="xs" class="mt-1 text-[var(--palette-warning-800)]">
                                                The "Knowledge:refresh" capability is disabled for this integration. Enable it in the integration settings to use automatic and manual refresh features.
                                            </flux:text>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <!-- TTL Configuration -->
                            <flux:field>
                                <flux:label>Time to Live (TTL) - Hours</flux:label>
                                <flux:input
                                    wire:model="ttlHours"
                                    type="number"
                                    min="0"
                                    max="8760" />
                                <flux:text size="xs" class="mt-1">
                                    How long to keep this document before expiration (0 = never expire, 1-8760 hours)
                                </flux:text>
                                @error('ttlHours')
                                    <flux:error>{{ $message }}</flux:error>
                                @enderror
                            </flux:field>

                            <!-- Refresh Status Section -->
                            @if($canRefresh)
                                <div class="rounded-lg border border-default bg-surface p-4  ">
                                    <div class="flex items-center justify-between mb-4">
                                        <flux:heading size="sm">Refresh Status</flux:heading>
                                        <flux:button
                                            wire:click="triggerManualRefresh"
                                            variant="primary"
                                            size="sm"
                                            icon="arrow-path"
                                            :disabled="$isRefreshing">
                                            {{ $isRefreshing ? 'Refreshing...' : 'Manual Refresh' }}
                                        </flux:button>
                                    </div>

                                @if($isRefreshing && $refreshProgress)
                                    <div class="mb-4 p-3 bg-accent/10 rounded-lg">
                                        <flux:text size="sm" class="text-accent">
                                            {{ $refreshProgress }}
                                        </flux:text>
                                    </div>
                                @endif

                                <div class="space-y-2 text-sm">
                                    @if($document->last_fetched_at)
                                        <div class="flex justify-between">
                                            <span class="text-tertiary ">Last Fetched:</span>
                                            <span class="font-medium">{{ $document->last_fetched_at->format('Y-m-d H:i:s') }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-tertiary "></span>
                                            <span class="text-gray-500 text-xs">{{ $document->last_fetched_at->diffForHumans() }}</span>
                                        </div>
                                    @endif

                                    @if($document->last_refresh_attempted_at)
                                        <div class="flex justify-between">
                                            <span class="text-tertiary ">Last Refresh Attempt:</span>
                                            <span class="font-medium">{{ $document->last_refresh_attempted_at->format('Y-m-d H:i:s') }}</span>
                                        </div>
                                    @endif

                                    @if($document->last_refresh_status)
                                        <div class="flex justify-between items-center">
                                            <span class="text-tertiary ">Status:</span>
                                            <div>
                                                @if($document->last_refresh_status === 'success')
                                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-[var(--palette-success-200)] text-[var(--palette-success-900)]">
                                                        <flux:icon.check-circle class="w-3 h-3 mr-1" />
                                                        Success
                                                    </span>
                                                @elseif($document->last_refresh_status === 'failed')
                                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-[var(--palette-error-200)] text-[var(--palette-error-900)]">
                                                        <flux:icon.x-circle class="w-3 h-3 mr-1" />
                                                        Failed
                                                    </span>
                                                @elseif($document->last_refresh_status === 'in_progress')
                                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-[var(--palette-warning-200)] text-[var(--palette-warning-900)]">
                                                        <flux:icon.clock class="w-3 h-3 mr-1" />
                                                        In Progress
                                                    </span>
                                                @else
                                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-[var(--palette-neutral-200)] text-[var(--palette-neutral-900)]">{{ $document->last_refresh_status }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    @endif

                                    @if($document->last_refresh_error)
                                        <div class="mt-2 p-2 bg-[var(--palette-error-100)] rounded">
                                            <span class="text-[var(--palette-error-700)] text-xs">
                                                Error: {{ $document->last_refresh_error }}
                                            </span>
                                        </div>
                                    @endif

                                    <div class="flex justify-between">
                                        <span class="text-tertiary ">Next Refresh:</span>
                                        <span class="font-medium">
                                            @if($document->next_refresh_at && $autoRefreshEnabled)
                                                {{ $document->next_refresh_at->diffForHumans() }}
                                                <span class="text-gray-500 text-xs ml-2">({{ $document->next_refresh_at->format('Y-m-d H:i:s') }})</span>
                                            @elseif($autoRefreshEnabled)
                                                <span class="text-[var(--palette-warning-700)]">Pending scheduling</span>
                                            @else
                                                <span class="text-gray-500 dark:text-gray-400">No automatic refresh</span>
                                            @endif
                                        </span>
                                    </div>

                                    @if($document->refresh_attempt_count > 0)
                                        <div class="flex justify-between">
                                            <span class="text-tertiary ">Attempt Count:</span>
                                            <span class="font-medium">{{ $document->refresh_attempt_count }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                        </form>
                    @endif

                    <!-- Metadata Tab -->
                    @if($selectedTab === 'metadata')
                        <div class="space-y-4">
                            <!-- Integration-Specific Source Information -->
                            @php
                                $integrationView = null;
                                $viewOriginalLinks = [];

                                // Check if document uses an integration provider
                                if ($document->integration_id && $document->integration) {
                                    try {
                                        $providerRegistry = app(\App\Services\Integrations\ProviderRegistry::class);
                                        $provider = $providerRegistry->get($document->integration->integrationToken->provider_id);

                                        if ($provider && $provider instanceof \App\Services\Integrations\Contracts\KnowledgeSourceProvider) {
                                            // Get integration-specific view for edit modal
                                            $integrationView = $provider->getEditModalView($document);

                                            // Get view original links
                                            $viewOriginalLinks = $provider->renderViewOriginalLinks($document);
                                        }
                                    } catch (\Exception $e) {
                                        // Log error and fallback to default rendering
                                        \Log::warning('Failed to get integration view', [
                                            'document_id' => $document->id,
                                            'error' => $e->getMessage(),
                                        ]);
                                    }
                                }
                            @endphp

                            @if($integrationView)
                                {{-- Integration-specific metadata display --}}
                                <div class="rounded-lg border border-default bg-surface p-4  ">
                                    <flux:heading size="sm" class="mb-4">Source Information</flux:heading>
                                    @include($integrationView, ['document' => $document])
                                </div>
                            @elseif(!empty($viewOriginalLinks))
                                {{-- Integration with view original links but no custom view --}}
                                <div class="rounded-lg border border-default bg-surface p-4  ">
                                    <flux:heading size="sm" class="mb-4">Original Source</flux:heading>
                                    <div class="space-y-2">
                                        @foreach($viewOriginalLinks as $link)
                                            <a
                                                href="{{ $link['url'] }}"
                                                target="_blank"
                                                rel="noopener"
                                                class="flex items-center text-sm text-accent hover:text-accent">
                                                <flux:icon.arrow-top-right-on-square class="w-4 h-4 mr-2 flex-shrink-0" />
                                                <span class="truncate">{{ $link['label'] }}</span>
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @elseif($this->getBacklinkUrl())
                                {{-- Default external source rendering --}}
                                <div class="rounded-lg border border-default bg-surface p-4  ">
                                    <flux:heading size="sm" class="mb-2">Original Source</flux:heading>
                                    <a
                                        href="{{ $this->getBacklinkUrl() }}"
                                        target="_blank"
                                        rel="noopener"
                                        class="inline-flex items-center text-sm text-accent hover:text-accent">
                                        <flux:icon.arrow-top-right-on-square class="w-4 h-4 mr-2" />
                                        {{ $this->getBacklinkUrl() }}
                                    </a>
                                </div>
                            @endif

                            <!-- Embedding Information -->
                            @php
                                $embeddingStatus = $this->getDocumentEmbeddingStatus($document);
                            @endphp
                            @if(!empty($embeddingStatus))
                                <div class="rounded-lg border border-default bg-surface p-4  ">
                                    <flux:heading size="sm" class="mb-4">Embedding Information</flux:heading>
                                    <dl class="space-y-3">
                                        <div class="flex justify-between text-sm">
                                            <dt class="text-tertiary ">Status:</dt>
                                            <dd class="font-medium">
                                                @if($embeddingStatus['status'] === 'available')
                                                    <flux:badge color="green" size="sm">Available</flux:badge>
                                                @elseif($embeddingStatus['status'] === 'missing')
                                                    <flux:badge color="yellow" size="sm">Missing</flux:badge>
                                                @else
                                                    <flux:badge color="zinc" size="sm">{{ ucfirst($embeddingStatus['status']) }}</flux:badge>
                                                @endif
                                            </dd>
                                        </div>

                                        @if(isset($embeddingStatus['dimensions']))
                                            <div class="flex justify-between text-sm">
                                                <dt class="text-tertiary ">Dimensions:</dt>
                                                <dd class="font-medium">{{ number_format($embeddingStatus['dimensions']) }}</dd>
                                            </div>
                                        @endif

                                        @if(isset($embeddingStatus['model']))
                                            <div class="flex justify-between text-sm">
                                                <dt class="text-tertiary ">Model:</dt>
                                                <dd class="font-medium font-mono text-xs">{{ $embeddingStatus['model'] }}</dd>
                                            </div>
                                        @endif

                                        @if(isset($embeddingStatus['details']) && !empty($embeddingStatus['details']))
                                            <div class="mt-2 pt-2 border-t border-default">
                                                @foreach($embeddingStatus['details'] as $detail)
                                                    <flux:text size="xs" class="text-tertiary ">
                                                        {{ $detail }}
                                                    </flux:text>
                                                @endforeach
                                            </div>
                                        @endif
                                    </dl>
                                </div>
                            @endif

                            <!-- Cached Metadata -->
                            @php
                                $metadata = $this->getMetadata();
                            @endphp

                            @if(!empty($metadata))
                                <div class="rounded-lg border border-default bg-surface p-4  ">
                                    <flux:heading size="sm" class="mb-4">Cached Metadata</flux:heading>
                                    <dl class="space-y-3">
                                        @if(isset($metadata['author']) && $metadata['author'])
                                            <div class="flex justify-between text-sm">
                                                <dt class="text-tertiary ">Author:</dt>
                                                <dd class="font-medium">{{ $metadata['author'] }}</dd>
                                            </div>
                                        @endif

                                        @if(isset($metadata['language']) && $metadata['language'])
                                            <div class="flex justify-between text-sm">
                                                <dt class="text-tertiary ">Language:</dt>
                                                <dd class="font-medium">{{ $metadata['language'] }}</dd>
                                            </div>
                                        @endif

                                        @if(isset($metadata['publishedAt']) && $metadata['publishedAt'])
                                            <div class="flex justify-between text-sm">
                                                <dt class="text-tertiary ">Published:</dt>
                                                <dd class="font-medium">{{ $metadata['publishedAt'] }}</dd>
                                            </div>
                                        @endif

                                        @if($document->word_count)
                                            <div class="flex justify-between text-sm">
                                                <dt class="text-tertiary ">Word Count:</dt>
                                                <dd class="font-medium">{{ number_format($document->word_count) }}</dd>
                                            </div>
                                        @endif

                                        @if($document->reading_time_minutes)
                                            <div class="flex justify-between text-sm">
                                                <dt class="text-tertiary ">Reading Time:</dt>
                                                <dd class="font-medium">{{ $document->reading_time_minutes }} min</dd>
                                            </div>
                                        @endif

                                        @if(isset($metadata['contentType']) && $metadata['contentType'])
                                            <div class="flex justify-between text-sm">
                                                <dt class="text-tertiary ">Content Type:</dt>
                                                <dd class="font-medium">{{ $metadata['contentType'] }}</dd>
                                            </div>
                                        @endif
                                    </dl>
                                </div>

                                <!-- Additional Metadata Fields -->
                                @if(isset($metadata['customFields']) && !empty($metadata['customFields']))
                                    <div class="rounded-lg border border-default bg-surface p-4  ">
                                        <flux:heading size="sm" class="mb-4">Additional Information</flux:heading>
                                        <dl class="space-y-3">
                                            @foreach($metadata['customFields'] as $key => $value)
                                                <div class="flex justify-between text-sm">
                                                    <dt class="text-tertiary ">{{ ucfirst($key) }}:</dt>
                                                    <dd class="font-medium">{{ is_array($value) ? json_encode($value) : $value }}</dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    </div>
                                @endif
                            @else
                                <div class="text-center py-8">
                                    <flux:icon.information-circle class="w-12 h-12 mx-auto text-gray-400" />
                                    <flux:text size="sm" class="mt-2 text-tertiary ">
                                        No metadata available
                                    </flux:text>
                                </div>
                            @endif
                        </div>
                    @endif

                    <!-- Preview Tab -->
                    @if($selectedTab === 'preview')
                        <div class="rounded-lg border border-default bg-surface p-4  ">
                            @if($document->content)
                                <div class="prose prose-sm dark:prose-invert max-w-none">
                                    @if($document->favicon_url || $document->thumbnail_url)
                                        <div class="flex items-center space-x-4 mb-4 not-prose">
                                            @if($document->favicon_url)
                                                <img src="{{ $document->favicon_url }}" alt="Favicon" class="w-6 h-6">
                                            @endif
                                            @if($document->thumbnail_url)
                                                <img src="{{ $document->thumbnail_url }}" alt="Thumbnail" class="w-32 h-32 object-cover rounded">
                                            @endif
                                        </div>
                                    @endif

                                    <div class="whitespace-pre-wrap break-words">
                                        {!! Str::markdown(Str::limit($document->content, 5000)) !!}
                                    </div>

                                    @if(strlen($document->content) > 5000)
                                        <div class="mt-4 p-3 bg-accent/10 rounded">
                                            <flux:text size="sm" class="text-accent">
                                                Content truncated. Full content available in the full preview.
                                            </flux:text>
                                        </div>
                                    @endif
                                </div>
                            @else
                                <div class="text-center py-8">
                                    <flux:icon.document class="w-12 h-12 mx-auto text-gray-400" />
                                    <flux:text size="sm" class="mt-2 text-tertiary ">
                                        No content available
                                    </flux:text>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                <!-- Modal Actions -->
                <div class="mt-6 flex justify-between">
                    <div>
                        @if($document->content_type === 'text' && $selectedTab === 'settings')
                            <flux:button wire:click="editContent" variant="outline" icon="pencil">
                                Edit Content
                            </flux:button>
                        @endif
                    </div>
                    <div class="flex space-x-3">
                        <flux:button wire:click="closeModal" variant="ghost">
                            Cancel
                        </flux:button>
                        @if($selectedTab === 'settings')
                            <flux:button wire:click="save" variant="primary">
                                Save Changes
                            </flux:button>
                        @endif
                    </div>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
