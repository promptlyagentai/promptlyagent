<div>
    <x-settings.layout
        heading="Edit MCP Server Integration"
        subheading="Update your Model Context Protocol (MCP) server configuration and tool capabilities."
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

        {{-- Edit Form Card --}}
        <div class="rounded-lg border border-default bg-surface p-6  ">
            <x-mcp-server.form-fields
                :integrationName="$integrationName"
                :integrationDescription="$integrationDescription"
                :serverName="$serverName"
                :serverNameReadonly="true"
                :relayConfigJson="$relayConfigJson"
                :credentials="$credentials"
                :discoveredTools="$discoveredTools"
                :discoveredPrompts="$discoveredPrompts"
                :connectionTestResult="$connectionTestResult"
                :visibility="$visibility"
                submitButtonText="Save Changes"
                testButtonText="Test Connection & Rediscover Tools"
            />
        </div>

        {{-- AI Agents Section --}}
        <x-integrations.ai-agents-section :integration="$integration" />

    </x-settings.layout>
</div>
