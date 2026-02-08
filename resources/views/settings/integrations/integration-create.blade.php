<x-layouts.app>
    <section class="w-full pb-20 xl:pb-0">
        @include('partials.settings-heading')

        <x-settings.layout
            :heading="'Setup ' . $provider->getProviderName() . ' Integration'"
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

            @if($errors->any())
                <div class="mb-6 rounded-lg border border-[var(--palette-error-200)] bg-[var(--palette-error-100)] p-4">
                    <div class="text-sm text-[var(--palette-error-800)]">
                        <strong>Validation errors:</strong>
                        <ul class="ml-4 list-disc mt-2">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            {{-- Setup Form Card --}}
            <div class="rounded-lg border border-default bg-surface p-6  ">
                <form id="integration-create-form" action="{{ route('integrations.store', ['provider' => $provider->getProviderId()]) }}" method="POST" class="space-y-6" novalidate>
                    @csrf

                    <div class="flex items-center gap-2 mb-6">
                        @if($logoUrl = $provider->getLogoUrl())
                            <img src="{{ $logoUrl }}" alt="{{ $provider->getProviderName() }}" class="h-8 w-8 object-contain" />
                        @else
                            <flux:icon.link class="h-6 w-6 text-tertiary " />
                        @endif
                        <flux:heading size="lg">Create Integration</flux:heading>
                    </div>

                    {{-- Credential Selector --}}
                    <flux:field>
                        <flux:label>{{ __('Credentials') }} *</flux:label>

                        @if($tokens->count() > 0)
                            <flux:select name="integration_token_id">
                                <option value="">{{ __('Select credentials...') }}</option>
                                @foreach($tokens as $token)
                                    <option value="{{ $token->id }}" {{ old('integration_token_id') == $token->id ? 'selected' : '' }}>
                                        {{ $token->provider_name ?? $provider->getProviderName() }}
                                        @if($token->workspace_name)
                                            ({{ $token->workspace_name }})
                                        @endif
                                        @if($token->account_name)
                                            - {{ $token->account_name }}
                                        @endif
                                    </option>
                                @endforeach
                            </flux:select>
                            <flux:description>{{ __('Select which credentials to use for this integration') }}</flux:description>
                        @else
                            <div class="rounded-lg border-2 border-dashed border-default bg-surface p-4 text-center  ">
                                <flux:text size="sm" class="text-tertiary ">
                                    {{ __('No credentials available.') }}
                                    <button
                                        type="button"
                                        onclick="Livewire.dispatch('openCredentialsModal', { providerId: '{{ $provider->getProviderId() }}' })"
                                        class="text-accent hover:text-accent font-medium">
                                        {{ __('Create new credentials') }}
                                    </button>
                                </flux:text>
                            </div>
                        @endif
                        <flux:error name="integration_token_id" />
                    </flux:field>

                    {{-- Integration Name --}}
                    <flux:field>
                        <flux:label>{{ __('Integration Name') }} *</flux:label>
                        <flux:input
                            name="name"
                            value="{{ old('name') }}"
                            placeholder="{{ __('e.g., My Notion Workspace, Production Database') }}" />
                        <flux:description>{{ __('Give this integration a memorable name') }}</flux:description>
                        <flux:error name="name" />
                    </flux:field>

                    {{-- Description (Optional) --}}
                    <flux:field>
                        <flux:label>{{ __('Description') }}</flux:label>
                        <flux:textarea
                            name="description"
                            rows="3"
                            placeholder="{{ __('Optional description of this integration') }}">{{ old('description') }}</flux:textarea>
                        <flux:description>{{ __('Optional: Add notes about how you use this integration') }}</flux:description>
                        <flux:error name="description" />
                    </flux:field>

                    {{-- Provider-Specific Configuration Sections --}}
                    @foreach($provider->getCreateFormSections() as $section)
                        @include($section, ['integration' => null, 'tokens' => $tokens, 'provider' => $provider])
                    @endforeach

                    {{-- Capabilities --}}
                    @php
                        $capabilities = $provider->getCapabilities();
                        $capabilityDescriptions = $provider->getCapabilityDescriptions();
                    @endphp

                    @if(!empty($capabilities))
                        <div class="space-y-4">
                            <div>
                                <flux:label>{{ __('Enabled Capabilities') }}</flux:label>
                                <flux:description>{{ __('Select which capabilities you want to enable for this integration') }}</flux:description>
                            </div>

                            @foreach($capabilities as $category => $categoryCapabilities)
                                {{-- Skip Agent category - it gets special handling below --}}
                                @if($category === 'Agent')
                                    @continue
                                @endif

                                {{-- Collapsible Category Section --}}
                                <div x-data="{ expanded: window.matchMedia('(min-width: 768px)').matches }">
                                    <button @click="expanded = !expanded" type="button"
                                        class="flex w-full items-start justify-between rounded-lg border border-default bg-surface-elevated px-4 py-3 text-left hover:bg-surface">
                                        <div class="flex items-start gap-2 flex-1">
                                            <div class="mt-0.5">
                                                <svg x-show="!expanded" class="h-4 w-4 text-tertiary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                                </svg>
                                                <svg x-show="expanded" x-cloak class="h-4 w-4 text-tertiary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                                </svg>
                                            </div>
                                            <div class="flex-1">
                                                <div class="flex items-center gap-2">
                                                    <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium bg-nav-content-active text-accent">{{ $category }}</span>
                                                    <flux:text size="xs" class="text-tertiary">
                                                        {{ count($categoryCapabilities) }} {{ Str::plural('capability', count($categoryCapabilities)) }}
                                                    </flux:text>
                                                </div>
                                                @php
                                                    $categoryDescription = \App\Services\Integrations\Contracts\IntegrationCapabilityCategories::getDescription($category);
                                                @endphp
                                                @if($categoryDescription)
                                                    <flux:text size="xs" class="mt-1 text-tertiary ">
                                                        {{ $categoryDescription }}
                                                    </flux:text>
                                                @endif
                                            </div>
                                        </div>
                                    </button>

                                    <div x-show="expanded" x-collapse class="mt-2 space-y-2">
                                        @foreach($categoryCapabilities as $capability)
                                            @php
                                                $capabilityKey = "{$category}:{$capability}";
                                                $description = $capabilityDescriptions[$capabilityKey] ?? ucfirst(str_replace('_', ' ', $capability));
                                                // Enable all by default, unless old input exists (form resubmit with validation errors)
                                                $isChecked = old('enabled_capabilities') !== null
                                                    ? in_array($capabilityKey, old('enabled_capabilities', []))
                                                    : true; // Default: all enabled
                                            @endphp

                                            <div class="flex items-center justify-between py-3 px-4 rounded-lg border bg-surface border-default   hover:border-accent transition"
                                                 x-data="{ enabled: {{ $isChecked ? 'true' : 'false' }} }">
                                                <div class="flex-1">
                                                    <div class="flex items-center gap-2">
                                                        <flux:text class="font-medium">{{ ucfirst($capability) }}</flux:text>
                                                    </div>
                                                    <flux:text size="xs" class="mt-1 text-tertiary ">
                                                        {{ $description }}
                                                    </flux:text>
                                                </div>

                                                {{-- Hidden checkbox for form submission --}}
                                                <input type="checkbox"
                                                       name="enabled_capabilities[]"
                                                       value="{{ $capabilityKey }}"
                                                       x-model="enabled"
                                                       class="hidden">

                                                {{-- Toggle switch button --}}
                                                <button @click="enabled = !enabled" type="button"
                                                    class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2"
                                                    :class="enabled ? 'bg-accent' : 'bg-surface'"
                                                    role="switch"
                                                    :aria-checked="enabled.toString()">
                                                    <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"
                                                          :class="enabled ? 'translate-x-5' : 'translate-x-0'"></span>
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                </form>
            </div>

            {{-- Bottom Action Bar --}}
            <div class="mt-6">
                <div class="flex items-center justify-end gap-3">
                    <a href="{{ route('integrations.index') }}" class="inline-flex items-center justify-center rounded-lg border border-default bg-surface px-4 py-2 text-sm font-medium text-secondary hover:bg-surface-elevated">
                        {{ __('Cancel') }}
                    </a>

                    <button type="submit" form="integration-create-form" class="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-accent-hover dark:bg-accent dark:hover:bg-accent-hover">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                        </svg>
                        <span>{{ __('Create Integration') }}</span>
                    </button>
                </div>
            </div>

            {{-- Include Livewire Modal for Credential Management --}}
            <livewire:manage-credentials />

        </x-settings.layout>
    </section>
</x-layouts.app>
