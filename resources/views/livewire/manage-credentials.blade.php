<div>
    {{-- Modal --}}
    @if($isOpen)
        <flux:modal wire:model.live="isOpen" class="w-[90vw] max-w-4xl" wire:key="manage-cred-modal-{{ $providerId }}">
        @if($provider)
            {{-- Header --}}
            <div class="mb-6">
                <flux:heading size="lg">{{ __('Manage Credentials') }}</flux:heading>
                <flux:subheading>{{ $provider->getProviderName() }}</flux:subheading>
            </div>

            {{-- Flash Messages --}}
            @if (session()->has('success'))
                <div class="mb-4 rounded-lg border border-success bg-success p-4">
                    <flux:text class="text-success-contrast">{{ session('success') }}</flux:text>
                </div>
            @endif

            @if (session()->has('error'))
                <div class="mb-4 rounded-lg border border-error bg-error p-4">
                    <flux:text class="text-error-contrast">{{ session('error') }}</flux:text>
                </div>
            @endif

            {{-- List Mode --}}
            @if($mode === 'list')
                <div class="space-y-4">
                    {{-- Setup Instructions (if OAuth provider not configured) --}}
                    @php
                        $authTypes = $provider->getSupportedAuthTypes();
                        $isOAuth = in_array('oauth2', $authTypes);
                    @endphp

                    @if($setupInstructions)
                        <flux:callout variant="info" icon="information-circle">
                            <flux:heading size="sm" class="mb-2">{{ $setupInstructions['title'] }}</flux:heading>
                            <flux:text class="mb-3">{{ $setupInstructions['description'] }}</flux:text>

                            <ol class="ml-4 space-y-3 list-decimal text-sm text-secondary ">
                                @foreach($setupInstructions['steps'] as $step)
                                    <li>
                                        {{ $step['text'] }}
                                        @if(isset($step['link']))
                                            <a href="{{ $step['link'] }}" target="_blank" class="text-accent hover:text-accent-hover underline">
                                                {{ $step['link_text'] ?? $step['link'] }}
                                            </a>
                                        @endif

                                        @if(isset($step['type']) && $step['type'] === 'manifest' && $appManifest)
                                            <div class="mt-2 relative">
                                                <div class="flex items-center justify-between mb-1">
                                                    <flux:text size="xs" class="font-medium">{{ __('App Manifest (YAML)') }}</flux:text>
                                                    <flux:button
                                                        size="xs"
                                                        variant="ghost"
                                                        x-data="{ copied: false }"
                                                        @click="navigator.clipboard.writeText($refs.manifest.textContent); copied = true; setTimeout(() => copied = false, 2000)"
                                                    >
                                                        <span x-show="!copied">{{ __('Copy') }}</span>
                                                        <span x-show="copied" x-cloak>{{ __('Copied!') }}</span>
                                                    </flux:button>
                                                </div>
                                                <pre x-ref="manifest" class="text-xs bg-surface  p-3 rounded overflow-x-auto">{{ $appManifest }}</pre>
                                            </div>
                                        @endif

                                        @if(isset($step['code']) && (!isset($step['type']) || $step['type'] !== 'manifest'))
                                            <div class="mt-1 ml-4 font-mono text-xs bg-surface  p-2 rounded whitespace-pre-wrap">{{ $step['code'] }}</div>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        </flux:callout>
                    @endif

                    {{-- Add New Button --}}
                    <div class="flex justify-end">
                        @if($isOAuth)
                            <flux:button
                                variant="primary"
                                size="sm"
                                wire:click="initiateOAuth"
                                :disabled="!$hasConfiguredApp">
                                <span class="flex items-center whitespace-nowrap">
                                    <flux:icon.plus class="h-4 w-4 mr-1" />
                                    {{ __('Add Credentials') }}
                                </span>
                            </flux:button>
                        @else
                            <flux:button variant="primary" size="sm" wire:click="showCreateForm">
                                <span class="flex items-center whitespace-nowrap">
                                    <flux:icon.plus class="h-4 w-4 mr-1" />
                                    {{ __('Add Credentials') }}
                                </span>
                            </flux:button>
                        @endif
                    </div>

                    {{-- Credentials List --}}
                    @if(count($tokens) > 0)
                        <div class="space-y-3">
                            @foreach($tokens as $token)
                                <div class="rounded-lg border border-default bg-surface p-4  ">
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1 min-w-0">
                                            <flux:heading size="sm">{{ $token['provider_name'] ?? $provider->getProviderName() }}</flux:heading>
                                            <div class="mt-1 flex items-center gap-2">
                                                @if($token['status'] === 'active')
                                                    <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                                                @elseif($token['status'] === 'error')
                                                    <flux:badge color="red" size="sm">{{ __('Error') }}</flux:badge>
                                                @else
                                                    <flux:badge color="zinc" size="sm">{{ $token['status'] }}</flux:badge>
                                                @endif

                                                @if(isset($token['created_at']))
                                                    <flux:text size="xs" class="text-tertiary">
                                                        {{ __('Created') }} {{ \Carbon\Carbon::parse($token['created_at'])->diffForHumans() }}
                                                    </flux:text>
                                                @endif
                                            </div>
                                        </div>

                                        <div class="flex items-center gap-2">
                                            {{-- Test Connection --}}
                                            <flux:button size="sm" variant="ghost" square wire:click="testConnection('{{ $token['id'] }}')" title="{{ __('Test Connection') }}">
                                                <flux:icon.arrow-path class="h-4 w-4" />
                                            </flux:button>

                                            {{-- Edit --}}
                                            @if(!$isOAuth)
                                                <flux:button size="sm" variant="ghost" square wire:click="showEditForm('{{ $token['id'] }}')" title="{{ __('Edit') }}">
                                                    <flux:icon.pencil class="h-4 w-4" />
                                                </flux:button>
                                            @endif

                                            {{-- Delete --}}
                                            <flux:button
                                                size="sm"
                                                variant="ghost"
                                                square
                                                wire:click="deleteToken('{{ $token['id'] }}')"
                                                wire:confirm="Are you sure you want to delete these credentials?"
                                                title="{{ __('Delete') }}"
                                                class="text-error hover:text-error">
                                                <flux:icon.trash class="h-4 w-4" />
                                            </flux:button>
                                        </div>
                                    </div>

                                    @if(isset($token['last_error']) && $token['last_error'])
                                        <div class="mt-2 text-sm text-error">
                                            {{ $token['last_error'] }}
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        {{-- Empty State --}}
                        <div class="rounded-lg border-2 border-dashed border-default bg-surface p-8 text-center  ">
                            <flux:icon.key class="mx-auto h-12 w-12 text-tertiary" />
                            <flux:heading size="sm" class="mt-2">{{ __('No credentials yet') }}</flux:heading>
                            <flux:text class="mt-1 text-tertiary ">
                                {{ __('Add your first credentials to get started') }}
                            </flux:text>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Create/Edit Mode --}}
            @if($mode === 'create' || $mode === 'edit')
                <form wire:submit="saveToken" class="space-y-4">
                    <div>
                        <flux:heading size="sm">
                            {{ $mode === 'create' ? __('Add New Credentials') : __('Edit Credentials') }}
                        </flux:heading>
                    </div>

                    {{-- Name (optional) --}}
                    <flux:field>
                        <flux:label>{{ __('Name (optional)') }}</flux:label>
                        <flux:input wire:model="formData.name" placeholder="{{ __('e.g., Production Account, Development') }}" />
                        <flux:description>{{ __('Give this credential a memorable name') }}</flux:description>
                        <flux:error name="formData.name" />
                    </flux:field>

                    {{-- Token Input (adapts to auth type) --}}
                    @php
                        $authTypes = $provider->getSupportedAuthTypes();
                        $authType = $authTypes[0] ?? 'bearer_token';
                    @endphp

                    @if($authType === 'bearer_token')
                        <flux:field>
                            <flux:label>{{ __('Bearer Token') }}</flux:label>
                            <flux:input type="password" wire:model="formData.token" maxlength="2048" placeholder="{{ __('Enter your bearer token') }}" />
                            <flux:description>{{ __('Your API bearer token will be encrypted and stored securely') }}</flux:description>
                            <flux:error name="formData.token" />
                        </flux:field>
                    @elseif($authType === 'api_key')
                        <flux:field>
                            <flux:label>{{ __('API Key') }}</flux:label>
                            <flux:input type="password" wire:model="formData.token" maxlength="2048" placeholder="{{ __('Enter your API key') }}" />
                            <flux:description>{{ __('Your API key will be encrypted and stored securely') }}</flux:description>
                            <flux:error name="formData.token" />
                        </flux:field>
                    @endif

                    {{-- Actions --}}
                    <div class="flex justify-end space-x-2">
                        <flux:button variant="ghost" wire:click="$set('mode', 'list')" type="button">
                            {{ __('Cancel') }}
                        </flux:button>

                        <flux:button variant="primary" type="submit">
                            {{ $mode === 'create' ? __('Create') : __('Update') }}
                        </flux:button>
                    </div>
                </form>
            @endif
        @endif
        </flux:modal>
    @endif
</div>

{{-- Listen for modal open events --}}
@script
<script>
    $wire.on('openCredentialsModal', (data) => {
        $wire.call('openModal', data.providerId);
    });
</script>
@endscript
