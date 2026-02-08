<x-layouts.app>
    <section class="w-full">
        @include('partials.settings-heading')

        <x-settings.layout
            :heading="$provider->getProviderName() . ' Triggers'"
            :subheading="$provider->getDescription()"
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

            {{-- Create New Trigger Button --}}
            <div class="mb-6 flex items-center justify-between">
                <div>
                    <flux:heading size="lg">{{ __('Your Triggers') }}</flux:heading>
                    <flux:text size="sm" class="mt-1 text-tertiary ">
                        {{ __('Manage your') }} {{ $provider->getProviderName() }} {{ __('triggers for invoking agents') }}
                    </flux:text>
                </div>
                <a href="{{ route('integrations.create-trigger', ['provider' => $provider->getTriggerType()]) }}">
                    <flux:button variant="primary" size="sm">
                        <flux:icon.plus class="h-4 w-4 mr-1" />
                        {{ __('Create New Trigger') }}
                    </flux:button>
                </a>
            </div>

            {{-- Triggers List --}}
            @if($triggers->isEmpty())
                <div class="rounded-lg border border-default bg-surface p-8 text-center  ">
                    <div class="flex flex-col items-center">
                        @if($svg = $provider->getTriggerIconSvg())
                            <div class="h-16 w-16 mb-3 text-secondary ">{!! $svg !!}</div>
                        @else
                            <span class="text-4xl mb-3">{{ $provider->getTriggerIcon() }}</span>
                        @endif
                        <flux:heading size="lg" class="mb-2">{{ __('No triggers yet') }}</flux:heading>
                        <flux:text class="mb-4 text-tertiary ">
                            {{ __('Create your first') }} {{ $provider->getProviderName() }} {{ __('trigger to start invoking agents') }}
                        </flux:text>
                        <a href="{{ route('integrations.create-trigger', ['provider' => $provider->getTriggerType()]) }}">
                            <flux:button variant="primary">
                                <flux:icon.plus class="h-4 w-4 mr-1" />
                                {{ __('Create Trigger') }}
                            </flux:button>
                        </a>
                    </div>
                </div>
            @else
                <div class="space-y-3">
                    @foreach($triggers as $trigger)
                        <div class="rounded-lg border border-default bg-surface p-4  ">
                            <div class="flex items-center space-x-4">
                                {{-- Provider Icon --}}
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-surface  flex-shrink-0">
                                    @if($svg = $provider->getTriggerIconSvg())
                                        <div class="h-6 w-6 text-secondary ">{!! $svg !!}</div>
                                    @else
                                        <span class="text-2xl">{{ $provider->getTriggerIcon() }}</span>
                                    @endif
                                </div>

                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center space-x-2">
                                        <flux:heading size="sm">{{ $trigger->name }}</flux:heading>
                                        @if($trigger->is_active)
                                            <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                                        @else
                                            <flux:badge color="gray" size="sm">{{ __('Inactive') }}</flux:badge>
                                        @endif
                                    </div>

                                    @if($trigger->description)
                                        <flux:text size="sm" class="mt-1 text-tertiary ">
                                            {{ $trigger->description }}
                                        </flux:text>
                                    @endif

                                    <div class="mt-2 space-y-1 text-sm text-tertiary ">
                                        <div>{{ __('Agent') }}: {{ $trigger->agent->name }}</div>
                                        <div>{{ __('Session Strategy') }}: {{ ucfirst(str_replace('_', ' ', $trigger->session_strategy)) }}</div>
                                        @if($trigger->usage_count > 0)
                                            <div>
                                                {{ __('Invocations') }}: {{ $trigger->usage_count }}
                                                @if($trigger->last_invoked_at)
                                                    ({{ $trigger->last_invoked_at->diffForHumans() }})
                                                @endif
                                            </div>
                                        @else
                                            <div class="text-tertiary">{{ __('Never used') }}</div>
                                        @endif
                                    </div>

                                    {{-- Capability Badge --}}
                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <flux:badge size="sm" color="green">{{ __('Input') }}</flux:badge>
                                    </div>
                                </div>

                                {{-- Action Buttons --}}
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    {{-- View Details Button --}}
                                    <a href="{{ route('integrations.trigger-details', $trigger) }}" title="{{ __('View Details') }}">
                                        <flux:button size="sm" variant="ghost" square>
                                            <flux:icon.eye class="h-4 w-4" />
                                        </flux:button>
                                    </a>

                                    {{-- Delete Button --}}
                                    <form action="{{ route('integrations.delete-trigger', $trigger) }}" method="POST"
                                          onsubmit="return confirm('{{ __('Are you sure you want to delete this trigger?') }}')">
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
            @endif

            {{-- Back to Integrations --}}
            <div class="mt-6">
                <a href="{{ route('integrations.index') }}">
                    <flux:button variant="ghost" size="sm">
                        <flux:icon.arrow-left class="h-4 w-4 mr-1" />
                        {{ __('Back to Integrations') }}
                    </flux:button>
                </a>
            </div>
        </x-settings.layout>
    </section>
</x-layouts.app>
