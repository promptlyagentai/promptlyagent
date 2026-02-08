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
                 class="bg-surface rounded-xl shadow-2xl w-full max-w-6xl max-h-[90vh] overflow-hidden">

                <!-- Header -->
                <div class="flex items-center justify-between p-6 border-b border-default">
                    <div class="flex items-center gap-3 flex-1 min-w-0">
                        @if($interaction)
                            <div class="flex-1 min-w-0">
                                <h2 class="text-xl font-semibold text-primary">
                                    Chat Interaction Details
                                </h2>
                                <p class="text-secondary text-sm truncate">
                                    {{ Str::limit($interaction->question, 80) }} • {{ $interaction->created_at->format('Y-m-d H:i:s T') }}
                                </p>
                            </div>
                        @else
                            <h2 class="text-xl font-semibold text-primary">
                                Interaction Details
                            </h2>
                        @endif
                    </div>

                    <!-- Close Button -->
                    <button wire:click="closeModal"
                            class="p-2 text-secondary hover:text-primary rounded-lg hover:bg-surface-elevated flex-shrink-0">
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
                    @elseif($interaction)
                        <div class="space-y-6">
                            <!-- Basic Information -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="bg-surface rounded-lg p-4">
                                    <div class="text-sm text-secondary">Interaction ID</div>
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono text-primary">{{ $interaction->id }}</span>
                                        <button wire:click="copyInteractionId"
                                                class="text-secondary hover:text-primary">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <div class="bg-surface rounded-lg p-4">
                                    <div class="text-sm text-secondary">Session</div>
                                    <div class="text-primary">
                                        {{ $interaction->session->title ?? 'Untitled Session' }}
                                    </div>
                                </div>

                                <div class="bg-surface rounded-lg p-4">
                                    <div class="text-sm text-secondary">User</div>
                                    <div class="text-primary">
                                        {{ $interaction->user->name ?? 'Unknown User' }}
                                    </div>
                                </div>
                            </div>

                            <!-- Question -->
                            <div>
                                <div class="flex items-center justify-between mb-3">
                                    <h3 class="text-lg font-medium text-primary">Question</h3>
                                    <button wire:click="copyInteractionQuestion"
                                            class="text-secondary hover:text-primary p-1 rounded"
                                            title="Copy question to clipboard">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                        </svg>
                                    </button>
                                </div>
                                <div class="bg-surface border border-default rounded-lg p-4">
                                    <p class="text-primary whitespace-pre-wrap">{{ $interaction->question }}</p>
                                </div>
                            </div>

                            <!-- Answer -->
                            @if($interaction->answer)
                                <div>
                                    <div class="flex items-center justify-between mb-3">
                                        <h3 class="text-lg font-medium text-primary">Answer</h3>
                                        <button wire:click="copyInteractionAnswer"
                                                class="text-secondary hover:text-primary p-1 rounded"
                                                title="Copy raw markdown to clipboard">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                            </svg>
                                        </button>
                                    </div>

                                    <!-- Tabbed view for markdown vs rendered -->
                                    <div x-data="{ activeTab: 'rendered' }" class="bg-surface border border-default rounded-lg">
                                        <!-- Tab Navigation -->
                                        <div class="flex border-b border-default">
                                            <button @click="activeTab = 'rendered'"
                                                    :class="activeTab === 'rendered' ? 'bg-surface text-primary' : 'text-secondary hover:bg-surface '"
                                                    class="px-4 py-2 text-sm font-medium rounded-tl-lg">
                                                Rendered
                                            </button>
                                            <button @click="activeTab = 'markdown'"
                                                    :class="activeTab === 'markdown' ? 'bg-surface text-primary' : 'text-secondary hover:bg-surface '"
                                                    class="px-4 py-2 text-sm font-medium">
                                                Raw Markdown
                                            </button>
                                        </div>

                                        <!-- Tab Content -->
                                        <div class="p-4">
                                            <!-- Rendered View -->
                                            <div x-show="activeTab === 'rendered'" class="prose dark:prose-invert max-w-none">
                                                <div x-data="markdownRenderer()" class="text-primary">
                                                    <span x-ref="source" class="hidden">{{ $interaction->answer }}</span>
                                                    <div x-ref="target" class="markdown" x-html="renderedHtml"></div>
                                                </div>
                                            </div>

                                            <!-- Raw Markdown View -->
                                            <div x-show="activeTab === 'markdown'" style="display: none;">
                                                <pre class="text-sm overflow-x-auto whitespace-pre-wrap text-primary font-mono">{{ $interaction->answer }}</pre>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif

                            <!-- Summary -->
                            @if($interaction->summary)
                                @php
                                    $summaryData = json_decode($interaction->summary, true);
                                    $isJsonSummary = json_last_error() === JSON_ERROR_NONE && is_array($summaryData);
                                @endphp
                                <div>
                                    <div class="flex items-center justify-between mb-3">
                                        <h3 class="text-lg font-medium text-primary">Summary</h3>
                                        <button wire:click="copyInteractionSummary"
                                                class="text-secondary hover:text-primary p-1 rounded"
                                                title="Copy summary to clipboard">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                            </svg>
                                        </button>
                                    </div>

                                    @if($isJsonSummary)
                                        <!-- Structured JSON Summary View -->
                                        <div x-data="{ activeTab: 'structured' }" class="bg-surface border border-default rounded-lg">
                                            <!-- Tab Navigation -->
                                            <div class="flex border-b border-default">
                                                <button @click="activeTab = 'structured'"
                                                        :class="activeTab === 'structured' ? 'bg-surface text-primary' : 'text-secondary hover:bg-surface '"
                                                        class="px-4 py-2 text-sm font-medium rounded-tl-lg">
                                                    Structured View
                                                </button>
                                                @if(!empty($summaryData['full_conversation']))
                                                    <button @click="activeTab = 'conversation'"
                                                            :class="activeTab === 'conversation' ? 'bg-surface text-primary' : 'text-secondary hover:bg-surface '"
                                                            class="px-4 py-2 text-sm font-medium">
                                                        Full Conversation
                                                    </button>
                                                @endif
                                                <button @click="activeTab = 'raw'"
                                                        :class="activeTab === 'raw' ? 'bg-surface text-primary' : 'text-secondary hover:bg-surface '"
                                                        class="px-4 py-2 text-sm font-medium">
                                                    Raw JSON
                                                </button>
                                            </div>

                                            <!-- Tab Content -->
                                            <div class="p-4">
                                                <!-- Structured View -->
                                                <div x-show="activeTab === 'structured'" class="space-y-4">
                                                    @if(!empty($summaryData['context_summary']))
                                                        <div class="bg-surface rounded-lg p-3 border border-default">
                                                            <h4 class="text-sm font-medium text-secondary mb-2">Overview</h4>
                                                            <p class="text-primary text-sm">{{ $summaryData['context_summary'] }}</p>
                                                        </div>
                                                    @endif

                                                    @if(!empty($summaryData['topics']))
                                                        <div class="bg-surface rounded-lg p-3 border border-default">
                                                            <h4 class="text-sm font-medium text-secondary mb-2">Topics Discussed</h4>
                                                            <ul class="list-disc list-inside space-y-1 text-sm text-primary">
                                                                @foreach($summaryData['topics'] as $topic)
                                                                    <li>{{ $topic }}</li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif

                                                    @if(!empty($summaryData['key_findings']))
                                                        <div class="bg-surface rounded-lg p-3 border border-default">
                                                            <h4 class="text-sm font-medium text-secondary mb-2">Key Findings</h4>
                                                            <ul class="list-disc list-inside space-y-1 text-sm text-primary">
                                                                @foreach($summaryData['key_findings'] as $finding)
                                                                    <li>{{ $finding }}</li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif

                                                    @if(!empty($summaryData['decisions']))
                                                        <div class="bg-surface rounded-lg p-3 border border-default">
                                                            <h4 class="text-sm font-medium text-secondary mb-2">Decisions Made</h4>
                                                            <ul class="list-disc list-inside space-y-1 text-sm text-primary">
                                                                @foreach($summaryData['decisions'] as $decision)
                                                                    <li>{{ $decision }}</li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif

                                                    @if(!empty($summaryData['action_items']))
                                                        <div class="bg-surface rounded-lg p-3 border border-default">
                                                            <h4 class="text-sm font-medium text-secondary mb-2">Action Items</h4>
                                                            <ul class="list-disc list-inside space-y-1 text-sm text-primary">
                                                                @foreach($summaryData['action_items'] as $item)
                                                                    <li>{{ $item }}</li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif

                                                    @if(!empty($summaryData['outstanding_issues']))
                                                        <div class="bg-surface rounded-lg p-3 border border-default">
                                                            <h4 class="text-sm font-medium text-secondary mb-2">Outstanding Issues</h4>
                                                            <ul class="list-disc list-inside space-y-1 text-sm text-primary">
                                                                @foreach($summaryData['outstanding_issues'] as $issue)
                                                                    <li>{{ $issue }}</li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif

                                                    @if(!empty($summaryData['key_sources']))
                                                        <div class="bg-surface rounded-lg p-3 border border-default">
                                                            <h4 class="text-sm font-medium text-secondary mb-2">Key Sources</h4>
                                                            <ul class="space-y-2">
                                                                @foreach($summaryData['key_sources'] as $source)
                                                                    <li class="text-sm">
                                                                        @if(isset($source['url']) && !empty($source['url']))
                                                                            <a href="{{ $source['url'] }}" target="_blank" class="text-accent hover:underline">
                                                                                {{ $source['title'] ?? $source['url'] }}
                                                                            </a>
                                                                        @else
                                                                            <span class="text-primary">{{ $source['title'] ?? 'Unknown Source' }}</span>
                                                                        @endif
                                                                    </li>
                                                                @endforeach
                                                            </ul>
                                                        </div>
                                                    @endif

                                                    @if(isset($summaryData['generated_method']))
                                                        <div class="text-xs text-tertiary mt-2">
                                                            Summary method: {{ ucfirst(str_replace('_', ' ', $summaryData['generated_method'])) }}
                                                        </div>
                                                    @endif
                                                </div>

                                                @if(!empty($summaryData['full_conversation']))
                                                    <!-- Full Conversation View -->
                                                    <div x-show="activeTab === 'conversation'" style="display: none;" class="prose dark:prose-invert max-w-none">
                                                        <div x-data="markdownRenderer()" class="text-primary">
                                                            <span x-ref="source" class="hidden">{{ $summaryData['full_conversation'] }}</span>
                                                            <div x-ref="target" class="markdown" x-html="renderedHtml"></div>
                                                        </div>
                                                    </div>
                                                @endif

                                                <!-- Raw JSON View -->
                                                <div x-show="activeTab === 'raw'" style="display: none;">
                                                    <pre class="text-sm overflow-x-auto whitespace-pre-wrap text-primary font-mono">{{ json_encode($summaryData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        <!-- Legacy Text/Markdown Summary View -->
                                        <div x-data="{ activeTab: 'rendered' }" class="bg-surface border border-default rounded-lg">
                                            <!-- Tab Navigation -->
                                            <div class="flex border-b border-default">
                                                <button @click="activeTab = 'rendered'"
                                                        :class="activeTab === 'rendered' ? 'bg-surface text-primary' : 'text-secondary hover:bg-surface '"
                                                        class="px-4 py-2 text-sm font-medium rounded-tl-lg">
                                                    Rendered
                                                </button>
                                                <button @click="activeTab = 'markdown'"
                                                        :class="activeTab === 'markdown' ? 'bg-surface text-primary' : 'text-secondary hover:bg-surface '"
                                                        class="px-4 py-2 text-sm font-medium">
                                                    Raw Text
                                                </button>
                                            </div>

                                            <!-- Tab Content -->
                                            <div class="p-4">
                                                <!-- Rendered View -->
                                                <div x-show="activeTab === 'rendered'" class="prose dark:prose-invert max-w-none">
                                                    <div x-data="markdownRenderer()" class="text-primary">
                                                        <span x-ref="source" class="hidden">{{ $interaction->summary }}</span>
                                                        <div x-ref="target" class="markdown" x-html="renderedHtml"></div>
                                                    </div>
                                                </div>

                                                <!-- Raw Text View -->
                                                <div x-show="activeTab === 'markdown'" style="display: none;">
                                                    <pre class="text-sm overflow-x-auto whitespace-pre-wrap text-primary font-mono">{{ $interaction->summary }}</pre>
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endif


                            <!-- Execution Steps -->
                            @if(!empty($executionSteps))
                                <div x-data="{ expanded: false }">
                                    <div class="flex items-center justify-between mb-3">
                                        <h3 class="text-lg font-medium text-primary">
                                            Agent Executions ({{ count($executionSteps) }})
                                        </h3>
                                        <button @click="expanded = !expanded"
                                                class="text-secondary hover:text-primary text-sm">
                                            <span x-text="expanded ? 'Collapse' : 'Expand'"></span>
                                        </button>
                                    </div>
                                    <div x-show="expanded" x-transition class="space-y-3">
                                        @foreach($executionSteps as $execution)
                                            <div x-data="{ executionExpanded: false }" class="bg-surface border border-default rounded-lg overflow-hidden">
                                                <!-- Execution Header -->
                                                <div class="flex items-center justify-between p-4">
                                                    <!-- Left: Agent info -->
                                                    <div class="flex items-center gap-3 flex-1 min-w-0">
                                                        <button @click="executionExpanded = !executionExpanded" class="flex-shrink-0 text-secondary hover:text-primary">
                                                            <svg class="w-5 h-5 transition-transform duration-200"
                                                                 :class="executionExpanded ? 'rotate-90' : ''"
                                                                 fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                                            </svg>
                                                        </button>
                                                        <span class="text-xl flex-shrink-0">{{ $this->getExecutionStatusIcon($execution['status']) }}</span>
                                                        <div class="flex-1 min-w-0">
                                                            <div class="font-medium text-primary truncate">
                                                                {{ $execution['agent_name'] }}
                                                                @if($execution['duration'])
                                                                    <span class="text-sm text-tertiary font-normal">({{ $execution['duration'] }})</span>
                                                                @endif
                                                            </div>
                                                            <div class="flex items-center gap-2 text-xs flex-wrap">
                                                                <span class="px-2 py-0.5 text-xs font-medium rounded {{ $this->getExecutionStatusBadgeClass($execution['status']) }}">
                                                                    {{ ucfirst($execution['status']) }}
                                                                </span>
                                                                <span class="text-secondary">
                                                                    {{ ($execution['started_at'] ?? $execution['created_at'])->format('H:i:s') }}
                                                                </span>
                                                                <span class="text-tertiary ">
                                                                    {{ count($execution['steps']) }} steps
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <!-- Right: Actions -->
                                                    <div class="flex items-center gap-2 ml-4 flex-shrink-0">
                                                        @if($execution['error_message'])
                                                            <button wire:click="retryExecution({{ $execution['id'] }})"
                                                                    class="px-2 py-1 text-xs bg-[var(--palette-error-100)] text-[var(--palette-error-800)] rounded hover:bg-[var(--palette-error-200)]"
                                                                    title="Retry execution">
                                                                🔄
                                                            </button>
                                                        @endif
                                                        @if($execution['status_stream_id'])
                                                            <button wire:click="$dispatch('openStepModal', { stepId: {{ $execution['status_stream_id'] }} })"
                                                                    class="px-2 py-1 text-xs bg-surface border border-default text-accent rounded hover:bg-accent/20"
                                                                    title="View full execution details">
                                                                Details
                                                            </button>
                                                        @endif
                                                    </div>
                                                </div>

                                                <!-- Timeline Steps (Collapsible) -->
                                                <div x-show="executionExpanded" x-transition class="border-t border-default bg-surface px-4 py-3">
                                                    @if($execution['error_message'])
                                                        <div class="mb-3 text-xs text-[var(--palette-error-800)] bg-[var(--palette-error-100)] border border-[var(--palette-error-200)] rounded px-3 py-2">
                                                            <span class="font-medium">Error:</span> {{ Str::limit($execution['error_message'], 200) }}
                                                        </div>
                                                    @endif

                                                    @if(!empty($execution['steps']))
                                                        <div class="space-y-1.5">
                                                            @foreach($execution['steps'] as $step)
                                                                <div class="flex items-start gap-2 text-sm">
                                                                    <span class="text-base flex-shrink-0 mt-0.5">{{ $this->getStepTypeIcon($step['step_type']) }}</span>
                                                                    <span class="text-tertiary flex-shrink-0 font-mono text-xs mt-0.5">
                                                                        {{ $step['timestamp']->format('H:i:s') }}
                                                                    </span>
                                                                    <span class="text-primary flex-1">
                                                                        {{ $step['message'] }}
                                                                    </span>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @else
                                                        <div class="text-sm text-tertiary italic">No timeline steps recorded</div>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <!-- Attachments -->
                            @if($interaction->attachments && $interaction->attachments->count() > 0)
                                <div>
                                    <h3 class="text-lg font-medium text-primary mb-3">Attachments</h3>
                                    <div class="space-y-2">
                                        @foreach($interaction->attachments as $attachment)
                                            <div class="flex items-center justify-between bg-surface rounded-lg p-3">
                                                <div class="flex items-center gap-3">
                                                    <!-- File Type Icon -->
                                                    @if($attachment->type === 'image')
                                                        <svg class="w-5 h-5 text-accent" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                        </svg>
                                                    @else
                                                        <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                                        </svg>
                                                    @endif

                                                    <div>
                                                        <div class="font-medium text-primary">{{ $attachment->filename }}</div>
                                                        <div class="text-sm text-secondary">
                                                            {{ number_format($attachment->file_size / 1024, 1) }}KB • {{ ucfirst($attachment->type) }}
                                                        </div>
                                                    </div>
                                                </div>

                                                <a href="{{ route('chat.attachment.download', $attachment->id) }}"
                                                   class="px-3 py-1 text-sm bg-surface border border-default text-accent rounded hover:bg-accent/20">
                                                    Download
                                                </a>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <!-- Sources -->
                            @if(!empty($sources))
                                <div x-data="{ expanded: false }">
                                    <div class="flex items-center justify-between mb-3">
                                        <h3 class="text-lg font-medium text-primary">Sources ({{ count($sources) }})</h3>
                                        <button @click="expanded = !expanded"
                                                class="text-secondary hover:text-primary text-sm">
                                            <span x-text="expanded ? 'Collapse' : 'Expand'"></span>
                                        </button>
                                    </div>
                                    <div x-show="expanded" x-transition class="space-y-3">
                                        @foreach($sources as $source)
                                            <div class="bg-surface rounded-lg p-4">
                                                <div class="flex items-start justify-between">
                                                    <div class="flex-1 min-w-0">
                                                        <div class="flex items-center gap-2 mb-2">
                                                            <h4 class="font-medium text-primary truncate">
                                                                {{ $source->title ?? 'Untitled' }}
                                                            </h4>
                                                            <span class="px-2 py-0.5 text-xs font-medium rounded {{ ($source->type ?? 'unknown') === 'web' ? 'bg-surface border border-default text-accent' : 'bg-[var(--palette-success-200)] text-[var(--palette-success-900)]' }}">
                                                                {{ ucfirst($source->type ?? 'unknown') }}
                                                            </span>
                                                        </div>

                                                        <div class="text-sm text-secondary mb-2">
                                                            <a href="{{ $source->url ?? '#' }}" target="_blank" class="hover:underline break-all">
                                                                {{ $source->url ?? 'No URL' }}
                                                            </a>
                                                        </div>

                                                        <div class="flex items-center gap-4 text-xs text-tertiary">
                                                            @if(isset($source->discovery_method))
                                                                <span>Method: {{ $source->discovery_method }}</span>
                                                            @endif
                                                            @if(isset($source->discovery_tool))
                                                                <span>Tool: {{ $source->discovery_tool }}</span>
                                                            @endif
                                                        </div>

                                                        @if(isset($source->content_excerpt) && $source->content_excerpt)
                                                            <div class="mt-2 p-2 bg-surface rounded text-sm">
                                                                <div class="text-secondary">{{ Str::limit($source->content_excerpt, 200) }}</div>
                                                            </div>
                                                        @endif

                                                        @if(isset($source->tags) && !empty($source->tags))
                                                            <div class="mt-2 flex flex-wrap gap-1">
                                                                @foreach($source->tags as $tag)
                                                                    <span class="px-1.5 py-0.5 text-xs bg-gray-200 dark:bg-gray-700 text-secondary  rounded">
                                                                        {{ $tag }}
                                                                    </span>
                                                                @endforeach
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('copy-to-clipboard', (event) => {
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(event.text).then(() => {
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
