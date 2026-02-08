<x-layouts.app>
    <section class="w-full">
        @include('partials.settings-heading')

        <x-settings.layout
            :heading="'Create ' . $provider->getTriggerTypeName() . ' Trigger'"
            :subheading="$provider->getDescription()"
            :wide="true">

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

            {{-- Two-Column Layout: Form + Documentation --}}
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
                {{-- Left Column: Form Fields (3/5 = 60%) --}}
                <div class="lg:col-span-3">
                    <div class="rounded-lg border border-default bg-surface p-6">
                        <form id="trigger-create-form" action="{{ route('integrations.store-trigger', ['provider' => $provider->getTriggerType()]) }}" method="POST" class="space-y-6" x-data="{
                            triggerTargetType: '{{ old('trigger_target_type', 'agent') }}',
                            selectedAgentType: '',
                            selectedCommand: '{{ old('command_class') }}',
                            commandParameters: @js($triggerableCommands ?? [])
                        }">
                            @csrf

                    {{-- Trigger Name --}}
                    <div>
                    <flux:input
                        name="name"
                        label="Trigger Name"
                        placeholder="My {{ $provider->getTriggerTypeName() }} Trigger"
                        value="{{ old('name') }}"
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
                        placeholder="Describe what this trigger is used for..."
                        rows="3">{{ old('description') }}</flux:textarea>
                    @error('description')
                        <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                    @enderror
                </div>

                {{-- Trigger Target Type Selection --}}
                <div>
                    <flux:fieldset>
                        <flux:legend>What should this trigger execute?</flux:legend>
                        <flux:description>Choose whether to execute an agent or a command</flux:description>

                        <div class="mt-3 space-y-3">
                            <label class="flex items-start gap-3 cursor-pointer">
                                <input type="radio"
                                       name="trigger_target_type"
                                       value="agent"
                                       x-model="triggerTargetType"
                                       class="mt-1"
                                       {{ old('trigger_target_type', 'agent') === 'agent' ? 'checked' : '' }}>
                                <div>
                                    <div class="font-medium text-primary">Agent Execution</div>
                                    <div class="text-sm text-secondary">Execute an AI agent with the webhook payload as input</div>
                                </div>
                            </label>

                            <label class="flex items-start gap-3 cursor-pointer">
                                <input type="radio"
                                       name="trigger_target_type"
                                       value="command"
                                       x-model="triggerTargetType"
                                       class="mt-1"
                                       {{ old('trigger_target_type') === 'command' ? 'checked' : '' }}>
                                <div>
                                    <div class="font-medium text-primary">Command Execution</div>
                                    <div class="text-sm text-secondary">Execute an Artisan command with mapped parameters</div>
                                </div>
                            </label>
                        </div>
                    </flux:fieldset>
                    @error('trigger_target_type')
                        <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                    @enderror
                </div>

                {{-- Agent Selection (conditional - only show when agent is selected) --}}
                <div x-show="triggerTargetType === 'agent'" x-cloak>
                    <flux:select name="agent_id" label="Agent" @change="selectedAgentType = $event.target.selectedOptions[0]?.dataset.agentType || ''">
                        <option value="">Not configured (allow runtime override)</option>
                        @foreach($agents as $agent)
                            <option value="{{ $agent->id }}" data-agent-type="{{ $agent->agent_type }}" {{ old('agent_id') == $agent->id ? 'selected' : '' }}>
                                {{ $agent->name }} ({{ ucfirst($agent->agent_type) }})
                            </option>
                        @endforeach
                    </flux:select>
                    <flux:text size="sm" class="mt-1 text-secondary">
                        Select "Not configured" to allow agent_id to be specified in webhook/API requests.
                    </flux:text>
                    <flux:text size="sm" class="mt-1 text-secondary">
                        <strong>Note: When set to a specific agent, it cannot be overridden by request parameters.</strong>
                    </flux:text>
                    @error('agent_id')
                        <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                    @enderror
                </div>

                {{-- Agent Input Template (conditional - only for agent target type) --}}
                <div x-show="triggerTargetType === 'agent'" x-cloak>
                    <flux:textarea
                        name="agent_input_template"
                        label="Agent Input Template (Optional)"
                        placeholder="Default: @{{ payload }} (entire payload as JSON)"
                        rows="4">{{ old('agent_input_template') }}</flux:textarea>
                    <flux:text size="sm" class="mt-1 text-secondary">
                        Use template placeholders to construct agent input from webhook payload. See documentation panel for syntax →
                    </flux:text>
                    @error('agent_input_template')
                        <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                    @enderror
                </div>

                {{-- Workflow Configuration (conditional - only for workflow agents + agent target type) --}}
                <div x-show="triggerTargetType === 'agent' && selectedAgentType === 'workflow'" x-cloak>
                    <flux:textarea
                        name="workflow_config"
                        label="Workflow Configuration (Optional)"
                        placeholder="{{ json_encode(['agents' => [['id' => 1, 'execution_order' => 1]], 'orchestration_mode' => 'sequential']) }}"
                        rows="6">{{ old('workflow_config') }}</flux:textarea>
                    <flux:text size="sm" class="mt-1 text-secondary">
                        JSON workflow configuration. <strong>Leave empty to allow workflow to be specified in webhook/API requests.</strong>
                    </flux:text>
                    <flux:text size="sm" class="mt-1 text-secondary">
                        <strong>Note: When set, this workflow cannot be overridden by request parameters.</strong>
                    </flux:text>
                    @error('workflow_config')
                        <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                    @enderror
                </div>

                {{-- Command Selection (conditional - only show when command is selected) --}}
                <div x-show="triggerTargetType === 'command'" x-cloak>
                    <flux:select name="command_class" label="Command" x-model="selectedCommand">
                        <option value="">Select a command...</option>
                        @foreach($triggerableCommands as $commandName => $commandInfo)
                            <option value="{{ $commandInfo['class'] }}" {{ old('command_class') == $commandInfo['class'] ? 'selected' : '' }}>
                                {{ $commandInfo['name'] }} ({{ $commandName }})
                            </option>
                        @endforeach
                    </flux:select>
                    <flux:text size="sm" class="mt-1 text-secondary">
                        Select which Artisan command to execute when this trigger is invoked.
                    </flux:text>
                    @error('command_class')
                        <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                    @enderror

                    {{-- Dynamic Command Parameters --}}
                    <template x-if="selectedCommand">
                        <div class="mt-4 space-y-4">
                            <div class="text-sm font-medium text-primary">Command Parameters</div>
                            <template x-for="(commandInfo, cmdName) in commandParameters" :key="cmdName">
                                <template x-if="commandInfo.class === selectedCommand">
                                    <div>
                                        <template x-for="(param, pName) in commandInfo.parameters" :key="pName">
                                            <div class="mb-4">
                                                <label class="block text-sm font-medium text-primary mb-2">
                                                    <span x-text="pName"></span>
                                                    <template x-if="param.required">
                                                        <span class="text-error">*</span>
                                                    </template>
                                                </label>

                                                {{-- Array type: Use textarea with one item per line --}}
                                                <template x-if="param.type === 'array'">
                                                    <div>
                                                        <textarea
                                                            :name="'command_parameters[' + pName + ']'"
                                                            :placeholder="param.placeholder || 'Enter one item per line'"
                                                            :required="param.required"
                                                            rows="3"
                                                            class="w-full rounded-lg border border-default bg-surface px-3 py-2 text-sm text-primary placeholder-secondary focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent"></textarea>
                                                        <p class="mt-1 text-xs text-secondary">Enter one item per line</p>
                                                    </div>
                                                </template>

                                                {{-- Other types: Use regular input --}}
                                                <template x-if="param.type !== 'array'">
                                                    <input
                                                        :type="param.type === 'integer' ? 'number' : 'text'"
                                                        :name="'command_parameters[' + pName + ']'"
                                                        :placeholder="param.placeholder || param.description"
                                                        :required="param.required"
                                                        class="w-full rounded-lg border border-default bg-surface px-3 py-2 text-sm text-primary placeholder-secondary focus:border-accent focus:outline-none focus:ring-1 focus:ring-accent" />
                                                </template>

                                                <p class="mt-1 text-sm text-secondary" x-text="param.description"></p>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </template>
                        </div>
                    </template>
                </div>

                {{-- Session Strategy (conditional - only for agent target type) --}}
                <div x-show="triggerTargetType === 'agent'" x-cloak>
                    <flux:select name="session_strategy" label="Session Strategy">
                        <option value="" {{ old('session_strategy') === '' || old('session_strategy') === null ? 'selected' : '' }}>
                            Not configured (allow runtime override)
                        </option>
                        <option value="new_each" {{ old('session_strategy', 'new_each') === 'new_each' ? 'selected' : '' }}>
                            New Session Each Time - Create a new chat session for every trigger invocation
                        </option>
                        <option value="continue_last" {{ old('session_strategy') === 'continue_last' ? 'selected' : '' }}>
                            Continue Last Session - Continue the most recent session from this trigger (maintains conversation context)
                        </option>
                    </flux:select>
                    <flux:text size="sm" class="mt-1 text-secondary">
                        Select "Not configured" to allow session_strategy to be specified in webhook/API requests.
                    </flux:text>
                    <flux:text size="sm" class="mt-1 text-secondary">
                        <strong>Note: When set, this strategy cannot be overridden by request parameters (though session_id can still be provided).</strong>
                    </flux:text>
                    @error('session_strategy')
                        <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                    @enderror
                </div>

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
                                value="{{ old('rate_limits.per_minute', 10) }}"
                                min="1"
                                max="100" />

                            <flux:input
                                type="number"
                                name="rate_limits[per_hour]"
                                label="Per Hour"
                                value="{{ old('rate_limits.per_hour', 100) }}"
                                min="1"
                                max="1000" />
                        </div>
                    </flux:fieldset>
                </div>

                {{-- IP Whitelist --}}
                <div x-data="{
                    ipRanges: {{ json_encode(old('ip_whitelist', [])) }},
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
                                            class="inline-flex items-center gap-1 rounded-lg bg-accent px-3 py-2 text-sm font-medium text-accent-foreground hover:bg-accent-hover">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                                        </svg>
                                        Add
                                    </button>
                                </div>
                            </div>

                            {{-- IP List --}}
                            <template x-if="ipRanges.length > 0">
                                <div class="rounded-lg border border-default">
                                    <div class="divide-y divide-default ">
                                        <template x-for="(ip, index) in ipRanges" :key="index">
                                            <div class="flex items-center justify-between px-3 py-2">
                                                <span class="font-mono text-sm text-primary" x-text="ip"></span>
                                                <button type="button"
                                                        @click="removeIp(index)"
                                                        class="text-error hover:opacity-80">
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
                                <div class="rounded-lg border border-dashed border-default bg-surface px-4 py-3 text-center">
                                    <p class="text-sm text-secondary">
                                        No IP restrictions configured. All IP addresses are allowed.
                                    </p>
                                </div>
                            </template>

                            {{-- Help Text --}}
                            <flux:text size="sm" class="text-secondary">
                                <strong>Examples:</strong> 192.168.1.1 (single IP), 10.0.0.0/24 (IPv4 range), 2001:db8::/32 (IPv6 range)
                            </flux:text>
                        </div>
                    </flux:fieldset>
                    @error('ip_whitelist')
                        <flux:text variant="danger" class="mt-1">{{ $message }}</flux:text>
                    @enderror
                </div>
                @endif

                        {{-- Provider-specific configuration --}}
                        @if(method_exists($provider, 'getConfigFormView'))
                            @include($provider->getConfigFormView())
                        @endif

                    </form>
                </div>
            </div>

            {{-- Right Column: Documentation (2/5 = 40%) --}}
            <div class="lg:col-span-2">
                <div class="sticky top-6 space-y-4">
                    {{-- Template Placeholder Documentation --}}
                    <div class="rounded-lg border border-default bg-surface p-4">
                        <div class="flex items-start gap-2 mb-3">
                            <svg class="h-5 w-5 text-accent mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <div class="text-sm font-semibold text-primary">Template Placeholders</div>
                        </div>

                        <div class="text-sm text-secondary space-y-4">
                            <p>Use placeholders to dynamically insert webhook payload data and trigger metadata:</p>

                            {{-- Webhook Payload Placeholders --}}
                            <div>
                                <div class="font-medium text-primary mb-2">Webhook Payload</div>
                                <div class="space-y-2">
                                    <div>
                                        <code class="px-2 py-0.5 bg-surface-elevated rounded text-accent text-xs">@{{ payload }}</code>
                                        <div class="text-xs mt-1">Entire payload as JSON</div>
                                    </div>
                                    <div>
                                        <code class="px-2 py-0.5 bg-surface-elevated rounded text-accent text-xs">@{{ payload.field }}</code>
                                        <div class="text-xs mt-1">Specific field value</div>
                                    </div>
                                    <div>
                                        <code class="px-2 py-0.5 bg-surface-elevated rounded text-accent text-xs">@{{ payload.user.email }}</code>
                                        <div class="text-xs mt-1">Nested field access</div>
                                    </div>
                                    <div>
                                        <code class="px-2 py-0.5 bg-surface-elevated rounded text-accent text-xs">@{{ field|default:"value" }}</code>
                                        <div class="text-xs mt-1">Default value if missing</div>
                                    </div>
                                </div>
                            </div>

                            {{-- Built-in Metadata --}}
                            <div>
                                <div class="font-medium text-primary mb-2">Built-in Metadata</div>
                                <div class="space-y-1 text-xs">
                                    <div>
                                        <code class="px-1.5 py-0.5 bg-surface-elevated rounded text-accent">@{{ date }}</code>
                                        <code class="px-1.5 py-0.5 bg-surface-elevated rounded text-accent">@{{ time }}</code>
                                        <code class="px-1.5 py-0.5 bg-surface-elevated rounded text-accent">@{{ datetime }}</code>
                                    </div>
                                    <div>
                                        <code class="px-1.5 py-0.5 bg-surface-elevated rounded text-accent">@{{ day }}</code>
                                        <code class="px-1.5 py-0.5 bg-surface-elevated rounded text-accent">@{{ week }}</code>
                                        <code class="px-1.5 py-0.5 bg-surface-elevated rounded text-accent">@{{ month }}</code>
                                        <code class="px-1.5 py-0.5 bg-surface-elevated rounded text-accent">@{{ year }}</code>
                                    </div>
                                    <div>
                                        <code class="px-1.5 py-0.5 bg-surface-elevated rounded text-accent">@{{ trigger_id }}</code>
                                        <code class="px-1.5 py-0.5 bg-surface-elevated rounded text-accent">@{{ trigger_name }}</code>
                                        <code class="px-1.5 py-0.5 bg-surface-elevated rounded text-accent">@{{ user_id }}</code>
                                    </div>
                                </div>
                            </div>

                            {{-- Examples --}}
                            <div>
                                <div class="font-medium text-primary mb-2">Examples</div>

                                <div class="rounded bg-surface-elevated p-3 border border-default mb-2">
                                    <div class="text-xs font-semibold text-primary mb-1">Agent Template:</div>
                                    <div class="text-xs font-mono text-secondary mb-2">
                                        Research: @{{ payload.topic }}<br>
                                        Focus: @{{ payload.focus|default:"overview" }}
                                    </div>
                                    <div class="text-xs text-tertiary">
                                        Payload: <code class="text-accent">{"topic": "AI", "focus": "apps"}</code>
                                    </div>
                                    <div class="text-xs text-tertiary">
                                        Result: "Research: AI. Focus: apps"
                                    </div>
                                </div>

                                <div class="rounded bg-surface-elevated p-3 border border-default">
                                    <div class="text-xs font-semibold text-primary mb-1">Command Parameter:</div>
                                    <div class="text-xs font-mono text-secondary mb-2">
                                        topics = @{{ payload.topic1 }}, @{{ payload.topic2 }}
                                    </div>
                                    <div class="text-xs text-tertiary">
                                        Payload: <code class="text-accent">{"topic1": "AI", "topic2": "ML"}</code>
                                    </div>
                                    <div class="text-xs text-tertiary">
                                        Result: topics = "AI, ML"
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Bottom Action Bar --}}
        <div class="mt-6">
            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('integrations.index') }}" class="inline-flex items-center justify-center rounded-lg border border-default bg-surface px-4 py-2 text-sm font-medium text-secondary hover:bg-surface-elevated">
                    {{ __('Cancel') }}
                </a>

                <button type="submit" form="trigger-create-form" class="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-accent-hover dark:bg-accent dark:hover:bg-accent-hover">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    <span>{{ __('Create Trigger') }}</span>
                </button>
            </div>
        </div>
        </x-settings.layout>
    </section>
</x-layouts.app>
