{{--
    Artifacts Tab Content Component

    Purpose: Display created artifacts for agent executions and interactions

    Display Modes:
    - Timeline Item: Shows artifacts within interaction timeline
    - Standalone: Shows artifacts independently (original format)

    Features:
    - File type icons (code, text, data, generic)
    - Clickable artifact links (open in new tab)
    - Filetype badges with color coding
    - Tool/interaction type display
    - Timestamp display
    - Empty state with helpful message

    Artifact Information:
    - Title: Clickable link to artifact
    - Description: Truncated description (standalone mode only)
    - Filetype: Badge with uppercase extension
    - Tool Used: Interaction type that created it
    - Timestamp: Creation time

    File Type Detection:
    - Code files: Code icon (js, py, php, etc.)
    - Text files: Document icon (txt, md, etc.)
    - Data files: Data icon (json, csv, xml, etc.)
    - Generic: Default document icon

    Badge Colors:
    - Determined by filetype_badge_class property
    - Different colors for different file categories
    - Consistent with artifact-card component

    Timeline Format:
    - Grouped by interaction
    - Interaction header with question
    - Artifact count display
    - Timeline dot and connecting line

    Standalone Format:
    - All artifacts in single list
    - Grouped by interaction ID
    - Full interaction query display
    - Description field visible

    Empty State:
    - Icon and message
    - Execution/interaction ID display
    - Helpful explanation text

    Related:
    - Livewire property: $timeline (array of artifacts)
    - Livewire property: $showAsTimelineItem (boolean)
    - Livewire property: $executionId, $interactionId
--}}
<div>
@if($showAsTimelineItem)
    {{-- Timeline item format for multiple interactions --}}
    <div class="relative">
        <!-- Timeline dot -->
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
                <!-- Artifacts for this interaction -->
                <div class="space-y-1">
                    <div class="text-xs text-gray-500 mb-2">Artifacts ({{ count($timeline) }} total)</div>
                    @foreach($timeline as $artifact)
                        <div class="flex items-center gap-3 py-1 px-2 text-sm hover:bg-surface rounded">
                            <!-- File Type Icon -->
                            <div class="flex-shrink-0">
                                @if($artifact['is_code_file'])
                                    <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                                    </svg>
                                @elseif($artifact['is_text_file'])
                                    <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                @elseif($artifact['is_data_file'])
                                    <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                    </svg>
                                @else
                                    <svg class="w-4 h-4 text-tertiary " fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                @endif
                            </div>

                            <!-- Artifact Title (Clickable) -->
                            <button type="button"
                                    wire:click="openArtifactDrawer({{ $artifact['id'] }})"
                                    class="font-medium text-primary hover:text-accent flex-1 min-w-0 transition-colors truncate text-left hover:underline cursor-pointer">
                                {{ $artifact['title'] }}
                            </button>

                            <!-- Filetype Badge -->
                            @if($artifact['filetype'])
                                <span class="text-xs px-2 py-0.5 {{ $artifact['filetype_badge_class'] }} rounded flex-shrink-0 font-medium">
                                    {{ strtoupper($artifact['filetype']) }}
                                </span>
                            @endif

                            <!-- Tool Used -->
                            @if(isset($artifact['tool_used']))
                                <span class="text-xs px-2 py-0.5 bg-surface text-secondary   rounded flex-shrink-0">
                                    {{ str_replace('_', ' ', $artifact['interaction_type']) }}
                                </span>
                            @endif

                            <!-- Timestamp -->
                            @if(isset($artifact['timestamp']))
                                <span class="text-xs text-tertiary flex-shrink-0">
                                    {{ \Carbon\Carbon::parse($artifact['timestamp'])->format('H:i') }}
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-xs text-gray-500 italic">No artifacts found for this interaction</div>
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
                // Group artifacts by interaction for timeline display
                $groupedArtifacts = [];
                foreach ($timeline as $artifact) {
                    $interactionId = $interactionId ?? 'current';
                    if (!isset($groupedArtifacts[$interactionId])) {
                        $groupedArtifacts[$interactionId] = [];
                    }
                    $groupedArtifacts[$interactionId][] = $artifact;
                }
            @endphp

            @foreach($groupedArtifacts as $groupInteractionId => $artifacts)
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
                                {{ count($artifacts) }} {{ Str::plural('artifact', count($artifacts)) }}
                            </div>
                        </div>

                    <!-- Artifacts for this interaction -->
                    <div class="space-y-1">
                        @foreach($artifacts as $artifact)
                            <div class="flex items-center gap-3 py-1 px-2 text-sm hover:bg-surface rounded">
                                <!-- File Type Icon -->
                                <div class="flex-shrink-0">
                                    @if($artifact['is_code_file'])
                                        <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                                        </svg>
                                    @elseif($artifact['is_text_file'])
                                        <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                    @elseif($artifact['is_data_file'])
                                        <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                                        </svg>
                                    @else
                                        <svg class="w-4 h-4 text-tertiary " fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                    @endif
                                </div>

                                <!-- Artifact Title (Clickable) -->
                                <button type="button"
                                        wire:click="openArtifactDrawer({{ $artifact['id'] }})"
                                        class="font-medium text-primary hover:text-accent flex-1 min-w-0 transition-colors truncate text-left hover:underline cursor-pointer">
                                    {{ $artifact['title'] }}
                                </button>

                                <!-- Artifact Description (if available) -->
                                @if($artifact['description'])
                                    <span class="text-tertiary  text-xs truncate flex-1 min-w-0">
                                        {{ Str::limit($artifact['description'], 40) }}
                                    </span>
                                @endif

                                <!-- Filetype Badge -->
                                @if($artifact['filetype'])
                                    <span class="text-xs px-2 py-0.5 {{ $artifact['filetype_badge_class'] }} rounded flex-shrink-0 font-medium">
                                        {{ strtoupper($artifact['filetype']) }}
                                    </span>
                                @endif

                                <!-- Tool Used / Interaction Type -->
                                @if(isset($artifact['tool_used']))
                                    <span class="text-xs px-2 py-0.5 bg-surface text-secondary   rounded flex-shrink-0">
                                        {{ str_replace('_', ' ', $artifact['interaction_type']) }}
                                    </span>
                                @endif

                                <!-- Timestamp -->
                                @if(isset($artifact['timestamp']))
                                    <span class="text-xs text-tertiary flex-shrink-0">
                                        {{ \Carbon\Carbon::parse($artifact['timestamp'])->format('H:i') }}
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
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            <h3 class="text-lg font-medium text-primary  mb-2">No Artifacts Found</h3>
            <div class="text-gray-500 dark:text-gray-400 space-y-2">
                <p>No artifacts have been created during this {{ $interactionId ? 'interaction' : 'execution' }}.</p>
                @if($executionId)
                    <p class="text-sm">
                        <strong>Execution ID:</strong> {{ $executionId }}
                        @if($interactionId)
                            | <strong>Interaction ID:</strong> {{ $interactionId }}
                        @endif
                    </p>
                    <p class="text-sm text-gray-400">
                        Artifacts will appear here when the AI creates or modifies documents during the conversation.
                    </p>
                @endif
            </div>
        </div>
    @endif
@endif
</div>
