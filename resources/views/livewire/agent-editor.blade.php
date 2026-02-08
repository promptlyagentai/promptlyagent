{{--
    Agent Editor Modal Component

    Purpose: Create and edit AI agent configurations with tools, knowledge, and workflows

    Features:
    - Agent type selection (individual, direct, workflow, promptly)
    - Tool configuration with priority levels
    - Knowledge document/tag assignment
    - Workflow agent orchestration
    - Real-time tool validation

    Livewire Properties:
    - @property bool $showModal Controls modal visibility
    - @property bool $isEditing Whether editing existing agent
    - @property string $agent_type Type of agent (individual/direct/workflow)
    - @property array $selectedTools Tools configured for agent
    - @property array $toolConfigs Tool priority and execution settings
    - @property array $workflow_agents Agents in workflow (for workflow type)
    - @property string $knowledgeAssignmentType Knowledge access type (none/documents/tags/all)

    Tool Priority System:
    - preferred: Highest priority, used first
    - standard: Used when preferred insufficient
    - fallback: Used only when other tools fail
--}}
<div>
    @if($showModal)
        <flux:modal wire:model.live="showModal" class="w-[80vw] max-w-none">
            <form wire:submit.prevent="save">
                    {{-- Modal Header with title and description --}}
                    <div class="flex items-start justify-between">
                        <div>
                            <flux:heading>
                                {{ $isEditing ? 'Edit Agent' : 'Create New Agent' }}
                            </flux:heading>
                            <flux:subheading>
                                {{ $isEditing ? 'Update your agent configuration and tools' : 'Configure your AI agent with custom tools and settings' }}
                            </flux:subheading>
                        </div>
                    </div>

                    <!-- Basic Information -->
                    <div class="space-y-6">
                        <!-- Agent Type Selection -->
                        <flux:field>
                            <flux:label>Agent Type</flux:label>
                            <select wire:model.live="agent_type" class="w-full rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent   dark:focus:ring-accent" {{ $isEditing ? 'disabled' : '' }}>
                                <option value="individual">Individual Agent</option>
                                <option value="direct">Direct Chat Agent</option>
                                @if($isEditing && $agent && in_array($agent->agent_type, ['workflow', 'promptly']))
                                    <option value="workflow">Multi-Agent Workflow</option>
                                    <option value="promptly">Promptly Meta-Agent</option>
                                @endif
                            </select>
                            <flux:description>
                                @if($agent_type === 'workflow' && !$isEditing)
                                    <span class="text-[var(--palette-warning-700)]">⚠️ Manual workflow creation is deprecated. Complex queries now use AI-generated workflows via Research Planner.</span>
                                @elseif($agent_type === 'workflow')
                                    You are editing an existing workflow agent. This workflow will continue to function as configured.
                                @elseif($agent_type === 'promptly')
                                    Promptly meta-agent uses AI to dynamically select the best agent for each query. No pre-configured workflow needed.
                                @elseif($agent_type === 'direct')
                                    Direct Chat agents provide real-time streaming responses with immediate interactive feedback.
                                @else
                                    Individual agents use tools and AI models directly. For complex multi-agent workflows, use Research Planner.
                                @endif
                            </flux:description>
                        </flux:field>

                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                            <!-- Name -->
                            <flux:input
                                wire:model.blur="name"
                                label="Agent Name"
                                placeholder="{{ $agent_type === 'workflow' ? 'My Agent Workflow' : 'My Research Agent' }}"
                                required
                                error="{{ $errors->first('name') }}" />

                            <!-- Status -->
                            <flux:field>
                                <flux:label>Status</flux:label>
                                <select wire:model="status" class="w-full rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent   dark:focus:ring-accent">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </flux:field>
                        </div>

                        <!-- Description -->
                        <flux:textarea 
                            wire:model="description" 
                            label="Description" 
                            placeholder="Describe what this agent does..."
                            rows="2" />

                        <!-- System Prompt -->
                        <flux:textarea
                            wire:model.blur="system_prompt"
                            label="{{ $agent_type === 'workflow' ? 'Workflow Orchestration Prompt' : 'System Prompt' }}"
                            placeholder="{{ $agent_type === 'workflow' ? 'You are a workflow orchestrator that coordinates multiple agents and synthesizes their results...' : 'You are a helpful AI assistant that...' }}"
                            rows="6"
                            required
                            error="{{ $errors->first('system_prompt') }}" />

                        @if($agent_type === 'workflow')
                            <flux:field>
                                <flux:description>
                                    This prompt guides how the workflow coordinates agents and synthesizes their results into the final output.
                                    Use <code>{available_agents}</code> to include information about the configured agents and their capabilities.
                                    It determines the quality, style, and format of the combined results.
                                </flux:description>
                            </flux:field>
                        @endif

                        <!-- AI Configuration -->
                        <div class="grid grid-cols-1 gap-6 sm:grid-cols-3">
                            <!-- AI Provider -->
                            <flux:field>
                                <flux:label>AI Provider</flux:label>
                                <select wire:model.live="ai_provider" class="w-full rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent   dark:focus:ring-accent">
                                    @foreach($availableProviders as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </flux:field>

                            <!-- AI Model -->
                            <flux:field>
                                <flux:label>AI Model</flux:label>
                                <select wire:model="ai_model" class="w-full rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent   dark:focus:ring-accent">
                                    @foreach($availableModels as $modelKey => $modelName)
                                        <option value="{{ $modelKey }}">{{ $modelName }}</option>
                                    @endforeach
                                </select>
                            </flux:field>

                            <!-- Max Steps -->
                            <flux:input 
                                wire:model="max_steps" 
                                label="Max Steps" 
                                type="number" 
                                min="1" 
                                max="50" 
                                required />
                        </div>

                        <!-- Settings -->
                        @if($agent_type !== 'workflow' || !$isEditing)
                        <div class="space-y-3">
                            <flux:checkbox wire:model="is_public" label="Make this agent public" />
                            <flux:checkbox wire:model="show_in_chat" label="Show in chat interface" />
                            <div class="flex items-center space-x-2">
                                <flux:checkbox wire:model="available_for_research" label="Available for research operations" />
                                <flux:tooltip>
                                    <flux:icon.information-circle class="w-4 h-4 text-tertiary" />
                                    <flux:tooltip.content>
                                        When enabled, this agent can be automatically selected by the Research Planner
                                        for specialized research tasks based on its capabilities and expertise.
                                    </flux:tooltip.content>
                                </flux:tooltip>
                            </div>
                            <div class="flex items-center space-x-2">
                                <flux:checkbox wire:model="enforce_response_language" label="Enforce response language" />
                                <flux:tooltip>
                                    <flux:icon.information-circle class="w-4 h-4 text-tertiary" />
                                    <flux:tooltip.content>
                                        When enabled, the agent will always respond in the user's query language,
                                        regardless of the language used in sources or retrieved documents.
                                    </flux:tooltip.content>
                                </flux:tooltip>
                            </div>
                        </div>
                        @endif
                    </div>

                    <!-- Workflow Configuration -->
                    @if($agent_type === 'workflow' && !$isEditing)
                        <div class="mt-8 border-t border-default pt-8">
                            <flux:heading size="lg" class="mb-4">Workflow Configuration</flux:heading>
                            
                            <!-- Available Agents -->
                            <div class="mb-6">
                                <flux:subheading class="mb-3">Available Agents</flux:subheading>
                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                    @forelse($available_agents as $agent)
                                        <div class="rounded-lg border border-default bg-surface p-4  ">
                                            <div class="flex items-center justify-between">
                                                <div class="flex-1 min-w-0">
                                                    <flux:heading size="sm">{{ $agent['name'] }}</flux:heading>
                                                    <flux:text size="xs" class="truncate">{{ $agent['description'] }}</flux:text>
                                                    <flux:badge color="blue" size="sm" class="mt-2">
                                                        Individual Agent
                                                    </flux:badge>
                                                </div>
                                                <flux:button 
                                                    type="button" 
                                                    wire:click="addWorkflowAgent({{ $agent['id'] }})"
                                                    size="sm"
                                                    variant="{{ in_array($agent['id'], array_column($workflow_agents, 'id')) ? 'ghost' : 'primary' }}"
                                                    :disabled="in_array($agent['id'], array_column($workflow_agents, 'id'))"
                                                    class="ml-2">
                                                    {{ in_array($agent['id'], array_column($workflow_agents, 'id')) ? 'Added' : 'Add' }}
                                                </flux:button>
                                            </div>
                                        </div>
                                    @empty
                                        <div class="col-span-full">
                                            <flux:text class="text-center text-tertiary">No individual agents available. Create some individual agents first to use in workflows.</flux:text>
                                        </div>
                                    @endforelse
                                </div>
                            </div>

                            <!-- Selected Workflow Agents -->
                            @if(!empty($workflow_agents))
                                <div>
                                    <flux:subheading class="mb-3">Workflow Agents ({{ count($workflow_agents) }})</flux:subheading>
                                    <flux:text size="sm" class="mb-4 text-tertiary ">Agents will execute in the order shown below. Click and drag to reorder.</flux:text>
                                    
                                    <div class="space-y-3">
                                        @foreach($workflow_agents as $index => $workflowAgent)
                                            <div class="rounded-lg border border-default bg-surface p-4  ">
                                                <div class="flex items-center justify-between">
                                                    <div class="flex items-center space-x-3 flex-1">
                                                        <div class="flex items-center justify-center w-8 h-8 bg-accent/20 text-accent rounded-full text-sm font-medium">
                                                            {{ $workflowAgent['execution_order'] }}
                                                        </div>
                                                        <div>
                                                            <flux:heading size="sm">{{ $workflowAgent['name'] }}</flux:heading>
                                                            <flux:text size="xs">{{ $workflowAgent['description'] }}</flux:text>
                                                        </div>
                                                        <flux:checkbox 
                                                            wire:click="toggleWorkflowAgent({{ $workflowAgent['id'] }})" 
                                                            :checked="$workflowAgent['enabled'] ?? true"
                                                            label="Enabled"
                                                            size="sm" />
                                                    </div>

                                                    <div class="flex items-center space-x-1">
                                                        <!-- Move Up -->
                                                        <flux:button 
                                                            type="button" 
                                                            wire:click="moveWorkflowAgentUp({{ $workflowAgent['id'] }})"
                                                            variant="ghost" 
                                                            size="sm"
                                                            icon="chevron-up"
                                                            :disabled="$index === 0" />

                                                        <!-- Move Down -->
                                                        <flux:button 
                                                            type="button" 
                                                            wire:click="moveWorkflowAgentDown({{ $workflowAgent['id'] }})"
                                                            variant="ghost" 
                                                            size="sm"
                                                            icon="chevron-down"
                                                            :disabled="$index === count($workflow_agents) - 1" />

                                                        <!-- Remove -->
                                                        <flux:button 
                                                            type="button" 
                                                            wire:click="removeWorkflowAgent({{ $workflowAgent['id'] }})"
                                                            variant="ghost" 
                                                            size="sm"
                                                            icon="x-mark"
                                                            class="text-[var(--palette-error-700)] hover:text-[var(--palette-error-800)]" />
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endif

                    <!-- Tools Configuration (Individual-Type Agents) -->
                    @if(in_array($agent_type, ['individual', 'direct']))
                        <div class="mt-8 border-t border-default pt-8">
                        <flux:heading size="lg" class="mb-4">Tools Configuration</flux:heading>
                        
                        <!-- Priority System Explanation -->
                        <div class="mb-6 p-4 bg-accent/10 rounded-lg border border-accent">
                            <flux:heading size="sm" class="text-primary mb-2">🎯 Tool Priority System</flux:heading>
                            <div class="text-sm text-secondary space-y-1">
                                <p><strong>🔒 Preferred Tools:</strong> Highest priority, used first. Perfect for company data or verified sources.</p>
                                <p><strong>⚡ Standard Tools:</strong> Used when preferred tools are insufficient or fail.</p>
                                <p><strong>🔄 Fallback Tools:</strong> Used only when other tools fail or return no results.</p>
                                <p class="mt-2 text-xs">This system ensures data quality by prioritizing verified sources and avoiding mixing high-quality data with broad search results.</p>
                            </div>
                        </div>
                                
                        <!-- Available Tools -->
                        <div class="mb-6">
                            <flux:subheading class="mb-3">Available Tools</flux:subheading>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                @foreach($availableTools as $tool)
                                    <div class="rounded-lg border border-default bg-surface p-4  ">
                                        <div class="flex items-center justify-between">
                                            <div class="flex-1 min-w-0">
                                                <flux:heading size="sm">{{ $tool['display_name'] ?? $tool['name'] }}</flux:heading>
                                                <flux:text size="xs" class="truncate">{{ $tool['description'] }}</flux:text>
                                                <flux:badge 
                                                    color="{{ $tool['source'] === 'local' ? 'green' : 'blue' }}" 
                                                    size="sm" 
                                                    class="mt-2">
                                                    {{ $tool['source'] }}
                                                </flux:badge>
                                            </div>
                                            <flux:button 
                                                type="button" 
                                                wire:click="addTool('{{ $tool['name'] }}')"
                                                size="sm"
                                                variant="{{ in_array($tool['name'], $selectedTools) ? 'ghost' : 'primary' }}"
                                                :disabled="in_array($tool['name'], $selectedTools)"
                                                class="ml-2">
                                                {{ in_array($tool['name'], $selectedTools) ? 'Added' : 'Add' }}
                                            </flux:button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <!-- Selected Tools -->
                        @if(!empty($selectedTools))
                            <div>
                                <flux:subheading class="mb-3">Selected Tools ({{ count($selectedTools) }})</flux:subheading>
                                <div class="space-y-3">
                                    @foreach($selectedTools as $index => $toolName)
                                        <div class="rounded-lg border border-default bg-surface p-4  ">
                                            <!-- Tool Header -->
                                            <div class="flex items-center justify-between mb-3">
                                                <div class="flex items-center space-x-3 flex-1">
                                                    <flux:heading size="sm">{{ $toolName }}</flux:heading>
                                                    <flux:text size="xs">Order: {{ $toolConfigs[$toolName]['execution_order'] ?? $index + 1 }}</flux:text>
                                                    <flux:checkbox 
                                                        wire:click="toggleTool('{{ $toolName }}')" 
                                                        :checked="$toolConfigs[$toolName]['enabled'] ?? true"
                                                        label="Enabled"
                                                        size="sm" />
                                                </div>

                                                <div class="flex items-center space-x-1">
                                                    <!-- Move Up -->
                                                    <flux:button 
                                                        type="button" 
                                                        wire:click="moveToolUp('{{ $toolName }}')"
                                                        variant="ghost" 
                                                        size="sm"
                                                        icon="chevron-up"
                                                        :disabled="$index === 0" />

                                                    <!-- Move Down -->
                                                    <flux:button 
                                                        type="button" 
                                                        wire:click="moveToolDown('{{ $toolName }}')"
                                                        variant="ghost" 
                                                        size="sm"
                                                        icon="chevron-down"
                                                        :disabled="$index === count($selectedTools) - 1" />

                                                    <!-- Remove -->
                                                    <flux:button 
                                                        type="button" 
                                                        wire:click="removeTool('{{ $toolName }}')"
                                                        variant="ghost" 
                                                        size="sm"
                                                        icon="x-mark"
                                                        class="text-[var(--palette-error-600)] hover:text-[var(--palette-error-700)]" />
                                                </div>
                                            </div>

                                            <!-- Priority Configuration -->
                                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mt-3 pt-3 border-t border-default">
                                                <!-- Priority Level -->
                                                <div>
                                                    <flux:label size="xs">Priority Level</flux:label>
                                                    <select 
                                                        wire:change="updateToolPriority('{{ $toolName }}', $event.target.value)"
                                                        class="w-full rounded border-0 bg-surface px-2 py-1 text-xs ring-1 ring-default transition focus:ring-2 focus:ring-accent   dark:focus:ring-accent">
                                                        <option value="preferred" {{ ($toolConfigs[$toolName]['priority_level'] ?? 'standard') === 'preferred' ? 'selected' : '' }}>
                                                            🔒 Preferred
                                                        </option>
                                                        <option value="standard" {{ ($toolConfigs[$toolName]['priority_level'] ?? 'standard') === 'standard' ? 'selected' : '' }}>
                                                            ⚡ Standard
                                                        </option>
                                                        <option value="fallback" {{ ($toolConfigs[$toolName]['priority_level'] ?? 'standard') === 'fallback' ? 'selected' : '' }}>
                                                            🔄 Fallback
                                                        </option>
                                                    </select>
                                                </div>

                                                <!-- Execution Strategy -->
                                                <div>
                                                    <flux:label size="xs">Execution Strategy</flux:label>
                                                    <select 
                                                        wire:change="updateToolExecutionStrategy('{{ $toolName }}', $event.target.value)"
                                                        class="w-full rounded border-0 bg-surface px-2 py-1 text-xs ring-1 ring-default transition focus:ring-2 focus:ring-accent   dark:focus:ring-accent">
                                                        <option value="always" {{ ($toolConfigs[$toolName]['execution_strategy'] ?? 'always') === 'always' ? 'selected' : '' }}>
                                                            Always
                                                        </option>
                                                        <option value="if_preferred_fails" {{ ($toolConfigs[$toolName]['execution_strategy'] ?? 'always') === 'if_preferred_fails' ? 'selected' : '' }}>
                                                            If Preferred Fails
                                                        </option>
                                                        <option value="if_no_preferred_results" {{ ($toolConfigs[$toolName]['execution_strategy'] ?? 'always') === 'if_no_preferred_results' ? 'selected' : '' }}>
                                                            If No Preferred Results
                                                        </option>
                                                        <option value="never_if_preferred_succeeds" {{ ($toolConfigs[$toolName]['execution_strategy'] ?? 'always') === 'never_if_preferred_succeeds' ? 'selected' : '' }}>
                                                            Never If Preferred Succeeds
                                                        </option>
                                                    </select>
                                                </div>

                                                <!-- Min Results Threshold -->
                                                <div>
                                                    <flux:label size="xs">Min Results</flux:label>
                                                    <input 
                                                        type="number" 
                                                        wire:change="updateToolMinResults('{{ $toolName }}', $event.target.value)"
                                                        value="{{ $toolConfigs[$toolName]['min_results_threshold'] ?? '' }}"
                                                        placeholder="Optional"
                                                        class="w-full rounded border-0 bg-surface px-2 py-1 text-xs ring-1 ring-default transition focus:ring-2 focus:ring-accent   dark:focus:ring-accent" />
                                                </div>

                                                <!-- Max Execution Time -->
                                                <div>
                                                    <flux:label size="xs">Max Time (ms)</flux:label>
                                                    <input 
                                                        type="number" 
                                                        wire:change="updateToolMaxExecutionTime('{{ $toolName }}', $event.target.value)"
                                                        value="{{ $toolConfigs[$toolName]['max_execution_time'] ?? 30000 }}"
                                                        placeholder="30000"
                                                        class="w-full rounded border-0 bg-surface px-2 py-1 text-xs ring-1 ring-default transition focus:ring-2 focus:ring-accent   dark:focus:ring-accent" />
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        </div>
                    @endif

                    <!-- Knowledge Assignment Configuration -->
                    @if($agent_type !== 'workflow' || !$isEditing)
                    <div class="mt-8 border-t border-default pt-8">
                        <flux:heading size="lg" class="mb-4">Knowledge Assignment</flux:heading>
                        <flux:description class="mb-6">
                            Configure which knowledge documents and data this agent can access for enhanced responses through Retrieval-Augmented Generation (RAG).
                        </flux:description>
                        
                        <!-- Assignment Type Selection -->
                        <flux:field class="mb-6">
                            <flux:label>Knowledge Access Type</flux:label>
                            <select wire:model.live="knowledgeAssignmentType" class="w-full rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent   dark:focus:ring-accent">
                                <option value="none">No Knowledge Access</option>
                                <option value="documents">Specific Documents</option>
                                <option value="tags">Knowledge by Tags</option>
                                <option value="all">All Knowledge Base</option>
                            </select>
                            <flux:description>
                                <strong>No Knowledge:</strong> Agent operates without knowledge base access<br>
                                <strong>Specific Documents:</strong> Access only selected documents<br>
                                <strong>Knowledge by Tags:</strong> Access documents with specific tags<br>
                                <strong>All Knowledge Base:</strong> Access to entire knowledge repository
                            </flux:description>
                        </flux:field>

                        @if($knowledgeAssignmentType !== 'none')
                            <!-- Priority Configuration -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <flux:field>
                                    <flux:label>Knowledge Priority</flux:label>
                                    <select wire:model="knowledgePriority" class="w-full rounded-lg border-0 bg-surface px-3 py-2 text-sm ring-1 ring-default transition focus:ring-2 focus:ring-accent   dark:focus:ring-accent">
                                        <option value="1">Low Priority (1)</option>
                                        <option value="2">Medium Priority (2)</option>
                                        <option value="3">High Priority (3)</option>
                                    </select>
                                    <flux:description>Higher priority knowledge will be preferred when multiple assignments exist</flux:description>
                                </flux:field>
                            </div>
                        @endif

                        <!-- Document Selection -->
                        @if($knowledgeAssignmentType === 'documents')
                            <div class="space-y-6">
                                <!-- Search Documents -->
                                <flux:input
                                    wire:model.live.debounce.300ms="knowledgeSearch"
                                    label="Search Documents"
                                    placeholder="Search by title or content..."
                                    class="mb-4">
                                    <flux:description>Search documents by title and content summary</flux:description>
                                </flux:input>

                                <!-- Available Documents -->
                                @if(!empty($availableDocuments))
                                    <div>
                                        <flux:subheading class="mb-3">Available Documents</flux:subheading>
                                        <div class="grid grid-cols-1 gap-3 max-h-64 overflow-y-auto">
                                            @foreach($availableDocuments as $document)
                                                <div class="rounded-lg border border-default bg-surface p-3  ">
                                                    <div class="flex items-center justify-between">
                                                        <div class="flex-1 min-w-0">
                                                            <flux:heading size="sm">{{ $document['title'] }}</flux:heading>
                                                            <div class="flex items-center space-x-2 mt-1">
                                                                <flux:badge color="blue" size="sm">
                                                                    {{ $document['type'] }}
                                                                </flux:badge>
                                                                <flux:text size="xs" class="text-tertiary">
                                                                    Updated {{ $document['updated_at'] }}
                                                                </flux:text>
                                                                @if(isset($document['score']) && $document['score'] !== null)
                                                                    <flux:badge color="green" size="sm">
                                                                        {{ number_format($document['score'], 2) }}
                                                                    </flux:badge>
                                                                @endif
                                                            </div>
                                                        </div>
                                                        <flux:button
                                                            type="button"
                                                            wire:click="addDocument({{ $document['id'] }})"
                                                            size="sm"
                                                            variant="{{ in_array($document['id'], $selectedDocuments) ? 'ghost' : 'primary' }}"
                                                            :disabled="in_array($document['id'], $selectedDocuments)"
                                                            class="ml-2">
                                                            {{ in_array($document['id'], $selectedDocuments) ? 'Added' : 'Add' }}
                                                        </flux:button>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                <!-- Selected Documents -->
                                @if(!empty($selectedDocuments))
                                    <div>
                                        <flux:subheading class="mb-3">Selected Documents ({{ count($selectedDocuments) }})</flux:subheading>
                                        <div class="space-y-2">
                                            @foreach($selectedDocuments as $documentId)
                                                @php
                                                    $document = collect($availableDocuments)->firstWhere('id', $documentId);
                                                @endphp
                                                @if($document)
                                                    <div class="flex items-center justify-between rounded-lg border border-[var(--palette-success-200)] bg-[var(--palette-success-100)] p-3">
                                                        <div class="flex-1">
                                                            <flux:heading size="sm" class="text-[var(--palette-success-900)]">
                                                                {{ $document['title'] }}
                                                            </flux:heading>
                                                            <flux:badge color="blue" size="sm">
                                                                {{ $document['type'] }}
                                                            </flux:badge>
                                                        </div>
                                                        <flux:button
                                                            type="button"
                                                            wire:click="removeDocument({{ $documentId }})"
                                                            variant="ghost"
                                                            size="sm"
                                                            icon="x-mark"
                                                            class="text-[var(--palette-error-700)] hover:text-[var(--palette-error-800)]" />
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif

                        <!-- Tag Selection -->
                        @if($knowledgeAssignmentType === 'tags')
                            <div class="space-y-6">
                                <!-- Available Tags -->
                                @if(!empty($availableTags))
                                    <div>
                                        <flux:subheading class="mb-3">Available Tags</flux:subheading>
                                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                            @foreach($availableTags as $tag)
                                                <div class="rounded-lg border border-default bg-surface p-3  ">
                                                    <div class="flex items-center justify-between">
                                                        <div class="flex-1 min-w-0">
                                                            <flux:heading size="sm">{{ $tag['name'] }}</flux:heading>
                                                            @if($tag['description'])
                                                                <flux:text size="xs" class="text-tertiary">
                                                                    {{ $tag['description'] }}
                                                                </flux:text>
                                                            @endif
                                                            @if($tag['color'])
                                                                <div class="flex items-center mt-1">
                                                                    <div class="w-3 h-3 rounded-full mr-2" style="background-color: {{ $tag['color'] }}"></div>
                                                                    <flux:text size="xs" class="text-tertiary">{{ $tag['color'] }}</flux:text>
                                                                </div>
                                                            @endif
                                                        </div>
                                                        <flux:button
                                                            type="button"
                                                            wire:click="addTag({{ $tag['id'] }})"
                                                            size="sm"
                                                            variant="{{ in_array($tag['id'], $selectedTags) ? 'ghost' : 'primary' }}"
                                                            :disabled="in_array($tag['id'], $selectedTags)"
                                                            class="ml-2">
                                                            {{ in_array($tag['id'], $selectedTags) ? 'Added' : 'Add' }}
                                                        </flux:button>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                <!-- Selected Tags -->
                                @if(!empty($selectedTags))
                                    <div>
                                        <flux:subheading class="mb-3">Selected Tags ({{ count($selectedTags) }})</flux:subheading>
                                        <div class="space-y-2">
                                            @foreach($selectedTags as $tagId)
                                                @php
                                                    $tag = collect($availableTags)->firstWhere('id', $tagId);
                                                @endphp
                                                @if($tag)
                                                    <div class="flex items-center justify-between rounded-lg border border-[var(--palette-success-200)] bg-[var(--palette-success-100)] p-3">
                                                        <div class="flex-1 flex items-center">
                                                            @if($tag['color'])
                                                                <div class="w-3 h-3 rounded-full mr-3" style="background-color: {{ $tag['color'] }}"></div>
                                                            @endif
                                                            <flux:heading size="sm" class="text-[var(--palette-success-900)]">
                                                                {{ $tag['name'] }}
                                                            </flux:heading>
                                                        </div>
                                                        <flux:button
                                                            type="button"
                                                            wire:click="removeTag({{ $tagId }})"
                                                            variant="ghost"
                                                            size="sm"
                                                            icon="x-mark"
                                                            class="text-[var(--palette-error-700)] hover:text-[var(--palette-error-800)]" />
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @endif

                        <!-- All Knowledge Info -->
                        @if($knowledgeAssignmentType === 'all')
                            <div class="rounded-lg border border-accent bg-accent/10 p-4">
                                <flux:heading size="sm" class="text-primary mb-2">
                                    🧠 Full Knowledge Base Access
                                </flux:heading>
                                <flux:text class="text-secondary">
                                    This agent will have access to all documents in the knowledge base. 
                                    RAG will automatically retrieve the most relevant information based on user queries.
                                </flux:text>
                            </div>
                        @endif
                    </div>
                    @endif

                    <!-- Footer -->
                    <div class="flex justify-end space-x-3 pt-6 border-t border-default">
                        <flux:button type="button" wire:click="closeEditor" variant="ghost">
                            Cancel
                        </flux:button>
                        <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                            <span wire:loading.remove>
                                {{ $isEditing ? 'Update Agent' : 'Create Agent' }}
                            </span>
                            <span wire:loading wire:target="save">
                                Saving...
                            </span>
                        </flux:button>
                    </div>
            </form>
        </flux:modal>
    @endif
</div>

@script
<script>
    // Listen for open/close events
    $wire.on('openAgentEditor', (agentId) => {
        // Modal will open automatically via showModal property
    });

    $wire.on('closeAgentEditor', () => {
        // Modal will close automatically via showModal property
    });
</script>
@endscript
