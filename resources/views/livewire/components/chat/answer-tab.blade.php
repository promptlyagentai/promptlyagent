@props([
    'interactions',
    'pendingQuestion',
    'currentInteractionId',
    'isStreaming',
    'isThinking',
    'currentStatus',
    'inlineArtifacts' => [],
    'formatExecutionTimeEstimate'
])

<div x-show="currentTab === 'answer'" x-transition class="h-full overflow-hidden">
    <div class="h-full flex flex-col border border-default rounded">
        @if(count($interactions) > 0 || !empty($pendingQuestion))
            <div class="flex-1 overflow-y-auto space-y-6 p-4" id="conversation-container">
                @foreach($interactions as $interaction)
                    <div class="flex justify-end group">
                        <div class="w-[80%] bg-accent text-accent-foreground rounded-lg p-3 text-left relative">
                            {{ $interaction->question }}
                            @php
                                $questionAttachments = $interaction->attachments->where('attached_to', 'question');
                            @endphp
                            @if($questionAttachments->count() > 0)
                                <div class="mt-3 pt-3 border-t border-accent">
                                    <div class="text-sm text-accent-foreground/80 mb-2">Attached files:</div>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach($questionAttachments as $attachment)
                                            <div class="flex items-center gap-2 bg-accent/70 rounded px-2 py-1 text-xs">
                                                @if($attachment->type === 'image')
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                    </svg>
                                                @elseif($attachment->type === 'document')
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                                    </svg>
                                                @elseif($attachment->type === 'audio')
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"/>
                                                    </svg>
                                                @elseif($attachment->type === 'video')
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                                    </svg>
                                                @endif
                                                <span>{{ $attachment->filename }}</span>
                                                <a href="{{ route('chat.attachment.download', $attachment->id) }}"
                                                   class="text-accent-foreground/80 hover:text-white"
                                                   title="Download">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                                    </svg>
                                                </a>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                        </div>
                    </div>
                    @if($interaction->answer && trim($interaction->answer) !== '')
                        <div class="flex justify-between items-end gap-4">
                            <div class="w-[80%] bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg p-3">
                                <div x-data="markdownRenderer()" class="text-primary  text-left"
                                     id="search-results-{{ $interaction->id }}">
                                    <span x-ref="source" class="hidden">{{ $interaction->answer }}</span>
                                    <div x-ref="target" class="markdown" x-html="renderedHtml"></div>
                                </div>

                                @php
                                    $answerAttachments = $interaction->attachments->where('attached_to', 'answer');
                                @endphp
                                @if($answerAttachments->count() > 0)
                                    <div class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-600">
                                        <div class="text-sm text-gray-600 dark:text-gray-400 mb-2">Generated files:</div>
                                        <div class="flex flex-wrap gap-2">
                                            @foreach($answerAttachments as $attachment)
                                                <div class="flex items-center gap-2 bg-gray-100 dark:bg-gray-700 rounded px-2 py-1 text-xs">
                                                    @if($attachment->type === 'image')
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                        </svg>
                                                    @elseif($attachment->type === 'document')
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                                        </svg>
                                                    @elseif($attachment->type === 'audio')
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M5.586 15H4a1 1 0 01-1-1v-4a1 1 0 011-1h1.586l4.707-4.707C10.923 3.663 12 4.109 12 5v14c0 .891-1.077 1.337-1.707.707L5.586 15z"/>
                                                        </svg>
                                                    @elseif($attachment->type === 'video')
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                                        </svg>
                                                    @endif
                                                    <span>{{ $attachment->filename }}</span>
                                                    <a href="{{ route('chat.attachment.download', $attachment->id) }}"
                                                       class="text-gray-600 dark:text-gray-400 hover:text-primary"
                                                       title="Download">
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                                        </svg>
                                                    </a>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                            @if(isset($inlineArtifacts[$interaction->id]) && count($inlineArtifacts[$interaction->id]) > 0)
                                <div class="flex-1 flex justify-end items-end">
                                    <div class="w-auto space-y-3">
                                        @foreach($inlineArtifacts[$interaction->id] as $artifact)
                                            @livewire('components.artifact-card', ['artifact' => $artifact], key('artifact-'.$artifact->id.'-'.$interaction->id))
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>

                        <!-- Action Bar - positioned below answer with tight spacing -->
                        {{-- Hide action buttons ONLY when actively researching current interaction without an answer --}}
                        @if(!($isThinking && $interaction->id === $currentInteractionId && empty(trim($interaction->answer))))
                        <div class="flex justify-start">
                            <div class="w-[80%]">
                                <div class="flex gap-2 opacity-75 hover:opacity-100 transition-opacity duration-200 -mt-4">
                                    <!-- Retry Button -->
                                    <button wire:click="retryQuestion({{ $interaction->id }})"
                                            class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors duration-200"
                                            title="Retry this question">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                        </svg>
                                    </button>

                                    <!-- Copy Answer Button -->
                                    <button wire:click="copyInteractionAnswer({{ $interaction->id }})"
                                            class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors duration-200"
                                            title="Copy answer to clipboard">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                        </svg>
                                    </button>

                                    <!-- Create Artifact Button -->
                                    <button wire:click="createArtifactFromAnswer({{ $interaction->id }})"
                                            wire:loading.attr="disabled"
                                            wire:loading.class="opacity-50 cursor-not-allowed"
                                            wire:target="createArtifactFromAnswer"
                                            class="p-2 text-gray-500 hover:text-accent dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors duration-200"
                                            title="Save answer as artifact">
                                        <!-- Default icon (shown when not loading) -->
                                        <span wire:loading.remove wire:target="createArtifactFromAnswer">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                            </svg>
                                        </span>
                                        <!-- Loading spinner (shown during loading) -->
                                        <span wire:loading wire:target="createArtifactFromAnswer">
                                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.984l2-2.693z"></path>
                                            </svg>
                                        </span>
                                    </button>

                                    <!-- Export Interaction Button -->
                                    <button wire:click="exportInteractionAsMarkdown({{ $interaction->id }})"
                                            class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors duration-200"
                                            title="Export as Markdown">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                        @endif
                    @else
                        <!-- Show placeholder for current interaction if no answer yet OR if streaming -->
                        @if($interaction->id === $currentInteractionId && ($isStreaming || empty(trim($interaction->answer))))
                            <div class="flex justify-start">
                                <div class="w-[80%]">
                                    <!-- PERSISTENT: Wire:Stream Thinking Process Container - Always present to preserve WebSocket content -->
                                    <!-- Primary thinking process display (CSS controlled visibility) -->
                                    <div class="relative p-4" style="display: {{ $isThinking ? 'block' : 'none' }}">
                                        <!-- Timeline line -->
                                        <div class="absolute left-10 top-4 bottom-4 w-px bg-border-default"></div>

                                        <div id="thinking-process-container" class="thinking-process-container" wire:ignore>
                                            <!-- Initial thinking state -->
                                            <div class="relative mb-6">
                                                <!-- Timeline dot -->
                                                <div class="absolute left-4 w-4 h-4 bg-surface  border-2 border-accent rounded-full flex items-center justify-center">
                                                    <svg class="animate-spin w-2 h-2 text-accent" fill="none" viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 8.015v-4.724z"></path>
                                                    </svg>
                                                </div>

                                                <!-- Content -->
                                                <div class="ml-12">
                                                    <div class="flex items-center justify-between mb-3">
                                                        <div class="font-medium text-sm text-primary flex-1 pr-4">
                                                            Researching
                                                        </div>
                                                        <div class="text-xs text-tertiary flex-shrink-0">
                                                            {{ now()->format('H:i:s') }}
                                                        </div>
                                                    </div>
                                                    <div class="text-sm text-secondary researching-message">
                                                        Your research process is ongoing, typical time to complete {{ $formatExecutionTimeEstimate }}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Alpine markdown renderer for streaming/status display -->
                                    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg p-3" style="display: {{ $isThinking ? 'none' : 'block' }}">
                                        <div x-data="markdownRenderer()" class="text-primary  text-sm text-left"
                                             id="search-results-{{ $interaction->id }}">
                                            <span x-ref="source" class="hidden">{{ $isStreaming ? '_Connecting..._' : $currentStatus }}</span>
                                            <div x-ref="target" class="markdown" x-html="renderedHtml"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Action Bar - positioned below placeholder for interactions without answers -->
                            {{-- Hide action buttons ONLY when actively researching current interaction without an answer --}}
                            @if(!($isThinking && $interaction->id === $currentInteractionId && empty(trim($interaction->answer))))
                            <div class="flex justify-start">
                                <div class="w-[80%]">
                                    <div class="flex gap-2 opacity-75 hover:opacity-100 transition-opacity duration-200 -mt-4">
                                    <!-- Retry Button -->
                                    <button wire:click="retryQuestion({{ $interaction->id }})"
                                            class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors duration-200"
                                            title="Retry this question">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                        </svg>
                                    </button>

                                    <!-- Copy Question Button (no answer available yet) -->
                                    <button wire:click="copyInteractionQuestion({{ $interaction->id }})"
                                            class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors duration-200"
                                            title="Copy question to clipboard">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/>
                                        </svg>
                                    </button>

                                    <!-- Export Interaction Button -->
                                    <button wire:click="exportInteractionAsMarkdown({{ $interaction->id }})"
                                            class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg transition-colors duration-200"
                                            title="Export as Markdown">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                        </svg>
                                    </button>
                                </div>
                                </div>
                            </div>
                            @endif
                        @endif
                    @endif
                @endforeach

                <!-- Show pending question immediately if form was just submitted -->
                @if(!empty($pendingQuestion))
                    <!-- Pending Question -->
                    <div class="flex justify-end">
                        <div class="w-[80%] bg-accent text-white rounded-lg p-3 text-left">
                            {{ $pendingQuestion }}
                        </div>
                    </div>

                    <!-- Pending Answer Placeholder with Alpine markdown renderer -->
                    <div class="flex justify-start">
                        <div class="w-[80%] bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg p-3">
                            <div x-data="markdownRenderer()" class="text-primary  text-sm text-left"
                                 id="search-results-{{ $currentInteractionId }}">
                                <span x-ref="source" class="hidden">_{{ $currentStatus }}_</span>
                                <div x-ref="target" class="markdown" x-html="renderedHtml"></div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        @else
            <!-- No interactions yet - Show engaging startup screen -->
            <div class="flex-1 overflow-y-auto p-8 flex items-center justify-center">
                <div class="max-w-2xl w-full">
                    <!-- Icon and Header -->
                    <div class="text-center mb-8">
                        <div class="flex items-center justify-center w-16 h-16 bg-accent rounded-xl shadow-lg mx-auto mb-4">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 14.5M14.25 3.104c.251.023.501.05.75.082M19.8 14.5l-5.207 5.207a2.25 2.25 0 01-1.591.659h-2.004a2.25 2.25 0 01-1.591-.659L4.8 14.5m15-.001l1.38 1.38a2.25 2.25 0 010 3.182l-1.38 1.38m-15-4.56l-1.38 1.38a2.25 2.25 0 000 3.182l1.38 1.38"/>
                            </svg>
                        </div>
                        <h3 class="text-xl font-semibold text-primary mb-2">Ready to research</h3>
                        <p class="text-secondary">Get started with these research ideas or ask your own question</p>
                    </div>

                    <!-- Prompt Suggestions (Dynamic) -->
                    @php
                        $researchTopics = app(\App\Services\ResearchTopicService::class)->getTopicsForUser(Auth::user());

                        // Color theme mapping for Tailwind classes
                        $colorThemes = [
                            'accent' => [
                                'bg' => 'bg-accent/5',
                                'border' => 'border-accent',
                                'hover' => 'hover:bg-accent/10',
                                'icon_bg' => 'bg-accent',
                                'title' => 'text-primary group-hover:text-accent',
                                'desc' => 'text-secondary',
                            ],
                            'emerald' => [
                                'bg' => 'bg-gradient-to-br from-emerald-50 to-teal-50 dark:from-emerald-900/20 dark:to-teal-900/20',
                                'border' => 'border-emerald-200 dark:border-emerald-800',
                                'hover' => 'hover:from-emerald-100 hover:to-teal-100 dark:hover:from-emerald-900/30 dark:hover:to-teal-900/30',
                                'icon_bg' => 'bg-emerald-500',
                                'title' => 'text-emerald-900 dark:text-emerald-100 group-hover:text-emerald-700 dark:group-hover:text-emerald-200',
                                'desc' => 'text-emerald-700 dark:text-emerald-300',
                            ],
                            'purple' => [
                                'bg' => 'bg-gradient-to-br from-purple-50 to-violet-50 dark:from-purple-900/20 dark:to-violet-900/20',
                                'border' => 'border-purple-200 dark:border-purple-800',
                                'hover' => 'hover:from-purple-100 hover:to-violet-100 dark:hover:from-purple-900/30 dark:hover:to-violet-900/30',
                                'icon_bg' => 'bg-purple-500',
                                'title' => 'text-purple-900 dark:text-purple-100 group-hover:text-purple-700 dark:group-hover:text-purple-200',
                                'desc' => 'text-purple-700 dark:text-purple-300',
                            ],
                            'orange' => [
                                'bg' => 'bg-gradient-to-br from-orange-50 to-red-50 dark:from-orange-900/20 dark:to-red-900/20',
                                'border' => 'border-orange-200 dark:border-orange-800',
                                'hover' => 'hover:from-orange-100 hover:to-red-100 dark:hover:from-orange-900/30 dark:hover:to-red-900/30',
                                'icon_bg' => 'bg-orange-500',
                                'title' => 'text-orange-900 dark:text-orange-100 group-hover:text-orange-700 dark:group-hover:text-orange-200',
                                'desc' => 'text-orange-700 dark:text-orange-300',
                            ],
                            'blue' => [
                                'bg' => 'bg-gradient-to-br from-blue-50 to-sky-50 dark:from-blue-900/20 dark:to-sky-900/20',
                                'border' => 'border-blue-200 dark:border-blue-800',
                                'hover' => 'hover:from-blue-100 hover:to-sky-100 dark:hover:from-blue-900/30 dark:hover:to-sky-900/30',
                                'icon_bg' => 'bg-blue-500',
                                'title' => 'text-blue-900 dark:text-blue-100 group-hover:text-blue-700 dark:group-hover:text-blue-200',
                                'desc' => 'text-blue-700 dark:text-blue-300',
                            ],
                            'indigo' => [
                                'bg' => 'bg-gradient-to-br from-indigo-50 to-blue-50 dark:from-indigo-900/20 dark:to-blue-900/20',
                                'border' => 'border-indigo-200 dark:border-indigo-800',
                                'hover' => 'hover:from-indigo-100 hover:to-blue-100 dark:hover:from-indigo-900/30 dark:hover:to-blue-900/30',
                                'icon_bg' => 'bg-indigo-500',
                                'title' => 'text-indigo-900 dark:text-indigo-100 group-hover:text-indigo-700 dark:group-hover:text-indigo-200',
                                'desc' => 'text-indigo-700 dark:text-indigo-300',
                            ],
                            'pink' => [
                                'bg' => 'bg-gradient-to-br from-pink-50 to-rose-50 dark:from-pink-900/20 dark:to-rose-900/20',
                                'border' => 'border-pink-200 dark:border-pink-800',
                                'hover' => 'hover:from-pink-100 hover:to-rose-100 dark:hover:from-pink-900/30 dark:hover:to-rose-900/30',
                                'icon_bg' => 'bg-pink-500',
                                'title' => 'text-pink-900 dark:text-pink-100 group-hover:text-pink-700 dark:group-hover:text-pink-200',
                                'desc' => 'text-pink-700 dark:text-pink-300',
                            ],
                            'teal' => [
                                'bg' => 'bg-gradient-to-br from-teal-50 to-cyan-50 dark:from-teal-900/20 dark:to-cyan-900/20',
                                'border' => 'border-teal-200 dark:border-teal-800',
                                'hover' => 'hover:from-teal-100 hover:to-cyan-100 dark:hover:from-teal-900/30 dark:hover:to-cyan-900/30',
                                'icon_bg' => 'bg-teal-500',
                                'title' => 'text-teal-900 dark:text-teal-100 group-hover:text-teal-700 dark:group-hover:text-teal-200',
                                'desc' => 'text-teal-700 dark:text-teal-300',
                            ],
                        ];
                    @endphp

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-6">
                        @foreach($researchTopics as $topic)
                            @php
                                $theme = $colorThemes[$topic['color_theme']] ?? $colorThemes['accent'];
                                $escapedQuery = addslashes($topic['query']);
                            @endphp

                            <button type="button"
                                    @click="$wire.set('query', '{{ $escapedQuery }}'); setTimeout(() => { const inputEl = document.querySelector('input[wire\\:model\\.live=query]'); if (inputEl) inputEl.focus(); }, 50);"
                                    class="p-3 text-left {{ $theme['bg'] }} border {{ $theme['border'] }} rounded-lg {{ $theme['hover'] }} transition-all duration-200 group">
                                <div class="flex items-start gap-2">
                                    <div class="flex-shrink-0 w-6 h-6 {{ $theme['icon_bg'] }} rounded-md flex items-center justify-center">
                                        <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $topic['icon_path'] }}"/>
                                        </svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <h4 class="text-sm font-medium {{ $theme['title'] }}">{{ $topic['title'] }}</h4>
                                        <p class="text-xs {{ $theme['desc'] }} mt-0.5">{{ $topic['description'] }}</p>
                                    </div>
                                </div>
                            </button>
                        @endforeach
                    </div>

                    <!-- Hidden search results div for JavaScript updates -->
                    <div id="search-results" class="hidden text-tertiary  text-sm whitespace-pre-wrap">
                        {{ $currentStatus }}
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
