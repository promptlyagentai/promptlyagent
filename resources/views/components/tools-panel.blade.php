@props(['showToolsPanel', 'availableTools', 'enabledTools', 'availableServers', 'enabledServers', 'toolOverrideEnabled' => false, 'toolOverrides' => [], 'serverOverrides' => []])

@if($showToolsPanel)
    <div class="border-b border-default bg-surface">
        <div class="px-4 py-3">
            <h3 class="font-semibold text-sm text-primary mb-3">Available Tools</h3>
            
            @if($toolOverrideEnabled)
                <div class="mb-3 p-2 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-md">
                    <div class="flex items-center gap-2 text-orange-800 dark:text-orange-200">
                        <div class="text-xs font-medium">⚠ Temporary Override Active</div>
                    </div>
                    <div class="text-xs text-orange-600 dark:text-orange-300 mt-1">
                        Tool changes will only apply to your next chat interaction and won't modify agent defaults.
                    </div>
                </div>
            @else
                <div class="mb-3 text-xs text-tertiary">
                    Showing agent's configured tools (read-only)
                </div>
            @endif
            
            <div class="space-y-3">
                {{-- Local Tools (individual control) --}}
                @foreach($availableTools as $tool)
                    @if($tool['source'] === 'local')
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <div class="text-sm font-medium text-primary truncate">
                                        {{ Str::limit($tool['name'], 20) }}
                                    </div>
                                    <span class="text-xs px-2 py-1 rounded-full flex-shrink-0 bg-[var(--palette-success-100)] text-[var(--palette-success-800)] dark:bg-[var(--palette-success-900)] dark:text-[var(--palette-success-200)]">
                                        local
                                    </span>
                                </div>
                                <div class="text-xs text-tertiary truncate">
                                    {{ Str::limit($tool['description'], 40) }}
                                </div>
                            </div>
                            <label class="relative inline-flex items-center {{ $toolOverrideEnabled ? 'cursor-pointer' : 'cursor-default' }} flex-shrink-0">
                                <input
                                    type="checkbox"
                                    @if($toolOverrideEnabled)
                                        wire:click="toggleTool('{{ $tool['name'] }}')"
                                        {{ in_array($tool['name'], $toolOverrides) ? 'checked' : '' }}
                                    @else
                                        {{ in_array($tool['name'], $enabledTools) ? 'checked' : '' }}
                                        disabled
                                    @endif
                                    class="sr-only peer"
                                >
                                <div class="w-9 h-5 {{ $toolOverrideEnabled ? 'bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-accent' : 'bg-gray-300 dark:bg-gray-600' }} rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all dark:border-gray-600 {{ $toolOverrideEnabled ? 'peer-checked:bg-accent' : 'peer-checked:bg-gray-500' }}"></div>
                            </label>
                        </div>
                    @endif
                @endforeach
                
                {{-- MCP Servers (server-level control) --}}
                @foreach($availableServers as $server)
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <div class="text-sm font-medium text-primary truncate">
                                    {{ Str::limit($server['name'], 20) }}
                                </div>
                                @if(str_contains($server['name'], 'ERROR'))
                                    <span class="text-xs px-2 py-1 rounded-full flex-shrink-0 bg-[var(--palette-error-100)] text-[var(--palette-error-800)] dark:bg-[var(--palette-error-900)] dark:text-[var(--palette-error-200)]">
                                        ERROR
                                    </span>
                                @else
                                    <span class="text-xs px-2 py-1 rounded-full flex-shrink-0 bg-surface border border-default text-accent">
                                        MCP
                                    </span>
                                @endif
                            </div>
                            <div class="text-xs text-tertiary truncate">
                                @if(isset($server['error']))
                                    Error: {{ Str::limit($server['error'], 50) }}
                                @else
                                    {{ $server['toolCount'] }} tool{{ $server['toolCount'] === 1 ? '' : 's' }} available
                                @endif
                            </div>
                        </div>
                        @if(!str_contains($server['name'], 'ERROR'))
                            <label class="relative inline-flex items-center {{ $toolOverrideEnabled ? 'cursor-pointer' : 'cursor-default' }} flex-shrink-0">
                                <input
                                    type="checkbox"
                                    @if($toolOverrideEnabled)
                                        wire:click="toggleServer('{{ $server['name'] }}')"
                                        {{ in_array($server['name'], $serverOverrides) ? 'checked' : '' }}
                                    @else
                                        {{ in_array($server['name'], $enabledServers) ? 'checked' : '' }}
                                        disabled
                                    @endif
                                    class="sr-only peer"
                                >
                                <div class="w-9 h-5 {{ $toolOverrideEnabled ? 'bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-accent' : 'bg-gray-300 dark:bg-gray-600' }} rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all dark:border-gray-600 {{ $toolOverrideEnabled ? 'peer-checked:bg-accent' : 'peer-checked:bg-gray-500' }}"></div>
                            </label>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    </div>
@endif 