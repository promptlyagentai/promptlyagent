@php
    $source = strtolower($step['source'] ?? ''); 
    $message = strtolower($step['message'] ?? '');
    
    // Determine if this step is significant (should get a blue dot)
    $isSignificant = false;
    
    // Check for significance patterns
    if (isset($step['is_significant']) && $step['is_significant']) {
        $isSignificant = true;
    } elseif (str_contains($message, 'agent execution') || 
              str_contains($message, 'step') && str_contains($message, 'executing') ||
              str_contains($message, 'found') && str_contains($message, 'results') ||
              str_contains($message, 'completed in') ||
              $source === 'tool_call') {
        $isSignificant = true;
    }
    
    // Format message with URLs and highlights
    $formattedMessage = $step['message'] ?? 'No message';
    
    // Make URLs clickable
    $formattedMessage = preg_replace(
        '/(https?:\/\/[^\s]+)/',
        '<a href="$1" target="_blank" class="text-accent underline hover:text-accent-hover">$1</a>',
        $formattedMessage
    );
    
    // Bold search terms in quotes
    $formattedMessage = preg_replace('/"([^"]+)"/', '<strong class="font-semibold text-primary ">"$1"</strong>', $formattedMessage);
    
    // Bold key results
    $formattedMessage = preg_replace(
        '/(found \d+ results?|completed in \d+[.\d]*\w+|validated \d+ URLs?)/i', 
        '<strong class="font-semibold text-success">$1</strong>', 
        $formattedMessage
    );
    
    // Check if user is admin
    $isAdmin = auth()->check() && auth()->user()->isAdmin();
@endphp

<div class="thinking-step flex items-start space-x-3 p-3 mb-2 border-l-2 
     {{ $isSignificant ? 'border-l-blue-500 bg-accent/10' : 'border-l-gray-200 dark:border-l-gray-700 bg-surface /50' }}">
    
    <!-- Timeline Marker -->
    <div class="flex-shrink-0 mt-0.5">
        @if($isSignificant)
            <!-- Blue dot for significant steps -->
            <div class="w-3 h-3 bg-accent rounded-full ring-2 ring-white dark:ring-gray-900"></div>
        @else
            <!-- Dash for regular steps -->
            <div class="w-1 h-4 bg-gray-300 dark:bg-gray-600 rounded-sm"></div>
        @endif
    </div>

    <!-- Step Content -->
    <div class="flex-1 min-w-0">
        <div class="flex items-center justify-between">
            <div class="flex-1">
                {{-- Hide source title for non-admins in thinking view --}}
                @if($isAdmin)
                    <p class="text-sm font-medium text-primary  mb-1">
                        {{ ucfirst($step['source']) }}
                    </p>
                @endif
                
                <!-- Formatted Message -->
                <div class="text-sm text-gray-600 dark:text-gray-300">
                    {!! $formattedMessage !!}
                </div>
            </div>
            
            <!-- Timestamp and Duration -->
            <div class="flex items-center space-x-2 text-xs text-gray-500 dark:text-gray-400">
                <span>{{ $step['timestamp'] }}</span>
                
                @if(isset($step['metadata']['step_duration_ms']))
                    @php
                        $duration = $step['metadata']['step_duration_ms'];
                        $formatted = $duration < 1000 ? round($duration) . 'ms' : round($duration / 1000, 1) . 's';
                    @endphp
                    <span class="px-1.5 py-0.5 bg-[var(--palette-success-200)] text-[var(--palette-success-900)] rounded text-xs">
                        {{ $formatted }}
                    </span>
                @endif
            </div>
        </div>

        <!-- Progress Bar for Tool Executions -->
        @if(isset($step['metadata']['current_count']) && isset($step['metadata']['counter_max']))
            <div class="mt-2">
                <div class="flex justify-between text-xs text-gray-600 dark:text-gray-300">
                    <span>Progress: {{ $step['metadata']['current_count'] }}/{{ $step['metadata']['counter_max'] }}</span>
                    <span>{{ $step['metadata']['percentage'] ?? '0' }}%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                    <div class="bg-accent h-1.5 rounded-full transition-all duration-300" 
                         style="width: {{ $step['metadata']['percentage'] ?? 0 }}%"></div>
                </div>
            </div>
        @endif
    </div>
</div>