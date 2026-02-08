{{--
    Steps Tab Content Component (Standalone)

    Purpose: Display detailed execution timeline with collapsible steps

    Features:
    - Expand/collapse all controls
    - Refresh timeline button
    - Export timeline to file
    - Execution statistics summary
    - Individual step details
    - Progress tracking

    Controls:
    - Expand All: Opens all step details
    - Collapse All: Closes all step details
    - Refresh: Reloads timeline data
    - Export: Downloads timeline as file

    Execution Stats:
    - Total Steps: Count of execution phases
    - Duration: Total time taken (formatted)
    - Status: Completed or In Progress (color-coded)
    - Current Phase: Active execution phase

    Timeline Display:
    - Uses timeline-step component for each entry
    - Clickable rows to toggle expansion
    - Border styling per step
    - Wire:key for proper tracking

    Step Properties:
    - show-source-title: false (hides source in step)
    - is-expandable: true (allows collapse/expand)
    - is-expanded: Based on expandedSteps array
    - context: "steps" (identifies display context)

    Empty State:
    - Clipboard icon
    - "No Steps Found" message
    - Explanation text

    Livewire Methods:
    - expandAll(): Expands all steps
    - collapseAll(): Closes all steps
    - toggleStep(index): Toggles specific step
    - refreshTimeline(): Reloads data
    - exportTimeline(): Exports to file
    - formatDuration(): Formats duration display

    Related:
    - livewire.components.timeline-step: Individual step component
    - $timeline: Array of steps with metadata
    - $expandedSteps: Array of expanded step indices
    - $executionStats: Summary statistics
--}}
<div class="space-y-4">
    {{-- Controls: Expand/Collapse/Refresh/Export --}}
    <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
            <button wire:click="expandAll" class="px-3 py-1 text-sm text-accent hover:text-accent">
                Expand All
            </button>
            <button wire:click="collapseAll" class="px-3 py-1 text-sm text-accent hover:text-accent">
                Collapse All
            </button>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="refreshTimeline" class="px-3 py-1 text-sm text-secondary hover:text-primary">
                <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                </svg>
                Refresh
            </button>
            <button wire:click="exportTimeline" class="px-3 py-1 text-sm text-secondary hover:text-primary">
                <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                Export
            </button>
        </div>
    </div>

    <!-- Execution Stats -->
    @if(!empty($executionStats))
        <div class="bg-surface rounded-lg p-4">
            <h3 class="text-lg font-semibold mb-3">Execution Summary</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <div class="text-sm text-secondary">Total Steps</div>
                    <div class="text-2xl font-bold text-primary">{{ $executionStats['total_phases'] ?? 0 }}</div>
                </div>
                <div>
                    <div class="text-sm text-secondary">Duration</div>
                    <div class="text-2xl font-bold text-primary">
                        {{ isset($executionStats['total_duration']) ? $this->formatDuration($executionStats['total_duration']) : 'N/A' }}
                    </div>
                </div>
                <div>
                    <div class="text-sm text-secondary">Status</div>
                    <div class="text-2xl font-bold {{ $executionStats['is_completed'] ? 'text-accent' : 'text-[var(--palette-warning-700)]' }}">
                        {{ $executionStats['is_completed'] ? 'Completed' : 'In Progress' }}
                    </div>
                </div>
                <div>
                    <div class="text-sm text-secondary">Current Phase</div>
                    <div class="text-sm font-medium text-primary">
                        {{ $executionStats['current_phase'] ?? 'N/A' }}
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- Timeline -->
    @if(count($timeline) > 0)
        <div class="space-y-2">
            @foreach($timeline as $index => $step)
                <div class="border border-default  rounded-lg overflow-hidden" 
                     wire:click="toggleStep({{ $index }})" style="cursor: pointer;">
                    <livewire:components.timeline-step 
                        :step="$step" 
                        :show-source-title="false" 
                        :is-expandable="true"
                        :is-expanded="in_array($index, $expandedSteps)"
                        context="steps"
                        :key="'steps-timeline-' . $index"
                    />
                </div>
            @endforeach
        </div>
    @else
        <div class="text-center py-8">
            <svg class="w-12 h-12 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
            </svg>
            <h3 class="text-lg font-medium text-primary mb-2">No Steps Found</h3>
            <p class="text-gray-500 dark:text-gray-400">
                No execution steps or status entries are available for this execution.
            </p>
        </div>
    @endif
</div>