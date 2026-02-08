{{--
    Sources Tab Content Component

    Purpose: Displays discovered knowledge sources for agent executions and interactions

    Display Modes:
    - Timeline Item: Shows sources within interaction timeline (with question/timestamp header)
    - Standalone: Shows sources independently (original single interaction format)

    Source Types:
    - Web Sources: External URLs with favicon, domain, title
    - Knowledge Sources: Internal knowledge documents (opens in modal preview)

    Features:
    - Dynamic favicon loading with fallback generation
    - Clickable sources (new tab for web, modal for knowledge)
    - Discovery tool tracking
    - Timestamp display
    - Knowledge document preview modal

    Component Props:
    - @props int|null $executionId Agent execution ID (optional)
    - @props int|null $interactionId Chat interaction ID (optional)
    - @props bool $showAsTimelineItem Whether to display in timeline format
    - @props string $interactionQuestion Question text for timeline header
    - @props string $interactionTimestamp Formatted timestamp for timeline header

    Livewire Properties:
    - @property array $timeline Collected sources with metadata
    - @property bool $showPreviewModal Whether preview modal is open
    - @property int|null $previewDocumentId Document ID for preview

    Favicon Strategy:
    1. Try document's stored favicon URL
    2. Fallback to Google's favicon service
    3. Generate letter-based fallback with domain-based color
--}}
<div>
@if($showAsTimelineItem)
    {{-- Timeline item format for multiple interactions within chat history --}}
    <div class="relative">
        {{-- Timeline visual indicator dot --}}
        <div class="absolute left-4 w-4 h-4 bg-surface border-2 border-default rounded-full flex items-center justify-center">
            <div class="w-2 h-2 bg-accent rounded-full"></div>
        </div>

        <!-- Content -->
        <div class="ml-12 pb-8">
            <!-- Interaction Header - Query + Timestamp on one line -->
            <div class="flex items-center justify-between mb-3">
                <div class="font-medium text-sm text-primary flex-1 pr-4">
                    {{ $interactionQuestion ? Str::limit($interactionQuestion, 60) : 'Research Query' }}
                </div>
                <div class="text-xs text-tertiary flex-shrink-0">
                    {{ $interactionTimestamp }}
                </div>
            </div>

            @if(count($timeline) > 0)
                <!-- Sources for this interaction -->
                <div class="space-y-1">
                    <div class="text-xs text-gray-500 mb-2">Sources ({{ count($timeline) }} total)</div>
                    @foreach($timeline as $source)
                        <div class="flex items-center gap-3 py-1 px-2 text-sm hover:bg-surface rounded">
                            <!-- Favicon -->
                            <div class="flex-shrink-0 relative favicon-container">
                                @php
                                    $domain = $source['domain'] ?? parse_url($source['url'], PHP_URL_HOST);
                                    $faviconId = 'favicon-' . md5($source['url']);
                                @endphp
                                
                                @if(!empty($source['favicon']))
                                    <img id="{{ $faviconId }}" src="{{ $source['favicon'] }}" alt="Favicon" class="w-4 h-4 rounded favicon-img" 
                                         onerror="this.onerror=null; this.src='https://www.google.com/s2/favicons?domain={{ urlencode($domain) }}&sz=16'; this.onerror=function(){document.getElementById('{{ $faviconId }}').style.display='none'; document.getElementById('{{ $faviconId }}-fallback').style.display='flex'};">
                                @else
                                    <!-- Try Google's favicon service first -->
                                    <img id="{{ $faviconId }}" src="https://www.google.com/s2/favicons?domain={{ urlencode($domain) }}&sz=16" alt="Favicon" class="w-4 h-4 rounded favicon-img" 
                                         onerror="document.getElementById('{{ $faviconId }}').style.display='none'; document.getElementById('{{ $faviconId }}-fallback').style.display='flex';">
                                @endif
                                
                                <!-- Fallback favicon -->
                                @php
                                    // Generate domain-based favicon character and color
                                    $cleanDomain = $domain;
                                    if (str_starts_with($domain, 'www.')) {
                                        $cleanDomain = substr($domain, 4);
                                    }
                                    $domainChar = $cleanDomain ? strtoupper(substr($cleanDomain, 0, 1)) : '?';
                                    
                                    // Generate color based on domain hash for consistency
                                    $colorHash = crc32($cleanDomain ?: 'unknown') % 6;
                                    $colorClasses = [
                                        'bg-gradient-to-br from-blue-100 to-blue-200 dark:from-blue-800 dark:to-blue-900 text-accent',
                                        'bg-gradient-to-br from-[var(--palette-success-100)] to-[var(--palette-success-200)] dark:from-[var(--palette-success-800)] dark:to-[var(--palette-success-900)] text-[var(--palette-success-700)] dark:text-[var(--palette-success-300)]',
                                        'bg-gradient-to-br from-purple-100 to-purple-200 dark:from-purple-800 dark:to-purple-900 text-purple-700 dark:text-purple-300',
                                        'bg-gradient-to-br from-orange-100 to-orange-200 dark:from-orange-800 dark:to-orange-900 text-orange-700 dark:text-orange-300',
                                        'bg-gradient-to-br from-pink-100 to-pink-200 dark:from-pink-800 dark:to-pink-900 text-pink-700 dark:text-pink-300',
                                        'bg-accent/10 text-accent',
                                    ];
                                    $selectedColors = $colorClasses[$colorHash];
                                @endphp
                                
                                <div id="{{ $faviconId }}-fallback" class="w-4 h-4 {{ $selectedColors }} rounded flex items-center justify-center border border-white/20 shadow-sm favicon-fallback hidden"
                                     title="Favicon for {{ $domain }}">
                                    <span class="text-xs font-bold leading-none">{{ $domainChar }}</span>
                                </div>
                            </div>
                            
                            <!-- Source Title/Domain (Clickable) -->
                            @if(isset($source['type']) && $source['type'] === 'knowledge_source')
                                {{-- Knowledge source - open in modal --}}
                                <button wire:click="openPreviewModal({{ $source['document']->id }})"
                                   class="font-medium text-primary hover:text-accent-hover min-w-0 flex-shrink-0 transition-colors">
                                    {{ $source['domain'] ?? 'knowledge' }}
                                </button>
                            @elseif(isset($source['type']) && $source['type'] === 'attachment')
                                {{-- Attachment - open file URL --}}
                                <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer"
                                   class="font-medium text-primary hover:text-accent-hover min-w-0 flex-shrink-0 transition-colors">
                                    {{ $source['domain'] }}
                                </a>
                            @else
                                {{-- Web source - open in new tab --}}
                                <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer"
                                   class="font-medium text-primary hover:text-accent-hover min-w-0 flex-shrink-0 transition-colors">
                                    {{ $source['domain'] ?? parse_url($source['url'], PHP_URL_HOST) }}
                                </a>
                            @endif

                            <!-- Source Title/Description (Clickable) -->
                            @if(isset($source['type']) && $source['type'] === 'knowledge_source')
                                {{-- Knowledge source - open in modal --}}
                                <button wire:click="openPreviewModal({{ $source['document']->id }})"
                                   class="text-tertiary  hover:text-secondary truncate flex-1 min-w-0 transition-colors text-left">
                                    {{ $source['title'] ?: ($source['description'] ? Str::limit($source['description'], 60) : 'Untitled Source') }}
                                </button>
                            @elseif(isset($source['type']) && $source['type'] === 'attachment')
                                {{-- Attachment - open file URL --}}
                                <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer"
                                   class="text-tertiary  hover:text-secondary truncate flex-1 min-w-0 transition-colors">
                                    {{ $source['title'] }}
                                    @if($source['description'])
                                        <span class="text-xs"> - {{ Str::limit($source['description'], 50) }}</span>
                                    @endif
                                </a>
                            @else
                                {{-- Web source - open in new tab --}}
                                <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer"
                                   class="text-tertiary  hover:text-secondary truncate flex-1 min-w-0 transition-colors">
                                    {{ $source['title'] ?: ($source['description'] ? Str::limit($source['description'], 60) : 'Untitled Source') }}
                                </a>
                            @endif
                            
                            
                            <!-- Discovery Tool -->
                            @if(isset($source['discovery_tool']))
                                <span class="text-xs px-2 py-0.5 bg-surface text-secondary   rounded flex-shrink-0">
                                    {{ $source['discovery_tool'] }}
                                </span>
                            @endif
                            
                            <!-- Timestamp -->
                            @if(isset($source['timestamp']))
                                <span class="text-xs text-tertiary flex-shrink-0">
                                    {{ \Carbon\Carbon::parse($source['timestamp'])->format('H:i') }}
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-xs text-gray-500 italic">No sources found for this interaction</div>
            @endif
        </div>
    </div>
@else
    <!-- Original single interaction format -->
    <div class="relative p-4">
        <!-- Timeline line -->
        <div class="absolute left-10 top-4 bottom-4 w-px bg-border-default"></div>

        @if(count($timeline) > 0)
            @php
                // Group sources by interaction for timeline display
                $groupedSources = [];
                foreach ($timeline as $source) {
                    $interactionId = $interactionId ?? 'current';
                    if (!isset($groupedSources[$interactionId])) {
                        $groupedSources[$interactionId] = [];
                    }
                    $groupedSources[$interactionId][] = $source;
                }
            @endphp
            
            @foreach($groupedSources as $groupInteractionId => $sources)
                <div class="relative">
                    <!-- Timeline dot -->
                    <div class="absolute left-4 w-4 h-4 bg-surface border-2 border-default rounded-full flex items-center justify-center">
                        <div class="w-2 h-2 bg-accent rounded-full"></div>
                    </div>

                    <!-- Content -->
                    <div class="ml-12 pb-8">
                        <!-- Interaction Header -->
                        <div class="flex items-center justify-between mb-3">
                            <div class="font-medium text-sm text-primary flex-1 pr-4">
                                {{ $interactionQuery ? Str::limit($interactionQuery, 80) : 'Research Query' }}
                            </div>
                            <div class="text-xs text-tertiary flex-shrink-0">
                                {{ count($sources) }} {{ Str::plural('source', count($sources)) }}
                            </div>
                        </div>

                    <!-- Sources for this interaction -->
                    <div class="space-y-1">
                        @foreach($sources as $source)
                            <div class="flex items-center gap-3 py-1 px-2 text-sm hover:bg-surface rounded">
                                <!-- Favicon -->
                                <div class="flex-shrink-0 relative favicon-container">
                                    @php
                                        $domain = $source['domain'] ?? parse_url($source['url'], PHP_URL_HOST);
                                        $faviconId = 'favicon-' . md5($source['url']);
                                    @endphp
                                    
                                    @if(!empty($source['favicon']))
                                        <img id="{{ $faviconId }}" src="{{ $source['favicon'] }}" alt="Favicon" class="w-4 h-4 rounded favicon-img" 
                                             onerror="this.onerror=null; this.src='https://www.google.com/s2/favicons?domain={{ urlencode($domain) }}&sz=16'; this.onerror=function(){document.getElementById('{{ $faviconId }}').style.display='none'; document.getElementById('{{ $faviconId }}-fallback').style.display='flex'};">
                                    @else
                                        <!-- Try Google's favicon service first -->
                                        <img id="{{ $faviconId }}" src="https://www.google.com/s2/favicons?domain={{ urlencode($domain) }}&sz=16" alt="Favicon" class="w-4 h-4 rounded favicon-img" 
                                             onerror="document.getElementById('{{ $faviconId }}').style.display='none'; document.getElementById('{{ $faviconId }}-fallback').style.display='flex';">
                                    @endif
                                    
                                    <!-- Fallback favicon -->
                                    @php
                                        // Generate domain-based favicon character and color
                                        $cleanDomain = $domain;
                                        if (str_starts_with($domain, 'www.')) {
                                            $cleanDomain = substr($domain, 4);
                                        }
                                        $domainChar = $cleanDomain ? strtoupper(substr($cleanDomain, 0, 1)) : '?';
                                        
                                        // Generate color based on domain hash for consistency
                                        $colorHash = crc32($cleanDomain ?: 'unknown') % 6;
                                        $colorClasses = [
                                            'bg-gradient-to-br from-blue-100 to-blue-200 dark:from-blue-800 dark:to-blue-900 text-accent',
                                            'bg-gradient-to-br from-[var(--palette-success-100)] to-[var(--palette-success-200)] dark:from-[var(--palette-success-800)] dark:to-[var(--palette-success-900)] text-[var(--palette-success-700)] dark:text-[var(--palette-success-300)]',
                                            'bg-gradient-to-br from-purple-100 to-purple-200 dark:from-purple-800 dark:to-purple-900 text-purple-700 dark:text-purple-300',
                                            'bg-gradient-to-br from-orange-100 to-orange-200 dark:from-orange-800 dark:to-orange-900 text-orange-700 dark:text-orange-300',
                                            'bg-gradient-to-br from-pink-100 to-pink-200 dark:from-pink-800 dark:to-pink-900 text-pink-700 dark:text-pink-300',
                                            'bg-accent/10 text-accent',
                                        ];
                                        $selectedColors = $colorClasses[$colorHash];
                                    @endphp
                                    
                                    <div id="{{ $faviconId }}-fallback" class="w-4 h-4 {{ $selectedColors }} rounded flex items-center justify-center border border-white/20 shadow-sm favicon-fallback hidden"
                                         title="Favicon for {{ $domain }}">
                                        <span class="text-xs font-bold leading-none">{{ $domainChar }}</span>
                                    </div>
                                </div>
                                
                                <!-- Source Title/Domain (Clickable) -->
                                @if(isset($source['type']) && $source['type'] === 'knowledge_source')
                                    {{-- Knowledge source - open in modal --}}
                                    <button wire:click="openPreviewModal({{ $source['document']->id }})"
                                       class="font-medium text-primary hover:text-accent-hover min-w-0 flex-shrink-0 transition-colors">
                                        {{ $source['domain'] ?? 'knowledge' }}
                                    </button>
                                @elseif(isset($source['type']) && $source['type'] === 'attachment')
                                    {{-- Attachment - open file URL --}}
                                    <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer"
                                       class="font-medium text-primary hover:text-accent-hover min-w-0 flex-shrink-0 transition-colors">
                                        {{ $source['domain'] }}
                                    </a>
                                @else
                                    {{-- Web source - open in new tab --}}
                                    <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer"
                                       class="font-medium text-primary hover:text-accent-hover min-w-0 flex-shrink-0 transition-colors">
                                        {{ $source['domain'] ?? parse_url($source['url'], PHP_URL_HOST) }}
                                    </a>
                                @endif

                                <!-- Source Title/Description (Clickable) -->
                                @if(isset($source['type']) && $source['type'] === 'knowledge_source')
                                    {{-- Knowledge source - open in modal --}}
                                    <button wire:click="openPreviewModal({{ $source['document']->id }})"
                                       class="text-tertiary  hover:text-secondary truncate flex-1 min-w-0 transition-colors text-left">
                                        {{ $source['title'] ?: ($source['description'] ? Str::limit($source['description'], 60) : 'Untitled Source') }}
                                    </button>
                                @elseif(isset($source['type']) && $source['type'] === 'attachment')
                                    {{-- Attachment - open file URL --}}
                                    <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer"
                                       class="text-tertiary  hover:text-secondary truncate flex-1 min-w-0 transition-colors">
                                        {{ $source['title'] }}
                                        @if($source['description'])
                                            <span class="text-xs"> - {{ Str::limit($source['description'], 50) }}</span>
                                        @endif
                                    </a>
                                @else
                                    {{-- Web source - open in new tab --}}
                                    <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer"
                                       class="text-tertiary  hover:text-secondary truncate flex-1 min-w-0 transition-colors">
                                        {{ $source['title'] ?: ($source['description'] ? Str::limit($source['description'], 60) : 'Untitled Source') }}
                                    </a>
                                @endif


                                <!-- Discovery Tool -->
                                @if(isset($source['discovery_tool']))
                                    <span class="text-xs px-2 py-0.5 bg-surface text-secondary   rounded flex-shrink-0">
                                        {{ $source['discovery_tool'] }}
                                    </span>
                                @endif
                                
                                <!-- Timestamp -->
                                @if(isset($source['timestamp']))
                                    <span class="text-xs text-tertiary flex-shrink-0">
                                        {{ \Carbon\Carbon::parse($source['timestamp'])->format('H:i') }}
                                    </span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    @else
        <div class="ml-12 text-center text-tertiary p-8">
            <svg class="w-12 h-12 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path>
            </svg>
            <h3 class="text-lg font-medium text-primary  mb-2">No Sources Found</h3>
            <div class="text-gray-500 dark:text-gray-400 space-y-2">
                <p>No sources have been discovered for this {{ $interactionId ? 'interaction' : 'execution' }}.</p>
                @if($executionId)
                    <p class="text-sm">
                        <strong>Execution ID:</strong> {{ $executionId }}
                        @if($interactionId)
                            | <strong>Interaction ID:</strong> {{ $interactionId }}
                        @endif
                    </p>
                    <p class="text-sm text-gray-400">
                        This may occur if the research didn't involve web sources, or if this session was created before source tracking was implemented.
                    </p>
                @endif
            </div>
        </div>
    @endif
@endif

    <!-- Inline styles for favicon handling -->
    <style>
        /* Favicon loading transitions */
        .favicon-img {
            transition: opacity 0.2s ease-in-out;
        }

        .favicon-fallback {
            transition: opacity 0.2s ease-in-out;
        }

        /* Improve favicon visibility on different backgrounds */
        .favicon-container {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(1px);
        }
    </style>

    <!-- Knowledge Source Preview Modal (80% screen size) -->
    @if($showPreviewModal && $previewDocumentId)
        <flux:modal wire:model="showPreviewModal" class="w-[80vw] h-[80vh] max-w-none">
            <div class="h-full overflow-hidden">
                <livewire:markdown-viewer :documentId="$previewDocumentId" wire:key="source-preview-{{ $previewDocumentId }}" />
            </div>
        </flux:modal>
    @endif
</div>