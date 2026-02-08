<x-layouts.app>
    <section class="w-full">
        @include('partials.settings-heading')

        <x-settings.layout
            :heading="__('Connect :provider', ['provider' => $provider->getProviderName()])"
            :subheading="__('Choose how you want to authenticate')">

            <div class="space-y-4">
                @foreach($authTypes as $authType)
                    <div class="rounded-lg border border-default bg-surface p-6 transition hover:border-default   ">
                        <div class="mb-4">
                            <flux:heading size="sm">
                                @if($authType === 'oauth2')
                                    {{ __('OAuth 2.0') }}
                                @elseif($authType === 'bearer_token')
                                    {{ __('Bearer Token') }}
                                @elseif($authType === 'api_key')
                                    {{ __('API Key') }}
                                @else
                                    {{ ucfirst(str_replace('_', ' ', $authType)) }}
                                @endif

                                @if($authType === $provider->getDefaultAuthType())
                                    <flux:badge size="sm" color="indigo" class="ml-2">{{ __('Recommended') }}</flux:badge>
                                @endif
                            </flux:heading>

                            <flux:text size="sm" class="mt-2 text-tertiary ">
                                {{ $provider->getAuthTypeDescription($authType) }}
                            </flux:text>
                        </div>

                        <form action="{{ route('integrations.initiate-auth', ['provider' => $provider->getProviderId(), 'authType' => $authType]) }}" method="GET">
                            <flux:button variant="primary" type="submit">
                                {{ __('Connect with :type', ['type' => ucfirst(str_replace('_', ' ', $authType))]) }}
                            </flux:button>
                        </form>
                    </div>
                @endforeach
            </div>

            {{-- Back Button --}}
            <div class="mt-6">
                <a href="{{ route('integrations.index') }}">
                    <flux:button variant="ghost">
                        <flux:icon.arrow-left class="h-4 w-4" />
                        {{ __('Back to Integrations') }}
                    </flux:button>
                </a>
            </div>
        </x-settings.layout>
    </section>
</x-layouts.app>
