{{-- MCP Server Integration Create Form --}}

<div class="space-y-6">
    {{-- Server Name --}}
    <flux:field>
        <flux:label>Server Name</flux:label>
        <flux:input
            wire:model="serverConfig.server_name"
            placeholder="my-mcp-server"
            required
        />
        <flux:description>
            Unique identifier for this server (lowercase, no spaces)
        </flux:description>
        @error('serverConfig.server_name')
            <flux:error>{{ $message }}</flux:error>
        @enderror
    </flux:field>

    {{-- Transport Type --}}
    <flux:field>
        <flux:label>Transport Type</flux:label>
        <flux:select wire:model="serverConfig.transport" required>
            <option value="Stdio">Stdio (Standard Input/Output)</option>
            <option value="Http">HTTP (REST API)</option>
        </flux:select>
        <flux:description>
            How to communicate with the MCP server
        </flux:description>
        @error('serverConfig.transport')
            <flux:error>{{ $message }}</flux:error>
        @enderror
    </flux:field>

    {{-- Command (for Stdio) --}}
    <div x-show="$wire.serverConfig.transport === 'Stdio'" x-cloak>
        <flux:field>
            <flux:label>Command</flux:label>
            <flux:textarea
                wire:model="serverConfig.command"
                placeholder='["npx", "@example/mcp-server"]'
                rows="3"
            />
            <flux:description>
                Command to start the server as JSON array (e.g., ["npx", "@example/mcp-server"])
            </flux:description>
            @error('serverConfig.command')
                <flux:error>{{ $message }}</flux:error>
            @enderror
        </flux:field>
    </div>

    {{-- URL (for HTTP) --}}
    <div x-show="$wire.serverConfig.transport === 'Http'" x-cloak>
        <flux:field>
            <flux:label>Server URL</flux:label>
            <flux:input
                wire:model="serverConfig.url"
                placeholder="https://mcp.example.com/api"
                type="url"
            />
            <flux:description>
                The HTTP endpoint for the MCP server
            </flux:description>
            @error('serverConfig.url')
                <flux:error>{{ $message }}</flux:error>
            @enderror
        </flux:field>
    </div>

    {{-- Timeout --}}
    <flux:field>
        <flux:label>Timeout (seconds)</flux:label>
        <flux:input
            wire:model="serverConfig.timeout"
            type="number"
            min="10"
            max="300"
            value="60"
        />
        <flux:description>
            Maximum time to wait for server response (10-300 seconds)
        </flux:description>
        @error('serverConfig.timeout')
            <flux:error>{{ $message }}</flux:error>
        @enderror
    </flux:field>

    {{-- Credentials (Key-Value Pairs) --}}
    <div class="space-y-4">
        <div>
            <flux:label>Environment Variables / Credentials</flux:label>
            <flux:description>
                Add environment variables needed by your MCP server (e.g., API keys, URLs)
            </flux:description>
        </div>

        @if(isset($credentials) && is_array($credentials))
            @foreach($credentials as $index => $credential)
                <div class="flex gap-2 items-start">
                    <div class="flex-1">
                        <flux:input
                            wire:model="credentials.{{ $index }}.key"
                            placeholder="API_KEY"
                        />
                    </div>
                    <div class="flex-1">
                        <flux:input
                            wire:model="credentials.{{ $index }}.value"
                            placeholder="sk-..."
                            type="password"
                        />
                    </div>
                    <flux:button
                        variant="danger"
                        size="sm"
                        wire:click="removeCredential({{ $index }})"
                        type="button"
                    >
                        Remove
                    </flux:button>
                </div>
                @error("credentials.{$index}.key")
                    <flux:error>{{ $message }}</flux:error>
                @enderror
                @error("credentials.{$index}.value")
                    <flux:error>{{ $message }}</flux:error>
                @enderror
            @endforeach
        @endif

        <flux:button
            variant="ghost"
            wire:click="addCredential"
            type="button"
        >
            + Add Variable
        </flux:button>
    </div>

    {{-- Connection Test --}}
    <div class="space-y-3">
        <flux:button
            wire:click="testConnection"
            wire:loading.attr="disabled"
            variant="primary"
            type="button"
        >
            <span wire:loading.remove wire:target="testConnection">Test Connection</span>
            <span wire:loading wire:target="testConnection">Testing...</span>
        </flux:button>

        @if(isset($connectionTestResult))
            <div class="p-4 rounded {{ $connectionTestResult['success'] ? 'bg-[var(--palette-success-50)] text-[var(--palette-success-800)] dark:bg-[var(--palette-success-900)] dark:text-[var(--palette-success-200)]' : 'bg-[var(--palette-error-50)] text-[var(--palette-error-800)] dark:bg-[var(--palette-error-900)] dark:text-[var(--palette-error-200)]' }}">
                <p class="font-medium">{{ $connectionTestResult['message'] }}</p>
                @if(isset($connectionTestResult['tools']) && $connectionTestResult['success'])
                    <p class="mt-2 text-sm">Found {{ count($connectionTestResult['tools']) }} tools</p>
                @endif
            </div>
        @endif
    </div>

    {{-- Sharing Section (Admin Only) --}}
    @if(auth()->user()?->is_admin)
        <div class="border-t pt-6 space-y-3">
            <div>
                <flux:label>Sharing</flux:label>
                <flux:description>
                    Make this integration available to all users in your organization
                </flux:description>
            </div>

            <flux:checkbox wire:model="serverConfig.visibility" value="shared">
                Share with all users
            </flux:checkbox>

            @if($serverConfig['visibility'] ?? false === 'shared')
                <div class="p-4 bg-[var(--palette-warning-50)] dark:bg-[var(--palette-warning-900/20)] text-[var(--palette-warning-800)] dark:text-[var(--palette-warning-200)] rounded">
                    <p class="font-medium">Important:</p>
                    <ul class="mt-2 text-sm list-disc list-inside space-y-1">
                        <li>All users will use your credentials for this integration</li>
                        <li>Rate limits and quotas will be shared among all users</li>
                        <li>You are responsible for the usage of shared integrations</li>
                    </ul>
                </div>
            @endif
        </div>
    @endif
</div>
