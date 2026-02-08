@props(['integration'])

@php
    $hasAgent = $integration->agent()->exists();
    $agent = $integration->agent;
    $hasToolsEnabled = collect($integration->getEnabledCapabilities())
        ->filter(fn($cap) => str_starts_with($cap, 'Tools:'))
        ->isNotEmpty();
    $agentCreateBlocked = collect($integration->integrationToken->metadata['blocked_capabilities'] ?? [])
        ->contains('Agent:create');
@endphp

<div class="rounded-lg border border-default bg-surface p-6">
    <div class="mb-4">
        <div class="flex items-center space-x-2">
            <flux:icon.cpu-chip class="h-5 w-5 text-secondary" />
            <flux:heading size="sm">{{ __('AI Agents') }}</flux:heading>
        </div>
        <flux:text size="sm" class="mt-1 text-secondary">
            {{ __('Specialized agents for interacting with your integrations using natural language.') }}
        </flux:text>
    </div>

    <div class="rounded-lg border border-default bg-surface p-4">
        <div class="mb-2">
            <flux:text size="sm" class="font-medium text-secondary">{{ $integration->name }}</flux:text>
        </div>

        @if($hasAgent)
            {{-- Agent Exists - Show Info and Delete Button --}}
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <div class="flex items-center gap-2">
                        <flux:heading size="sm">{{ $agent->name }}</flux:heading>
                        <flux:badge size="sm" color="green">Active</flux:badge>
                    </div>
                    <flux:text size="sm" class="mt-2 text-secondary">
                        {{ $agent->description }}
                    </flux:text>
                    <flux:text size="xs" class="mt-2 text-tertiary">
                        This agent can be invoked in chat by saying: "Find X in {{ $integration->name }}"
                    </flux:text>
                </div>

                <form method="POST" action="{{ route('integrations.agent.delete', $integration) }}"
                      onsubmit="return confirm('{{ __('Are you sure you want to delete this agent? You can recreate it later.') }}')"
                      class="ml-4">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                        class="inline-flex items-center gap-2 rounded-lg border border-error bg-surface px-3 py-2 text-sm font-medium text-error hover:bg-error hover:text-error-contrast transition-colors">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                        </svg>
                        {{ __('Delete') }}
                    </button>
                </form>
            </div>
        @else
            {{-- No Agent - Show Create Button --}}
            <div class="flex items-start justify-between">
                <div class="flex-1">
                    <flux:text size="sm" class="text-secondary">
                        Create an AI agent that can interact with this integration using natural language.
                    </flux:text>

                    @if(!$hasToolsEnabled)
                        <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-900/20">
                            <flux:text size="xs" class="text-amber-800 dark:text-amber-200">
                                <strong>Note:</strong> Enable at least one Tool capability in the capabilities section above first.
                            </flux:text>
                        </div>
                    @elseif($agentCreateBlocked)
                        <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-900/20">
                            <flux:text size="xs" class="text-amber-800 dark:text-amber-200">
                                <strong>Insufficient permissions:</strong> Your token needs additional scopes to create an agent.
                            </flux:text>
                        </div>
                    @endif
                </div>

                <form method="POST" action="{{ route('integrations.agent.create', $integration) }}" class="inline">
                    @csrf
                    <button type="submit"
                            @if(!$hasToolsEnabled || $agentCreateBlocked) disabled @endif
                        class="inline-flex items-center gap-2 rounded-lg bg-accent px-3 py-2 text-sm font-semibold text-white hover:bg-accent disabled:opacity-50 disabled:cursor-not-allowed dark:bg-accent dark:hover:bg-accent">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        {{ __('Create Agent') }}
                    </button>
                </form>
            </div>
        @endif
    </div>
</div>
