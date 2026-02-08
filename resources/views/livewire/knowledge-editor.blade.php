{{--
    Knowledge Editor Modal Component

    Purpose: Create and edit knowledge documents from multiple sources (text, file, integrations)

    Supported Sources:
    - Text: Manual markdown/text content entry
    - File: Document uploads (PDF, DOCX, images, archives)
    - Integrations: Notion, Google Drive, and other connected services
    - External: URL-based content with auto-refresh

    Features:
    - Multi-source content import
    - AI-powered metadata suggestions (title, description, tags)
    - Tag management and organization
    - TTL (Time-to-Live) expiration settings
    - Integration browser for external content selection
    - Archive processing (ZIP, TGZ) with bulk import
    - Real-time preview for supported formats

    Livewire Properties:
    - @property bool $showModal Controls modal visibility
    - @property string $content_type Source type (text/file/external/integration:provider:id)
    - @property array $available_knowledge_integrations Connected integration sources
    - @property array $ai_suggestions AI-generated metadata from file analysis
    - @property bool $analyzing_file Whether AI is analyzing uploaded file
    - @property int $ttl_hours Document expiration time in hours

    Integration Format:
    - content_type format: "integration:provider_id:integration_id"
    - Example: "integration:notion:123" for Notion workspace 123
--}}
<div>
    @if($showModal)
        <flux:modal wire:model.live="showModal" class="w-[80vw] max-w-none">
            <form wire:submit.prevent="save" x-data="{ loadingLabel: '{{ $this->getSelectedIntegrationLabel() }}' }" x-init="() => { loadingLabel = '{{ $this->getSelectedIntegrationLabel() }}' }">
                {{-- Modal Header --}}
                <div class="flex items-start justify-between mb-6">
                    <div>
                        <flux:heading>
                            {{ $isEditing ? 'Edit Knowledge Document' : 'Add New Knowledge' }}
                        </flux:heading>
                        <flux:subheading>
                            {{ $isEditing ? 'Update your knowledge document settings and content' : 'Create a new knowledge document from text or file upload' }}
                        </flux:subheading>
                    </div>
                </div>

                <!-- Form Content -->
                <div class="space-y-6">
                    <!-- Content Type Selection (only for new documents) -->
                    @if(!$isEditing)
                        <flux:field>
                            <flux:label>Content Type</flux:label>
                            <div class="flex flex-wrap gap-3">
                                <!-- Core content types -->
                                <label class="flex items-center whitespace-nowrap cursor-pointer">
                                    <input type="radio" wire:model.live="content_type" value="text" class="mr-2 flex-shrink-0">
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                        <span class="text-sm">Text Content</span>
                                    </div>
                                </label>
                                <label class="flex items-center whitespace-nowrap cursor-pointer">
                                    <input type="radio" wire:model.live="content_type" value="file" class="mr-2 flex-shrink-0">
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                        </svg>
                                        <span class="text-sm">File Upload</span>
                                    </div>
                                </label>

                                <!-- Dynamic integration sources -->
                                @foreach($available_knowledge_integrations as $integration)
                                    @php
                                        // Format: integration:provider_id:integration_id (e.g., integration:notion:123)
                                        $integrationValue = 'integration:' . $integration['provider_id'];
                                        if (isset($integration['integration_id'])) {
                                            $integrationValue .= ':' . $integration['integration_id'];
                                        }
                                    @endphp
                                    <label class="flex items-center whitespace-nowrap cursor-pointer">
                                        <input
                                            type="radio"
                                            wire:model.live="content_type"
                                            value="{{ $integrationValue }}"
                                            class="mr-2 flex-shrink-0"
                                            @change="loadingLabel = '{{ $integration['label'] }}'"
                                            x-on:click="loadingLabel = '{{ $integration['label'] }}'"
                                        >
                                        <div class="flex items-center gap-2">
                                            @if($integration['icon'] === 'document-text')
                                                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                </svg>
                                            @elseif($integration['icon'] === 'link')
                                                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
                                                </svg>
                                            @else
                                                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                </svg>
                                            @endif
                                            <span class="text-sm">{{ $integration['label'] }}</span>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                            <flux:description>
                                Choose the source for your knowledge document
                                @if(count($available_knowledge_integrations) === 0)
                                    - <a href="{{ route('integrations.index') }}" class="text-accent hover:text-accent-hover">Connect integrations</a> to import from Notion, Google Drive, and more
                                @endif
                            </flux:description>
                        </flux:field>
                    @endif

                    <!-- Basic Information -->
                    <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 items-start">
                        <!-- Title -->
                        <flux:field>
                            <flux:label>Title</flux:label>
                            <input
                                wire:model.blur="title"
                                type="text"
                                placeholder="Enter a descriptive title..."
                                required
                                class="w-full rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent" />
                            @if($errors->has('title'))
                                <flux:description class="text-error">{{ $errors->first('title') }}</flux:description>
                            @endif
                        </flux:field>

                        <!-- Privacy Level -->
                        <flux:field>
                            <flux:label>Privacy Level</flux:label>
                            <select wire:model="privacy_level" class="w-full rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent">
                                <option value="private">Private</option>
                                <option value="public">Public</option>
                                <option value="group">Group</option>
                            </select>
                            <flux:description>
                                Private: Only you can access. Public: Anyone can access. Group: Shared with specific groups.
                            </flux:description>
                        </flux:field>
                    </div>

                    <!-- Description -->
                    <flux:textarea 
                        wire:model="description" 
                        label="Description" 
                        placeholder="Optional description of the knowledge content..."
                        rows="3"
                        error="{{ $errors->first('description') }}" />

                    <!-- Content Section -->
                    <!-- Loading State for Integration Transitions (shown immediately when switching to integrations) -->
                    <div wire:loading wire:target="content_type" class="rounded-lg border border-default bg-surface p-6   w-full">
                        <div class="w-full space-y-6">
                                <div class="flex items-center justify-center py-12">
                                    <div class="text-center">
                                        <svg class="mx-auto h-8 w-8 animate-spin text-accent" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        <p class="mt-3 text-sm font-medium text-secondary " x-text="'Loading ' + loadingLabel + '...'"></p>
                                        <p class="mt-1 text-xs text-tertiary ">Connecting and fetching available items</p>
                                    </div>
                                </div>

                                <!-- Skeleton placeholders -->
                                <div class="space-y-4 animate-pulse">
                                    <div class="h-10 bg-surface-elevated rounded-lg w-full"></div>
                                    <div class="flex flex-wrap gap-3">
                                        <div class="h-8 w-40 bg-surface-elevated rounded"></div>
                                        <div class="h-8 w-32 bg-surface-elevated rounded"></div>
                                        <div class="h-8 w-24 bg-surface-elevated rounded"></div>
                                    </div>
                                    <!-- Single skeleton row to minimize layout shift -->
                                    <div class="flex items-start gap-3 rounded-lg border border-default bg-surface p-4  ">
                                        <div class="h-4 w-4 bg-surface-elevated rounded flex-shrink-0"></div>
                                        <div class="flex-1 space-y-3">
                                            <div class="h-4 bg-surface-elevated rounded w-3/4"></div>
                                            <div class="h-3 bg-surface-elevated rounded w-1/2"></div>
                                        </div>
                                        <div class="h-6 w-20 bg-surface-elevated rounded flex-shrink-0"></div>
                                    </div>
                                </div>
                            </div>
                    </div>

                    @if($content_type === 'text')
                        <!-- Text Content with Markdown Editor -->
                        <div wire:loading.remove wire:target="content_type">
                            <flux:field>
                                <flux:label>Content</flux:label>
                                <x-markdown-editor
                                    wire:model="content"
                                    placeholder="Enter your knowledge content in Markdown format..."
                                    class="w-full"
                                    rows="12"
                                />
                                @if($errors->has('content'))
                                    <flux:description class="text-error">{{ $errors->first('content') }}</flux:description>
                                @else
                                    <flux:description>
                                        Enter the text content for your knowledge document. Use Markdown formatting with live preview. Click the Preview button to see the formatted output.
                                    </flux:description>
                                @endif
                            </flux:field>

                            <!-- AI Analysis Button for Text Content -->
                            @if(!empty($content))
                                <div class="mt-2">
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        wire:click="analyzeTextContent"
                                        wire:loading.attr="disabled"
                                        wire:target="analyzeTextContent">
                                        <flux:icon.sparkles class="w-4 h-4" wire:loading.remove wire:target="analyzeTextContent" />
                                        <flux:icon.loading class="w-4 h-4" wire:loading wire:target="analyzeTextContent" />
                                        <span wire:loading.remove wire:target="analyzeTextContent">Analyze with AI</span>
                                        <span wire:loading wire:target="analyzeTextContent">Analyzing...</span>
                                    </flux:button>
                                </div>
                            @endif

                            <!-- Show AI Suggestions -->
                            @if(!empty($ai_suggestions) && !$analyzing_file)
                                <div class="mt-3 p-4 bg-accent/10 rounded-lg border border-accent">
                                    <div class="flex items-center mb-2">
                                        <flux:icon.sparkles class="w-4 h-4 text-accent mr-2" />
                                        <span class="text-sm font-medium text-accent">AI Suggestions Applied</span>
                                        @if(!empty($ai_suggestions['ai_confidence']))
                                            <span class="ml-auto text-xs text-tertiary">
                                                Confidence: {{ number_format($ai_suggestions['ai_confidence'] * 100) }}%
                                            </span>
                                        @endif
                                    </div>

                                    <div class="text-sm space-y-1">
                                        @if(!empty($ai_suggestions['suggested_title']))
                                            <div><span class="text-tertiary">Title:</span> {{ $ai_suggestions['suggested_title'] }}</div>
                                        @endif
                                        @if(!empty($ai_suggestions['suggested_description']))
                                            <div><span class="text-tertiary">Description:</span> {{ Str::limit($ai_suggestions['suggested_description'], 100) }}</div>
                                        @endif
                                        @if(!empty($ai_suggestions['suggested_tags']))
                                            <div>
                                                <span class="text-tertiary">Tags:</span>
                                                @foreach($ai_suggestions['suggested_tags'] as $tag)
                                                    <span class="inline-block px-2 py-0.5 text-xs bg-accent/20 rounded mr-1">{{ $tag }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    @elseif($content_type === 'file' && !$isEditing)
                        <!-- File Upload -->
                        <div wire:loading.remove wire:target="content_type">
                            <flux:field>
                            <flux:label>Upload File</flux:label>
                            <div class="mt-1 flex justify-center rounded-lg border border-dashed border-default px-6 py-10 ">
                                <div class="text-center">
                                    <flux:icon.document class="mx-auto h-12 w-12 text-tertiary" />
                                    <div class="mt-4 flex text-sm leading-6 text-tertiary ">
                                        <label class="relative cursor-pointer rounded-md bg-surface font-semibold text-accent focus-within:outline-none focus-within:ring-2 focus-within:ring-accent focus-within:ring-offset-2 hover:text-accent-hover ">
                                            <span>Upload a file</span>
                                            <input wire:model="uploaded_file" type="file" class="sr-only" accept=".pdf,.docx,.doc,.txt,.md,.html,.csv,.xlsx,.xls,.png,.jpg,.jpeg,.gif,.webp,.zip,.tar,.tgz,.tar.gz">
                                        </label>
                                        <p class="pl-1">or drag and drop</p>
                                    </div>
                                    <p class="text-xs leading-5 text-tertiary ">
                                        Documents, images, code files, markdown, archives (ZIP, TGZ), and more up to 50MB
                                    </p>
                                </div>
                            </div>
                            @if($errors->has('uploaded_file'))
                                <flux:description class="text-error">{{ $errors->first('uploaded_file') }}</flux:description>
                            @endif
                            @if($uploaded_file)
                                <div class="mt-2 p-3 bg-success rounded-lg border border-success">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center">
                                            <flux:icon.document class="w-4 h-4 text-accent mr-2" />
                                            <span class="text-sm text-success">
                                                {{ $uploaded_file->getClientOriginalName() }}
                                                ({{ number_format($uploaded_file->getSize() / 1024, 1) }} KB)
                                            </span>
                                        </div>
                                        @if($analyzing_file)
                                            <div class="flex items-center text-accent">
                                                <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-accent mr-2"></div>
                                                <span class="text-xs">Analyzing with AI...</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            <!-- AI Analysis Results -->
                            @if(!empty($ai_suggestions) && !$analyzing_file)
                                <div class="mt-3 p-4 bg-accent/10 rounded-lg border border-accent">
                                    <div class="flex items-center mb-2">
                                        <flux:icon.sparkles class="w-4 h-4 text-accent mr-2" />
                                        <span class="text-sm font-medium text-accent">AI Suggestions</span>
                                        @if(!empty($ai_suggestions['ai_confidence']))
                                            <span class="ml-2 text-xs bg-accent text-accent-foreground px-2 py-1 rounded">
                                                {{ round($ai_suggestions['ai_confidence'] * 100) }}% confident
                                            </span>
                                        @endif
                                    </div>
                                    
                                    <div class="space-y-2 text-sm">
                                        @if(!empty($ai_suggestions['suggested_title']))
                                            <div class="flex items-center justify-between">
                                                <span class="text-tertiary ">Title: "{{ $ai_suggestions['suggested_title'] }}"</span>
                                                <span class="text-accent text-xs">✓ Applied</span>
                                            </div>
                                        @endif

                                        @if(!empty($ai_suggestions['suggested_description']))
                                            <div class="flex items-center justify-between">
                                                <span class="text-tertiary ">Description: "{{ Str::limit($ai_suggestions['suggested_description'], 50) }}"</span>
                                                <span class="text-accent text-xs">✓ Applied</span>
                                            </div>
                                        @endif

                                        @if(!empty($ai_suggestions['suggested_tags']))
                                            <div class="flex items-center justify-between">
                                                <span class="text-tertiary ">Tags: {{ implode(', ', $ai_suggestions['suggested_tags']) }}</span>
                                                <span class="text-accent text-xs">✓ Applied</span>
                                            </div>
                                        @endif

                                        @if(!empty($ai_suggestions['suggested_ttl_hours']) && $ai_suggestions['suggested_ttl_hours'] > 0)
                                            <div class="flex items-center justify-between">
                                                <span class="text-tertiary ">TTL: {{ $ai_suggestions['suggested_ttl_hours'] }} hours</span>
                                                <span class="text-accent text-xs">✓ Applied</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </flux:field>
                        </div>
                    @elseif(Str::startsWith($content_type, 'integration:') && !$isEditing)
                        @php
                            // Extract provider ID and integration ID from content_type
                            // Format: "integration:provider_id" or "integration:provider_id:integration_id"
                            $parts = explode(':', Str::after($content_type, 'integration:'));
                            $providerId = $parts[0] ?? null;
                            $integrationId = $parts[1] ?? null;

                            // Find the matching integration entry using both provider_id and integration_id
                            $selectedIntegration = collect($available_knowledge_integrations)->first(function ($integration) use ($providerId, $integrationId) {
                                if ($integration['provider_id'] !== $providerId) {
                                    return false;
                                }
                                // If integration_id is specified, match it exactly (UUID string); otherwise match null integration_id
                                // Use null coalescing to handle both integration_id and token_id keys
                                return ($integration['integration_id'] ?? $integration['token_id'] ?? null) === ($integrationId ?? null);
                            });

                            // Get the specific integration by ID for providers that require authentication
                            $integration = null;
                            $integrationId = $selectedIntegration['integration_id'] ?? $selectedIntegration['token_id'] ?? null;

                            if ($selectedIntegration && $integrationId) {
                                $integration = Auth::user()->integrations()
                                    ->where('id', $integrationId)
                                    ->where('status', 'active')
                                    ->with('integrationToken')
                                    ->first();
                            }
                        @endphp

                        @if($selectedIntegration && $selectedIntegration['component_class'])
                            @if($has_notion_preview && !empty($notion_preview_data) && $providerId === 'notion')
                                <!-- Notion Preview (replaces browser after collection) -->
                                <div class="border border-accent/30 bg-accent/10 rounded-lg p-6">
                                    <div class="flex items-center mb-4">
                                        <svg class="w-5 h-5 text-accent mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        <div class="flex-1">
                                            <div class="text-sm font-medium text-primary">
                                                Collected {{ $notion_preview_data['page_count'] ?? 0 }} page(s) from Notion
                                            </div>
                                            <div class="text-xs text-secondary mt-1">
                                                AI-generated metadata has been populated below. Review and edit before saving.
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Collected Pages -->
                                    @if(!empty($notion_preview_data['source_config']['pages']))
                                        <details class="mt-3">
                                            <summary class="cursor-pointer text-sm font-medium text-primary hover:text-accent">
                                                View Collected Pages ({{ count($notion_preview_data['source_config']['pages']) }})
                                            </summary>
                                            <div class="mt-3 space-y-2 max-h-64 overflow-y-auto">
                                                @foreach($notion_preview_data['source_config']['pages'] as $page)
                                                    <div class="bg-surface  rounded p-3 text-sm border border-default">
                                                        <div class="flex items-start justify-between">
                                                            <div class="flex-1 min-w-0">
                                                                <div class="font-medium text-primary truncate">
                                                                    {{ $page['title'] ?? 'Untitled' }}
                                                                </div>
                                                                @if(!empty($page['url']))
                                                                    <a href="{{ $page['url'] }}" target="_blank" class="text-xs text-accent hover:text-accent-hover truncate block">
                                                                        {{ $page['url'] }}
                                                                    </a>
                                                                @endif
                                                                @if(!empty($page['last_edited_time']))
                                                                    <div class="text-xs text-tertiary  mt-1">
                                                                        Last edited: {{ \Carbon\Carbon::parse($page['last_edited_time'])->diffForHumans() }}
                                                                    </div>
                                                                @endif
                                                            </div>
                                                            @if($page['import_children'] ?? false)
                                                                <span class="ml-2 text-xs bg-accent/20 text-accent px-2 py-1 rounded">
                                                                    + children
                                                                </span>
                                                            @endif
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </details>
                                    @endif

                                    <!-- Raw JSON -->
                                    <details class="mt-3">
                                        <summary class="cursor-pointer text-sm font-medium text-primary hover:text-accent">
                                            View Raw Source Data (JSON)
                                        </summary>
                                        <div class="mt-3 bg-zinc-900 dark:bg-zinc-950 rounded p-3 overflow-x-auto">
                                            <pre class="text-xs text-zinc-100"><code>{{ json_encode($notion_preview_data['source_config'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                                        </div>
                                    </details>
                                </div>
                            @else
                                <!-- Dynamic Integration Browser Component -->
                                <div wire:loading.remove wire:target="content_type">
                                    <div class="rounded-lg border border-default bg-surface p-6  ">
                                        @livewire($selectedIntegration['component_class'], ['integration' => $integration], key('integration-browser-'.$providerId.'-'.$integrationId))
                                    </div>
                                </div>
                            @endif
                        @endif
                    @elseif($content_type === 'external' && !$isEditing)
                        <!-- External URL Source (Legacy - will be refactored to provider pattern) -->
                        <flux:field>
                            <flux:label>External Source URL</flux:label>
                            <div class="flex space-x-2">
                                <input
                                    wire:model.live.debounce.500ms="external_source_url"
                                    type="url"
                                    placeholder="https://example.com/article"
                                    class="flex-1 rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent" />
                                <flux:button
                                    type="button"
                                    wire:click="validateUrl"
                                    variant="outline"
                                    size="sm"
                                    :disabled="empty($external_source_url) || $validating_url">
                                    @if($validating_url)
                                        <div class="animate-spin rounded-full h-3 w-3 border-b-2 border-accent mr-1"></div>
                                        Validating...
                                    @else
                                        Validate & Fill
                                    @endif
                                </flux:button>
                            </div>
                            @if($errors->has('external_source_url'))
                                <flux:description class="text-error">{{ $errors->first('external_source_url') }}</flux:description>
                            @else
                                <flux:description>
                                    Enter the URL of the external knowledge source (web page, article, documentation, etc.)
                                </flux:description>
                            @endif

                            <!-- URL Validation Results -->
                            @if(!empty($url_validation_results) && !$validating_url)
                                <div class="mt-3 p-4 rounded-lg border {{ $url_validation_results['isValid'] ? 'bg-success border-success' : 'bg-error border-error' }}">
                                    <div class="flex items-center mb-2">
                                        @if($url_validation_results['isValid'])
                                            <flux:icon.check-circle class="w-4 h-4 text-success mr-2" />
                                            <span class="text-sm font-medium text-success">URL Validated Successfully</span>
                                        @else
                                            <flux:icon.x-circle class="w-4 h-4 text-error mr-2" />
                                            <span class="text-sm font-medium text-error">URL Validation Failed</span>
                                        @endif
                                    </div>

                                    @if($url_validation_results['isValid'])
                                        <div class="space-y-1 text-sm">
                                            @if(!empty($url_validation_results['metadata']['title']))
                                                <div class="flex items-center justify-between">
                                                    <span class="text-tertiary ">Title: "{{ $url_validation_results['metadata']['title'] }}"</span>
                                                    <span class="text-accent text-xs">✓ Applied</span>
                                                </div>
                                            @endif

                                            @if(!empty($url_validation_results['metadata']['description']))
                                                <div class="flex items-center justify-between">
                                                    <span class="text-tertiary ">Description: "{{ Str::limit($url_validation_results['metadata']['description'], 50) }}"</span>
                                                    <span class="text-accent text-xs">✓ Applied</span>
                                                </div>
                                            @endif

                                            @if(!empty($url_validation_results['suggestedTags']))
                                                <div class="flex items-center justify-between">
                                                    <span class="text-tertiary ">Tags: {{ implode(', ', array_slice($url_validation_results['suggestedTags'], 0, 3)) }}</span>
                                                    <span class="text-accent text-xs">✓ Applied</span>
                                                </div>
                                            @endif
                                        </div>
                                    @else
                                        <p class="text-sm text-error">
                                            {{ $url_validation_results['error'] ?? 'Unable to access or parse the URL content.' }}
                                        </p>
                                    @endif
                                </div>
                            @endif
                        </flux:field>

                        <!-- Auto-refresh Settings -->
                        <flux:field>
                            <flux:label>Auto-refresh Settings</flux:label>
                            <div class="space-y-4">
                                <div class="flex items-center">
                                    <input
                                        type="checkbox"
                                        wire:model="auto_refresh_enabled"
                                        class="rounded border-default text-accent shadow-sm focus:border-accent focus:ring focus:ring-accent/20 focus:ring-opacity-50"
                                    />
                                    <label class="ml-2 text-sm font-medium text-secondary ">
                                        Enable automatic refresh
                                    </label>
                                </div>

                                @if($auto_refresh_enabled)
                                    <div class="ml-6 space-y-2">
                                        <div class="flex items-center space-x-2">
                                            <span class="text-sm text-tertiary ">Refresh every</span>
                                            <input
                                                type="number"
                                                wire:model="refresh_interval_minutes"
                                                min="5"
                                                max="10080"
                                                class="w-20 rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent"
                                            />
                                            <span class="text-sm text-tertiary ">minutes</span>
                                        </div>
                                        <flux:description>
                                            The system will periodically check for changes and update the content automatically.
                                            Minimum: 5 minutes, Maximum: 1 week (10080 minutes)
                                        </flux:description>
                                    </div>
                                @endif
                            </div>
                            <flux:description>
                                Enable auto-refresh to keep external knowledge sources up-to-date automatically.
                            </flux:description>
                        </flux:field>
                    @endif

                    <!-- TTL (Time to Live) -->
                    <flux:field>
                        <flux:label>Expiration (TTL)</flux:label>
                        <div class="flex items-center space-x-2">
                            <input
                                type="number"
                                wire:model="ttl_hours"
                                placeholder="Hours"
                                min="0"
                                max="8760"
                                class="w-24 rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent" />
                            <flux:text size="sm">hours (0 = never expire)</flux:text>
                        </div>
                        <flux:description>
                            Set when this knowledge should expire and be excluded from searches. Set to 0 or leave empty for no expiration.
                            Quick presets: 24h (1 day), 168h (1 week), 720h (1 month)
                        </flux:description>
                        @if($errors->has('ttl_hours'))
                            <flux:description class="text-error">{{ $errors->first('ttl_hours') }}</flux:description>
                        @endif
                    </flux:field>

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
                                            class="ml-2 text-accent hover:text-accent-hover">
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
                                class="flex-1 rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent" />
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
                            <flux:description class="text-error">{{ $errors->first('new_tag') }}</flux:description>
                        @endif
                        
                        <flux:description>
                            Add tags to organize and categorize your knowledge. Press Enter or click Add to add a tag.
                        </flux:description>
                        
                        <!-- Suggested Tags -->
                        @if($availableTags->count() > 0 && count($tags) < 10)
                            <div class="mt-2">
                                <flux:text size="xs" class="text-tertiary mb-1">{{ $tagSuggestionLabel }}</flux:text>
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
                </div>

                <!-- Modal Actions -->
                <div class="mt-8 flex justify-end space-x-3">
                    <flux:button wire:click="closeEditor" type="button" variant="ghost">
                        Cancel
                    </flux:button>
                    <flux:button type="submit" variant="primary">
                        @if($isEditing)
                            Update Document
                        @else
                            @if($content_type === 'file')
                                Upload & Process
                            @elseif($content_type === 'external')
                                Add External Source
                            @else
                                Create Document
                            @endif
                        @endif
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    <!-- Archive Warning Dialog -->
    @if($show_archive_warning)
        <flux:modal wire:model="show_archive_warning" class="max-w-2xl">
            <div class="p-6">
                <div class="flex items-center mb-4">
                    <flux:icon.exclamation-triangle class="w-6 h-6 text-[var(--palette-warning-700)] mr-3" />
                    <flux:heading size="lg">Archive Detected</flux:heading>
                </div>
                
                <div class="space-y-4">
                    <flux:subheading>
                        You've uploaded an archive file that contains multiple files. Each file will be processed as a separate knowledge document.
                    </flux:subheading>
                    
                    @if(!empty($archive_info))
                        <div class="bg-surface rounded-lg p-4 ">
                            <div class="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <span class="font-medium text-secondary ">Archive Type:</span>
                                    <span class="text-tertiary ">{{ $uploaded_file->getClientOriginalName() }}</span>
                                </div>
                                <div>
                                    <span class="font-medium text-secondary ">Total Files:</span>
                                    <span class="text-tertiary ">{{ $archive_info['total_files'] ?? 0 }}</span>
                                </div>
                                <div>
                                    <span class="font-medium text-secondary ">Total Size:</span>
                                    <span class="text-tertiary ">{{ number_format(($archive_info['total_size'] ?? 0) / 1024, 1) }} KB</span>
                                </div>
                                <div>
                                    <span class="font-medium text-secondary ">Base Title:</span>
                                    <span class="text-tertiary ">{{ $title }}</span>
                                </div>
                            </div>
                        </div>
                        
                        @if(!empty($archive_info['files']) && count($archive_info['files']) <= 10)
                            <div class="space-y-2">
                                <flux:text size="sm" class="font-medium">Files in archive:</flux:text>
                                <div class="max-h-40 overflow-y-auto bg-surface rounded p-3 ">
                                    @foreach(array_slice($archive_info['files'], 0, 10) as $file)
                                        <div class="flex items-center justify-between text-xs py-1">
                                            <span class="text-secondary  truncate">{{ $file['name'] }}</span>
                                            <span class="text-tertiary ml-2">{{ number_format($file['size'] / 1024, 1) }} KB</span>
                                        </div>
                                    @endforeach
                                    @if(count($archive_info['files']) > 10)
                                        <div class="text-xs text-tertiary pt-2 border-t border-default">
                                            ... and {{ count($archive_info['files']) - 10 }} more files
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @endif
                    
                    <div class="bg-[var(--palette-warning-100)] border border-[var(--palette-warning-200)] rounded-lg p-4">
                        <div class="flex items-start">
                            <flux:icon.information-circle class="w-5 h-5 text-[var(--palette-warning-700)] mr-2 mt-0.5" />
                            <div class="text-sm text-[var(--palette-warning-800)]">
                                <strong>Important:</strong>
                                <ul class="mt-1 ml-4 list-disc space-y-1">
                                    <li>Each compatible file will become a separate knowledge document</li>
                                    <li>Document titles will be: "{{ $title }} - [filename]"</li>
                                    <li>All documents will use the same privacy level and tags</li>
                                    <li>You'll need to review and edit each document individually after creation</li>
                                    <li>Only supported file types will be processed (unsupported files will be skipped)</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-3">
                    <flux:button wire:click="cancelArchiveProcessing" variant="ghost">
                        Cancel
                    </flux:button>
                    <flux:button wire:click="confirmArchiveProcessing" variant="primary">
                        Process Archive
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    <!-- Import Progress Modal -->
    @if($show_import_progress)
        <flux:modal wire:model="show_import_progress" class="max-w-2xl">
            <div class="p-6">
                <div class="flex items-center mb-4">
                    <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-accent mr-3"></div>
                    <flux:heading size="lg">Importing from Notion</flux:heading>
                </div>

                <div class="space-y-4">
                    <!-- Progress Bar -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <flux:text size="sm">Progress</flux:text>
                            <flux:text size="sm" class="font-medium">
                                {{ $import_processed }} / {{ $import_total }}
                            </flux:text>
                        </div>
                        <div class="w-full bg-surface rounded-full h-2">
                            <div
                                class="bg-accent h-2 rounded-full transition-all duration-300"
                                style="width: {{ $import_total > 0 ? ($import_processed / $import_total * 100) : 0 }}%">
                            </div>
                        </div>
                    </div>

                    <!-- Stats -->
                    <div class="grid grid-cols-3 gap-4">
                        <div class="bg-accent/10 rounded-lg p-3">
                            <div class="text-xs text-accent">Total</div>
                            <div class="text-2xl font-semibold text-accent">
                                {{ $import_total }}
                            </div>
                        </div>
                        <div class="bg-success rounded-lg p-3">
                            <div class="text-xs text-success">Successful</div>
                            <div class="text-2xl font-semibold text-success">
                                {{ $import_successful }}
                            </div>
                        </div>
                        <div class="bg-error rounded-lg p-3">
                            <div class="text-xs text-error">Failed</div>
                            <div class="text-2xl font-semibold text-error">
                                {{ $import_failed }}
                            </div>
                        </div>
                    </div>

                    <!-- Import Results List -->
                    @if(!empty($import_results))
                        <div class="max-h-48 overflow-y-auto bg-surface rounded-lg p-3 ">
                            @foreach($import_results as $result)
                                <div class="flex items-start py-2 text-sm {{ !$loop->last ? 'border-b border-default' : '' }}">
                                    @if($result['status'] === 'success')
                                        <flux:icon.check-circle class="w-4 h-4 text-success mr-2 mt-0.5 flex-shrink-0" />
                                        <span class="text-secondary ">{{ $result['item'] }}</span>
                                    @else
                                        <flux:icon.x-circle class="w-4 h-4 text-error mr-2 mt-0.5 flex-shrink-0" />
                                        <div class="flex-1">
                                            <span class="text-secondary ">{{ $result['item'] }}</span>
                                            <div class="text-xs text-error">{{ $result['error'] }}</div>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <!-- Completion Message -->
                    @if($import_processed === $import_total && $import_total > 0)
                        <div class="bg-success border border-success rounded-lg p-4">
                            <div class="flex items-center">
                                <flux:icon.check-circle class="w-5 h-5 text-success mr-2" />
                                <span class="text-sm text-success">
                                    Import completed! Closing in a moment...
                                </span>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </flux:modal>
    @endif
</div>

@script
<script>
    // Handle file drag and drop
    document.addEventListener('DOMContentLoaded', function() {
        const dropZone = document.querySelector('[wire\\:model="uploaded_file"]')?.closest('.border-dashed');

        if (dropZone) {
            dropZone.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('border-accent', 'bg-accent/10');
            });

            dropZone.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.classList.remove('border-accent', 'bg-accent/10');
            });

            dropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('border-accent', 'bg-accent/10');

                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    const input = this.querySelector('input[type="file"]');
                    input.files = files;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        }
    });

    // Listen for close-all-import-modals event
    $wire.on('close-all-import-modals', () => {
        // Wait 2 seconds to show completion message, then close all modals
        setTimeout(() => {
            $wire.show_import_progress = false;
            $wire.showModal = false;
            $wire.closeEditor();
        }, 2000);
    });

</script>
@endscript