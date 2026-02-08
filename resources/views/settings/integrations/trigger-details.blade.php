<x-layouts.app>
    <section class="w-full pb-20 xl:pb-0">
        @include('partials.settings-heading')

        <x-settings.layout
            :heading="'Configure ' . $provider->getTriggerTypeName() . ' Trigger'"
            :subheading="$trigger->name"
            wide>

            {{-- Success/Error Messages --}}
            @if (session('success'))
                <div class="mb-6 rounded-lg border border-[var(--palette-success-200)] bg-[var(--palette-success-100)] p-4">
                    <div class="flex items-center">
                        <svg class="h-5 w-5 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="ml-3 text-sm text-[var(--palette-success-800)]">{{ session('success') }}</span>
                    </div>
                </div>
            @endif

            @if (session('error'))
                <div class="mb-6 rounded-lg border border-[var(--palette-error-200)] bg-[var(--palette-error-100)] p-4">
                    <div class="flex items-center">
                        <svg class="h-5 w-5 text-[var(--palette-error-700)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="ml-3 text-sm text-[var(--palette-error-800)]">{{ session('error') }}</span>
                    </div>
                </div>
            @endif

            {{-- Responsive Grid Container --}}
            <div class="space-y-6 xl:grid xl:grid-cols-12 xl:gap-6 xl:space-y-0">

                {{-- LEFT COLUMN - Edit Form (60%) --}}
                <div class="space-y-6 xl:col-span-7">

                    {{-- Edit Form Card --}}
                    <div class="rounded-lg border border-default bg-surface p-6  ">
                        <form id="trigger-edit-form" action="{{ route('integrations.update-trigger', $trigger) }}" method="POST" class="space-y-6" x-data="{
                            selectedAgentType: '{{ $trigger->agent->agent_type ?? '' }}',
                            selectedCommand: '{{ $trigger->command_class ?? '' }}',
                            triggerTargetType: '{{ $trigger->trigger_target_type }}',
                            commandParameters: @js($triggerableCommands ?? [])
                        }">
                            @csrf
                            @method('PATCH')

                            <div class="flex items-center gap-2 mb-6">
                                @if($svg = $provider->getTriggerIconSvg())
                                    <div class="h-6 w-6 text-secondary ">{!! $svg !!}</div>
                                @else
                                    <span class="text-2xl">{{ $provider->getTriggerIcon() }}</span>
                                @endif
                                <flux:heading size="lg">Edit Trigger</flux:heading>
                            </div>

                            {{-- Trigger Target Type (Display Only) --}}
                            <div>
                                <flux:text size="sm" class="font-medium text-tertiary">Trigger Type</flux:text>
                                <div class="mt-2 rounded-lg border border-default bg-surface-elevated px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        @if($trigger->trigger_target_type === 'agent')
                                            <svg class="h-5 w-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                            </svg>
                                            <span class="font-medium text-primary">Agent Execution</span>
                                        @else
                                            <svg class="h-5 w-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                            </svg>
                                            <span class="font-medium text-primary">Command Execution</span>
                                        @endif
                                    </div>
                                    <flux:text size="sm" class="mt-1 text-tertiary">Trigger type cannot be changed after creation</flux:text>
                                </div>
                            </div>

                            {{-- Trigger Name --}}
                            <div>
                                <flux:input
                                    name="name"
                                    label="Trigger Name"
                                    value="{{ old('name', $trigger->name) }}"
                                    required />
                                @error('name')
                                    <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                                @enderror
                            </div>

                            {{-- Description --}}
                            <div>
                                <flux:textarea
                                    name="description"
                                    label="Description (Optional)"
                                    rows="3">{{ old('description', $trigger->description) }}</flux:textarea>
                                @error('description')
                                    <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                                @enderror
                            </div>

                            {{-- Command Display (for command triggers - read only) --}}
                            @if($trigger->trigger_target_type === 'command')
                            <div x-show="triggerTargetType === 'command'">
                                @php
                                    $commandRegistry = app(\App\Services\InputTrigger\TriggerableCommandRegistry::class);
                                    $commandDef = $commandRegistry->getByClass($trigger->command_class);
                                @endphp
                                <flux:text size="sm" class="font-medium text-tertiary">Command</flux:text>
                                <div class="mt-2 rounded-lg border border-default bg-surface-elevated px-4 py-3">
                                    <div class="font-medium text-primary">{{ $commandDef['name'] ?? 'Unknown Command' }}</div>
                                    <flux:text size="sm" class="mt-1 text-tertiary">{{ $commandDef['description'] ?? '' }}</flux:text>
                                </div>
                                <flux:text size="sm" class="mt-1 text-tertiary">Command cannot be changed after trigger creation</flux:text>
                            </div>

                            {{-- Command Parameters (for command triggers) --}}
                            @if($trigger->trigger_target_type === 'command' && !empty($commandDef['parameters']))
                            <div x-show="triggerTargetType === 'command'">
                                <flux:text size="sm" class="font-medium text-tertiary mb-3">Command Parameters</flux:text>
                                <div class="space-y-4">
                                    @foreach($commandDef['parameters'] as $paramName => $paramDef)
                                        <div>
                                            <label class="block text-sm font-medium text-primary mb-2">
                                                {{ $paramName }}
                                                @if($paramDef['required'] ?? false)
                                                    <span class="text-error">*</span>
                                                @endif
                                            </label>

                                            @php
                                                $currentValue = old("command_parameters.{$paramName}", $trigger->command_parameters[$paramName] ?? ($paramDef['default'] ?? ''));
                                                if (is_array($currentValue)) {
                                                    $currentValue = implode("\n", $currentValue);
                                                }
                                            @endphp

                                            {{-- Array type: Use textarea with one item per line --}}
                                            @if(($paramDef['type'] ?? 'string') === 'array')
                                                <textarea
                                                    name="command_parameters[{{ $paramName }}]"
                                                    placeholder="{{ $paramDef['placeholder'] ?? 'Enter one item per line' }}"
                                                    rows="3"
                                                    @if($paramDef['required'] ?? false) required @endif
                                                    class="w-full rounded-lg border border-default bg-surface px-3 py-2 text-sm text-primary placeholder-secondary focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent">{{ $currentValue }}</textarea>
                                                <p class="mt-1 text-xs text-secondary">Enter one item per line</p>
                                            @else
                                                {{-- Other types: Use regular input --}}
                                                <input
                                                    type="{{ ($paramDef['type'] ?? 'string') === 'integer' ? 'number' : 'text' }}"
                                                    name="command_parameters[{{ $paramName }}]"
                                                    placeholder="{{ $paramDef['placeholder'] ?? $paramDef['description'] ?? '' }}"
                                                    value="{{ $currentValue }}"
                                                    @if($paramDef['required'] ?? false) required @endif
                                                    class="w-full rounded-lg border border-default bg-surface px-3 py-2 text-sm text-primary placeholder-secondary focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                                            @endif

                                            @if(!empty($paramDef['description']))
                                                <p class="mt-1 text-sm text-secondary">{{ $paramDef['description'] }}</p>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                            @endif
                            @endif

                            {{-- Agent Selection (for agent triggers only) --}}
                            @if($trigger->trigger_target_type === 'agent')
                            <div x-show="triggerTargetType === 'agent'">
                                @php
                                    $agents = \App\Models\Agent::where('status', 'active')
                                        ->orderBy('agent_type')
                                        ->orderBy('name')
                                        ->get();
                                @endphp
                                <flux:select name="agent_id" label="Agent" @change="selectedAgentType = $event.target.selectedOptions[0]?.dataset.agentType || ''">
                                    <option value="" {{ old('agent_id', $trigger->agent_id) == '' ? 'selected' : '' }}>Not configured (allow runtime override)</option>
                                    @foreach($agents as $agent)
                                        <option value="{{ $agent->id }}" data-agent-type="{{ $agent->agent_type }}" {{ old('agent_id', $trigger->agent_id) == $agent->id ? 'selected' : '' }}>
                                            {{ $agent->name }} ({{ ucfirst($agent->agent_type) }})
                                        </option>
                                    @endforeach
                                </flux:select>
                                <flux:text size="sm" class="mt-1 text-tertiary ">
                                    Select "Not configured" to allow agent_id to be specified in webhook/API requests.
                                </flux:text>
                                <flux:text size="sm" class="mt-1 text-tertiary ">
                                    <strong>Note: When set to a specific agent, it cannot be overridden by request parameters.</strong>
                                </flux:text>
                                @error('agent_id')
                                    <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                                @enderror
                            </div>

                            {{-- Workflow Configuration (conditional - only for workflow agents) --}}
                            <div x-show="triggerTargetType === 'agent' && selectedAgentType === 'workflow'" x-cloak>
                                <flux:textarea
                                    name="workflow_config"
                                    label="Workflow Configuration (Optional)"
                                    placeholder="{{ json_encode(['agents' => [['id' => 1, 'execution_order' => 1]], 'orchestration_mode' => 'sequential']) }}"
                                    rows="6">{{ old('workflow_config', isset($trigger->config['workflow_config']) ? json_encode($trigger->config['workflow_config']) : '') }}</flux:textarea>
                                <flux:text size="sm" class="mt-1 text-tertiary ">
                                    JSON workflow configuration. <strong>Leave empty to allow workflow to be specified in webhook/API requests.</strong>
                                </flux:text>
                                <flux:text size="sm" class="mt-1 text-tertiary ">
                                    <strong>Note: When set, this workflow cannot be overridden by request parameters.</strong>
                                </flux:text>
                                @error('workflow_config')
                                    <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                                @enderror
                            </div>

                            {{-- Session Strategy (for agent triggers only) --}}
                            <div x-show="triggerTargetType === 'agent'" @if($trigger->trigger_target_type !== 'agent') x-cloak @endif>
                                <flux:select name="session_strategy" label="Session Strategy">
                                    <option value="" {{ old('session_strategy', $trigger->session_strategy) === '' || old('session_strategy', $trigger->session_strategy) === null ? 'selected' : '' }}>
                                        Not configured (allow runtime override)
                                    </option>
                                    <option value="new_each" {{ old('session_strategy', $trigger->session_strategy) === 'new_each' ? 'selected' : '' }}>
                                        New Session Each Time - Create a new chat session for every trigger invocation
                                    </option>
                                    <option value="continue_last" {{ old('session_strategy', $trigger->session_strategy) === 'continue_last' ? 'selected' : '' }}>
                                        Continue Last Session - Continue the most recent session from this trigger (maintains conversation context)
                                    </option>
                                </flux:select>
                                <flux:text size="sm" class="mt-1 text-tertiary ">
                                    Select "Not configured" to allow session_strategy to be specified in webhook/API requests.
                                </flux:text>
                                <flux:text size="sm" class="mt-1 text-tertiary ">
                                    <strong>Note: When set, this strategy cannot be overridden by request parameters (though session_id can still be provided).</strong>
                                </flux:text>
                                @error('session_strategy')
                                    <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                                @enderror
                            </div>
                            @endif

                            {{-- Provider-specific configuration --}}
                            @if(method_exists($provider, 'getConfigFormView'))
                                @include($provider->getConfigFormView())
                            @endif

                            {{-- HTTP Security Features (only for HTTP-triggered providers) --}}
                            @if(!method_exists($provider, 'requiresHttpSecurity') || $provider->requiresHttpSecurity())
                            {{-- Rate Limits --}}
                            <div>
                                <flux:fieldset>
                                    <flux:legend>Rate Limits</flux:legend>
                                    <flux:description>Protect your agent from excessive invocations</flux:description>

                                    <div class="mt-3 grid grid-cols-2 gap-4">
                                        <flux:input
                                            type="number"
                                            name="rate_limits[per_minute]"
                                            label="Per Minute"
                                            value="{{ old('rate_limits.per_minute', $trigger->rate_limits['per_minute'] ?? 10) }}"
                                            min="1"
                                            max="100" />

                                        <flux:input
                                            type="number"
                                            name="rate_limits[per_hour]"
                                            label="Per Hour"
                                            value="{{ old('rate_limits.per_hour', $trigger->rate_limits['per_hour'] ?? 100) }}"
                                            min="1"
                                            max="1000" />
                                    </div>
                                </flux:fieldset>
                            </div>

                            {{-- IP Whitelist --}}
                            <div x-data="{
                                ipRanges: {{ json_encode(old('ip_whitelist', $trigger->ip_whitelist ?? [])) }},
                                newIp: '',
                                addIp() {
                                    const ip = this.newIp.trim();
                                    if (!ip) return;

                                    // Basic CIDR validation
                                    const cidrPattern = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)(?:\/([0-9]|[1-2][0-9]|3[0-2]))?$|^(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}(?:\/([0-9]|[1-9][0-9]|1[01][0-9]|12[0-8]))?$/;

                                    if (!cidrPattern.test(ip)) {
                                        alert('Invalid IP address or CIDR range format. Examples: 192.168.1.1, 10.0.0.0/24, 2001:db8::/32');
                                        return;
                                    }

                                    if (this.ipRanges.includes(ip)) {
                                        alert('This IP range is already in the list');
                                        return;
                                    }

                                    if (this.ipRanges.length >= 50) {
                                        alert('Maximum of 50 IP ranges allowed');
                                        return;
                                    }

                                    this.ipRanges.push(ip);
                                    this.newIp = '';
                                },
                                removeIp(index) {
                                    this.ipRanges.splice(index, 1);
                                }
                            }">
                                <flux:fieldset>
                                    <flux:legend>IP Whitelist (Optional)</flux:legend>
                                    <flux:description>Restrict access to specific IP addresses or CIDR ranges. Leave empty to allow all IPs.</flux:description>

                                    <div class="mt-3 space-y-3">
                                        {{-- Add IP Input --}}
                                        <div class="flex gap-2">
                                            <div class="flex-1">
                                                <flux:input
                                                    x-model="newIp"
                                                    @keydown.enter.prevent="addIp()"
                                                    placeholder="192.168.1.1 or 10.0.0.0/24"
                                                    label="Add IP Range" />
                                            </div>
                                            <div class="self-end">
                                                <button type="button"
                                                        @click="addIp()"
                                                        class="inline-flex items-center gap-1 rounded-lg bg-accent px-3 py-2 text-sm font-medium text-white hover:bg-accent dark:bg-accent dark:hover:bg-accent">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                                    </svg>
                                                    Add
                                                </button>
                                            </div>
                                        </div>

                                        {{-- IP List --}}
                                        <template x-if="ipRanges.length > 0">
                                            <div class="rounded-lg border border-default ">
                                                <div class="divide-y divide-default ">
                                                    <template x-for="(ip, index) in ipRanges" :key="index">
                                                        <div class="flex items-center justify-between px-3 py-2">
                                                            <span class="font-mono text-sm text-primary " x-text="ip"></span>
                                                            <button type="button"
                                                                    @click="removeIp(index)"
                                                                    class="text-[var(--palette-error-700)] hover:text-[var(--palette-error-800)] dark:hover:text-[var(--palette-error-300)]">
                                                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                                                </svg>
                                                            </button>
                                                            <input type="hidden" :name="'ip_whitelist[' + index + ']'" :value="ip">
                                                        </div>
                                                    </template>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- Empty State --}}
                                        <template x-if="ipRanges.length === 0">
                                            <div class="rounded-lg border border-dashed border-default bg-surface px-4 py-3 text-center  /50">
                                                <p class="text-sm text-tertiary ">
                                                    No IP restrictions configured. All IP addresses are allowed.
                                                </p>
                                            </div>
                                        </template>

                                        {{-- Help Text --}}
                                        <flux:text size="sm" class="text-tertiary ">
                                            <strong>Examples:</strong> 192.168.1.1 (single IP), 10.0.0.0/24 (IPv4 range), 2001:db8::/32 (IPv6 range)
                                        </flux:text>
                                    </div>
                                </flux:fieldset>
                                @error('ip_whitelist')
                                    <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                                @enderror
                            </div>
                            @endif

                            {{-- Status --}}
                            <div>
                                <flux:select name="status" label="Status" required>
                                    <option value="active" {{ old('status', $trigger->status) === 'active' ? 'selected' : '' }}>Active</option>
                                    <option value="paused" {{ old('status', $trigger->status) === 'paused' ? 'selected' : '' }}>Paused</option>
                                    <option value="disabled" {{ old('status', $trigger->status) === 'disabled' ? 'selected' : '' }}>Disabled</option>
                                </flux:select>
                                @error('status')
                                    <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                                @enderror
                            </div>

                        </form>
                    </div>

                    {{-- Execution Statistics Card --}}
                    <div class="rounded-lg border border-default bg-surface p-6  ">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="text-xl">📊</span>
                            <flux:heading size="sm">Execution Statistics</flux:heading>
                        </div>

                        <div class="grid grid-cols-3 gap-4">
                            <div class="text-center">
                                <div class="text-2xl font-bold text-primary ">{{ $trigger->total_invocations }}</div>
                                <div class="text-sm text-tertiary ">Total</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold text-success">{{ $trigger->successful_invocations }}</div>
                                <div class="text-sm text-tertiary ">Successful</div>
                            </div>
                            <div class="text-center">
                                <div class="text-2xl font-bold text-[var(--palette-error-700)]">{{ $trigger->failed_invocations }}</div>
                                <div class="text-sm text-tertiary ">Failed</div>
                            </div>
                        </div>

                        @if($trigger->last_invoked_at)
                            <div class="mt-4 pt-4 border-t border-default  text-sm text-tertiary ">
                                Last invoked: <span class="font-medium text-primary ">{{ $trigger->last_invoked_at->diffForHumans() }}</span>
                            </div>
                        @endif

                        {{-- Manual Execution Button (for scheduled triggers) --}}
                        @if($trigger->provider_id === 'schedule')
                            <div class="mt-4 pt-4 border-t border-default ">
                                <form action="{{ route('integrations.execute-trigger', $trigger->id) }}" method="POST">
                                    @csrf
                                    <button type="submit"
                                            @if($trigger->status !== 'active') disabled @endif
                                            class="w-full inline-flex items-center justify-center gap-2 rounded-lg bg-accent px-4 py-2.5 text-sm font-semibold text-white hover:bg-accent disabled:opacity-50 disabled:cursor-not-allowed dark:bg-accent dark:hover:bg-accent">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"></path>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                        Execute Now
                                    </button>
                                    @if($trigger->status !== 'active')
                                        <p class="mt-2 text-xs text-center text-tertiary ">
                                            Trigger must be active to execute
                                        </p>
                                    @endif
                                </form>
                            </div>
                        @endif
                    </div>

                </div>

                {{-- RIGHT COLUMN - Setup Instructions (40%) --}}
                <div class="space-y-6 xl:col-span-5">

                    {{-- Webhook Secret Management Card (Webhook triggers only) --}}
                    @if($trigger->provider_id === 'webhook')
                    <div class="rounded-lg border border-default bg-surface  ">
                        <div class="border-b border-default px-6 py-4 ">
                            <div class="flex items-center gap-2">
                                <span class="text-xl">🔐</span>
                                <flux:heading size="sm">Secret Management</flux:heading>
                            </div>
                        </div>
                        <div class="p-6 space-y-4">

                            {{-- Secret Metadata --}}
                            <div class="text-sm">
                                <div class="flex justify-between mb-2">
                                    <span class="text-tertiary ">Secret Created:</span>
                                    <span class="font-mono text-primary ">
                                        @if($trigger->secret_created_at)
                                            {{ $trigger->secret_created_at->diffForHumans() }}
                                        @else
                                            <span class="text-tertiary ">Not tracked (created before update)</span>
                                        @endif
                                    </span>
                                </div>
                                @if($trigger->secret_rotated_at)
                                <div class="flex justify-between mb-2">
                                    <span class="text-tertiary ">Last Rotated:</span>
                                    <span class="font-mono text-primary ">
                                        {{ $trigger->secret_rotated_at->diffForHumans() }}
                                    </span>
                                </div>
                                @endif
                                @if($trigger->secret_rotation_count > 0)
                                <div class="flex justify-between">
                                    <span class="text-tertiary ">Rotation Count:</span>
                                    <span class="font-mono text-primary ">
                                        {{ $trigger->secret_rotation_count }} time(s)
                                    </span>
                                </div>
                                @endif
                            </div>

                            {{-- Warning about secret visibility --}}
                            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-900/20">
                                <div class="flex gap-2">
                                    <svg class="h-5 w-5 text-[var(--palette-warning-700)] flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                                    </svg>
                                    <div class="text-sm">
                                        <p class="font-semibold text-amber-800 dark:text-amber-200">Security Notice</p>
                                        <p class="text-amber-700 dark:text-amber-300 mt-1">
                                            Your webhook secret is shown in the setup instructions above. Store it securely and never commit it to version control.
                                        </p>
                                    </div>
                                </div>
                            </div>

                            {{-- Regenerate Button with Confirmation --}}
                            <form action="{{ route('integrations.regenerate-trigger-secret', $trigger->id) }}"
                                  method="POST"
                                  x-data="{ showConfirm: false }"
                                  class="space-y-3">
                                @csrf

                                <template x-if="!showConfirm">
                                    <button type="button" @click="showConfirm = true"
                                            class="inline-flex items-center gap-2 rounded-lg border border-[var(--palette-error-300)] bg-surface px-3 py-2 text-sm font-medium text-[var(--palette-error-700)] hover:bg-[var(--palette-error-50)] dark:border-[var(--palette-error-600)]  dark:text-[var(--palette-error-400)] dark:hover:bg-[var(--palette-error-900/20)]">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                        </svg>
                                        Regenerate Secret
                                    </button>
                                </template>

                                <template x-if="showConfirm">
                                    <div class="rounded-lg border border-[var(--palette-error-200)] bg-[var(--palette-error-100)] p-4">
                                        <p class="text-sm font-semibold text-[var(--palette-error-800)] mb-2">
                                            ⚠️ Are you absolutely sure?
                                        </p>
                                        <p class="text-sm text-[var(--palette-error-700)] dark:text-[var(--palette-error-300)] mb-3">
                                            This will immediately invalidate the current secret. All existing webhook integrations will stop working until you update them with the new secret.
                                        </p>
                                        <div class="flex gap-2">
                                            <button type="submit"
                                                    class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700 dark:bg-[var(--palette-error-500)] dark:hover:bg-red-600">
                                                Yes, Regenerate Secret
                                            </button>
                                            <button type="button" @click="showConfirm = false"
                                                    class="inline-flex items-center rounded-lg border border-default bg-surface px-3 py-2 text-sm font-medium text-secondary hover:bg-surface    ">
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </form>

                            {{-- Best Practices --}}
                            <div class="text-xs text-tertiary  space-y-1">
                                <p class="font-semibold">When to regenerate:</p>
                                <ul class="list-disc list-inside pl-2 space-y-1">
                                    <li>Secret may have been compromised or exposed</li>
                                    <li>Offboarding team members with secret access</li>
                                    <li>Regular security rotation (e.g., every 90 days)</li>
                                    <li>After detecting suspicious webhook activity</li>
                                </ul>
                            </div>

                        </div>
                    </div>
                    @endif

                    {{-- Setup Instructions Card --}}
                    <div class="rounded-lg border border-default bg-surface  "
                         x-data="{
                             setupInstructions: @js($setupInstructions),
                             renderedHtml: '',
                             renderMarkdown() {
                                 if (window.marked) {
                                     try {
                                         this.renderedHtml = window.marked.parse(this.setupInstructions);
                                         setTimeout(() => {
                                             if (window.hljs && this.$refs.instructions) {
                                                 this.$refs.instructions.querySelectorAll('pre code').forEach(block => {
                                                     window.hljs.highlightElement(block);
                                                 });
                                             }
                                         }, 10);
                                     } catch (e) {
                                         console.error('Markdown rendering error:', e);
                                     }
                                 }
                             }
                         }"
                         x-init="renderMarkdown()">
                        <div class="border-b border-default px-6 py-4 ">
                            <div class="flex items-center gap-2">
                                @if($svg = $provider->getTriggerIconSvg())
                                    <div class="h-5 w-5 text-secondary ">{!! $svg !!}</div>
                                @else
                                    <span class="text-xl">{{ $provider->getTriggerIcon() }}</span>
                                @endif
                                <flux:heading size="sm">Setup Instructions</flux:heading>
                            </div>
                        </div>
                        <div x-ref="instructions" class="markdown-compact">
                            <div class="markdown p-6" x-html="renderedHtml"></div>
                        </div>
                    </div>

                </div>

            </div>

            {{-- Bottom Action Bar --}}
            <div class="mt-6">
                <div class="flex items-center justify-end gap-3">
                    <a href="{{ route('integrations.index') }}" class="inline-flex items-center justify-center rounded-lg border border-default bg-surface px-4 py-2 text-sm font-medium text-secondary hover:bg-surface    ">
                        {{ __('Back') }}
                    </a>

                    <button type="submit" form="trigger-edit-form" class="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-accent dark:bg-accent dark:hover:bg-accent">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        <span>{{ __('Save Configuration') }}</span>
                    </button>
                </div>
            </div>

        </x-settings.layout>
    </section>
</x-layouts.app>
