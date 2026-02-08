<div>
    <!-- Modal Overlay -->
    <div x-show="$wire.show" 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm z-50"
         @click="$wire.closeModal()"
         style="display: none;">
         
        <!-- Modal Content -->
        <div class="flex items-center justify-center min-h-screen p-4">
            <div @click.stop 
                 x-show="$wire.show"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95"
                 class="bg-surface rounded-xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden">
                
                <!-- Header -->
                <div class="flex items-center justify-between p-6 border-b border-default">
                    <div class="flex items-center gap-3">
                        @if($statusStream)
                            <span class="text-2xl">{{ $this->getStepTypeIcon() }}</span>
                            <div>
                                <h2 class="text-xl font-semibold text-primary">
                                    Execution Step Details
                                </h2>
                                <p class="{{ $this->getStepTypeColor() }} text-sm">
                                    {{ $statusStream->source }} • {{ $statusStream->timestamp->format('Y-m-d H:i:s T') }}
                                </p>
                            </div>
                        @else
                            <h2 class="text-xl font-semibold text-primary">
                                Step Details
                            </h2>
                        @endif
                    </div>
                    
                    <!-- Close Button -->
                    <button wire:click="closeModal" 
                            class="p-2 text-secondary hover:text-primary rounded-lg hover:bg-surface-elevated">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Content -->
                <div class="p-6 overflow-y-auto max-h-[calc(90vh-140px)]">
                    @if($error)
                        <!-- Error State -->
                        <div class="bg-[var(--palette-error-100)] border border-[var(--palette-error-200)] rounded-lg p-4">
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5 text-[var(--palette-error-700)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <span class="text-[var(--palette-error-800)] font-medium">Error</span>
                            </div>
                            <p class="text-[var(--palette-error-700)] mt-2">{{ $error }}</p>
                        </div>
                    @elseif($statusStream)
                        <div class="space-y-6">
                            <!-- Main Message -->
                            <div>
                                <h3 class="text-lg font-medium text-primary mb-3">Message</h3>
                                <div class="bg-surface rounded-lg p-4">
                                    <p class="text-primary whitespace-pre-wrap">{{ $statusStream->message }}</p>
                                </div>
                            </div>

                            <!-- Technical Details -->
                            <div>
                                <h3 class="text-lg font-medium text-primary mb-3">Technical Details</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="bg-surface rounded-lg p-4">
                                        <div class="text-sm text-secondary">ID</div>
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono text-primary">{{ $statusStream->id }}</span>
                                            <button wire:click="copyToClipboard('{{ $statusStream->id }}')" 
                                                    class="text-secondary hover:text-primary">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="bg-surface rounded-lg p-4">
                                        <div class="text-sm text-secondary">Interaction ID</div>
                                        <div class="flex items-center gap-2">
                                            <span class="font-mono text-primary">{{ $statusStream->interaction_id }}</span>
                                            <button wire:click="copyToClipboard('{{ $statusStream->interaction_id }}')" 
                                                    class="text-secondary hover:text-primary">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="bg-surface rounded-lg p-4">
                                        <div class="text-sm text-secondary">Significant</div>
                                        <span class="px-2 py-1 text-xs font-medium rounded {{ $statusStream->is_significant ? 'bg-[var(--palette-success-100)] text-[var(--palette-success-800)] dark:bg-[var(--palette-success-900)] dark:text-[var(--palette-success-300)]' : 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300' }}">
                                            {{ $statusStream->is_significant ? 'Yes' : 'No' }}
                                        </span>
                                    </div>
                                    
                                    <div class="bg-surface rounded-lg p-4">
                                        <div class="text-sm text-secondary">Create Event</div>
                                        <span class="px-2 py-1 text-xs font-medium rounded {{ $statusStream->create_event ? 'bg-[var(--palette-success-100)] text-[var(--palette-success-800)] dark:bg-[var(--palette-success-900)] dark:text-[var(--palette-success-300)]' : 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-300' }}">
                                            {{ $statusStream->create_event ? 'Yes' : 'No' }}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Metadata -->
                            @if(!empty($formattedMetadata))
                                <div x-data="{ expanded: false }">
                                    <div class="flex items-center justify-between mb-3">
                                        <h3 class="text-lg font-medium text-primary">Metadata</h3>
                                        <button @click="expanded = !expanded" 
                                                class="text-secondary hover:text-primary text-sm">
                                            <span x-text="expanded ? 'Collapse' : 'Expand'"></span>
                                        </button>
                                    </div>
                                    
                                    <div x-show="expanded" x-transition class="space-y-3">
                                        @foreach($formattedMetadata as $item)
                                            <div class="bg-surface rounded-lg p-4">
                                                <div class="flex items-center gap-2 mb-2">
                                                    <span class="font-medium text-primary">{{ $item['key'] }}</span>
                                                    <span class="px-2 py-0.5 text-xs bg-surface  text-secondary rounded">
                                                        {{ $item['type'] }}
                                                    </span>
                                                </div>
                                                
                                                @if($item['type'] === 'json')
                                                    <pre class="bg-code rounded p-3 text-sm overflow-x-auto">
<code class="text-primary">{{ $item['formatted_value'] }}</code></pre>
                                                @elseif($item['type'] === 'url')
                                                    <a href="{{ $item['formatted_value'] }}" target="_blank" 
                                                       class="text-accent hover:underline break-all">
                                                        {{ $item['formatted_value'] }}
                                                    </a>
                                                @else
                                                    <div class="text-primary break-all">
                                                        {{ $item['formatted_value'] }}
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <!-- Related Agent Execution -->
                            @if($statusStream->agentExecution)
                                <div>
                                    <h3 class="text-lg font-medium text-primary mb-3">Related Agent Execution</h3>
                                    <div class="bg-surface rounded-lg p-4">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                            <div>
                                                <div class="text-sm text-secondary">Agent</div>
                                                <div class="font-medium text-primary">
                                                    {{ $statusStream->agentExecution->agent->name ?? 'Unknown Agent' }}
                                                </div>
                                            </div>
                                            
                                            <div>
                                                <div class="text-sm text-secondary">Status</div>
                                                <span class="px-2 py-1 text-xs font-medium rounded {{ $statusStream->agentExecution->status === 'completed' ? 'bg-[var(--palette-success-200)] text-[var(--palette-success-900)]' : ($statusStream->agentExecution->status === 'running' ? 'bg-accent text-accent-foreground' : 'bg-[var(--palette-error-200)] text-[var(--palette-error-900)]') }}">
                                                    {{ ucfirst($statusStream->agentExecution->status) }}
                                                </span>
                                            </div>
                                            
                                            <div>
                                                <div class="text-sm text-secondary">Started At</div>
                                                <div class="text-primary">
                                                    {{ ($statusStream->agentExecution->started_at ?? $statusStream->agentExecution->created_at)->format('Y-m-d H:i:s T') }}
                                                </div>
                                            </div>
                                            
                                            <div>
                                                <div class="text-sm text-secondary">Completed At</div>
                                                <div class="text-primary">
                                                    {{ ($statusStream->agentExecution->completed_at ?? $statusStream->agentExecution->updated_at)->format('Y-m-d H:i:s T') }}
                                                </div>
                                            </div>
                                        </div>
                                        
                                        @if($statusStream->agentExecution->error_message)
                                            <div class="mb-4">
                                                <div class="flex items-center justify-between mb-2">
                                                    <div class="text-sm text-secondary">Error Message</div>
                                                    <button wire:click="retryExecution"
                                                            class="px-3 py-1 text-xs bg-[var(--palette-error-100)] text-[var(--palette-error-800)] rounded hover:bg-[var(--palette-error-200)] flex items-center gap-1">
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                                        </svg>
                                                        Retry Execution
                                                    </button>
                                                </div>
                                                <div class="bg-[var(--palette-error-100)] border border-[var(--palette-error-200)] rounded p-3">
                                                    <p class="text-[var(--palette-error-800)] text-sm">{{ $statusStream->agentExecution->error_message }}</p>
                                                </div>
                                            </div>
                                        @endif
                                        
                                        <div class="flex justify-end">
                                            <button wire:click="toggleExecutionDetails"
                                                    class="px-3 py-1 text-sm bg-accent text-accent-foreground rounded hover:bg-accent-hover flex items-center gap-2">
                                                <span x-text="$wire.showFullExecutionDetails ? 'Hide' : 'Show'"></span>
                                                Full Execution Details
                                                <svg class="w-4 h-4 transition-transform duration-200" 
                                                     :class="$wire.showFullExecutionDetails ? 'rotate-180' : ''"
                                                     fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                                                </svg>
                                            </button>
                                        </div>

                                        <!-- Expanded Execution Details -->
                                        <div x-show="$wire.showFullExecutionDetails" 
                                             x-transition:enter="transition ease-out duration-300"
                                             x-transition:enter-start="opacity-0 max-h-0"
                                             x-transition:enter-end="opacity-100 max-h-screen"
                                             x-transition:leave="transition ease-in duration-200"
                                             x-transition:leave-start="opacity-100 max-h-screen"
                                             x-transition:leave-end="opacity-0 max-h-0"
                                             class="mt-4 overflow-hidden">
                                            
                                            <div class="border-t border-default pt-4">
                                                <h4 class="text-md font-medium text-primary mb-3">Complete Execution Information</h4>
                                                
                                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                                    <div>
                                                        <div class="text-sm text-secondary">Execution ID</div>
                                                        <div class="flex items-center gap-2">
                                                            <span class="font-mono text-primary">{{ $statusStream->agentExecution->id }}</span>
                                                            <button wire:click="copyToClipboard('{{ $statusStream->agentExecution->id }}')" 
                                                                    class="text-secondary hover:text-primary">
                                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                                                </svg>
                                                            </button>
                                                        </div>
                                                    </div>
                                                    
                                                    <div>
                                                        <div class="text-sm text-secondary">User ID</div>
                                                        <div class="font-mono text-primary">{{ $statusStream->agentExecution->user_id }}</div>
                                                    </div>
                                                    
                                                </div>

                                                @if($statusStream->agentExecution->input)
                                                    <div class="mb-4">
                                                        <div class="flex items-center justify-between mb-2">
                                                            <div class="text-sm text-secondary">Input</div>
                                                            <button wire:click="copyToClipboard({{ json_encode($statusStream->agentExecution->input) }})" 
                                                                    class="text-secondary hover:text-primary p-1 rounded"
                                                                    title="Copy input to clipboard">
                                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                                                </svg>
                                                            </button>
                                                        </div>
                                                        
                                                        <!-- Tabbed view for input rendered vs raw -->
                                                        <div x-data="{ activeTab: 'rendered' }" class="bg-accent/10 border border-accent rounded-lg">
                                                            <!-- Tab Navigation -->
                                                            <div class="flex border-b border-accent">
                                                                <button @click="activeTab = 'rendered'" 
                                                                        :class="activeTab === 'rendered' ? 'bg-accent text-accent-foreground' : 'text-accent hover:bg-accent/20'"
                                                                        class="px-4 py-2 text-sm font-medium rounded-tl-lg">
                                                                    Rendered
                                                                </button>
                                                                <button @click="activeTab = 'raw'" 
                                                                        :class="activeTab === 'raw' ? 'bg-accent text-accent-foreground' : 'text-accent hover:bg-accent/20'"
                                                                        class="px-4 py-2 text-sm font-medium">
                                                                    Raw Text
                                                                </button>
                                                            </div>
                                                            
                                                            <!-- Tab Content -->
                                                            <div class="p-4 max-h-60 overflow-y-auto">
                                                                <!-- Rendered View -->
                                                                <div x-show="activeTab === 'rendered'" class="prose dark:prose-invert max-w-none">
                                                                    <div x-data="markdownRenderer()" class="text-primary">
                                                                        <span x-ref="source" class="hidden">{{ $statusStream->agentExecution->input }}</span>
                                                                        <div x-ref="target" class="markdown" x-html="renderedHtml"></div>
                                                                    </div>
                                                                </div>
                                                                
                                                                <!-- Raw Text View -->
                                                                <div x-show="activeTab === 'raw'" style="display: none;">
                                                                    <pre class="text-sm overflow-x-auto whitespace-pre-wrap text-primary font-mono">{{ $statusStream->agentExecution->input }}</pre>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endif

                                                @if($statusStream->agentExecution->output)
                                                    <div class="mb-4">
                                                        <div class="flex items-center justify-between mb-2">
                                                            <div class="text-sm text-secondary">Output</div>
                                                            <div class="flex items-center gap-2">
                                                                <button wire:click="saveOutputToChat"
                                                                        class="text-secondary hover:text-primary p-1 rounded"
                                                                        title="Save output to chat interaction">
                                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V7l-4-4z"/>
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 17H8v-6h8v6z"/>
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v4"/>
                                                                    </svg>
                                                                </button>
                                                                <button wire:click="copyToClipboard({{ json_encode($statusStream->agentExecution->output) }})"
                                                                        class="text-secondary hover:text-primary p-1 rounded"
                                                                        title="Copy output to clipboard">
                                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                                                    </svg>
                                                                </button>
                                                            </div>
                                                        </div>
                                                        
                                                        <!-- Tabbed view for output rendered vs raw -->
                                                        <div x-data="{ activeTab: 'rendered' }" class="bg-accent/10 border border-accent rounded-lg">
                                                            <!-- Tab Navigation -->
                                                            <div class="flex border-b border-accent">
                                                                <button @click="activeTab = 'rendered'"
                                                                        :class="activeTab === 'rendered' ? 'bg-accent text-accent-foreground' : 'text-accent hover:bg-accent/20'"
                                                                        class="px-4 py-2 text-sm font-medium rounded-tl-lg">
                                                                    Rendered
                                                                </button>
                                                                <button @click="activeTab = 'raw'"
                                                                        :class="activeTab === 'raw' ? 'bg-accent text-accent-foreground' : 'text-accent hover:bg-accent/20'"
                                                                        class="px-4 py-2 text-sm font-medium">
                                                                    Raw Text
                                                                </button>
                                                            </div>

                                                            <!-- Tab Content -->
                                                            <div class="p-4 max-h-60 overflow-y-auto">
                                                                <!-- Rendered View -->
                                                                <div x-show="activeTab === 'rendered'" class="prose dark:prose-invert max-w-none">
                                                                    <div x-data="markdownRenderer()" class="text-primary">
                                                                        <span x-ref="source" class="hidden">{{ $statusStream->agentExecution->output }}</span>
                                                                        <div x-ref="target" class="markdown" x-html="renderedHtml"></div>
                                                                    </div>
                                                                </div>

                                                                <!-- Raw Text View -->
                                                                <div x-show="activeTab === 'raw'" style="display: none;">
                                                                    <pre class="text-sm overflow-x-auto whitespace-pre-wrap text-primary font-mono">{{ $statusStream->agentExecution->output }}</pre>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endif

                                                @if($statusStream->agentExecution->metadata)
                                                    <div x-data="{ metadataExpanded: false }" class="mb-4">
                                                        <div class="flex items-center justify-between mb-2">
                                                            <div class="text-sm text-secondary">Execution Metadata</div>
                                                            <button @click="metadataExpanded = !metadataExpanded"
                                                                    class="text-secondary hover:text-primary text-sm">
                                                                <span x-text="metadataExpanded ? 'Collapse' : 'Expand'"></span>
                                                            </button>
                                                        </div>
                                                        <div x-show="metadataExpanded" x-transition>
                                                            <div class="bg-surface rounded-lg p-4">
                                                                <pre class="text-sm overflow-x-auto">
<code class="text-primary">{{ json_encode($statusStream->agentExecution->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</code></pre>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <!-- Related Interaction -->
                            @if($statusStream->chatInteraction)
                                <div>
                                    <h3 class="text-lg font-medium text-primary mb-3">Related Interaction</h3>
                                    <div class="bg-surface rounded-lg p-4">
                                        <div>
                                            <div class="font-medium text-primary">
                                                {{ Str::limit($statusStream->chatInteraction->question, 100) }}
                                            </div>
                                            <div class="text-sm text-secondary mt-1">
                                                {{ $statusStream->chatInteraction->created_at->format('Y-m-d H:i:s T') }}
                                            </div>
                                        </div>

                                        <div class="flex justify-end pt-2 border-t border-default mt-4">
                                            <button wire:click="closeModal(); $dispatch('openInteractionModal', { interactionId: {{ $statusStream->chatInteraction->id }} })"
                                                    class="px-3 py-1 text-sm bg-accent text-accent-foreground rounded hover:bg-accent-hover flex items-center gap-2">
                                                <span>View Details</span>
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Copy to Clipboard & Markdown Renderer JavaScript -->
    <script>
        // Alpine.js markdown renderer function
        function markdownRenderer() {
            return {
                renderedHtml: '',
                init() {
                    this.render();
                    this.observer = new MutationObserver(() => this.render());
                    this.observer.observe(this.$refs.source, {
                        characterData: true,
                        childList: true,
                        subtree: true,
                    });
                    // Listen for streaming updates
                    this.$refs.source.addEventListener('input', () => this.render());
                    window.addEventListener('marked:ready', () => this.render());
                },
                render() {
                    if (!window.marked || !this.$refs.source || !this.$refs.target) return;
                    const raw = this.$refs.source.textContent.trim();
                    try {
                        // Step 1: Parse markdown to HTML (creates structure)
                        const html = window.marked.parse(raw);
                        if (html !== this.renderedHtml) {
                            // Step 2: Update DOM with parsed HTML
                            this.$refs.target.innerHTML = html;
                            this.renderedHtml = html;
                            // Step 3: Apply Prism.js highlighting - simple approach
                            if (window.Prism) {
                                // Simple direct highlighting without complex timing
                                window.Prism.highlightAllUnder(this.$refs.target);
                                // Fallback: also try highlighting individual elements
                                const codeElements = this.$refs.target.querySelectorAll('pre code[class*="language-"]');
                                codeElements.forEach(codeElement => {
                                    if (!codeElement.classList.contains('highlighted')) {
                                        window.Prism.highlightElement(codeElement);
                                    }
                                });
                            }
                            window.dispatchEvent(new CustomEvent('markdown-update'));
                        }
                    } catch (error) {
                        console.error('Markdown parsing error:', error);
                        // Fallback to showing raw content if parsing fails
                        this.$refs.target.innerHTML = `<pre>${raw}</pre>`;
                    }
                },
                destroy() {
                    this.observer && this.observer.disconnect();
                }
            };
        }

        document.addEventListener('livewire:init', () => {
            Livewire.on('copy-to-clipboard', (event) => {
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(event.text).then(() => {
                        // Could show a toast notification here
                        console.log('Copied to clipboard:', event.text);
                    });
                } else {
                    // Fallback for older browsers
                    const textArea = document.createElement('textarea');
                    textArea.value = event.text;
                    document.body.appendChild(textArea);
                    textArea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textArea);
                    console.log('Copied to clipboard (fallback):', event.text);
                }
            });
        });
    </script>
</div>