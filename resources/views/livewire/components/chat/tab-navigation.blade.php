{{--
    Chat Tab Navigation Component

    Purpose: Tab navigation bar for chat interface with queue status display

    Tabs:
    - Chat (answer): Main conversation view
    - Sources: Discovered knowledge sources
    - Artifacts: Created artifacts from interactions
    - Steps: Execution timeline and workflow steps

    Component Props:
    - @props string $selectedTab Currently active tab
    - @props array $queueJobDisplay Queue status indicators array
    - @props array $queueJobCounts Running and queued job counts

    Features:
    - Alpine.js tab switching (currentTab binding)
    - Automatic tab data refresh on switch
    - Real-time queue status display
    - Cancel pending jobs button
    - Visual indicators (icons, counts, colors)

    Tab Switching Behavior:
    - Immediate UI update via Alpine.js
    - Delayed Livewire refresh (100ms) for data sync
    - Updates lastRefresh timestamp for debugging

    Queue Status:
    - Running jobs (spinner icon)
    - Queued jobs (clock icon)
    - Cancel button (stop icon)
    - Color-coded status indicators

    Alpine.js Integration:
    - currentTab: Tracks active tab state
    - Wire calls for refreshing tab data
    - Timestamp tracking for refresh debugging

    Related:
    - livewire.components.chat.answer-tab: Chat conversation view
    - livewire.components.chat.sources-tab: Source discovery view
    - livewire.components.chat.artifacts-tab: Artifacts display
    - livewire.components.chat.steps-tab: Execution timeline
--}}
@props([
    'selectedTab',
    'queueJobDisplay' => [],
    'queueJobCounts' => ['running' => 0, 'queued' => 0],
    'blockingExecutionId' => null
])

{{-- Fixed Tabs with Alpine.js for immediate response during research --}}
<nav class="border-b border-default">
    <div class="flex items-end gap-4">
        <button
            @click="currentTab = 'answer'"
            :class="currentTab === 'answer' ? 'border-b-2 border-accent font-medium' : 'text-secondary'"
            class="px-4 py-2 -mb-px whitespace-nowrap">Chat</button>
        <button
            @click="
                currentTab = 'sources';
                setTimeout(() => {
                    $wire.call('refreshTabData', 'sources').then(() => {
                        lastSourcesRefresh = new Date().toLocaleTimeString();
                    });
                }, 100);
            "
            :class="currentTab === 'sources' ? 'border-b-2 border-accent font-medium' : 'text-secondary'"
            class="px-4 py-2 -mb-px whitespace-nowrap">Sources</button>
        <button
            @click="
                currentTab = 'artifacts';
                setTimeout(() => {
                    $wire.call('refreshTabData', 'artifacts').then(() => {
                        lastArtifactsRefresh = new Date().toLocaleTimeString();
                    });
                }, 100);
            "
            :class="currentTab === 'artifacts' ? 'border-b-2 border-accent font-medium' : 'text-secondary'"
            class="px-4 py-2 -mb-px whitespace-nowrap">Artifacts</button>
        <button
            @click="
                currentTab = 'steps';
                setTimeout(() => {
                    $wire.call('refreshTabData', 'steps').then(() => {
                        stepsData = $wire.executionSteps || [];
                        lastStepsRefresh = new Date().toLocaleTimeString();
                    });
                }, 100);
            "
            :class="currentTab === 'steps' ? 'border-b-2 border-accent font-medium' : 'text-secondary'"
            class="px-4 py-2 -mb-px whitespace-nowrap">Steps</button>

        @if($blockingExecutionId || count($queueJobDisplay) > 0 || $queueJobCounts['running'] > 0 || $queueJobCounts['queued'] > 0)
            <div class="flex items-center space-x-3 text-sm pb-2 ml-auto">
            <!-- Blocking Execution Warning -->
            @if($blockingExecutionId)
                <div class="flex items-center space-x-2 text-warning flex-nowrap">
                    <span class="text-base">⚠️</span>
                    <span class="font-medium text-xs whitespace-nowrap">Execution #{{ $blockingExecutionId }} is blocking</span>
                    <button
                        wire:click="cancelBlockingExecution({{ $blockingExecutionId }})"
                        class="px-2 py-1 text-xs text-[var(--palette-error-600)] dark:text-[var(--palette-error-400)] border border-[var(--palette-error-600)] dark:border-[var(--palette-error-400)] rounded hover:bg-[var(--palette-error-600)] hover:text-white dark:hover:bg-[var(--palette-error-400)] dark:hover:text-[var(--palette-neutral-900)] transition-colors whitespace-nowrap"
                        title="Cancel blocking execution"
                        wire:loading.attr="disabled"
                        wire:target="cancelBlockingExecution">
                        <span wire:loading.remove wire:target="cancelBlockingExecution">Cancel</span>
                        <span wire:loading wire:target="cancelBlockingExecution">Cancelling...</span>
                    </button>
                </div>
            @endif

            <!-- Regular Queue Status -->
            @if(count($queueJobDisplay) > 0)
                @foreach($queueJobDisplay as $status)
                    <div class="flex items-center space-x-1 {{ $status['color'] }} whitespace-nowrap flex-nowrap">
                        <span>{{ $status['icon'] }}</span>
                        <span class="font-medium">{{ $status['count'] }}</span>
                        <span class="text-xs opacity-75">{{ $status['label'] }}</span>
                    </div>
                @endforeach
            @elseif($queueJobCounts['running'] > 0 || $queueJobCounts['queued'] > 0)
                {{-- Fallback: Show counts even if display array is empty --}}
                @if($queueJobCounts['running'] > 0)
                    <div class="flex items-center space-x-1 text-tropical-teal-600 dark:text-tropical-teal-400 whitespace-nowrap flex-nowrap">
                        <span>⚡</span>
                        <span class="font-medium">{{ $queueJobCounts['running'] }}</span>
                        <span class="text-xs opacity-75">running</span>
                    </div>
                @endif
                @if($queueJobCounts['queued'] > 0)
                    <div class="flex items-center space-x-1 text-yellow-600 dark:text-yellow-400 whitespace-nowrap flex-nowrap">
                        <span>⏳</span>
                        <span class="font-medium">{{ $queueJobCounts['queued'] }}</span>
                        <span class="text-xs opacity-75">queued</span>
                    </div>
                @endif
            @endif

            <!-- Cancel button for pending jobs -->
            @if($queueJobCounts['running'] > 0 || $queueJobCounts['queued'] > 0)
                <button
                    wire:click="cancelPendingJobs"
                    class="ml-2 px-2 py-1 text-xs text-[var(--palette-error-600)] dark:text-[var(--palette-error-400)] border border-[var(--palette-error-600)] dark:border-[var(--palette-error-400)] rounded hover:bg-[var(--palette-error-600)] hover:text-white dark:hover:bg-[var(--palette-error-400)] dark:hover:text-[var(--palette-neutral-900)] transition-colors whitespace-nowrap"
                    title="Cancel all pending jobs"
                    wire:loading.attr="disabled"
                    wire:target="cancelPendingJobs">
                    <span wire:loading.remove wire:target="cancelPendingJobs">🛑</span>
                    <span wire:loading wire:target="cancelPendingJobs">⏳</span>
                </button>
            @endif
            </div>
        @endif
    </div>
</nav>
