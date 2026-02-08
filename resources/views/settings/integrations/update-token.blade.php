<x-layouts.app>
    <section class="w-full">
        @include('partials.settings-heading')

        <x-settings.layout
            :heading="__('Update :provider Token', ['provider' => $provider->getProviderName()])"
            :subheading="__('Rotate your integration token while preserving all existing connections and data')">

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

            {{-- Important Notice --}}
            <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
                <div class="flex">
                    <flux:icon.exclamation-triangle class="h-5 w-5 text-amber-600 dark:text-amber-400" />
                    <div class="ml-3">
                        <flux:heading size="sm" class="text-amber-900 dark:text-amber-100">
                            {{ __('Token Rotation') }}
                        </flux:heading>
                        <flux:text size="sm" class="mt-1 text-amber-700 dark:text-amber-300">
                            {{ __('Updating your token will preserve all existing connections, including knowledge documents and their refresh schedules. The new token will be validated before the old one is replaced.') }}
                        </flux:text>
                    </div>
                </div>
            </div>

            {{-- Current Integration Info --}}
            <div class="mb-6 rounded-lg border border-default bg-surface p-4  ">
                <div class="flex items-center space-x-3">
                    @php
                        $logoUrl = $provider?->getLogoUrl();
                    @endphp
                    <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-surface-elevated">
                        @if($logoUrl)
                            <img src="{{ $logoUrl }}" alt="{{ $token->provider_name }}" class="h-6 w-6 object-contain" />
                        @else
                            <flux:icon.link class="h-5 w-5 text-tertiary " />
                        @endif
                    </div>
                    <div>
                        <flux:heading size="sm">{{ $token->provider_name }}</flux:heading>
                        @if($token->workspace_name || $token->account_name)
                            <flux:text size="sm" class="text-tertiary ">
                                {{ $token->workspace_name ?? $token->account_name }}
                            </flux:text>
                        @endif
                    </div>
                </div>

                {{-- Show connection stats --}}
                @if($token->knowledgeDocuments()->count() > 0)
                    <div class="mt-3 border-t border-default pt-3 ">
                        <flux:text size="xs" class="text-tertiary ">
                            📚 <strong>{{ $token->knowledgeDocuments()->count() }}</strong> knowledge documents connected
                        </flux:text>
                    </div>
                @endif
            </div>

            {{-- Token Update Form --}}
            <form action="{{ route('integrations.update-token', $token) }}" method="POST" class="space-y-6">
                @csrf

                {{-- New Token Input --}}
                <flux:field>
                    <flux:label>
                        @if($authType === 'bearer_token')
                            {{ __('New Bearer Token') }}
                        @else
                            {{ __('New API Key') }}
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
                        {{ __('Enter your new token. It will be validated before replacing the current one.') }}
                    </flux:text>
                </flux:field>

                {{-- Form Actions --}}
                <div class="flex items-center justify-between space-x-4">
                    <a href="{{ route('integrations.index') }}" class="inline-flex items-center rounded-lg border border-default bg-surface px-4 py-2 text-sm font-medium text-secondary hover:bg-surface    ">
                        {{ __('Cancel') }}
                    </a>

                    <button type="submit" class="inline-flex items-center gap-2 rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-white hover:bg-accent dark:bg-accent dark:hover:bg-accent">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                        <span>{{ __('Update Token') }}</span>
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
                            {{ __('Your new token will be encrypted using industry-standard encryption before being stored. The old token will be securely discarded after the update is successful.') }}
                        </flux:text>
                    </div>
                </div>
            </div>
        </x-settings.layout>
    </section>
</x-layouts.app>
