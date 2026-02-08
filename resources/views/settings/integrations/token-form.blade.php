<x-layouts.app>
    <section class="w-full">
        @include('partials.settings-heading')

        <x-settings.layout
            :heading="__('Connect :provider', ['provider' => $provider->getProviderName()])"
            :subheading="$authType === 'bearer_token' ? __('Enter your bearer token or integration secret') : __('Enter your API key')">

            {{-- Error Message --}}
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

            {{-- Instructions --}}
            <div class="mb-6 rounded-lg border border-accent bg-accent/10 p-4">
                <div class="flex">
                    <flux:icon.information-circle class="h-5 w-5 text-accent" />
                    <div class="ml-3">
                        <flux:heading size="sm" class="text-primary">
                            {{ __('How to get your token') }}
                        </flux:heading>
                        <flux:text size="sm" class="mt-1 text-secondary">
                            @if($authType === 'bearer_token')
                                {{ __('You can create a token in your :provider account settings. Look for "Integrations", "API", or "Developer" sections.', ['provider' => $provider->getProviderName()]) }}
                            @else
                                {{ __('You can find your API key in your :provider account settings under "API" or "Developer" sections.', ['provider' => $provider->getProviderName()]) }}
                            @endif
                        </flux:text>
                    </div>
                </div>
            </div>

            {{-- Token Form --}}
            <form action="{{ route('integrations.store-token', ['provider' => $provider->getProviderId(), 'authType' => $authType]) }}" method="POST" class="space-y-6">
                @csrf

                {{-- Connection Name --}}
                <flux:field>
                    <flux:label>
                        {{ __('Connection Name') }}
                        <span class="text-tertiary">({{ __('optional') }})</span>
                    </flux:label>
                    <flux:input
                        name="name"
                        type="text"
                        placeholder="{{ __('My :provider Integration', ['provider' => $provider->getProviderName()]) }}"
                        value="{{ old('name') }}"
                    />
                    <flux:text size="xs" class="mt-1 text-tertiary ">
                        {{ __('Give this connection a memorable name to identify it later') }}
                    </flux:text>
                </flux:field>

                {{-- Token Input --}}
                <flux:field>
                    <flux:label>
                        @if($authType === 'bearer_token')
                            {{ __('Bearer Token') }}
                        @else
                            {{ __('API Key') }}
                        @endif
                    </flux:label>
                    <flux:textarea
                        name="token"
                        rows="4"
                        placeholder="secret_..."
                        required
                        class="font-mono text-sm"
                    >{{ old('token') }}</flux:textarea>
                    @error('token')
                        <flux:error>{{ $message }}</flux:error>
                    @enderror
                    <flux:text size="xs" class="mt-1 text-tertiary ">
                        {{ __('Your token will be encrypted and stored securely') }}
                    </flux:text>
                </flux:field>

                {{-- Form Actions --}}
                <div class="flex items-center justify-between space-x-4">
                    <a href="{{ route('integrations.index') }}" class="inline-flex items-center rounded-lg border border-default bg-surface px-4 py-2 text-sm font-medium text-secondary hover:bg-surface    ">
                        {{ __('Cancel') }}
                    </a>

                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-accent dark:bg-accent dark:hover:bg-accent">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        <span>{{ __('Connect') }}</span>
                    </button>
                </div>
            </form>

            {{-- Security Notice --}}
            <div class="mt-6 rounded-lg border border-default bg-surface p-4  ">
                <div class="flex">
                    <flux:icon.lock-closed class="h-5 w-5 text-tertiary " />
                    <div class="ml-3">
                        <flux:heading size="sm" class="text-primary ">
                            {{ __('Security') }}
                        </flux:heading>
                        <flux:text size="sm" class="mt-1 text-secondary ">
                            {{ __('Your token is encrypted using industry-standard encryption before being stored in our database. We will never log or expose your token in plain text.') }}
                        </flux:text>
                    </div>
                </div>
            </div>
        </x-settings.layout>
    </section>
</x-layouts.app>
