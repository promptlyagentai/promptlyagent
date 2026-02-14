{{--
    PWA Access Settings Page

    Purpose: Generate PWA API tokens with QR code for easy mobile setup

    Features:
    - Generate permanent PWA tokens
    - Display QR code with server URL + token
    - View active PWA devices with revoke functionality
    - Copy-to-clipboard for manual setup fallback

    Livewire Component Properties:
    - @property bool $pwaEnabled Whether user has active PWA tokens
    - @property string|null $newToken Current token (shown once after generation)
    - @property bool $generating Loading state during token generation
    - @property bool $showTokenModal Whether to show QR code modal

    Methods:
    - generatePwaToken(): Create new PWA token and show QR modal
    - closeTokenModal(): Close QR code modal
    - revokePwaToken(int $tokenId): Delete specific PWA token

    Security:
    - Plain text token shown ONLY on creation (one-time display)
    - Users can revoke PWA access anytime
    - Tokens stored as hashed values (Laravel Sanctum)
--}}
<?php

use App\Services\Pwa\PwaTokenService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public bool $pwaEnabled = false;

    public ?string $newToken = null;

    public ?array $qrData = null;

    public ?string $setupUrl = null;

    public bool $generating = false;

    public bool $showTokenModal = false;

    public bool $showTokenQr = false;

    /**
     * Load PWA status on mount
     */
    public function mount(): void
    {
        $this->loadPwaStatus();
    }

    /**
     * Check if user has active PWA tokens
     */
    public function loadPwaStatus(): void
    {
        $service = app(PwaTokenService::class);
        $pwaTokens = $service->getUserPwaTokens(Auth::user());
        $this->pwaEnabled = $pwaTokens->isNotEmpty();
    }

    /**
     * Generate PWA token and show QR code for setup page
     */
    public function generatePwaToken(): void
    {
        $this->generating = true;

        $service = app(PwaTokenService::class);
        $result = $service->generatePwaToken(Auth::user(), 'PWA Device');

        $this->newToken = $result['token'];

        // Generate one-time setup code (15min expiry)
        $setupCode = $service->generateSetupCode(Auth::user(), $this->newToken);
        $this->setupUrl = $service->getSetupUrl($setupCode);
        $this->qrData = $service->getQrData($this->newToken);

        $this->pwaEnabled = true;
        $this->showTokenModal = true;
        $this->showTokenQr = false;
        $this->generating = false;

        // Log for debugging
        logger()->info('Dispatching pwa-qr-generated', [
            'setupUrl' => $this->setupUrl,
            'qrData' => $this->qrData,
        ]);

        $this->dispatch('pwa-qr-generated', $this->setupUrl, $this->qrData);
    }

    /**
     * Show token QR (second step)
     */
    public function showTokenQrCode(): void
    {
        $this->showTokenQr = true;

        logger()->info('Dispatching pwa-token-qr-generated', [
            'qrData' => $this->qrData,
        ]);

        $this->dispatch('pwa-token-qr-generated', $this->qrData);
    }

    /**
     * Close token modal
     */
    public function closeTokenModal(): void
    {
        $this->showTokenModal = false;
        $this->showTokenQr = false;
        $this->newToken = null;
        $this->qrData = null;
        $this->setupUrl = null;
    }

    /**
     * Revoke PWA token
     */
    public function revokePwaToken(int $tokenId): void
    {
        $service = app(PwaTokenService::class);
        $service->revokePwaToken(Auth::user(), $tokenId);

        $this->loadPwaStatus();
        $this->dispatch('token-revoked');
    }

    /**
     * Provide data to view
     */
    public function with(): array
    {
        $service = app(PwaTokenService::class);

        return [
            'pwaTokens' => $service->getUserPwaTokens(Auth::user()),
        ];
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout
        :heading="__('PWA/Mobile Access')"
        :subheading="__('Generate API tokens for Progressive Web App and mobile access with QR code setup')">

        <div class="my-6 w-full space-y-6">

            {{-- Generate Token Section --}}
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Generate PWA Token') }}</flux:heading>
                <flux:subheading>
                    {{ __('Create a new API token with full access for PWA. Scan the QR code with your mobile device to automatically configure the app.') }}
                </flux:subheading>

                <flux:button
                    wire:click="generatePwaToken"
                    :disabled="$generating"
                    variant="primary">
                    @if($generating)
                        {{ __('Generating...') }}
                    @else
                        {{ __('Generate Token') }}
                    @endif
                </flux:button>
            </div>

            {{-- Active PWA Tokens --}}
            @if($pwaTokens->isNotEmpty())
                <flux:separator />

                <div class="space-y-4">
                    <flux:heading size="lg">{{ __('Active Tokens') }}</flux:heading>
                    <flux:subheading>
                        {{ __('API tokens currently authorized for PWA access') }}
                    </flux:subheading>

                    <div class="space-y-2">
                        @foreach($pwaTokens as $token)
                            <div class="flex items-center justify-between p-4 bg-surface rounded-lg border border-default">
                                <div class="flex-1">
                                    <div class="font-medium text-primary">
                                        {{ $token->name }}
                                    </div>
                                    <div class="text-sm text-tertiary mt-1">
                                        Created {{ $token->created_at->diffForHumans() }}
                                        @if($token->last_used_at)
                                            • Last used {{ $token->last_used_at->diffForHumans() }}
                                        @endif
                                    </div>
                                </div>
                                <flux:button
                                    wire:click="revokePwaToken({{ $token->id }})"
                                    wire:confirm="Are you sure you want to revoke this token? The device will lose access immediately."
                                    variant="danger"
                                    size="sm">
                                    {{ __('Revoke') }}
                                </flux:button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

        </div>
    </x-settings.layout>

    {{-- Token Display Modal with QR Code --}}
    @if($showTokenModal)
        <flux:modal wire:model="showTokenModal" class="max-w-lg">
            @if(!$showTokenQr)
                {{-- Step 1: Setup URL QR Code --}}
                <div class="space-y-6">
                    <div>
                        <flux:heading size="xl">{{ __('Step 1: Scan Setup QR Code') }}</flux:heading>
                        <flux:subheading class="mt-2">
                            {{ __('Scan this QR code with your mobile device to open the installation instructions page.') }}
                        </flux:subheading>
                    </div>

                    {{-- QR Code Display --}}
                    <div class="flex justify-center p-6 bg-white rounded-lg">
                        <div id="setup-qrcode" class="qr-code-container"></div>
                    </div>

                    {{-- Instructions --}}
                    <flux:callout variant="info">
                        {{ __('ℹ️ After scanning, follow the instructions to install the PWA app on your mobile device, then click "Next" below.') }}
                    </flux:callout>

                    {{-- Manual Setup Link --}}
                    <details class="text-sm">
                        <summary class="cursor-pointer text-secondary hover:text-primary font-medium">
                            {{ __('Manual Setup (if QR scan fails)') }}
                        </summary>
                        <div class="mt-3 space-y-3">
                            <div>
                                <flux:label>{{ __('Setup URL') }}</flux:label>
                                <div class="flex items-center gap-2 mt-1">
                                    <code class="flex-1 px-3 py-2 bg-surface border border-default rounded text-xs break-all">
                                        {{ $setupUrl }}
                                    </code>
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        onclick="navigator.clipboard.writeText('{{ $setupUrl }}')">
                                        {{ __('Copy') }}
                                    </flux:button>
                                </div>
                            </div>
                            <flux:subheading>
                                {{ __('Open this URL on your mobile device browser.') }}
                            </flux:subheading>
                        </div>
                    </details>

                    <div class="flex justify-between">
                        <flux:button wire:click="closeTokenModal" variant="ghost">
                            {{ __('Cancel') }}
                        </flux:button>
                        <flux:button wire:click="showTokenQrCode" variant="primary">
                            {{ __('Next - Show Token QR') }}
                        </flux:button>
                    </div>
                </div>
            @else
                {{-- Step 2: Token QR Code --}}
                <div class="space-y-6">
                    <div>
                        <flux:heading size="xl">{{ __('Step 2: Scan Token QR Code') }}</flux:heading>
                        <flux:subheading class="mt-2">
                            {{ __('Open the installed PWA app on your mobile device, go to Settings, and tap "Scan QR Code" to scan this token.') }}
                        </flux:subheading>
                    </div>

                    {{-- Token QR Code Display --}}
                    <div class="flex justify-center p-6 bg-white rounded-lg">
                        <div id="token-qrcode" class="qr-code-container"></div>
                    </div>

                    {{-- Security Warning --}}
                    <flux:callout variant="warning">
                        {{ __('⚠️ This token grants full access to your account. Keep it secure and do not share it.') }}
                    </flux:callout>

                    {{-- Manual Token Entry --}}
                    <details class="text-sm">
                        <summary class="cursor-pointer text-secondary hover:text-primary font-medium">
                            {{ __('Manual Entry (if QR scan fails)') }}
                        </summary>
                        <div class="mt-3 space-y-3">
                            <div>
                                <flux:label>{{ __('Server URL') }}</flux:label>
                                <div class="flex items-center gap-2 mt-1">
                                    <code class="flex-1 px-3 py-2 bg-surface border border-default rounded text-xs">
                                        {{ url('/') }}
                                    </code>
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        onclick="navigator.clipboard.writeText('{{ url('/') }}')">
                                        {{ __('Copy') }}
                                    </flux:button>
                                </div>
                            </div>
                            <div>
                                <flux:label>{{ __('API Token') }}</flux:label>
                                <div class="flex items-center gap-2 mt-1">
                                    <code class="flex-1 px-3 py-2 bg-surface border border-default rounded text-xs break-all">
                                        {{ $newToken }}
                                    </code>
                                    <flux:button
                                        size="sm"
                                        variant="ghost"
                                        onclick="navigator.clipboard.writeText('{{ $newToken }}')">
                                        {{ __('Copy') }}
                                    </flux:button>
                                </div>
                            </div>
                            <flux:subheading>
                                {{ __('Go to PWA Settings, enter these values manually, and tap "Save & Test Connection".') }}
                            </flux:subheading>
                        </div>
                    </details>

                    <flux:callout variant="info">
                        {{ __('ℹ️ Make sure to save this token now - you won\'t be able to see it again!') }}
                    </flux:callout>

                    <div class="flex justify-end">
                        <flux:button wire:click="closeTokenModal" variant="primary">
                            {{ __('Done') }}
                        </flux:button>
                    </div>
                </div>
            @endif
        </flux:modal>
    @endif

    {{-- QR Code Generation Script (qrcodejs) --}}
    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <script>
        document.addEventListener('livewire:initialized', () => {
            let setupQrGenerated = false;
            let tokenQrGenerated = false;

            // Generate setup URL QR (Step 1)
            const generateSetupQr = (setupUrl, qrData) => {
                if (setupQrGenerated) return;

                const container = document.getElementById('setup-qrcode');
                if (!container || container.offsetParent === null) {
                    return; // Container not visible yet
                }

                console.log('Generating setup QR - setupUrl:', setupUrl);
                console.log('Generating setup QR - qrData:', qrData);

                if (!setupUrl) {
                    console.error('No setup URL provided');
                    return;
                }

                container.innerHTML = '';

                new QRCode(container, {
                    text: setupUrl,
                    width: 256,
                    height: 256,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.H
                });

                setupQrGenerated = true;
                console.log('Setup QR code generated successfully');
            };

            // Generate token QR (Step 2)
            const generateTokenQr = (qrData) => {
                if (tokenQrGenerated) return;

                const container = document.getElementById('token-qrcode');
                if (!container || container.offsetParent === null) {
                    return; // Container not visible yet
                }

                console.log('Generating token QR - qrData:', qrData);

                if (!qrData || Object.keys(qrData).length === 0) {
                    console.error('No token data provided');
                    return;
                }

                container.innerHTML = '';

                new QRCode(container, {
                    text: JSON.stringify(qrData),
                    width: 256,
                    height: 256,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                    correctLevel: QRCode.CorrectLevel.H
                });

                tokenQrGenerated = true;
                console.log('Token QR code generated successfully');
            };

            // Listen for events with data
            // Livewire v3 passes all params as array elements
            Livewire.on('pwa-qr-generated', (event) => {
                console.log('PWA QR generated event received');
                console.log('Event (raw):', event);

                // Event is an array: [setupUrl, qrData]
                const setupUrl = Array.isArray(event) ? event[0] : event;
                const qrData = Array.isArray(event) ? event[1] : null;

                console.log('Extracted - setupUrl:', setupUrl);
                console.log('Extracted - qrData:', qrData);

                setupQrGenerated = false; // Reset flag
                setTimeout(() => generateSetupQr(setupUrl, qrData), 150);
            });

            Livewire.on('pwa-token-qr-generated', (event) => {
                console.log('PWA token QR generated event received');
                console.log('Event (raw):', event);

                // Event is the qrData object (single param)
                const qrData = Array.isArray(event) ? event[0] : event;

                console.log('Extracted - qrData:', qrData);

                tokenQrGenerated = false; // Reset flag
                setTimeout(() => generateTokenQr(qrData), 150);
            });
        });
    </script>
    @endpush
</section>
