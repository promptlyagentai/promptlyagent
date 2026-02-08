<x-layouts.app>
    <section class="w-full">
        @include('partials.settings-heading')

        <x-settings.layout
            :heading="__('Integrations')"
            :subheading="__('Connect external services to import knowledge and enhance your workflow')"
            wide>

            {{-- Success/Error Messages --}}
            @if (session('success'))
                <div class="mb-6 rounded-lg border border-[var(--palette-success-200)] bg-[var(--palette-success-100)] p-4">
                    <div class="flex items-center">
                        <svg class="h-5 w-5 text-[var(--palette-success-700)]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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

            {{-- Connected Integrations Section --}}
            @if($userIntegrations->count() > 0 || $inputTriggers->count() > 0 || $outputActions->count() > 0)
                <div class="mb-8">
                    <flux:heading size="lg" class="mb-4">{{ __('Connected Integrations') }}</flux:heading>

                    <div class="space-y-3">
                        @foreach($userIntegrations as $integration)
                            @php
                                // Get provider instance for logo
                                $token = $integration->integrationToken;
                                $providerRegistry = app(\App\Services\Integrations\ProviderRegistry::class);
                                try {
                                    $provider = $providerRegistry->get($token->provider_id);
                                    $logoUrl = $provider?->getLogoUrl();
                                } catch (\Exception $e) {
                                    $logoUrl = null;
                                }
                            @endphp

                            <div class="rounded-lg border border-default bg-surface p-4  ">
                                <div class="flex items-center space-x-4">
                                    {{-- Provider Logo/Icon --}}
                                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-surface  flex-shrink-0">
                                        @if($logoUrl)
                                            <img src="{{ $logoUrl }}" alt="{{ $integration->name }}" class="h-6 w-6 object-contain" />
                                        @else
                                            <flux:icon.link class="h-5 w-5 text-tertiary " />
                                        @endif
                                    </div>

                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center space-x-2">
                                            <flux:heading size="sm">{{ $integration->name }}</flux:heading>
                                            @if($integration->status === 'active')
                                                <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                                            @elseif($integration->status === 'paused')
                                                <flux:badge color="yellow" size="sm">{{ __('Paused') }}</flux:badge>
                                            @elseif($integration->status === 'archived')
                                                <flux:badge color="zinc" size="sm">{{ __('Archived') }}</flux:badge>
                                            @endif
                                        </div>

                                        <div class="mt-1 space-y-1 text-sm text-tertiary ">
                                            @if($integration->description)
                                                <div>{{ $integration->description }}</div>
                                            @endif
                                            @if($token->workspace_name)
                                                <div>{{ $token->workspace_name }}</div>
                                            @endif
                                            @if($token->account_name)
                                                <div>{{ $token->account_name }}</div>
                                            @endif
                                            @if($integration->last_used_at)
                                                <div>{{ __('Last used') }}: {{ $integration->last_used_at->diffForHumans() }}</div>
                                            @endif
                                        </div>

                                        {{-- Enabled Capabilities (Categories) --}}
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @php
                                                $enabledCategories = $integration->getEnabledCategories();
                                            @endphp
                                            @if(count($enabledCategories) > 0)
                                                @foreach($enabledCategories as $category)
                                                    <flux:badge size="sm" color="green">{{ $category }}</flux:badge>
                                                @endforeach
                                            @else
                                                <flux:text size="xs" class="text-tertiary">{{ __('No capabilities enabled') }}</flux:text>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Action Buttons --}}
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        {{-- Edit Button --}}
                                        <a href="{{ route('integrations.edit', $integration) }}" title="{{ __('Edit') }}">
                                            <flux:button size="sm" variant="ghost" square>
                                                <flux:icon.pencil class="h-4 w-4" />
                                            </flux:button>
                                        </a>

                                        {{-- Test Connection Button (uses token) --}}
                                        <form action="{{ route('integrations.test', $integration->integrationToken) }}" method="POST">
                                            @csrf
                                            <flux:button size="sm" variant="ghost" type="submit" square title="{{ __('Test Connection') }}">
                                                <flux:icon.arrow-path class="h-4 w-4" />
                                            </flux:button>
                                        </form>

                                        {{-- Delete Button --}}
                                        <form action="{{ route('integrations.delete', $integration) }}" method="POST"
                                              onsubmit="return confirm('{{ __('Are you sure you want to delete this integration?') }}')">
                                            @csrf
                                            @method('DELETE')
                                            <flux:button size="sm" variant="ghost" class="text-[var(--palette-error-700)] hover:text-[var(--palette-error-800)]" type="submit" square title="{{ __('Delete') }}">
                                                <flux:icon.trash class="h-4 w-4" />
                                            </flux:button>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            @if($token->last_error)
                                <div class="ml-14 mt-2 rounded-lg bg-[var(--palette-error-100)] p-3 text-sm text-[var(--palette-error-700)]">
                                    {{ $token->last_error }}
                                </div>
                            @endif
                        @endforeach

                        {{-- Input Triggers --}}
                        @foreach($inputTriggers as $trigger)
                            @php
                                $providerRegistry = app(\App\Services\InputTrigger\InputTriggerRegistry::class);
                                try {
                                    $provider = $providerRegistry->getProvider($trigger->provider_id);
                                    $icon = $provider?->getTriggerIcon() ?? '🔗';
                                    $iconSvg = $provider?->getTriggerIconSvg();
                                } catch (\Exception $e) {
                                    $icon = '🔗';
                                    $iconSvg = null;
                                }
                            @endphp

                            <div class="rounded-lg border border-default bg-surface p-4  ">
                                <div class="flex items-center space-x-4">
                                    {{-- Provider Icon --}}
                                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-surface  flex-shrink-0">
                                        @if($iconSvg)
                                            <div class="h-6 w-6 text-secondary ">{!! $iconSvg !!}</div>
                                        @else
                                            <span class="text-2xl">{{ $icon }}</span>
                                        @endif
                                    </div>

                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center space-x-2">
                                            <flux:heading size="sm">{{ $trigger->name }}</flux:heading>
                                            @if($trigger->status === 'active')
                                                <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                                            @elseif($trigger->status === 'paused')
                                                <flux:badge color="yellow" size="sm">{{ __('Paused') }}</flux:badge>
                                            @else
                                                <flux:badge color="gray" size="sm">{{ __('Disabled') }}</flux:badge>
                                            @endif
                                        </div>

                                        {{-- Capability Badge --}}
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <flux:badge size="sm" color="green">Input Trigger</flux:badge>
                                        </div>
                                    </div>

                                    {{-- Action Buttons --}}
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        {{-- Configure Button --}}
                                        <a href="{{ route('integrations.trigger-details', $trigger) }}" title="{{ __('Configure') }}">
                                            <flux:button size="sm" variant="ghost" square>
                                                <flux:icon.cog class="h-4 w-4" />
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

                        {{-- Output Actions --}}
                        @foreach($outputActions as $action)
                            @php
                                $providerRegistry = app(\App\Services\OutputAction\OutputActionRegistry::class);
                                try {
                                    $provider = $providerRegistry->getProvider($action->provider_id);
                                    $icon = $provider?->getActionIcon() ?? '🔗';
                                    $iconSvg = $provider?->getActionIconSvg();
                                } catch (\Exception $e) {
                                    $icon = '🔗';
                                    $iconSvg = null;
                                }
                            @endphp

                            <div class="rounded-lg border border-default bg-surface p-4  ">
                                <div class="flex items-center space-x-4">
                                    {{-- Provider Icon --}}
                                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-surface  flex-shrink-0">
                                        @if($iconSvg)
                                            <div class="h-6 w-6 text-secondary ">{!! $iconSvg !!}</div>
                                        @else
                                            <span class="text-2xl">{{ $icon }}</span>
                                        @endif
                                    </div>

                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center space-x-2">
                                            <flux:heading size="sm">{{ $action->name }}</flux:heading>
                                            @if($action->status === 'active')
                                                <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                                            @elseif($action->status === 'paused')
                                                <flux:badge color="yellow" size="sm">{{ __('Paused') }}</flux:badge>
                                            @else
                                                <flux:badge color="gray" size="sm">{{ __('Disabled') }}</flux:badge>
                                            @endif
                                        </div>

                                        {{-- Capability Badge --}}
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            <flux:badge size="sm" color="green">Output Action</flux:badge>
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
                </div>
            @endif

            {{-- Available Integrations (Provider Marketplace) --}}
            <div>
                <flux:heading size="lg" class="mb-4">{{ __('Available Integrations') }}</flux:heading>

                @if($providers->isEmpty())
                    <div class="rounded-lg border border-default bg-surface p-8 text-center  ">
                        <flux:icon.exclamation-circle class="mx-auto h-12 w-12 text-tertiary" />
                        <flux:text class="mt-2 text-tertiary ">
                            {{ __('No integrations are currently available. Please configure integration providers in your environment.') }}
                        </flux:text>
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach($providers as $provider)
                            @php
                                $isAlwaysAvailable = $provider->getDefaultAuthType() === 'none';
                                $logoUrl = $provider->getLogoUrl();
                            @endphp

                            <div class="rounded-lg border border-default bg-surface p-4 transition hover:border-default    {{ $isAlwaysAvailable ? 'bg-surface /50' : '' }}">
                                <div class="flex items-center space-x-4">
                                    {{-- Provider Logo/Icon --}}
                                    <div class="flex h-16 w-16 items-center justify-center rounded-lg bg-surface  flex-shrink-0">
                                        @if($logoUrl)
                                            <img src="{{ $logoUrl }}" alt="{{ $provider->getProviderName() }}" class="h-10 w-10 object-contain" />
                                        @elseif($provider instanceof \App\Services\Integrations\Contracts\InputTriggerProvider)
                                            @if($svg = $provider->getTriggerIconSvg())
                                                <div class="h-10 w-10 text-secondary ">{!! $svg !!}</div>
                                            @else
                                                <span class="text-4xl">{{ $provider->getTriggerIcon() }}</span>
                                            @endif
                                        @elseif($provider instanceof \App\Services\OutputAction\Contracts\OutputActionProvider)
                                            @if($svg = $provider->getActionIconSvg())
                                                <div class="h-10 w-10 text-secondary ">{!! $svg !!}</div>
                                            @else
                                                <span class="text-4xl">{{ $provider->getActionIcon() }}</span>
                                            @endif
                                        @else
                                            <flux:icon.link class="h-8 w-8 text-tertiary " />
                                        @endif
                                    </div>

                                    {{-- Provider Info --}}
                                    <div class="flex-1 min-w-0">
                                        <flux:heading size="sm">{{ $provider->getProviderName() }}</flux:heading>
                                        <flux:text size="sm" class="mt-1 text-tertiary ">
                                            {{ $provider->getDescription() }}
                                        </flux:text>

                                        {{-- Capabilities (Categories) --}}
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @php
                                                $categories = array_keys($provider->getCapabilities());
                                                $categoryLabels = [
                                                    'Input' => 'Input Trigger',
                                                    'Output' => 'Output Action',
                                                ];
                                            @endphp
                                            @foreach($categories as $category)
                                                <flux:badge size="sm" color="indigo">{{ $categoryLabels[$category] ?? $category }}</flux:badge>
                                            @endforeach
                                        </div>

                                        {{-- Auth Types --}}
                                        @if(count($provider->getSupportedAuthTypes()) > 1)
                                            <flux:text size="xs" class="mt-2 text-tertiary ">
                                                {{ __('Multiple authentication options available') }}
                                            </flux:text>
                                        @endif
                                    </div>

                                    {{-- Setup & Credentials Buttons --}}
                                    <div class="flex-shrink-0">
                                        @php
                                            $isConnectable = method_exists($provider, 'isConnectable') ? $provider->isConnectable() : !$isAlwaysAvailable;
                                            $requiresCredentials = $provider->getDefaultAuthType() !== 'none';
                                            $hasCredentials = $tokenCounts->get($provider->getProviderId(), 0) > 0;
                                        @endphp

                                        @if(!$isConnectable)
                                            {{-- Not connectable - just always available --}}
                                            <flux:button variant="primary" size="sm" disabled class="opacity-50 cursor-not-allowed">
                                                {{ __('Core') }}
                                            </flux:button>
                                        @else
                                            {{-- Connectable - show Credentials and/or Setup buttons --}}
                                            <div class="flex items-center gap-2">
                                                {{-- Credentials Button (only if provider requires credentials) --}}
                                                @if($requiresCredentials)
                                                    <button
                                                        type="button"
                                                        onclick="Livewire.dispatch('openCredentialsModal', { providerId: '{{ $provider->getProviderId() }}' })"
                                                        class="inline-flex items-center gap-1 rounded-lg border border-default  bg-surface  px-3 py-2 text-sm font-medium text-secondary  hover:bg-surface ">
                                                        <flux:icon.key class="h-4 w-4" />
                                                        {{ __('Credentials') }}
                                                    </button>
                                                @endif

                                                {{-- Setup Integration Button - Only show if credentials exist or not required --}}
                                                @if(!$requiresCredentials || $hasCredentials)
                                                    <a href="{{ $provider->getSetupRoute() }}">
                                                        <flux:button variant="primary" size="sm">
                                                            {{ __('Setup') }}
                                                        </flux:button>
                                                    </a>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Livewire Credentials Modal --}}
            <livewire:manage-credentials />

        </x-settings.layout>
    </section>
</x-layouts.app>
