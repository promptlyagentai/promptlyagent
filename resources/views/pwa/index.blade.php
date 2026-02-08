<x-layouts.pwa>
    <x-slot name="title">Home</x-slot>

    <div class="max-w-4xl mx-auto px-4 py-8">
        <!-- Welcome Section -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-20 h-20 mb-4">
                <img src="/favicon.svg" alt="{{ config('app.name') }}" class="w-20 h-20">
            </div>
            <h1 class="text-3xl font-bold mb-2">Welcome to {{ config('app.name') }}</h1>
            <p class="text-tertiary ">AI-powered research and knowledge management</p>
        </div>

        <!-- Auth Status Check -->
        <div x-data="{
            hasToken: false,
            testing: false,
            connectionStatus: null,
            async waitForPWA() {
                let attempts = 0;
                while (!window.PWA && attempts < 50) {
                    await new Promise(resolve => setTimeout(resolve, 100));
                    attempts++;
                }
                return !!window.PWA;
            },
            async checkAuth() {
                try {
                    if (!await this.waitForPWA()) {
                        console.error('PWA services not available');
                        return;
                    }

                    const token = await window.PWA.db.get('settings', 'api_token');
                    this.hasToken = !!token?.value;

                    if (this.hasToken) {
                        await this.testConnection();
                    }
                } catch (error) {
                    console.error('Auth check failed:', error);
                }
            },
            async testConnection() {
                this.testing = true;
                try {
                    const auth = new window.PWA.AuthService();
                    const result = await auth.testConnection();
                    this.connectionStatus = result;
                } catch (error) {
                    this.connectionStatus = { success: false, error: error.message };
                }
                this.testing = false;
            }
        }" x-init="checkAuth()">

            <!-- Not Configured -->
            <div x-show="!hasToken" class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-6 mb-6">
                <div class="flex items-start">
                    <svg class="w-6 h-6 text-amber-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <div class="flex-1">
                        <h3 class="font-semibold text-amber-900 dark:text-amber-100 mb-1">Setup Required</h3>
                        <p class="text-sm text-amber-800 dark:text-amber-200 mb-4">
                            Please configure your API token in settings to use {{ config('app.name') }}.
                        </p>
                        <a href="{{ route('pwa.settings') }}" class="inline-flex items-center px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white rounded-lg text-sm font-medium">
                            Go to Settings
                            <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Configured & Connected -->
            <div x-show="hasToken && connectionStatus?.success" class="bg-surface rounded-lg p-6 mb-6" style="border: 1px solid var(--palette-primary-800);">
                <div class="flex items-start">
                    <svg class="w-6 h-6 text-accent mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div class="flex-1">
                        <h3 class="font-semibold mb-1">Connected</h3>
                        <p class="text-sm text-tertiary">
                            Your API token is configured and working correctly.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Connection Error -->
            <div x-show="hasToken && connectionStatus && !connectionStatus.success" class="bg-surface rounded-lg p-6 mb-6" style="border: 1px solid var(--palette-primary-800);">
                <div class="flex items-start">
                    <svg class="w-6 h-6 text-[var(--palette-error-500)] mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div class="flex-1">
                        <h3 class="font-semibold mb-1">Connection Failed</h3>
                        <p class="text-sm text-tertiary mb-2" x-text="connectionStatus?.error"></p>
                        <button @click="testConnection()" class="text-sm text-accent hover:underline" x-text="testing ? 'Testing...' : 'Test Again'"></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="grid grid-cols-1 gap-4 mb-8">
            <a href="{{ route('pwa.chat') }}" class="flex items-center p-6 bg-surface  rounded-lg border border-default  hover:border-accent transition-colors">
                <div class="flex-shrink-0 w-12 h-12 bg-accent/20 rounded-lg flex items-center justify-center mr-4">
                    <svg class="w-6 h-6 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="font-semibold mb-1">Start Chatting</h3>
                    <p class="text-sm text-tertiary ">Ask questions and get AI-powered answers</p>
                </div>
                <svg class="w-5 h-5 text-tertiary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>

            <a href="{{ route('pwa.knowledge') }}" class="flex items-center p-6 bg-surface  rounded-lg border border-default  hover:border-accent transition-colors">
                <div class="flex-shrink-0 w-12 h-12 bg-accent/20 rounded-lg flex items-center justify-center mr-4">
                    <svg class="w-6 h-6 text-accent" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <h3 class="font-semibold mb-1">Search Knowledge</h3>
                    <p class="text-sm text-tertiary ">Find documents and information</p>
                </div>
                <svg class="w-5 h-5 text-tertiary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                </svg>
            </a>
        </div>

        <!-- Features -->
        <div class="space-y-4">
            <h2 class="text-lg font-semibold mb-4">Features</h2>

            <div class="flex items-start space-x-3">
                <svg class="w-6 h-6 text-accent flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <div>
                    <h3 class="font-medium">Offline Support</h3>
                    <p class="text-sm text-tertiary ">Access recent chats even when offline</p>
                </div>
            </div>

            <div class="flex items-start space-x-3">
                <svg class="w-6 h-6 text-accent flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <div>
                    <h3 class="font-medium">File & Photo Support</h3>
                    <p class="text-sm text-tertiary ">Upload files or capture photos</p>
                </div>
            </div>

            <div class="flex items-start space-x-3">
                <svg class="w-6 h-6 text-accent flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <div>
                    <h3 class="font-medium">Export Chats</h3>
                    <p class="text-sm text-tertiary ">Download conversations as Markdown</p>
                </div>
            </div>
        </div>
    </div>
</x-layouts.pwa>
