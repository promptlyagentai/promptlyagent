<x-layouts.app>
    <section class="w-full">
        @include('partials.settings-heading')

        <x-settings.layout
            :heading="__('Output Actions')"
            :subheading="__('Manage HTTP webhooks and automated actions triggered by agent completions')"
            wide>

            {{-- Success/Error Messages --}}
            @if (session('success'))
                <div class="mb-6 rounded-lg border border-[var(--palette-success-200)] bg-[var(--palette-success-100)] p-4">
                    <div class="flex items-center">
                        <svg class="h-5 w-5 text-success" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="ml-3 text-sm text-[var(--palette-success-800)]">{{ session('success') }}</span>
                    </div>
                </div>
            @endif

            @if (session('error'))
                <div class="mb-6 rounded-lg border border-[var(--palette-error-200)] bg-[var(--palette-error-100)] p-4">
                    <div class="flex items-center">
                        <svg class="h-5 w-5 text-[var(--palette-error-700)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="ml-3 text-sm text-[var(--palette-error-800)]">{{ session('error') }}</span>
                    </div>
                </div>
            @endif

            {{-- Actions List --}}
            @if($actions->count() > 0)
                <div class="space-y-3">
                    @foreach($actions as $action)
                        @php
                            $metadata = $providerMetadata[$action->provider_id] ?? null;
                            $icon = $metadata['icon'] ?? '🔗';
                            $iconSvg = $metadata['icon_svg'] ?? null;
                        @endphp

                        <div class="rounded-lg border border-default bg-surface p-4 transition hover:border-default   ">
                            <div class="flex items-center space-x-4">
                                {{-- Provider Icon --}}
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-surface  flex-shrink-0">
                                    @if($iconSvg)
                                        <div class="h-6 w-6 text-secondary ">{!! $iconSvg !!}</div>
                                    @else
                                        <span class="text-2xl">{{ $icon }}</span>
                                    @endif
                                </div>

                                {{-- Action Info --}}
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center space-x-2">
                                        <flux:heading size="sm">{{ $action->name }}</flux:heading>

                                        {{-- Status Badge --}}
                                        @if($action->status === 'active')
                                            <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                                        @elseif($action->status === 'paused')
                                            <flux:badge color="yellow" size="sm">{{ __('Paused') }}</flux:badge>
                                        @else
                                            <flux:badge color="gray" size="sm">{{ __('Disabled') }}</flux:badge>
                                        @endif

                                        {{-- Trigger Condition Badge --}}
                                        @if($action->trigger_on === 'success')
                                            <flux:badge color="blue" size="sm">On Success</flux:badge>
                                        @elseif($action->trigger_on === 'failure')
                                            <flux:badge color="red" size="sm">On Failure</flux:badge>
                                        @else
                                            <flux:badge color="purple" size="sm">Always</flux:badge>
                                        @endif
                                    </div>

                                    @if($action->description)
                                        <flux:text size="sm" class="mt-1 text-tertiary ">
                                            {{ Str::limit($action->description, 100) }}
                                        </flux:text>
                                    @endif

                                    {{-- Stats and Relationships --}}
                                    <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-tertiary ">
                                        {{-- Execution Stats --}}
                                        <div class="flex items-center gap-1">
                                            <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                            </svg>
                                            <span>{{ $action->total_executions }} executions</span>
                                        </div>

                                        {{-- Success Rate --}}
                                        @if($action->total_executions > 0)
                                            @php
                                                $successRate = round(($action->successful_executions / $action->total_executions) * 100);
                                            @endphp
                                            <div class="flex items-center gap-1">
                                                <svg class="h-3 w-3 {{ $successRate >= 80 ? 'text-success' : ($successRate >= 50 ? 'text-[var(--palette-warning-700)]' : 'text-[var(--palette-error-700)]') }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <span>{{ $successRate }}% success rate</span>
                                            </div>
                                        @endif

                                        {{-- Linked Agents --}}
                                        @if($action->agents->count() > 0)
                                            <div class="flex items-center gap-1">
                                                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                                                </svg>
                                                <span>{{ $action->agents->count() }} {{ Str::plural('agent', $action->agents->count()) }}</span>
                                            </div>
                                        @endif

                                        {{-- Linked Triggers --}}
                                        @if($action->inputTriggers->count() > 0)
                                            <div class="flex items-center gap-1">
                                                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                                </svg>
                                                <span>{{ $action->inputTriggers->count() }} {{ Str::plural('trigger', $action->inputTriggers->count()) }}</span>
                                            </div>
                                        @endif

                                        {{-- Last Executed --}}
                                        @if($action->last_executed_at)
                                            <div class="flex items-center gap-1">
                                                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <span>Last used {{ $action->last_executed_at->diffForHumans() }}</span>
                                            </div>
                                        @endif
                                    </div>

                                    {{-- Capability Badge --}}
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <flux:badge size="sm" color="green">Output</flux:badge>
                                    </div>
                                </div>

                                {{-- Action Buttons --}}
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    {{-- Configure Button --}}
                                    <a href="{{ route('integrations.action-details', $action) }}" title="{{ __('Configure') }}">
                                        <flux:button size="sm" variant="ghost" square>
                                            <flux:icon.cog class="h-4 w-4" />
                                        </flux:button>
                                    </a>

                                    {{-- Test Button --}}
                                    <form action="{{ route('integrations.test-action', $action) }}" method="POST">
                                        @csrf
                                        <flux:button size="sm" variant="ghost" type="submit" square title="{{ __('Test Action') }}">
                                            <flux:icon.arrow-path class="h-4 w-4" />
                                        </flux:button>
                                    </form>

                                    {{-- Delete Button --}}
                                    <form action="{{ route('integrations.delete-action', $action) }}" method="POST"
                                          onsubmit="return confirm('{{ __('Are you sure you want to delete this action?') }}')">
                                        @csrf
                                        @method('DELETE')
                                        <flux:button size="sm" variant="ghost" class="text-[var(--palette-error-700)] hover:text-[var(--palette-error-800)]" type="submit" square title="{{ __('Delete') }}">
                                            <flux:icon.trash class="h-4 w-4" />
                                        </flux:button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                {{-- Empty State --}}
                <div class="rounded-lg border border-dashed border-default bg-surface p-12 text-center  /50">
                    <svg class="mx-auto h-12 w-12 text-tertiary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                    </svg>
                    <flux:heading size="lg" class="mt-4 text-primary ">{{ __('No Output Actions Yet') }}</flux:heading>
                    <flux:text class="mt-2 text-tertiary ">
                        {{ __('Output actions allow you to trigger HTTP webhooks and automated tasks when your agents complete.') }}
                    </flux:text>
                    <div class="mt-6">
                        <a href="{{ route('integrations.index') }}">
                            <flux:button variant="primary">
                                {{ __('Browse Available Actions') }}
                            </flux:button>
                        </a>
                    </div>
                </div>
            @endif

        </x-settings.layout>
    </section>
</x-layouts.app>
