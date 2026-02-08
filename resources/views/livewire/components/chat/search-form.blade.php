{{--
    Chat Search Form Component

    Purpose: Main input form for chat research interface with agent selection and tool configuration

    Features:
    - Agent/mode selection dropdown (Chat with AI, Promptly, Deeply, workflows, agents)
    - Tool override system for temporary configuration changes
    - File attachment support (upload + camera + paste)
    - MCP server management
    - Auto-resizing textarea
    - Query optimization with AI

    Component Props:
    - @props string $selectedAgent Currently selected agent ID or mode
    - @props array $availableAgents List of available agents
    - @props bool $toolOverrideEnabled Whether tool overrides are active
    - @props array $availableTools Local tools available for configuration
    - @props array $availableServers MCP servers available for configuration
    - @props array $enabledTools Tools enabled for selected agent
    - @props array $enabledServers MCP servers enabled for selected agent
    - @props array $toolOverrides User's temporary tool overrides
    - @props array $serverOverrides User's temporary server overrides
    - @props array $researchAgents Agent display names for dropdown
    - @props array $attachments Currently attached files
    - @props string $query Current query text

    Tool Override System:
    - When disabled: Shows agent's configured tools (read-only)
    - When enabled: Allows temporary per-interaction tool changes
    - Overrides don't modify agent defaults, only current interaction

    Keyboard Shortcuts:
    - Enter: Submit query
    - Shift+Enter: New line in textarea
    - Ctrl+V (with image): Paste image directly as attachment

    Alpine.js Functions:
    - handlePaste(): Detects and processes pasted images from clipboard
    - autoResize(): Dynamically resizes textarea based on content
--}}
@props([
    'selectedAgent',
    'availableAgents',
    'toolOverrideEnabled',
    'availableTools',
    'availableServers',
    'enabledTools',
    'enabledServers',
    'toolOverrides',
    'serverOverrides',
    'researchAgents',
    'attachments',
    'query'
])

{{-- Fixed Bottom Search Form --}}
<div class="flex-shrink-0 border-t border-default pt-4 mt-4">
    <form wire:submit.prevent="startSearch" class="flex w-full gap-2 items-end"
          x-data="{
              handlePaste(event) {
                  const items = event.clipboardData?.items;
                  if (!items) return;

                  for (const item of items) {
                      if (item.type.indexOf('image') !== -1) {
                          event.preventDefault();
                          const file = item.getAsFile();
                          if (file) {
                              // Create unique filename
                              const timestamp = Date.now();
                              const extension = file.type.split('/')[1] || 'png';
                              const fileName = `pasted-image-${timestamp}.${extension}`;

                              // Create a new File object with proper name
                              const renamedFile = new File([file], fileName, { type: file.type });

                              // Upload via Livewire (single file upload for pasted images)
                              $wire.upload('attachments', renamedFile, (uploadedFilename) => {
                                  console.log('Pasted image uploaded:', uploadedFilename);
                              });

                              // Show notification
                              $dispatch('notify', {
                                  message: 'Image pasted from clipboard',
                                  type: 'success'
                              });
                          }
                      }
                  }
              }
          }"
          @paste.window="handlePaste($event)">
        <!-- Settings dropdown (appears for Direct Chat and individual agents) -->
        @if($selectedAgent === 'directly' || in_array($selectedAgent, array_column(array_filter($availableAgents, fn($agent) => $agent['agent_type'] === 'individual'), 'id')))
            <div class="flex-shrink-0" x-data="{ open: false }" @click.outside="open = false">
                <div class="relative">
                    <button
                        type="button"
                        @click="open = !open"
                        class="p-2 text-tertiary hover:text-primary rounded-lg hover:bg-surface"
                        title="Tool Settings">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </button>

                    <!-- Tools dropdown -->
                    <div
                        x-show="open"
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 scale-95"
                        x-transition:enter-end="opacity-100 scale-100"
                        x-transition:leave="transition ease-in duration-150"
                        x-transition:leave-start="opacity-100 scale-100"
                        x-transition:leave-end="opacity-0 scale-95"
                        class="absolute bottom-full mb-2 w-80 bg-surface-elevated border border-default rounded-lg shadow-lg z-50"
                        x-cloak
                    >
                        <div class="p-3 border-b border-default ">
                            <div class="flex items-center justify-between">
                                <h4 class="text-sm font-medium text-primary ">Tool Settings</h4>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-tertiary">Override</span>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input
                                            type="checkbox"
                                            wire:click="toggleToolOverride"
                                            {{ $toolOverrideEnabled ? 'checked' : '' }}
                                            class="sr-only peer"
                                        >
                                        <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-accent rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all dark:border-gray-600 peer-checked:bg-orange-500"></div>
                                    </label>
                                </div>
                            </div>
                            @if($toolOverrideEnabled)
                                <div class="mt-2 p-2 bg-warning rounded text-xs text-warning-contrast">
                                    <div class="flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                                        </svg>
                                        <span class="font-medium">Temporary Override Active</span>
                                    </div>
                                    <div class="mt-1">Tool changes will only apply to your next chat interaction and won't modify agent defaults.</div>
                                </div>
                            @else
                                <div class="mt-2 p-2 bg-accent/10 rounded text-xs text-accent">
                                    Showing agent's configured tools (read-only)
                                </div>
                            @endif
                        </div>

                        <div class="p-3">
                            <div class="space-y-3 max-h-64 overflow-y-auto">
                                {{-- Local Tools (individual control) --}}
                                @foreach($availableTools as $tool)
                                    @if($tool['source'] === 'local')
                                        <div class="flex items-center justify-between gap-2" wire:key="tool-{{ $tool['name'] }}-{{ $selectedAgent }}-{{ $toolOverrideEnabled ? 'override' : 'agent' }}">
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2">
                                                    <div class="text-sm font-medium text-primary  truncate">
                                                        {{ Str::limit($tool['name'], 20) }}
                                                    </div>
                                                    <span class="text-xs px-2 py-1 rounded-full flex-shrink-0 bg-success text-success-contrast">
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
                                                <div class="w-9 h-5 {{ $toolOverrideEnabled ? 'bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-accent' : 'bg-gray-300 dark:bg-gray-600' }} rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all dark:border-gray-600 {{ $toolOverrideEnabled ? 'peer-checked:bg-orange-500' : 'peer-checked:bg-gray-500' }}"></div>
                                            </label>
                                        </div>
                                    @endif
                                @endforeach

                                {{-- MCP Servers (server-level control) --}}
                                @foreach($availableServers as $server)
                                    <div class="flex items-center justify-between gap-2" wire:key="server-{{ $server['name'] }}-{{ $selectedAgent }}-{{ $toolOverrideEnabled ? 'override' : 'agent' }}">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2">
                                                <div class="text-sm font-medium text-primary  truncate">
                                                    {{ Str::limit($server['name'], 20) }}
                                                </div>
                                                @if(str_contains($server['name'], 'ERROR'))
                                                    <span class="text-xs px-2 py-1 rounded-full flex-shrink-0 bg-[var(--palette-error-200)] text-[var(--palette-error-900)]">
                                                        ERROR
                                                    </span>
                                                @else
                                                    <span class="text-xs px-2 py-1 rounded-full flex-shrink-0 bg-accent text-accent-foreground">
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
                                                <div class="w-9 h-5 {{ $toolOverrideEnabled ? 'bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-accent' : 'bg-gray-300 dark:bg-gray-600' }} rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all dark:border-gray-600 {{ $toolOverrideEnabled ? 'peer-checked:bg-orange-500' : 'peer-checked:bg-gray-500' }}"></div>
                                            </label>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        <!-- Agent Dropdown -->
        <div class="flex-shrink-0" x-data="{ open: false }" @click.outside="open = false">
            <div class="relative">
                <button type="button" @click="open = !open" class="flex items-center justify-between w-36 px-3 py-2 text-sm font-medium text-secondary bg-surface border border-default rounded-lg hover:opacity-90">
                    <span class="truncate">{{ $researchAgents[$selectedAgent] ?? 'Chat with AI' }}</span>
                    <svg class="w-4 h-4 text-tertiary flex-shrink-0" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                <!-- Dropdown content -->
                <div x-show="open" x-cloak
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95"
                     class="absolute bottom-full mb-2 w-80 bg-surface-elevated border border-default rounded-lg shadow-lg z-50">
                    <div class="p-3 border-b border-default ">
                        <h4 class="text-sm font-medium text-primary ">Select Mode</h4>
                    </div>
                    <div class="py-2">
                        <!-- Chat with AI (Directly Agent) Option -->
                        <button type="button"
                                wire:click="$set('selectedAgent', 'directly')"
                                @click="open = false"
                                class="w-full px-4 py-2 text-left hover:bg-surface flex items-center gap-3 {{ $selectedAgent === 'directly' ? 'bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300' : 'text-secondary' }}">
                            <svg class="w-5 h-5 text-accent" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155"/>
                            </svg>
                            <div class="flex-1 min-w-0">
                                <div class="font-medium">Chat with AI</div>
                                <div class="text-xs text-tertiary">Real-time chat with AI responses</div>
                            </div>
                            @if($selectedAgent === 'directly')
                                <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            @endif
                        </button>

                        <!-- Promptly Agent Option - DEFAULT -->
                        <button type="button"
                                wire:click="$set('selectedAgent', 'promptly')"
                                @click="open = false"
                                class="w-full px-4 py-2 text-left hover:bg-surface flex items-center gap-3 {{ ($selectedAgent === 'promptly' || !$selectedAgent) ? 'bg-accent/10 text-accent' : 'text-secondary ' }}">
                            <svg class="w-5 h-5 text-accent" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                            </svg>
                            <div class="flex-1 min-w-0">
                                <div class="font-medium">Promptly Agent</div>
                                <div class="text-xs text-tertiary">Intelligent Agent selection</div>
                            </div>
                            @if($selectedAgent === 'promptly' || !$selectedAgent)
                                <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            @endif
                        </button>

                        <!-- Deeply Agent Option -->
                        <button type="button"
                                wire:click="$set('selectedAgent', 'deeply')"
                                @click="open = false"
                                class="w-full px-4 py-2 text-left hover:bg-surface flex items-center gap-3 {{ $selectedAgent === 'deeply' ? 'bg-accent/10 text-accent' : 'text-secondary ' }}">
                            <svg class="w-5 h-5 text-accent" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 002.25-2.25V6.75a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 6.75v10.5a2.25 2.25 0 002.25 2.25zm.75-12h9v9h-9v-9z"/>
                            </svg>
                            <div class="flex-1 min-w-0">
                                <div class="font-medium">Deeply Agent</div>
                                <div class="text-xs text-tertiary">Fully automatic AI workflows</div>
                            </div>
                            @if($selectedAgent === 'deeply')
                                <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                </svg>
                            @endif
                        </button>

                        @if(count($researchAgents) > 1)
                            @php
                                $workflows = [];
                                $individualAgents = [];

                                foreach($availableAgents as $agent) {
                                    if ($agent['agent_type'] === 'workflow') {
                                        $workflows[] = $agent;
                                    } elseif ($agent['agent_type'] === 'individual') {
                                        $individualAgents[] = $agent;
                                    }
                                }
                            @endphp

                            @if(!empty($workflows))
                                <div class="border-t border-default  my-2"></div>
                                <div class="px-4 py-1">
                                    <div class="text-xs font-medium text-tertiary uppercase tracking-wider">Workflows</div>
                                </div>

                                @foreach($workflows as $agent)
                                    <button type="button"
                                            wire:click="$set('selectedAgent', '{{ $agent['id'] }}')"
                                            @click="open = false"
                                            class="w-full px-4 py-2 text-left hover:bg-surface flex items-center gap-3 {{ $selectedAgent == $agent['id'] ? 'bg-accent/10 text-accent' : 'text-secondary ' }}">
                                        <svg class="w-5 h-5 text-accent" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14-7l2 2-2 2m2-2H9m10 7l2 2-2 2m2-2H9"/>
                                        </svg>

                                        <div class="flex-1 min-w-0">
                                            <div class="font-medium truncate">{{ $agent['name'] }}</div>
                                            <div class="text-xs text-tertiary truncate">
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-accent/20 text-accent">
                                                    Workflow
                                                </span>
                                            </div>
                                        </div>
                                        @if($selectedAgent == $agent['id'])
                                            <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        @endif
                                    </button>
                                @endforeach
                            @endif

                            @if(!empty($individualAgents))
                                <div class="border-t border-default  my-2"></div>
                                <div class="px-4 py-1">
                                    <div class="text-xs font-medium text-tertiary uppercase tracking-wider">Agents</div>
                                </div>

                                @foreach($individualAgents as $agent)
                                    <button type="button"
                                            wire:click="$set('selectedAgent', '{{ $agent['id'] }}')"
                                            @click="open = false"
                                            class="w-full px-4 py-2 text-left hover:bg-surface flex items-center gap-3 {{ $selectedAgent == $agent['id'] ? 'bg-success text-success-contrast' : 'text-secondary ' }}">
                                        <svg class="w-5 h-5 text-accent" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z"/>
                                        </svg>

                                        <div class="flex-1 min-w-0">
                                            <div class="font-medium truncate">{{ $agent['name'] }}</div>
                                            <div class="text-xs text-tertiary truncate">
                                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-success text-success-contrast">
                                                    Agent
                                                </span>
                                            </div>
                                        </div>
                                        @if($selectedAgent == $agent['id'])
                                            <svg class="w-4 h-4 text-accent" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        @endif
                                    </button>
                                @endforeach
                            @endif
                        @else
                            <div class="px-4 py-3 text-sm text-tertiary">
                                No agents available
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <!-- File Upload Button with Dropdown -->
        <div class="flex-shrink-0 relative"
             x-data="{
                 showUpload: false,
                 selectedFiles: @entangle('attachments'),
                 uploading: false,
                 async handleFileSelect(event) {
                     const files = Array.from(event.target.files);
                     console.log('Files selected:', files.length);

                     if (files.length === 0) return;

                     this.uploading = true;

                     // Upload files sequentially to S3 (one at a time)
                     for (const file of files) {
                         try {
                             await new Promise((resolve, reject) => {
                                 $wire.upload('attachments', file,
                                     (uploadedFilename) => {
                                         console.log('File uploaded:', uploadedFilename);
                                         resolve();
                                     },
                                     (error) => {
                                         console.error('Upload failed:', error);
                                         reject(error);
                                     }
                                 );
                             });
                         } catch (error) {
                             console.error('Error uploading file:', file.name, error);
                             $dispatch('notify', {
                                 message: `Failed to upload ${file.name}`,
                                 type: 'error'
                             });
                         }
                     }

                     this.uploading = false;

                     // Clear the file input
                     event.target.value = '';
                 }
             }"
             @click.outside="showUpload = false">
            <button type="button" @click="showUpload = !showUpload"
                    class="p-2 text-secondary hover:text-primary rounded-lg hover:bg-surface"
                    title="Attach file (or paste image with Ctrl+V)">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                </svg>
                @if($attachments && count($attachments) > 0)
                    <div class="absolute -top-1 -right-1 w-3 h-3 bg-accent rounded-full flex items-center justify-center">
                        <span class="text-xs text-white font-medium">{{ count($attachments) }}</span>
                    </div>
                @endif
            </button>

            <!-- File upload dropdown -->
            <div x-show="showUpload"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95"
                 class="absolute bottom-full mb-2 left-0 w-80 bg-surface-elevated border border-default rounded-lg shadow-lg z-50"
                 x-cloak>
                <div class="p-4">
                    <h4 class="text-sm font-medium text-primary mb-3">Attach File</h4>
                    <div class="file-upload-area">
                        <input type="file"
                               @change="handleFileSelect($event)"
                               multiple
                               accept="image/jpeg,image/jpg,image/png,image/gif,image/webp,application/pdf,text/plain,text/markdown,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,audio/mpeg,audio/wav,audio/aac,audio/flac,video/mp4,video/mov,video/webm,video/avi"
                               class="w-full p-2 text-sm text-tertiary  bg-transparent border-0 focus:outline-none"
                               id="file-upload"
                               x-ref="fileInput">
                        <div class="text-xs text-tertiary  mt-2">
                            Max 10MB per file. Supports multiple files: images, documents, audio, and video.
                        </div>
                    </div>

                    @if($attachments)
                        <div class="mt-3">
                            <div class="text-xs font-medium text-tertiary  mb-2">Selected {{ count($attachments) === 1 ? 'file' : 'files' }}:</div>
                            @foreach($attachments as $index => $attachment)
                                <div class="flex items-center justify-between py-1 px-2 hover:bg-surface  rounded">
                                    <div class="text-xs text-tertiary  flex items-center gap-2 flex-1 min-w-0">
                                        <span>📎</span>
                                        <span class="truncate">{{ $attachment->getClientOriginalName() }}</span>
                                        <span class="text-tertiary flex-shrink-0">({{ number_format($attachment->getSize() / 1024, 1) }}KB)</span>
                                    </div>
                                    <button type="button"
                                            wire:click="removeAttachment({{ $index }})"
                                            class="ml-2 p-1 text-tertiary hover:text-error rounded transition-colors"
                                            title="Remove attachment">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="flex-1 relative self-end" x-data="{
            autoResize() {
                const textarea = $refs.queryInput;
                const wrapper = $refs.textareaWrapper;
                if (!textarea || !wrapper) return;

                // Reset height to auto to get accurate scrollHeight
                textarea.style.height = 'auto';

                // Calculate new height with max of 200px
                const newHeight = Math.min(textarea.scrollHeight, 200);
                const minHeight = 42;

                // Calculate how much extra height we have
                const extraHeight = newHeight - minHeight;

                if (extraHeight > 0) {
                    // Use negative margin to grow upward without affecting layout
                    wrapper.style.marginTop = `-${extraHeight}px`;
                    wrapper.style.position = 'relative';
                    wrapper.style.zIndex = '50';
                } else {
                    // Reset when back to normal size
                    wrapper.style.marginTop = '0';
                    wrapper.style.position = 'static';
                    wrapper.style.zIndex = 'auto';
                }

                // Set the textarea height
                textarea.style.height = newHeight + 'px';
            },
            init() {
                // Initial resize on load
                this.$nextTick(() => this.autoResize());

                // Watch for programmatic changes to the textarea value
                // Add null check to prevent errors when ref isn't available yet
                this.$watch('$refs.queryInput', (value) => {
                    if (value) {
                        this.$nextTick(() => this.autoResize());
                    }
                });
            }
        }"
             x-init="
                // Also listen for Livewire updates
                $wire.$watch('query', () => {
                    $nextTick(() => autoResize());
                });
             ">
            <div x-ref="textareaWrapper" class="flex items-end transition-all" style="min-height: 40px;">
                <textarea
                    x-ref="queryInput"
                    wire:model="query"
                    @input="autoResize()"
                    @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.startSearch(); }"
                    placeholder="Enter your query (Shift+Enter for new line)"
                    rows="1"
                    class="w-full rounded-lg border-0 bg-surface text-primary px-4 text-sm ring-1 ring-default focus:ring-2 focus:ring-accent resize-none overflow-y-auto shadow-lg"
                    style="min-height: 40px; padding-top: 10px; padding-bottom: 10px; line-height: 20px;"
                ></textarea>
            </div>
        </div>

        <!-- Optimize Query Button -->
        <div class="flex-shrink-0">
            <button type="button"
                    wire:click="optimizeQuery"
                    wire:loading.attr="disabled"
                    wire:target="optimizeQuery"
                    class="p-2 text-secondary hover:text-primary rounded-lg hover:bg-surface disabled:opacity-50 disabled:cursor-not-allowed"
                    title="Optimize query for selected agent mode">
                <!-- Sparkles icon -->
                <svg wire:loading.remove wire:target="optimizeQuery" class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z"/>
                </svg>
                <!-- Loading spinner -->
                <svg wire:loading wire:target="optimizeQuery" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            </button>
        </div>

        <flux:button type="submit" variant="primary" size="base" wire:loading.attr="disabled">Submit</flux:button>
    </form>
</div>
