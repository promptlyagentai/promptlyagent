{{-- MCP Server Integration Edit Form --}}

<div class="space-y-6">
    {{-- Server Name (Read-only in edit mode) --}}
    <flux:field>
        <flux:label>Server Name</flux:label>
        <flux:input
            wire:model="serverConfig.server_name"
            disabled
        />
        <flux:description>
            Server name cannot be changed after creation
        </flux:description>
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
                Command to start the server as JSON array
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
                Update environment variables and credentials. Existing values are masked for security.
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
                            placeholder="••••••••"
                            type="password"
                        />
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Leave blank to keep existing value</p>
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
                    Control whether this integration is available to all users
                </flux:description>
            </div>

            <flux:checkbox wire:model="integration.visibility" value="shared">
                Share with all users
            </flux:checkbox>

            @if($integration->visibility === 'shared')
                <div class="p-4 bg-[var(--palette-warning-50)] dark:bg-[var(--palette-warning-900/20)] text-[var(--palette-warning-800)] dark:text-[var(--palette-warning-200)] rounded">
                    <p class="font-medium">Currently Shared</p>
                    <ul class="mt-2 text-sm list-disc list-inside space-y-1">
                        <li>All users are using your credentials for this integration</li>
                        <li>Rate limits and quotas are shared among all users</li>
                        <li>Changing to private will remove access for other users</li>
                    </ul>
                </div>
            @elseif($integration->visibility === 'private' && isset($wasShared) && $wasShared)
                <div class="p-4 bg-accent/10 text-accent rounded">
                    <p class="font-medium">Visibility Changed</p>
                    <p class="mt-1 text-sm">This integration will become private and other users will lose access when you save.</p>
                </div>
            @endif
        </div>
    @endif
</div>
