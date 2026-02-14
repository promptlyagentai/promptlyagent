<x-layouts.pwa>
    <x-slot name="title">PWA Setup</x-slot>

    <div class="max-w-4xl mx-auto px-4 py-6 space-y-6">

        <!-- Setup Instructions -->
        <div class="bg-surface rounded-lg border border-default overflow-hidden">
            <div class="p-4 border-b border-default">
                <h2 class="text-lg font-semibold">Install PromptlyAgent PWA</h2>
                <p class="text-sm text-tertiary mt-1">Follow the instructions below to install the app on your device</p>
            </div>

            <div class="p-4 space-y-6">
                <!-- iOS Instructions -->
                <div>
                    <h3 class="font-medium mb-3 flex items-center gap-2">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09l.01-.01zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/>
                        </svg>
                        iOS (Safari)
                    </h3>
                    <ol class="space-y-2 text-sm text-secondary">
                        <li>1. Tap the <strong>Share</strong> button (square with arrow up) in Safari</li>
                        <li>2. Scroll down and tap <strong>"Add to Home Screen"</strong></li>
                        <li>3. Tap <strong>"Add"</strong> in the top right corner</li>
                        <li>4. Open the app from your home screen</li>
                    </ol>
                </div>

                <!-- Android Instructions -->
                <div>
                    <h3 class="font-medium mb-3 flex items-center gap-2">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M17.6 9.48l1.84-3.18c.16-.31.04-.69-.26-.85-.29-.15-.65-.06-.83.22l-1.88 3.24a11.5 11.5 0 00-8.94 0L5.65 5.67c-.19-.28-.54-.37-.83-.22-.3.16-.42.54-.26.85l1.84 3.18C4.8 11.16 3.5 13.84 3.5 16.5h17c0-2.66-1.3-5.34-2.9-7.02zM7 14.5c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm10 0c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1z"/>
                        </svg>
                        Android (Chrome)
                    </h3>
                    <ol class="space-y-2 text-sm text-secondary">
                        <li>1. Tap the <strong>Menu</strong> button (three dots) in Chrome</li>
                        <li>2. Tap <strong>"Add to Home screen"</strong> or <strong>"Install app"</strong></li>
                        <li>3. Tap <strong>"Add"</strong> or <strong>"Install"</strong></li>
                        <li>4. Open the app from your home screen or app drawer</li>
                    </ol>
                </div>

                <!-- Next Steps -->
                <div class="pt-4 border-t border-default">
                    <div class="bg-accent/10 border border-accent/20 rounded-lg p-4">
                        <p class="font-medium mb-2">📱 Next Steps:</p>
                        <ol class="text-sm text-secondary space-y-2">
                            <li>1. After installing the app, <strong>return to your desktop/laptop</strong></li>
                            <li>2. On desktop, click <strong>"Next - Show Token QR"</strong> in the setup dialog</li>
                            <li>3. Open the <strong>PromptlyAgent app</strong> on your mobile device</li>
                            <li>4. Navigate to <strong>Settings</strong> → Tap <strong>"Scan QR Code"</strong></li>
                            <li>5. Scan the <strong>second QR code</strong> from your desktop screen</li>
                            <li>6. Tap <strong>"Save & Test Connection"</strong></li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts.pwa>
