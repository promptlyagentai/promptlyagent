<div class="space-y-6">

    {{-- System Overview Widget --}}
    <div class="bg-surface rounded-lg shadow-sm border border-default ">
        <div class="p-4 border-b border-default ">
            <div class="flex justify-between items-center">
                <div class="flex items-center space-x-2">
                    <div class="flex items-center space-x-2">
                        @if(!empty($healthData))
                            <div class="w-3 h-3 rounded-full {{ $healthData['overall_status'] === 'healthy' ? 'bg-accent' : ($healthData['overall_status'] === 'warning' ? 'bg-[var(--palette-warning-500)]' : 'bg-[var(--palette-error-500)]') }}"></div>
                        @endif
                        <h3 class="text-lg font-semibold text-primary">System Health</h3>
                    </div>
                    @if(!empty($healthData['last_checked']))
                        <span class="text-sm text-tertiary">
                            Updated: {{ \Carbon\Carbon::parse($healthData['last_checked'])->diffForHumans() }}
                        </span>
                    @endif
                </div>

                <button
                    wire:click="refreshStatus"
                    wire:loading.attr="disabled"
                    class="flex items-center px-3 py-1.5 text-sm font-medium text-secondary bg-surface hover:opacity-90 rounded-md transition-colors duration-200 border border-default"
                >
                    <svg wire:loading wire:target="refreshStatus" class="animate-spin -ml-1 mr-2 h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <svg wire:loading.remove wire:target="refreshStatus" class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    <span wire:loading.remove wire:target="refreshStatus">Refresh</span>
                    <span wire:loading wire:target="refreshStatus">Checking...</span>
                </button>
            </div>
            
            @if(!empty($healthData['stats']))
                <div class="mt-3 grid grid-cols-4 gap-4">
                    <div class="text-center">
                        <div class="text-2xl font-bold text-accent">{{ $healthData['stats']['healthy'] }}</div>
                        <div class="text-sm text-tertiary">Healthy</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-warning">{{ $healthData['stats']['warnings'] }}</div>
                        <div class="text-sm text-tertiary">Warnings</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-error">{{ $healthData['stats']['errors'] }}</div>
                        <div class="text-sm text-tertiary">Errors</div>
                    </div>
                    <div class="text-center">
                        <div class="text-2xl font-bold text-accent">
                            @if($healthData['stats']['avg_response_time'])
                                {{ $healthData['stats']['avg_response_time'] }}ms
                            @else
                                --
                            @endif
                        </div>
                        <div class="text-sm text-tertiary">Avg Response</div>
                    </div>
                </div>
            @endif
        </div>

        @if(!empty($healthData['services']))
            <div class="divide-y divide-default">
                @php
                    $servicesByType = collect($healthData['services'])->groupBy('type');
                @endphp

                @foreach(['infrastructure', 'search', 'ai', 'processing', 'realtime', 'mcp'] as $type)
                    @if(isset($servicesByType[$type]))
                        <div class="p-4">
                            <h4 class="text-sm font-medium text-primary mb-3 capitalize">
                                {{ str_replace('_', ' ', $type) }} Services
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                @foreach($servicesByType[$type] as $service)
                                    <div class="flex items-center justify-between p-3 bg-surface rounded-lg min-w-0 border border-subtle">
                                        <div class="flex items-center space-x-3">
                                            <div class="flex-shrink-0">
                                                <div class="w-2.5 h-2.5 rounded-full {{
                                                    $service['status'] === 'healthy' ? 'bg-accent' :
                                                    ($service['status'] === 'warning' ? 'bg-[var(--palette-warning-500)]' : 'bg-[var(--palette-error-500)]')
                                                }}"></div>
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <p class="text-sm font-medium text-primary">
                                                    {{ $service['name'] }}
                                                </p>
                                                <p class="text-xs text-tertiary truncate" title="{{ $service['message'] }}">
                                                    {{ Str::limit($service['message'], 60) }}
                                                </p>
                                                @if(!empty($service['error']))
                                                    <div class="text-xs text-error mt-1 max-w-full">
                                                        <p class="truncate break-words overflow-hidden" title="{{ $service['error'] }}">
                                                            Error: {{ Str::limit($service['error'], 35) }}
                                                        </p>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="flex-shrink-0 text-right">
                                            @if($service['response_time'])
                                                <div class="text-xs text-tertiary">
                                                    {{ $service['response_time'] }}ms
                                                </div>
                                            @endif


                                            {{-- Details popover trigger --}}
                                            @if(!empty($service['details']))
                                                <div class="relative inline-block" x-data="{ open: false }">
                                                    <button
                                                        @click="open = !open"
                                                        class="text-xs text-accent hover:text-accent-hover mt-1"
                                                    >
                                                        Details
                                                    </button>
                                                    <div x-show="open"
                                                         x-transition
                                                         @click.away="open = false"
                                                         class="absolute right-0 top-full mt-2 w-80 max-w-[90vw] p-3 text-xs bg-surface-elevated border border-default rounded-lg shadow-xl z-50 overflow-hidden"
                                                         style="min-width: 280px;">
                                                        @foreach($service['details'] as $key => $value)
                                                            @if(is_string($value) || is_numeric($value))
                                                                <div class="flex justify-between py-1 border-b border-subtle last:border-b-0">
                                                                    <span class="text-tertiary font-medium flex-shrink-0">{{ ucwords(str_replace('_', ' ', $key)) }}:</span>
                                                                    <span class="text-primary font-mono ml-2 truncate text-right" title="{{ $value }}">{{ $value }}</span>
                                                                </div>
                                                            @elseif(is_array($value))
                                                                <div class="py-1 border-b border-subtle last:border-b-0">
                                                                    <div class="text-tertiary font-medium">{{ ucwords(str_replace('_', ' ', $key)) }}:</div>
                                                                    <div class="ml-2 text-primary mt-1 truncate" title="{{ is_array($value) ? implode(', ', $value) : '' }}">
                                                                        @if(isset($value[0]))
                                                                            {{ implode(', ', array_slice($value, 0, 3)) }}
                                                                            @if(count($value) > 3)...@endif
                                                                        @else
                                                                            {{ count($value) }} items
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                            @endif
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</div>