<x-layouts.app>
    <section class="w-full pb-20 xl:pb-0">
        @include('partials.settings-heading')

        <x-settings.layout
            :heading="'Edit ' . $integration->name"
            :subheading="$provider->getProviderName() . ' Integration'"
            wide>

            {{-- Success/Error Messages --}}
            @if (session('success'))
                <div class="mb-6 rounded-lg border border-success bg-success p-4">
                    <div class="flex items-center">
                        <svg class="h-5 w-5 text-success-contrast" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="ml-3 text-sm text-success-contrast">{{ session('success') }}</span>
                    </div>
                </div>
            @endif

            @if (session('error'))
                <div class="mb-6 rounded-lg border border-error bg-error p-4">
                    <div class="flex items-center">
                        <svg class="h-5 w-5 text-error-contrast" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="ml-3 text-sm text-error-contrast">{{ session('error') }}</span>
                    </div>
                </div>
            @endif

            @if($errors->any())
                <div class="mb-6 rounded-lg border border-error bg-error p-4">
                    <div class="text-sm text-error-contrast">
                        <strong>Validation errors:</strong>
                        <ul class="ml-4 list-disc mt-2">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            <form id="integration-edit-form" action="{{ route('integrations.update', $integration) }}" method="POST">
                @csrf
                @method('PATCH')

                {{-- Responsive Grid Container --}}
                <div class="space-y-6 xl:grid xl:grid-cols-12 xl:gap-6 xl:space-y-0 xl:items-start">

                    {{-- LEFT COLUMN - Primary Controls (60%) --}}
                    <div class="space-y-6 xl:col-span-7">

                        {{-- Integration Identity Card --}}
                        <div class="rounded-lg border border-default bg-surface p-4  ">
                            <div class="flex items-center space-x-3">
                                @php
                                    $logoUrl = $provider->getLogoUrl();
                                @endphp
                                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-surface-elevated">
                                    @if($logoUrl)
                                        <img src="{{ $logoUrl }}" alt="{{ $provider->getProviderName() }}" class="h-6 w-6 object-contain" />
                                    @else
                                        <flux:icon.link class="h-5 w-5 text-tertiary " />
                                    @endif
                                </div>
                                <div class="flex-1">
                                    <flux:heading size="sm">{{ $integration->name }}</flux:heading>
                                    @if($integration->last_used_at)
                                        <flux:text size="xs" class="mt-0.5 text-tertiary ">
                                            Last used {{ $integration->last_used_at->diffForHumans() }}
                                        </flux:text>
                                    @endif
                                </div>
                            </div>

                            {{-- Name Field --}}
                            <div class="mt-4">
                                <flux:field>
                                    <flux:label>{{ __('Integration Name') }}</flux:label>
                                    <flux:input
                                        name="name"
                                        value="{{ old('name', $integration->name) }}"
                                        placeholder="{{ __('e.g., My Notion Workspace, Production Database') }}"
                                        required />
                                    <flux:description>{{ __('Give this integration a memorable name') }}</flux:description>
                                    <flux:error name="name" />
                                </flux:field>
                            </div>

                            {{-- Description Field --}}
                            <div class="mt-4">
                                <flux:field>
                                    <flux:label>{{ __('Description') }}</flux:label>
                                    <flux:textarea
                                        name="description"
                                        rows="3"
                                        placeholder="{{ __('Optional description of this integration') }}">{{ old('description', $integration->description) }}</flux:textarea>
                                    <flux:description>{{ __('Optional: Add notes about how you use this integration') }}</flux:description>
                                    <flux:error name="description" />
                                </flux:field>
                            </div>
                        </div>

                        {{-- Capabilities Configuration --}}
                        @php
                            $capabilities = $provider->getCapabilities();
                            $capabilityDescriptions = $provider->getCapabilityDescriptions();
                            $enabledCapabilities = old('enabled_capabilities', $integration->getEnabledCapabilities());
                        @endphp

                        @if(!empty($capabilities))
                            <div class="rounded-lg border border-default bg-surface p-6  ">
                                <div class="mb-6">
                                    <div class="flex items-center space-x-2">
                                        <flux:icon.cog class="h-5 w-5 text-tertiary " />
                                        <flux:heading size="sm">{{ __('Capabilities') }}</flux:heading>
                                    </div>
                                    <flux:text size="sm" class="mt-1 text-tertiary ">
                                        {{ __('Select which capabilities you want to enable for this integration') }}
                                    </flux:text>
                                </div>

                                <div class="space-y-4">
                                    @foreach($capabilities as $category => $categoryCapabilities)
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
                                                            <flux:text size="xs" class="mt-1 text-tertiary">
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
                                                        $isChecked = in_array($capabilityKey, $enabledCapabilities);
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
                            </div>
                        @endif

                    </div>

                    {{-- RIGHT COLUMN - Configuration & Settings (40%) --}}
                    <div class="space-y-6 xl:col-span-5">

                        {{-- Credential Selector --}}
                        <div class="rounded-lg border border-default bg-surface p-6  ">
                            <div class="mb-4">
                                <div class="flex items-center space-x-2">
                                    <flux:icon.key class="h-5 w-5 text-tertiary " />
                                    <flux:heading size="sm">{{ __('Credentials') }}</flux:heading>
                                </div>
                                <flux:text size="sm" class="mt-1 text-tertiary ">
                                    {{ __('Select which credentials this integration should use') }}
                                </flux:text>
                            </div>

                            @if($tokens->count() > 0)
                                <flux:field>
                                    <flux:label>{{ __('Credential') }} *</flux:label>
                                    <flux:select name="integration_token_id" required>
                                        @foreach($tokens as $token)
                                            <option value="{{ $token->id }}" {{ old('integration_token_id', $integration->integration_token_id) == $token->id ? 'selected' : '' }}>
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
                                    <flux:description>{{ __('You can switch to different credentials for this integration') }}</flux:description>
                                    <flux:error name="integration_token_id" />
                                </flux:field>
                            @else
                                <div class="rounded-lg border-2 border-dashed border-default bg-surface p-4 text-center  ">
                                    <flux:text size="sm" class="text-tertiary ">
                                        {{ __('No other credentials available.') }}
                                        <button
                                            type="button"
                                            onclick="Livewire.dispatch('openCredentialsModal', { providerId: '{{ $provider->getProviderId() }}' })"
                                            class="text-accent hover:text-accent font-medium">
                                            {{ __('Create new credentials') }}
                                        </button>
                                    </flux:text>
                                </div>
                            @endif
                        </div>

                        {{-- Provider-Specific Configuration (Livewire Components - Legacy) --}}
                        @if(method_exists($provider, 'getCustomConfigComponent') && $provider->getCustomConfigComponent())
                            <div class="rounded-lg border border-default bg-surface p-6  ">
                                <div class="mb-4">
                                    <div class="flex items-center space-x-2">
                                        <flux:icon.adjustments-horizontal class="h-5 w-5 text-tertiary " />
                                        <flux:heading size="sm">{{ $provider->getProviderName() }} {{ __('Configuration') }}</flux:heading>
                                    </div>
                                    <flux:text size="sm" class="mt-1 text-tertiary ">
                                        {{ __('Provider-specific settings for this integration') }}
                                    </flux:text>
                                </div>

                                @livewire($provider->getCustomConfigComponent(), ['token' => $integration->integrationToken, 'standalone' => false], key('provider-config-' . $integration->id))
                            </div>
                        @endif

                        {{-- Provider-Specific Form Fields (Inside main form) --}}
                        @if(method_exists($provider, 'getEditFormFields') && !empty($provider->getEditFormFields()))
                            @foreach($provider->getEditFormFields() as $fieldSection)
                                @include($fieldSection, ['integration' => $integration, 'provider' => $provider])
                            @endforeach
                        @endif

                        {{-- Provider-Specific Configuration Sections (Inside form) --}}
                        @if(method_exists($provider, 'getEditFormSections') && !empty($provider->getEditFormSections()))
                            <div class="rounded-lg border border-default bg-surface p-6">
                                <div class="mb-4">
                                    <div class="flex items-center space-x-2">
                                        <flux:icon.adjustments-horizontal class="h-5 w-5 text-tertiary" />
                                        <flux:heading size="sm">{{ $provider->getProviderName() }} {{ __('Settings') }}</flux:heading>
                                    </div>
                                    <flux:text size="sm" class="mt-1 text-tertiary">
                                        {{ __('Configure provider-specific options') }}
                                    </flux:text>
                                </div>
                                @foreach($provider->getEditFormSections() as $section)
                                    @include($section, ['integration' => $integration, 'provider' => $provider])
                                @endforeach
                            </div>
                        @endif
                    </form>

                    {{-- AI Agents Section - Only show if Agent capability exists --}}
                    @php
                        $hasAgentCapability = collect($capabilities['Agent'] ?? [])->isNotEmpty();
                    @endphp
                    @if($hasAgentCapability)
                        <x-integrations.ai-agents-section :integration="$integration" />
                    @endif

                    </div>
                </div>

            {{-- Bottom Action Bar --}}
            <div class="mt-6">
                <div class="flex items-center justify-end gap-3">
                    <a href="{{ route('integrations.index') }}"
                       class="inline-flex items-center justify-center rounded-lg border border-default bg-surface px-4 py-2 text-sm font-medium text-secondary hover:bg-surface    ">
                        {{ __('Back') }}
                    </a>

                    <button type="submit" form="integration-edit-form"
                            class="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-accent dark:bg-accent dark:hover:bg-accent">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        <span>{{ __('Save Configuration') }}</span>
                    </button>
                </div>
            </div>

            {{-- Include Livewire Modal for Credential Management --}}
            <livewire:manage-credentials />

            {{-- Hidden form for clear cache button (must be outside main form to avoid nesting) --}}
            <form id="clear-cache-form-{{ $integration->id }}"
                  method="POST"
                  action="{{ route('integrations.clear-cache', $integration) }}"
                  style="display: none;">
                @csrf
            </form>

        </x-settings.layout>
    </section>
</x-layouts.app>
