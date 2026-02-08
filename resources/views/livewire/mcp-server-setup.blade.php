<div>
    <x-settings.layout
        heading="Setup MCP Server Integration"
        subheading="Connect your own Model Context Protocol (MCP) server to extend agent capabilities with custom tools."
        wide>

        {{-- Success/Error Messages --}}
        @if (session()->has('success'))
            <div class="mb-6 rounded-lg border border-[var(--palette-success-200)] bg-[var(--palette-success-100)] p-4">
                <p class="text-sm text-[var(--palette-success-800)]">
                    {{ session('success') }}
                </p>
            </div>
        @endif

        @if (session()->has('error'))
            <div class="mb-6 rounded-lg border border-[var(--palette-error-200)] bg-[var(--palette-error-100)] p-4">
                <p class="text-sm text-[var(--palette-error-800)]">
                    {{ session('error') }}
                </p>
            </div>
        @endif

        {{-- Setup Form Card --}}
        <div class="rounded-lg border border-default bg-surface p-6  ">
            <x-mcp-server.form-fields
                :integrationName="$integrationName"
                :integrationDescription="$integrationDescription"
                :serverName="$serverName"
                :serverNameReadonly="false"
                :relayConfigJson="$relayConfigJson"
                :credentials="$credentials"
                :discoveredTools="$discoveredTools"
                :discoveredPrompts="$discoveredPrompts"
                :connectionTestResult="$connectionTestResult"
                :visibility="$visibility"
                submitButtonText="Create Integration"
                testButtonText="Test Connection & Discover Tools"
            />
        </div>

        {{-- Bottom Action Bar --}}
        <div class="mt-6">
            <div class="flex items-center justify-end gap-3">
                <a href="{{ route('integrations.index') }}" class="inline-flex items-center justify-center rounded-lg border border-default bg-surface px-4 py-2 text-sm font-medium text-secondary hover:bg-surface-elevated">
                    {{ __('Cancel') }}
                </a>

                <button type="button"
                        wire:click="save"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-accent-hover dark:bg-accent dark:hover:bg-accent-hover disabled:opacity-50">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" wire:loading.remove wire:target="save">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                    <span wire:loading.remove wire:target="save">{{ __('Create Integration') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
                </button>
            </div>
        </div>

    </x-settings.layout>
</div>
