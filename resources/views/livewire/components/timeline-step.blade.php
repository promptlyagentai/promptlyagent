{{--
    Timeline Step Component

    Purpose: Display individual execution step or status update in timeline format

    Display Contexts:
    - thinking: Compact format for real-time thinking process (dots/dashes)
    - steps: Full format with icons and expandable details

    Features:
    - Visual markers (dot/dash/significant dot)
    - Color-coded status
    - Icon representation
    - Timestamp display
    - Duration badge
    - Expandable detail view (steps context)
    - Template mode for JavaScript updates

    Component Props:
    - @props array $step Step data with metadata
    - @props string $context Display context (thinking/steps)
    - @props bool $template Whether rendering as JavaScript template
    - @props bool $isExpandable Whether step can be expanded
    - @props bool $isExpanded Current expansion state

    Timeline Markers:
    - Significant: Blue dot with ring (major events)
    - Dot: Small dot (minor events with create_event=true)
    - Dash: Vertical line (regular steps)

    Step Icons (steps context):
    - Heroicons based on step type
    - Color-coded background circles
    - Determined by getStepIcon() method

    Expandable Details:
    - Full message text
    - Complete timestamp
    - Additional JSON data
    - Click chevron to toggle

    Data Attributes:
    - data-template: Template mode flag
    - data-step-type: Type classification
    - data-step-source: Source system
    - data-significant: Significance flag
    - data-field: JavaScript updatable fields

    CSS Classes:
    - Dynamic border colors
    - Timeline connecting lines
    - Marker positioning
    - Dark mode support

    Related:
    - Used by steps-tab-content component
    - Real-time updates via JavaScript
    - Livewire component backend logic
--}}
<div class="timeline-step flex items-start space-x-3 {{ $context === 'thinking' ? 'p-3 mb-2' : 'p-4' }}
     border-l-2 {{ $template ? 'border-l-default' : $getStepColorClass() }}
     {{ $template ? '' : ($isSignificantStep() ? 'border-l-blue-500' : 'border-l-default') }}
     {{ $template ? 'timeline-marker-regular' : $getTimelineMarkerClass() }}"
     data-template="{{ $template ? 'true' : 'false' }}"
     data-step-type="{{ $template ? '' : ($step['type'] ?? 'status') }}"
     data-step-source="{{ $template ? '' : ($step['source'] ?? '') }}"
     data-significant="{{ $template ? '' : ($isSignificantStep() ? 'true' : 'false') }}">

    <!-- Timeline Marker -->
    <div class="timeline-marker flex-shrink-0 relative">
        @if($context === 'thinking')
            <!-- Thinking Process: Dot/Dash markers -->
            <div class="timeline-indicator" data-indicator-type="{{ $template ? 'regular' : ($isSignificantStep() ? 'significant' : ($getTimelineMarkerClass() === 'timeline-marker-dot' ? 'dot' : 'regular')) }}">
                @if($template)
                    <!-- Template placeholder marker -->
                    <div class="w-1 h-4 bg-surface rounded-sm" data-marker="regular"></div>
                    <!-- Hidden markers that will be shown/hidden via JS -->
                    <div class="hidden w-3 h-3 bg-accent rounded-full ring-2 ring-white dark:ring-surface" data-marker="significant"></div>
                    <div class="hidden w-1.5 h-1.5 bg-surface rounded-full" data-marker="dot"></div>
                @elseif($isSignificantStep())
                    <!-- Blue dot for significant steps -->
                    <div class="w-3 h-3 bg-accent rounded-full ring-2 ring-white dark:ring-surface" data-marker="significant"></div>
                @elseif($getTimelineMarkerClass() === 'timeline-marker-dot')
                    <!-- Dot for non-significant steps with create_event = true -->
                    <div class="w-1.5 h-1.5 bg-surface rounded-full" data-marker="dot"></div>
                @else
                    <!-- Dash for regular steps -->
                    <div class="w-1 h-4 bg-surface rounded-sm" data-marker="regular"></div>
                @endif
            </div>
        @else
            <!-- Steps Tab: Icon in colored circle -->
            <div class="w-8 h-8 rounded-full flex items-center justify-center {{ $template ? 'bg-surface' : $getStepColorClass() }}" data-field="icon-container">
                @if($template)
                    <span data-field="step-icon" class="w-4 h-4"></span>
                @else
                    <x-heroicon-s-{{ $getStepIcon() }} class="w-4 h-4" data-field="step-icon" />
                @endif
            </div>
        @endif
    </div>

    <!-- Step Content -->
    <div class="flex-1 min-w-0">

        <!-- Header -->
        <div class="flex items-center justify-between">
            <div class="flex-1">
                <!-- Message -->
                <div class="text-sm {{ $context === 'thinking' ? 'text-secondary' : 'text-primary' }}" data-field="message">
                    {!! $template ? '' : $getFormattedMessage() !!}
                </div>
            </div>

            <!-- Timestamp and Controls -->
            <div class="flex items-center space-x-2 text-xs text-tertiary ml-4">
                <span data-field="timestamp">{{ $template ? '' : \Carbon\Carbon::parse($step['timestamp'] ?? now())->format($context === 'thinking' ? 'H:i:s' : 'H:i:s') }}</span>

                @if($context === 'steps' && $isExpandable)
                    <svg class="w-4 h-4 transform transition-transform {{ $isExpanded ? 'rotate-180' : '' }}"
                         fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                @endif

                <span data-field="duration" data-show="{{ $template ? 'false' : (isset($step['metadata']['step_duration_ms']) ? 'true' : 'false') }}" class="px-1.5 py-0.5 bg-[var(--palette-success-200)] text-[var(--palette-success-900)] rounded text-xs {{ $template || !isset($step['metadata']['step_duration_ms']) ? 'hidden' : '' }}">
                    {{ $template ? '' : (isset($step['metadata']['step_duration_ms']) ? ($step['metadata']['step_duration_ms'] < 1000 ? round($step['metadata']['step_duration_ms']) . 'ms' : round($step['metadata']['step_duration_ms'] / 1000, 1) . 's') : '') }}
                </span>
            </div>
        </div>

        <!-- Progress Bar removed -->

        <!-- Expanded Details (Steps Context Only) -->
        @if($context === 'steps' && $isExpandable && $isExpanded)
            <div class="mt-3 pt-3 border-t border-default  space-y-2">
                <!-- Full Message -->
                <div>
                    <h5 class="text-xs font-medium text-tertiary uppercase tracking-wider mb-1">Message</h5>
                    <p class="text-sm text-tertiary ">{!! $getFormattedMessage() !!}</p>
                </div>

                <!-- Full Timestamp -->
                <div>
                    <h5 class="text-xs font-medium text-tertiary uppercase tracking-wider mb-1">Timestamp</h5>
                    <p class="text-sm text-tertiary ">
                        {{ \Carbon\Carbon::parse($step['timestamp'] ?? now())->format('Y-m-d H:i:s') }}
                    </p>
                </div>

                <!-- Additional Data -->
                @if(isset($step['data']) && is_array($step['data']) && count($step['data']) > 0)
                    <div>
                        <h5 class="text-xs font-medium text-tertiary uppercase tracking-wider mb-1">Additional Data</h5>
                        <div class="bg-gray-100 dark:bg-gray-900 rounded p-2">
                            <pre class="text-xs text-tertiary  whitespace-pre-wrap">{{ json_encode($step['data'], JSON_PRETTY_PRINT) }}</pre>
                        </div>
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>

<style>
/* Timeline marker styles */
.timeline-marker-significant .timeline-indicator {
    position: relative;
}

.timeline-marker-regular .timeline-indicator {
    position: relative;
}

.timeline-marker-dot .timeline-indicator {
    position: relative;
}

/* Add connecting line for timeline */
.timeline-step:not(:last-child) .timeline-marker::after {
    content: '';
    position: absolute;
    top: 1rem;
    left: 50%;
    width: 1px;
    height: calc(100% + 0.5rem);
    background-color: theme('colors.gray.200');
    transform: translateX(-50%);
}

.dark .timeline-step:not(:last-child) .timeline-marker::after {
    background-color: theme('colors.gray.700');
}

/* Significant steps get blue connecting lines */
.timeline-marker-significant.timeline-step:not(:last-child) .timeline-marker::after {
    background-color: theme('colors.blue.200');
}

.dark .timeline-marker-significant.timeline-step:not(:last-child) .timeline-marker::after {
    background-color: theme('colors.blue.800');
}
</style>
