{{--
    PWA Layout

    Purpose: Progressive Web App layout for mobile-optimized offline-capable interface

    Features:
    - Fixed top header with back button or logo
    - Offline mode indicator
    - Fixed bottom navigation (Chat, Knowledge, Settings)
    - Full-height scrollable content area
    - Mobile-first design
    - Responsive header actions slot

    Header Structure:
    - Left: Back button (if $showBack) or logo + app name
    - Right: Optional header action slot + page title
    - Offline banner (conditional display)

    Bottom Navigation:
    - Chat: Real-time chat interface
    - Knowledge: Knowledge base browser
    - Settings: App settings and configuration
    - Active state highlighting

    Offline Support:
    - Visual offline indicator with icon
    - "Read Only" messaging
    - Alpine.js network status detection
    - Persistent across page changes

    Content Area:
    - Top padding for fixed header (pt-12)
    - Bottom padding for navigation (pb-14)
    - Full height with flex column
    - Scrollable main content

    Slots:
    - $slot: Main page content
    - $title: Page title (displayed in header)
    - $headerAction: Optional action buttons (e.g., "+ New")
    - $showBack: Boolean to show back button instead of logo

    Scripts:
    - Livewire scripts
    - Stack for additional page scripts

    Used By:
    - pwa.chat: PWA chat interface
    - pwa.knowledge: PWA knowledge browser
    - pwa.settings: PWA settings page
    - pwa.share-create-knowledge: Web Share Target handler

    Related:
    - service-worker.js: Handles offline caching
    - resources/js/pwa/: PWA JavaScript services
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" >
<head>
    @include('partials.head')
    @livewireStyles
    @stack('head')
</head>
<body class="min-h-screen bg-page text-primary">
    {{-- Fixed Top Header --}}
    <header class="fixed top-0 left-0 right-0 z-40 bg-surface" style="border-bottom: 1px solid var(--palette-primary-800);">
        <div class="flex items-center px-4 py-2">
            @if(isset($showBack) && $showBack)
                <button onclick="history.back()" class="p-2 -ml-2 text-secondary">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>
            @else
                <div class="flex items-center space-x-2 min-w-0">
                    <img src="/favicon.svg" alt="{{ config('app.name') }}" class="w-8 h-8 flex-shrink-0 dark:brightness-100 brightness-0">
                    <span class="text-lg font-semibold truncate">{{ config('app.name') }}</span>
                </div>
            @endif

            <div class="ml-auto flex items-center gap-3">
                @if(isset($headerAction))
                    {{ $headerAction }}
                @endif
                <h1 class="text-lg font-semibold text-secondary">
                    {{ $title ?? '' }}
                </h1>
            </div>
        </div>

        <!-- Offline Indicator -->
        <div x-data="{ offline: !navigator.onLine }"
             x-show="offline"
             x-init="
                window.addEventListener('online', () => offline = false);
                window.addEventListener('offline', () => offline = true);
             "
             class="bg-amber-500 text-white text-sm text-center py-1 px-4">
            <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728m0 0l-2.829-2.829m2.829 2.829L21 21M15.536 8.464a5 5 0 010 7.072m0 0l-2.829-2.829m-4.243 2.829a4.978 4.978 0 01-1.414-2.83m-1.414 5.658a9 9 0 01-2.167-9.238m7.824 2.167a1 1 0 111.414 1.414m-1.414-1.414L3 3m8.293 8.293l1.414 1.414"/>
            </svg>
            Offline Mode - Read Only
        </div>
    </header>

    <!-- Main Content -->
    <main class="pt-12 pb-14 h-screen flex flex-col">
        {{ $slot }}
    </main>

    <!-- Bottom Navigation -->
    <nav class="fixed bottom-0 left-0 right-0 z-40 bg-surface">
        <div class="flex items-center justify-around">
            <!-- Chat -->
            <a href="{{ route('pwa.chat') }}"
               class="flex-1 flex flex-col items-center py-2 px-2 {{ request()->routeIs('pwa.chat*') ? 'text-accent' : 'text-secondary' }}">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>
                <span class="text-xs mt-1">Chat</span>
            </a>

            <!-- Knowledge -->
            <a href="{{ route('pwa.knowledge') }}"
               class="flex-1 flex flex-col items-center py-2 px-2 {{ request()->routeIs('pwa.knowledge*') ? 'text-accent' : 'text-secondary' }}">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                </svg>
                <span class="text-xs mt-1">Knowledge</span>
            </a>

            <!-- Settings -->
            <a href="{{ route('pwa.settings') }}"
               class="flex-1 flex flex-col items-center py-2 px-2 {{ request()->routeIs('pwa.settings*') ? 'text-accent' : 'text-secondary' }}">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span class="text-xs mt-1">Settings</span>
            </a>
        </div>
    </nav>

    @livewireScripts
    @stack('scripts')
</body>
</html>
