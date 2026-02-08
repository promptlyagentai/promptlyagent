@props([
    'interactions',
    'currentInteractionId',
    'isStreaming',
    'executionSteps',
    'stepCounter',
    'pendingQuestion',
    'formatStepDescription'
])

<div x-show="currentTab === 'steps'" x-transition class="h-full overflow-hidden">
    <div class="h-full flex flex-col border border-default rounded">
        <div class="flex-1 overflow-y-auto">
        <div class="relative p-4">
            <!-- Timeline line -->
            <div class="absolute left-10 top-4 bottom-4 w-px bg-border-default"></div>

        @if(count($interactions) > 0)
            @foreach($interactions as $loop_interaction)
                @php
                    // For Sources/Steps tabs: show if has answer OR is current interaction (even without streaming flag)
                    $shouldShowSteps = $loop_interaction->answer ||
                                      ($loop_interaction->id === $currentInteractionId && ($isStreaming || empty(trim($loop_interaction->answer))));
                @endphp
                @if($shouldShowSteps) <!-- Show steps for completed interactions OR current interaction (streaming or no answer yet) -->
                    <div class="relative">
                        <!-- Timeline dot -->
                        <div class="absolute left-4 w-4 h-4 bg-surface border-2 border-default rounded-full flex items-center justify-center">
                            <div class="w-2 h-2 bg-accent rounded-full"></div>
                        </div>

                        <!-- Content -->
                        <div class="ml-12 pb-8">
                            <!-- Interaction Header - Query + Timestamp on one line -->
                            <div class="flex items-center justify-between mb-3 cursor-pointer hover:bg-surface  rounded"
                                 wire:click="openInteractionModal({{ $loop_interaction->id }})">
                                <div class="font-medium text-sm text-primary flex-1 pr-4">
                                    {{ Str::limit($loop_interaction->question, 60) }}
                                </div>
                                <div class="text-xs text-tertiary flex-shrink-0">
                                    {{ $loop_interaction->created_at->format('M j, H:i') }}
                                </div>
                            </div>

                            <!-- Workflow Plan Display -->
                            @if($loop_interaction->execution && $loop_interaction->execution->workflow_plan)
                                <div class="mb-4">
                                    <livewire:workflow-plan-display :workflow-plan="$loop_interaction->execution->workflow_plan" :key="'plan-'.$loop_interaction->id" />
                                </div>
                            @endif

                            <!-- Steps for this interaction -->
                            <div class="space-y-1" wire:key="steps-{{ $stepCounter }}">
                            @if($loop_interaction->id === $currentInteractionId)
                                <!-- Current interaction - show from Alpine.js data during streaming -->
                                <div class="text-xs text-gray-500 mb-2" x-show="stepsData.length > 0">
                                    Live steps (<span x-text="stepsData.length">0</span> total)
                                </div>

                                <!-- Alpine.js rendered steps for current interaction -->
                                <template x-for="(step, index) in stepsData" :key="index">
                                    <div class="flex items-center gap-3 py-1 px-2 text-sm hover:bg-surface  rounded cursor-pointer"
                                         @click="if(step.id) { $wire.dispatch('openStepModal', { stepId: step.id }); }">
                                        <div class="w-1.5 h-1.5 bg-accent rounded-full flex-shrink-0"></div>

                                        <div class="text-secondary truncate flex-1 min-w-0" x-text="step.description || ''"></div>
                                        <span x-show="step.tool" class="text-xs px-2 py-0.5 bg-accent text-accent-foreground rounded flex-shrink-0" x-text="step.tool"></span>
                                        <span x-show="step.duration_formatted" class="text-xs px-1.5 py-0.5 bg-success text-success-contrast rounded flex-shrink-0" x-text="step.duration_formatted" title="Step duration"></span>
                                        <span x-show="step.timestamp" class="text-xs text-tertiary flex-shrink-0" x-text="step.timestamp"></span>
                                    </div>
                                </template>

                                <!-- Fallback: Show Blade-rendered steps if Alpine.js data is empty (for completed interactions) -->
                                <div x-show="stepsData.length === 0" style="display: none;">
                                    @foreach($executionSteps as $step)
                                        <div class="flex items-center gap-3 py-1 px-2 text-sm hover:bg-surface  rounded {{ !empty($step['id']) ? 'cursor-pointer' : '' }}"
                                             @if(!empty($step['id'])) wire:click="$dispatch('openStepModal', { stepId: '{{ $step['id'] }}' })" @endif>
                                            <div class="w-1.5 h-1.5 bg-accent rounded-full flex-shrink-0"></div>
                                            @if(!empty($step['action']))
                                                <div class="font-medium text-primary min-w-0 flex-shrink-0">{{ $step['action'] }}</div>
                                            @endif
                                            <div class="text-secondary truncate flex-1 min-w-0">{!! $formatStepDescription($step['description'] ?? '') !!}</div>
                                            @if(isset($step['tool']))
                                                <span class="text-xs px-2 py-0.5 bg-accent text-accent-foreground rounded flex-shrink-0">{{ $step['tool'] }}</span>
                                            @endif
                                            @if(isset($step['duration_formatted']))
                                                <span class="text-xs px-1.5 py-0.5 bg-success text-success-contrast rounded flex-shrink-0" title="Step duration">{{ $step['duration_formatted'] }}</span>
                                            @endif
                                            @if(isset($step['timestamp']))
                                                <span class="text-xs text-tertiary flex-shrink-0">{{ $step['timestamp'] }}</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <!-- Past interactions - show combined timeline (StatusStream + AgentExecution phases) -->
                                @php
                                    $combinedSteps = $this->getCombinedTimelineForInteraction($loop_interaction->id);
                                @endphp
                                @if(!empty($combinedSteps))
                                    <div class="text-xs text-gray-500 mb-2">Combined timeline ({{ count($combinedSteps) }} total)</div>
                                    @foreach($combinedSteps as $step)
                                        <div class="flex items-center gap-3 py-1 px-2 text-sm hover:bg-surface  rounded {{ !empty($step['id']) ? 'cursor-pointer' : '' }}"
                                             @if(!empty($step['id'])) wire:click="$dispatch('openStepModal', { stepId: '{{ $step['id'] }}' })" @endif>
                                            <div class="w-1.5 h-1.5 {{ $step['type'] === 'phase' ? 'bg-accent' : 'bg-accent' }} rounded-full flex-shrink-0"></div>
                                            @if(!empty($step['action']))
                                                <div class="font-medium text-primary min-w-0 flex-shrink-0">{{ $step['action'] }}</div>
                                            @endif
                                            <div class="text-secondary truncate flex-1 min-w-0">
                                                {!! $formatStepDescription($step['description'] ?? '') !!}
                                            </div>
                                            @if(isset($step['tool']))
                                                <span class="text-xs px-2 py-0.5 {{ $step['type'] === 'phase' ? 'bg-accent text-accent-foreground' : 'bg-success text-success-contrast' }} rounded flex-shrink-0">
                                                    {{ $step['tool'] }}
                                                </span>
                                            @endif
                                            @if(isset($step['timestamp']))
                                                <span class="text-xs text-tertiary flex-shrink-0">{{ $step['timestamp'] }}</span>
                                            @endif
                                            @if(isset($step['type']))
                                                <span class="text-xs px-1.5 py-0.5 {{ $step['type'] === 'phase' ? 'bg-accent/10 text-accent' : 'bg-success text-success-contrast' }} rounded flex-shrink-0">
                                                    {{ $step['type'] }}
                                                </span>
                                            @endif
                                        </div>
                                    @endforeach
                                @else
                                    <!-- Fallback for interactions without any steps -->
                                    <div class="flex items-center gap-3 py-1 px-2 text-sm hover:bg-surface  rounded">
                                        <div class="w-1.5 h-1.5 bg-accent rounded-full flex-shrink-0"></div>
                                        <div class="font-medium text-primary min-w-0 flex-shrink-0">
                                            Research Completed
                                        </div>
                                        <div class="text-secondary truncate flex-1 min-w-0">
                                            AI research and analysis completed successfully
                                        </div>
                                        <span class="text-xs px-2 py-0.5 bg-success text-success-contrast rounded flex-shrink-0">
                                            system
                                        </span>
                                        <span class="text-xs text-tertiary flex-shrink-0">{{ $loop_interaction->updated_at->format('H:i') }}</span>
                                    </div>
                                @endif
                            @endif
                            </div>
                        </div>
                    </div>
                @endif
            @endforeach
        @else
            {{-- Show pending steps if we have them during streaming --}}
            @if($isStreaming && !empty($executionSteps))
                <div class="relative">
                    <!-- Timeline dot -->
                    <div class="absolute left-4 w-4 h-4 bg-surface border-2 border-default rounded-full flex items-center justify-center">
                        <div class="w-2 h-2 bg-accent rounded-full animate-pulse"></div>
                    </div>

                    <!-- Content -->
                    <div class="ml-12 pb-8">
                        <!-- Pending Interaction Header -->
                        <div class="flex items-center justify-between mb-3 {{ $currentInteractionId ? 'cursor-pointer hover:bg-surface  rounded' : '' }}"
                             @if($currentInteractionId) wire:click="openInteractionModal({{ $currentInteractionId }})" @endif>
                            <div class="font-medium text-sm text-primary flex-1 pr-4">
                                {{ $pendingQuestion ?: 'Current Research' }}
                            </div>
                            <div class="text-xs text-tertiary flex-shrink-0">
                                In Progress...
                            </div>
                        </div>

                        <!-- Pending steps -->
                        <div class="space-y-1">
                            <div class="text-xs text-gray-500 mb-2">Live steps ({{ count($executionSteps) }} total)</div>
                            @foreach($executionSteps as $step)
                                <div class="flex items-center gap-3 py-1 px-2 text-sm hover:bg-surface  rounded {{ !empty($step['id']) ? 'cursor-pointer' : '' }}"
                                     @if(!empty($step['id'])) wire:click="$dispatch('openStepModal', { stepId: '{{ $step['id'] }}' })" @endif>
                                    <div class="w-1.5 h-1.5 bg-accent rounded-full flex-shrink-0"></div>
                                    @if(!empty($step['action']))
                                        <div class="font-medium text-primary min-w-0 flex-shrink-0">{{ $step['action'] }}</div>
                                    @endif
                                    <div class="text-secondary truncate flex-1 min-w-0">
                                        {!! $formatStepDescription($step['description'] ?? '') !!}
                                    </div>
                                    @if(isset($step['tool']))
                                        <span class="text-xs px-2 py-0.5 bg-accent text-accent-foreground rounded flex-shrink-0">
                                            {{ $step['tool'] }}
                                        </span>
                                    @endif
                                    @if(isset($step['duration_formatted']))
                                        <span class="text-xs px-1.5 py-0.5 bg-success text-success-contrast rounded flex-shrink-0" title="Step duration">{{ $step['duration_formatted'] }}</span>
                                    @endif
                                    @if(isset($step['timestamp']))
                                        <span class="text-xs text-tertiary flex-shrink-0">{{ $step['timestamp'] }}</span>
                                    @endif>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @else
                <div class="ml-12 text-center text-tertiary p-8">
                    Execution steps will appear here during agent workflow processing.
                </div>
            @endif
        @endif
        </div>
        </div>
    </div>
</div>
