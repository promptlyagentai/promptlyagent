@props([
    'integrationName' => '',
    'integrationDescription' => '',
    'serverName' => '',
    'serverNameReadonly' => false,
    'relayConfigJson' => '',
    'credentials' => [],
    'discoveredTools' => [],
    'discoveredPrompts' => [],
    'connectionTestResult' => null,
    'visibility' => 'private',
    'submitButtonText' => 'Save',
    'testButtonText' => 'Test Connection & Discover Tools',
])

<form @submit.prevent="$wire.save()" class="space-y-6">

    {{-- Integration Name --}}
    <flux:field>
        <flux:label>Integration Name *</flux:label>
        <flux:input
            wire:model="integrationName"
            placeholder="My MCP Server" />
        <flux:description>
            A friendly name for this integration
        </flux:description>
        @error('integrationName')
            <flux:error>{{ $message }}</flux:error>
        @enderror
    </flux:field>

    {{-- Integration Description --}}
    <flux:field>
        <flux:label>Description</flux:label>
        <flux:textarea
            wire:model="integrationDescription"
            rows="3"
            placeholder="Optional description of what this MCP server provides..." />
        @error('integrationDescription')
            <flux:error>{{ $message }}</flux:error>
        @enderror
    </flux:field>

    {{-- Server Name --}}
    <flux:field>
        <flux:label>Server Name {{ $serverNameReadonly ? '' : '*' }}</flux:label>
        <flux:input
            wire:model="serverName"
            placeholder="my-mcp-server"
            :disabled="$serverNameReadonly"
            :class="$serverNameReadonly ? 'bg-surface cursor-not-allowed' : ''" />
        <flux:description>
            @if($serverNameReadonly)
                Server name cannot be changed after creation
            @else
                Unique identifier for this server (lowercase, no spaces)
            @endif
        </flux:description>
        @if(!$serverNameReadonly)
            @error('serverName')
                <flux:error>{{ $message }}</flux:error>
            @enderror
        @endif
    </flux:field>

    {{-- Server Configuration JSON --}}
    <flux:field>
        <flux:label>Server Configuration (JSON) *</flux:label>
        <flux:textarea
            wire:model.live="relayConfigJson"
            rows="15"
            class="font-mono text-sm"
            placeholder='{"command": ["npx", "@server/mcp"], "transport": "Stdio", ...}' />
        <flux:description>
            MCP server relay configuration (command, transport, env variables)
        </flux:description>
        @error('relayConfigJson')
            <div class="mt-2 text-sm text-[var(--palette-error-600)] dark:text-[var(--palette-error-400)]">
                {{ $message }}
            </div>
        @enderror
    </flux:field>

    {{-- Credentials --}}
    @if(count($credentials) > 0)
        <div class="space-y-4 pt-4 border-t border-default">
            <div>
                <flux:label>Credentials {{ $serverNameReadonly ? '' : '(auto-detected from config)' }}</flux:label>
                <flux:description>
                    @if($serverNameReadonly)
                        Leave blank to keep existing values. Only provide new values if you want to update credentials.
                    @else
                        Provide values for the credential placeholders found in your configuration
                    @endif
                </flux:description>
            </div>

            @foreach($credentials as $index => $credential)
                <flux:field>
                    <flux:label>
                        {{ $credential['key'] }}
                        @if($credential['existing'] ?? false)
                            <span class="text-xs text-tertiary">(existing - leave blank to keep)</span>
                        @else
                            <span class="text-xs text-tertiary">*</span>
                        @endif
                    </flux:label>
                    <flux:input
                        wire:model="credentials.{{ $index }}.value"
                        type="password"
                        maxlength="512"
                        placeholder="{{ ($credential['existing'] ?? false) ? 'Leave blank to keep existing value' : 'Enter ' . strtolower($credential['key']) . '...' }}" />
                    @error("credentials.{$index}.value")
                        <flux:error>{{ $message }}</flux:error>
                    @enderror
                </flux:field>
            @endforeach
        </div>
    @endif

    {{-- Test Connection Button --}}
    <div class="flex items-center gap-4 pt-4 border-t border-default">
        <button
            @click="$wire.testConnection()"
            wire:loading.attr="disabled"
            type="button"
            class="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-accent disabled:opacity-50">
            <span wire:loading.remove wire:target="testConnection">{{ $testButtonText }}</span>
            <span wire:loading wire:target="testConnection">Testing...</span>
        </button>

        @if($connectionTestResult)
            <div class="flex items-center gap-2">
                @if($connectionTestResult['success'])
                    <svg class="w-5 h-5 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    <span class="text-sm text-accent">
                        {{ $connectionTestResult['toolCount'] ?? 0 }} tools discovered
                    </span>
                @else
                    <svg class="w-5 h-5 text-error" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    <span class="text-sm text-error">Connection failed</span>
                @endif
            </div>
        @endif
    </div>

    {{-- Connection Test Result Details --}}
    @if($connectionTestResult)
        <div class="rounded-lg p-4 {{ $connectionTestResult['success'] ? 'bg-success border border-success' : 'bg-error border border-error' }}">
            <p class="text-sm {{ $connectionTestResult['success'] ? 'text-success-contrast' : 'text-error-contrast' }}">
                {{ $connectionTestResult['message'] }}
            </p>
        </div>
    @endif

    {{-- Discovered Tools --}}
    @if(count($discoveredTools) > 0)
        <div class="space-y-4 pt-4 border-t border-default">
            <div>
                <flux:label>Discovered Tools ({{ count($discoveredTools) }})</flux:label>
                <flux:description>
                    Enable or disable specific tools. Enabled tools will be available to agents.
                </flux:description>
            </div>

            <div class="space-y-2 max-h-96 overflow-y-auto">
                @foreach($discoveredTools as $index => $tool)
                    <div class="flex items-start gap-3 p-3 rounded-lg border border-default hover:bg-surface">
                        <input
                            type="checkbox"
                            @click="$wire.toggleTool({{ $index }})"
                            {{ ($tool['enabled'] ?? true) ? 'checked' : '' }}
                            class="mt-1 rounded border-default text-accent focus:ring-accent" />
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-primary">
                                {{ $tool['name'] }}
                            </p>
                            <p class="text-xs text-secondary mt-1">
                                {{ $tool['description'] }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Visibility (Admin Only) --}}
    @if(auth()->user()?->is_admin)
        <div class="pt-4 border-t border-default">
            <flux:field>
                <flux:label>Visibility</flux:label>
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="radio"
                            wire:model="visibility"
                            value="private"
                            class="rounded-full border-default text-accent focus:ring-accent" />
                        <span class="text-sm text-secondary">Private</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input
                            type="radio"
                            wire:model="visibility"
                            value="shared"
                            class="rounded-full border-default text-accent focus:ring-accent" />
                        <span class="text-sm text-secondary">Shared</span>
                    </label>
                </div>
                <flux:description>
                    Private integrations are only accessible by you. Shared integrations (admin only) are available to all users using your credentials.
                </flux:description>
            </flux:field>
        </div>
    @endif

</form>
