{{--
    Agent Manager

    Lists and manages AI agents with filtering, status toggles, and inline editing.
    Supports individual, workflow, direct, promptly, synthesizer, and QA agent types.
--}}
<div class="rounded-xl border border-default bg-surface p-6 flex flex-col gap-6">
    <div class="flex items-start justify-between">
        <div>
            <flux:heading size="xl">Agent Manager</flux:heading>
            <flux:subheading>Manage your AI agents, configure tools, and monitor executions.</flux:subheading>
        </div>        <flux:button wire:click="createAgent" type="button" variant="primary" icon="plus">
            Create Agent
        </flux:button>
    </div>
    <div class="rounded-xl border border-default bg-surface p-6">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-5">
            <div class="sm:col-span-2">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    label="Search Agents"
                    placeholder="Search by name or description..."
                    icon="magnifying-glass" />
            </div>
            <div>
                <flux:field>
                    <flux:label>Type</flux:label>
                    <select wire:model.live="selectedAgentType" class="w-full rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent">
                        <option value="all">All Types</option>
                        <option value="individual">Individual</option>
                        <option value="workflow">Workflow</option>
                        <option value="direct">Direct Chat</option>
                        <option value="promptly">Promptly</option>
                        <option value="synthesizer">Synthesizer</option>
                        <option value="qa">QA</option>
                    </select>
                </flux:field>
            </div>
            <div>
                <flux:field>
                    <flux:label>Status</flux:label>
                    <select wire:model.live="selectedStatus" class="w-full rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent">
                        <option value="all">All Status</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </flux:field>
            </div>
            <div>
                <flux:field>
                    <flux:label>Ownership</flux:label>
                    <flux:checkbox wire:model.live="showOnlyMyAgents" label="My agents only" />
                </flux:field>
            </div>
        </div>
    </div>
    @if($agents->count() > 0)
        <div class="rounded-xl border border-default bg-surface p-6  ">
            @foreach($agents as $agent)
                <div class="flex items-center justify-between p-4 {{ !$loop->last ? 'border-b border-default' : '' }}">
                    <div class="flex items-center space-x-4 flex-1 min-w-0">
                        <!-- Status Badge -->
                        <div class="flex-shrink-0">
                            @if($agent->status === 'active')
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-success-300)] text-[var(--palette-success-950)]">
                                    Active
                                </span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-neutral-200)] text-[var(--palette-neutral-900)]">
                                    Inactive
                                </span>
                            @endif
                        </div>

                        <!-- Agent Details -->
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center space-x-2">
                                <flux:heading size="sm" class="truncate">{{ $agent->name }}</flux:heading>
                                
                                <!-- Agent Type Badge -->
                                @php
                                    $agentType = $agent->agent_type ?? 'individual';
                                @endphp
                                @if($agentType === 'workflow')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-notify-300)] text-[var(--palette-notify-950)]">
                                        <flux:icon.cog-6-tooth class="w-3 h-3 mr-1" />
                                        Workflow
                                    </span>
                                @elseif($agentType === 'direct')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-success-200)] text-[var(--palette-success-900)]">
                                        <flux:icon.bolt class="w-3 h-3 mr-1" />
                                        Direct Chat
                                    </span>
                                @elseif($agentType === 'promptly')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-warning-300)] text-[var(--palette-warning-950)]">
                                        <flux:icon.sparkles class="w-3 h-3 mr-1" />
                                        Promptly
                                    </span>
                                @elseif($agentType === 'synthesizer')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-200 text-purple-900 dark:bg-purple-900 dark:text-purple-100">
                                        <flux:icon.document-text class="w-3 h-3 mr-1" />
                                        Synthesizer
                                    </span>
                                @elseif($agentType === 'qa')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-200 text-orange-900 dark:bg-orange-900 dark:text-orange-100">
                                        <flux:icon.shield-check class="w-3 h-3 mr-1" />
                                        QA
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-neutral-200)] text-[var(--palette-neutral-900)]">
                                        <flux:icon.user class="w-3 h-3 mr-1" />
                                        Individual
                                    </span>
                                @endif
                                
                                @if($agent->is_public)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-notify-100)] text-[var(--palette-notify-800)]">Public</span>
                                @endif

                                @if($agent->show_in_chat)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-[var(--palette-success-100)] text-[var(--palette-success-800)]">
                                        <flux:icon.chat-bubble-left-right class="w-3 h-3 mr-1" />
                                        Chat Available
                                    </span>
                                @endif
                            </div>
                            
                            @if($agent->description)
                                <flux:subheading class="mt-1">{{ $agent->description }}</flux:subheading>
                            @endif
                            
                            <div class="mt-2 flex items-center space-x-4 flex-wrap">
                                <flux:text size="xs">{{ $agent->ai_provider }} • {{ $agent->ai_model }}</flux:text>
                                
                                @if(($agent->agent_type ?? 'individual') === 'workflow')
                                    <flux:text size="xs">{{ count($agent->workflow_config['agents'] ?? []) }} agents in workflow</flux:text>
                                @else
                                    <flux:text size="xs">{{ $agent->tools_count }} tools</flux:text>
                                @endif
                                
                                <flux:text size="xs">{{ $agent->executions_count }} executions</flux:text>
                                <flux:text size="xs">Created by {{ $agent->creator->name }}</flux:text>
                                <flux:text size="xs">{{ $agent->updated_at->diffForHumans() }}</flux:text>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center space-x-2 ml-4">
                        @php
                            $canEdit = auth()->user()->is_admin || $agent->created_by === auth()->id();
                        @endphp

                        <!-- Toggle Status (only for agents user can edit) -->
                        @if($canEdit)
                            <flux:button
                                wire:click="toggleAgentStatus({{ $agent->id }})"
                                variant="ghost"
                                size="sm">
                                {{ $agent->status === 'active' ? 'Deactivate' : 'Activate' }}
                            </flux:button>
                        @endif

                        <!-- Edit (only for agents user can edit) -->
                        @if($canEdit)
                            <flux:button
                                wire:click="editAgent({{ $agent->id }})"
                                variant="ghost"
                                size="sm"
                                icon="pencil">
                                Edit
                            </flux:button>
                        @endif

                        <!-- Duplicate (available for all agents) -->
                        <flux:button
                            wire:click="duplicateAgent({{ $agent->id }})"
                            variant="ghost"
                            size="sm"
                            icon="document-duplicate">
                            Duplicate
                        </flux:button>

                        <!-- Delete (only for agents user can edit) -->
                        @if($canEdit)
                            <flux:button
                                wire:click="deleteAgent({{ $agent->id }})"
                                onclick="return confirm('Are you sure you want to delete this agent? This action cannot be undone.')"
                                variant="ghost"
                                size="sm"
                                icon="trash"
                                class="text-error hover:text-error">
                                Delete
                            </flux:button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        <!-- Pagination -->
        @if($agents->hasPages())
            <div class="mt-6">
                {{ $agents->links() }}
            </div>
        @endif
    @else
        <!-- Empty State -->
        <div class="rounded-xl border border-default bg-surface text-center py-12  ">
            <div class="flex flex-col items-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center">
                    <flux:icon.cpu-chip class="h-12 w-12 text-tertiary" />
                </div>
                <flux:heading size="lg" class="mt-4">No agents found</flux:heading>
                <flux:subheading class="mt-2">
                    {{ $search || $selectedStatus !== 'all' || !$showOnlyMyAgents 
                        ? 'Try adjusting your filters to find agents.' 
                        : 'Get started by creating your first agent.' }}
                </flux:subheading>
                @if(!$search && $selectedStatus === 'all' && $showOnlyMyAgents)
                    <div class="mt-6">        <flux:button wire:click="createAgent" type="button" variant="primary" icon="plus">
            Create Agent
        </flux:button>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <!-- Agent Editor Modal -->
    @if($showCreateModal)
        @if($editingAgent)
            <livewire:agent-editor :agent="$editingAgent" wire:key="agent-editor-edit-{{ $editingAgent->id }}" />
        @else
            <livewire:agent-editor wire:key="agent-editor-create" />
        @endif
    @endif
</div>

@script
<script>
    // Listen for editor events
    $wire.on('agent-saved', () => {
        // Refresh the agents list
        $wire.$refresh();
    });
    
    // Show success/error messages
    $wire.on('success', (message) => {
        // You can integrate with your existing notification system
        console.log('Success:', message);
    });
    
    $wire.on('error', (message) => {
        // You can integrate with your existing notification system
        console.error('Error:', message);
    });
</script>
@endscript