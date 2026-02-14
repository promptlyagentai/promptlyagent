<x-layouts.pwa>
    <x-slot name="title">Settings</x-slot>

    <div class="max-w-4xl mx-auto px-4 py-6 space-y-6" x-data="settingsManager()">
        <!-- API Configuration -->
        <div class="bg-surface  rounded-lg border border-default  overflow-hidden">
            <div class="p-4 border-b border-default ">
                <h2 class="text-lg font-semibold">API Configuration</h2>
                <p class="text-sm text-tertiary  mt-1">Configure your API access</p>
            </div>

            <div class="p-4 space-y-4">
                <!-- Server URL -->
                <div>
                    <label class="block text-sm font-medium mb-2">Server URL</label>
                    <input type="url"
                           x-model="serverUrl"
                           placeholder="https://your-server.com"
                           class="w-full px-4 py-2 bg-surface  border border-default  rounded-lg focus:ring-2 focus:ring-accent focus:border-transparent">
                    <p class="text-xs text-tertiary  mt-1">Leave empty to use current domain</p>
                </div>

                <!-- API Token -->
                <div>
                    <label class="block text-sm font-medium mb-2">API Token</label>
                    <div class="relative">
                        <input :type="showToken ? 'text' : 'password'"
                               x-model="apiToken"
                               placeholder="Enter your API token"
                               class="w-full px-4 py-2 pr-12 bg-surface  border border-default  rounded-lg focus:ring-2 focus:ring-accent focus:border-transparent">
                        <button @click="showToken = !showToken"
                                type="button"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-tertiary hover:text-secondary ">
                            <svg x-show="!showToken" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                            <svg x-show="showToken" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Save & Test Buttons -->
                <div class="flex space-x-3">
                    <button @click="saveSettings()"
                            :disabled="saving"
                            class="flex-1 px-4 py-2 bg-accent hover:bg-accent disabled:bg-accent/50 text-white rounded-lg font-medium">
                        <span x-show="!saving">Save Settings</span>
                        <span x-show="saving">Saving...</span>
                    </button>

                    <button @click="testConnection()"
                            :disabled="testing || !apiToken"
                            class="px-4 py-2 bg-surface  hover:bg-surface-elevated disabled:opacity-50 rounded-lg font-medium">
                        <span x-show="!testing">Test</span>
                        <span x-show="testing">Testing...</span>
                    </button>
                </div>

                <!-- QR Scanner Button -->
                <div class="mt-4">
                    <button
                        @click="startQrScanner()"
                        type="button"
                        class="w-full py-3 px-4 bg-accent text-white rounded-lg font-medium hover:bg-accent/90 flex items-center justify-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/>
                        </svg>
                        Scan QR Code
                    </button>
                </div>

                <!-- Connection Status -->
                <div x-show="connectionResult" class="p-3 rounded-lg" :class="{
                    'bg-[var(--palette-success-50)] dark:bg-[var(--palette-success-900/20)] border border-[var(--palette-success-200)] dark:border-[var(--palette-success-800)]': connectionResult?.success,
                    'bg-[var(--palette-error-50)] dark:bg-[var(--palette-error-900/20)] border border-[var(--palette-error-200)] dark:border-[var(--palette-error-800)]': connectionResult && !connectionResult.success
                }">
                    <div class="flex items-start">
                        <svg x-show="connectionResult?.success" class="w-5 h-5 text-success mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <svg x-show="connectionResult && !connectionResult.success" class="w-5 h-5 text-[var(--palette-error-500)] mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <div class="flex-1 text-sm">
                            <span x-show="connectionResult?.success" class="text-[var(--palette-success-800)] dark:text-[var(--palette-success-200)]" x-text="connectionResult?.message"></span>
                            <span x-show="connectionResult && !connectionResult.success" class="text-[var(--palette-error-800)] dark:text-[var(--palette-error-200)]" x-text="connectionResult?.error"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- QR Scanner Modal (shown when scanning) -->
        <div x-show="scanning"
             x-cloak
             class="fixed inset-0 bg-black/90 z-50 flex flex-col"
             @click.self="stopQrScanner()">

            <div class="flex items-center justify-between p-4 bg-black/50">
                <h3 class="text-white font-semibold">Scan QR Code</h3>
                <button @click="stopQrScanner()" class="text-white p-2">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="flex-1 flex items-center justify-center p-4">
                <div id="qr-reader" class="w-full max-w-md" style="min-height: 300px;"></div>
            </div>

            <!-- Debug info -->
            <div x-show="scanError" class="p-4 bg-red-500/20 text-white text-sm">
                <p class="font-semibold mb-1">Camera Error:</p>
                <p x-text="scanError"></p>
            </div>

            <div class="p-4 text-center text-white text-sm">
                Position QR code within the frame
            </div>
        </div>

        <!-- Storage Management -->
        <div class="bg-surface  rounded-lg border border-default  overflow-hidden">
            <div class="p-4 border-b border-default ">
                <h2 class="text-lg font-semibold">Offline Storage</h2>
                <p class="text-sm text-tertiary  mt-1">Manage cached data</p>
            </div>

            <div class="p-4 space-y-4">
                <!-- Storage Stats -->
                <div class="grid grid-cols-3 gap-4 text-center">
                    <div>
                        <div class="text-2xl font-bold text-accent" x-text="syncStatus?.sessions?.count || 0"></div>
                        <div class="text-xs text-tertiary ">Sessions</div>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-accent" x-text="syncStatus?.interactions?.count || 0"></div>
                        <div class="text-xs text-tertiary ">Messages</div>
                    </div>
                    <div>
                        <div class="text-2xl font-bold text-accent" x-text="syncStatus?.knowledge?.count || 0"></div>
                        <div class="text-xs text-tertiary ">Documents</div>
                    </div>
                </div>

                <div x-show="syncStatus?.storage" class="p-3 bg-surface  rounded-lg">
                    <div class="text-sm">
                        <span class="font-medium">Storage Used:</span>
                        <span x-text="formatBytes(syncStatus?.storage?.usage || 0)"></span>
                        /
                        <span x-text="formatBytes(syncStatus?.storage?.quota || 0)"></span>
                    </div>
                </div>

                <!-- Sync Actions -->
                <div class="space-y-2">
                    <button @click="syncNow()"
                            :disabled="syncing"
                            class="w-full px-4 py-2 bg-accent hover:bg-accent disabled:bg-accent/50 text-white rounded-lg font-medium">
                        <span x-show="!syncing">Sync Now</span>
                        <span x-show="syncing">Syncing...</span>
                    </button>

                    <button @click="clearCache()"
                            :disabled="clearing"
                            class="w-full px-4 py-2 bg-[var(--palette-error-500)] hover:bg-[var(--palette-error-600)] disabled:bg-[var(--palette-error-400)] text-white rounded-lg font-medium">
                        <span x-show="!clearing">Clear All Cache</span>
                        <span x-show="clearing">Clearing...</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- App Info -->
        <div class="bg-surface  rounded-lg border border-default  overflow-hidden">
            <div class="p-4 space-y-2 text-sm text-tertiary ">
                <div class="flex justify-between">
                    <span>Version</span>
                    <span class="font-medium">1.0.0</span>
                </div>
                <div class="flex justify-between">
                    <span>PWA Enabled</span>
                    <span class="font-medium">Yes</span>
                </div>
                <div class="flex justify-between">
                    <span>Service Worker</span>
                    <span class="font-medium" x-text="'serviceWorker' in navigator ? 'Active' : 'Not Available'"></span>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        function settingsManager() {
            return {
                serverUrl: '',
                apiToken: '',
                showToken: false,
                saving: false,
                testing: false,
                syncing: false,
                clearing: false,
                connectionResult: null,
                syncStatus: null,
                auth: null,
                sync: null,
                scanning: false,
                html5QrCode: null,
                scanError: null,

                async init() {
                    // Wait for PWA services to be available
                    await this.waitForPWA();

                    this.auth = new window.PWA.AuthService();
                    this.sync = new window.PWA.SyncService();

                    await this.loadSettings();
                    await this.loadSyncStatus();
                },

                async waitForPWA() {
                    // Wait for window.PWA to be available (max 10 seconds)
                    let attempts = 0;
                    while (!window.PWA && attempts < 100) {
                        await new Promise(resolve => setTimeout(resolve, 100));
                        attempts++;
                        if (attempts % 10 === 0) {
                            console.log(`Still waiting for PWA services... (${attempts}/100)`);
                        }
                    }
                    if (!window.PWA) {
                        console.error('PWA services never loaded. Check console for errors.');
                        throw new Error('PWA services failed to load. Please refresh the page.');
                    }
                    console.log('PWA services ready!');
                },

                async ensureServices() {
                    await this.waitForPWA();
                    if (!this.auth) this.auth = new window.PWA.AuthService();
                    if (!this.sync) this.sync = new window.PWA.SyncService();
                },

                async loadSettings() {
                    await this.ensureServices();
                    this.serverUrl = await this.auth.getServerUrl() || '';
                    this.apiToken = await this.auth.getToken() || '';
                },

                async saveSettings() {
                    this.saving = true;
                    try {
                        await this.ensureServices();
                        await this.auth.saveToken(this.apiToken, this.serverUrl || null);
                        this.connectionResult = { success: true, message: 'Settings saved successfully' };
                        setTimeout(() => this.connectionResult = null, 3000);
                    } catch (error) {
                        this.connectionResult = { success: false, error: error.message };
                        console.error('Save failed:', error);
                    }
                    this.saving = false;
                },

                async testConnection() {
                    this.testing = true;
                    try {
                        await this.ensureServices();
                        this.connectionResult = await this.auth.testConnection();
                    } catch (error) {
                        this.connectionResult = { success: false, error: error.message };
                        console.error('Test failed:', error);
                    }
                    this.testing = false;
                },

                async loadSyncStatus() {
                    await this.ensureServices();
                    this.syncStatus = await this.sync.getSyncStatus();
                },

                async syncNow() {
                    this.syncing = true;
                    try {
                        await this.ensureServices();
                        await this.sync.syncSessions();
                        await this.loadSyncStatus();
                    } catch (error) {
                        console.error('Sync failed:', error);
                    }
                    this.syncing = false;
                },

                async clearCache() {
                    if (!confirm('Clear all cached data? This cannot be undone.')) return;

                    this.clearing = true;
                    try {
                        await this.ensureServices();
                        await this.sync.clearAllCache();
                        await this.loadSyncStatus();
                    } catch (error) {
                        console.error('Clear failed:', error);
                    }
                    this.clearing = false;
                },

/**
                 * Start QR code scanner
                 */
                async startQrScanner() {
                    this.scanning = true;
                    this.scanError = null;

                    // Check for secure context (HTTPS required for camera access)
                    if (!window.isSecureContext) {
                        this.scanError = 'Camera access requires HTTPS. Please access the app via HTTPS.';
                        console.error('QR Scanner: Not in secure context (HTTPS required)');
                        return;
                    }

                    console.log('QR Scanner: Starting scanner...');

                    // Wait for modal to render
                    await this.$nextTick();

                    // Check if container exists
                    const container = document.getElementById('qr-reader');
                    if (!container) {
                        this.scanError = 'QR reader container not found';
                        console.error('QR Scanner: Container element not found');
                        return;
                    }

                    console.log('QR Scanner: Container found, loading library...');

                    // Import html5-qrcode library (load from CDN)
                    if (!window.Html5Qrcode) {
                        try {
                            await this.loadQrLibrary();
                            console.log('QR Scanner: Library loaded successfully');
                        } catch (err) {
                            this.scanError = 'Failed to load QR scanner library. Check internet connection.';
                            console.error('QR Scanner: Library load failed:', err);
                            return;
                        }
                    }

                    console.log('QR Scanner: Initializing Html5Qrcode...');
                    this.html5QrCode = new Html5Qrcode("qr-reader");

                    try {
                        console.log('QR Scanner: Requesting camera access...');
                        await this.html5QrCode.start(
                            { facingMode: "environment" }, // Use back camera
                            {
                                fps: 10,
                                qrbox: { width: 250, height: 250 },
                                aspectRatio: 1.0
                            },
                            (decodedText) => {
                                console.log('QR Scanner: Code scanned:', decodedText.substring(0, 50) + '...');
                                this.handleQrCodeScanned(decodedText);
                            },
                            (errorMessage) => {
                                // Ignore scanning errors (just means no QR detected yet)
                            }
                        );
                        console.log('QR Scanner: Camera started successfully');
                    } catch (err) {
                        console.error('QR Scanner: Failed to start camera:', err);
                        console.error('Error name:', err.name);
                        console.error('Error message:', err.message);

                        // Detailed error messages
                        if (err.name === 'NotAllowedError') {
                            this.scanError = 'Camera permission denied. Please allow camera access in your browser settings.';
                        } else if (err.name === 'NotFoundError') {
                            this.scanError = 'No camera found on this device.';
                        } else if (err.name === 'NotReadableError') {
                            this.scanError = 'Camera is already in use by another app.';
                        } else if (err.name === 'OverconstrainedError') {
                            this.scanError = 'Camera configuration not supported by device.';
                        } else {
                            this.scanError = `Camera error: ${err.message}`;
                        }

                        this.stopQrScanner();
                    }
                },

                /**
                 * Stop QR code scanner
                 */
                async stopQrScanner() {
                    if (this.html5QrCode) {
                        try {
                            await this.html5QrCode.stop();
                            this.html5QrCode.clear();
                        } catch (err) {
                            console.error('Error stopping scanner:', err);
                        }
                        this.html5QrCode = null;
                    }
                    this.scanning = false;
                },

                /**
                 * Handle scanned QR code data
                 */
                handleQrCodeScanned(decodedText) {
                    try {
                        // Parse JSON data
                        const data = JSON.parse(decodedText);

                        if (data.server && data.token) {
                            // Auto-fill form fields
                            this.serverUrl = data.server;
                            this.apiToken = data.token;

                            // Stop scanner
                            this.stopQrScanner();

                            // Show success message
                            alert('QR code scanned successfully! Click "Save & Test Connection" to continue.');
                        } else {
                            alert('Invalid QR code format. Please use a QR code generated from PWA Settings.');
                        }
                    } catch (error) {
                        console.error('Failed to parse QR code:', error);
                        alert('Invalid QR code. Please try again.');
                    }
                },

                /**
                 * Load html5-qrcode library (local copy for production stability)
                 */
                async loadQrLibrary() {
                    return new Promise((resolve, reject) => {
                        if (window.Html5Qrcode) {
                            resolve();
                            return;
                        }

                        const script = document.createElement('script');
                        // Use local copy instead of CDN to avoid CSP/network issues in production
                        script.src = '/vendor/html5-qrcode/html5-qrcode.min.js';
                        script.onload = resolve;
                        script.onerror = (error) => {
                            console.error('Failed to load local QR library:', error);
                            reject(new Error('QR scanner library failed to load'));
                        };
                        document.head.appendChild(script);
                    });
                },

                formatBytes(bytes) {
                    if (!bytes) return '0 Bytes';
                    const k = 1024;
                    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
                    const i = Math.floor(Math.log(bytes) / Math.log(k));
                    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
                }
            }
        }

        window.settingsManager = settingsManager;
    </script>
    @endpush
</x-layouts.pwa>
