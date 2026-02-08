<div class="rounded-xl border border-default bg-surface p-4  ">
    {{-- Summary Card --}}
    <div class="flex items-start justify-between">
        <div class="flex items-start space-x-3 flex-1">
            {{-- Icon --}}
            <div class="flex-shrink-0">
                <flux:icon.document-text class="w-6 h-6 text-accent" />
            </div>

            {{-- Plan Summary --}}
            <div class="flex-1 min-w-0">
                <div class="flex items-center space-x-2">
                    <flux:heading size="sm">Workflow Plan Generated</flux:heading>
                    @if($this->planType === 'parallel_research')
                        <flux:badge color="blue" size="sm">Research Plan</flux:badge>
                    @elseif($this->planType === 'workflow_plan')
                        <flux:badge color="purple" size="sm">Multi-Agent Workflow</flux:badge>
                    @endif
                </div>

                <div class="mt-2 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <flux:text size="xs" class="text-tertiary ">Strategy</flux:text>
                        <flux:text size="sm" class="font-medium">{{ ucfirst(str_replace('_', ' ', $this->strategyType)) }}</flux:text>
                    </div>

                    <div>
                        <flux:text size="xs" class="text-tertiary ">
                            @if($this->planType === 'parallel_research')
                                Research Threads
                            @else
                                Stages
                            @endif
                        </flux:text>
                        <flux:text size="sm" class="font-medium">{{ $this->totalStages }}</flux:text>
                    </div>

                    <div>
                        <flux:text size="xs" class="text-tertiary ">Est. Duration</flux:text>
                        <flux:text size="sm" class="font-medium">
                            @if($this->estimatedDuration < 60)
                                {{ $this->estimatedDuration }}s
                            @else
                                {{ round($this->estimatedDuration / 60, 1) }} min
                            @endif
                        </flux:text>
                    </div>

                    <div>
                        <flux:text size="xs" class="text-tertiary ">Generated</flux:text>
                        <flux:text size="sm" class="font-medium">
                            {{ \Carbon\Carbon::parse($workflowPlan['generated_at'])->diffForHumans() }}
                        </flux:text>
                    </div>
                </div>
            </div>
        </div>

        {{-- Expand/Collapse Button --}}
        <div class="flex-shrink-0 ml-4">
            <flux:button wire:click="toggleExpanded" variant="ghost" size="sm" icon="{{ $expanded ? 'chevron-up' : 'chevron-down' }}">
                {{ $expanded ? 'Hide' : 'Show' }} Details
            </flux:button>
        </div>
    </div>

    {{-- Expanded Details --}}
    @if($expanded)
        <div class="mt-4 pt-4 border-t border-default ">
            @if($this->planType === 'parallel_research')
                {{-- OLD System: Parallel Research Plan --}}
                <flux:heading size="sm" class="mb-3">Research Plan Details</flux:heading>

                <div class="space-y-3">
                    @foreach($workflowPlan['sub_queries'] as $index => $subQuery)
                        <div class="rounded-lg border border-default bg-surface p-3  ">
                            <div class="flex items-start space-x-2">
                                <flux:badge color="indigo" size="sm">Thread {{ $index + 1 }}</flux:badge>
                                <div class="flex-1 min-w-0">
                                    <flux:text size="sm" class="font-medium">{{ $subQuery['query'] ?? $subQuery }}</flux:text>
                                    @if(is_array($subQuery) && isset($subQuery['focus']))
                                        <flux:text size="xs" class="text-tertiary  mt-1">
                                            Focus: {{ $subQuery['focus'] }}
                                        </flux:text>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

            @elseif($this->planType === 'workflow_plan')
                {{-- NEW System: WorkflowPlan --}}
                <flux:heading size="sm" class="mb-3">Workflow Plan Details</flux:heading>

                @if(isset($workflowPlan['original_query']))
                    <div class="rounded-lg border border-default bg-surface p-3 mb-3  ">
                        <flux:text size="xs" class="text-tertiary ">Original Query</flux:text>
                        <flux:text size="sm" class="font-medium mt-1">{{ $workflowPlan['original_query'] }}</flux:text>
                    </div>
                @endif

                <div class="space-y-4">
                    @foreach($workflowPlan['stages'] as $stageIndex => $stage)
                        <div class="rounded-lg border border-default bg-surface p-3  ">
                            <div class="flex items-center space-x-2 mb-3">
                                <flux:badge color="purple" size="sm">Stage {{ $stageIndex + 1 }}</flux:badge>
                                <flux:badge color="{{ $stage['type'] === 'parallel' ? 'blue' : 'green' }}" size="sm">
                                    {{ ucfirst($stage['type']) }} Execution
                                </flux:badge>
                            </div>

                            <div class="space-y-2">
                                @foreach($stage['nodes'] as $nodeIndex => $node)
                                    <div class="rounded border border-default bg-surface p-2  ">
                                        <div class="flex items-start justify-between">
                                            <div class="flex-1 min-w-0">
                                                <flux:text size="sm" class="font-medium">{{ $node['agent_name'] }}</flux:text>
                                                <flux:text size="xs" class="text-secondary mt-1">{{ $node['input'] }}</flux:text>
                                            </div>
                                        </div>

                                        @if(isset($node['rationale']) && $node['rationale'])
                                            <div class="mt-2 pt-2 border-t border-default ">
                                                <flux:text size="xs" class="text-tertiary ">
                                                    <span class="font-medium">Rationale:</span> {{ $node['rationale'] }}
                                                </flux:text>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                @if(isset($workflowPlan['synthesizer_agent_id']))
                    <div class="mt-4 rounded-lg border border-accent/30 bg-accent/10 p-3">
                        <div class="flex items-center space-x-2">
                            <flux:icon.beaker class="w-4 h-4 text-accent" />
                            <flux:text size="sm" class="font-medium text-primary">
                                Synthesis Agent: ID {{ $workflowPlan['synthesizer_agent_id'] }}
                            </flux:text>
                        </div>
                        <flux:text size="xs" class="text-accent mt-1">
                            Will combine results from all stages into cohesive response
                        </flux:text>
                    </div>
                @endif
            @endif
        </div>
    @endif
</div>
