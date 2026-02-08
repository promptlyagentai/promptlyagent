{{--
    App Sidebar Layout

    Primary layout with persistent navigation sidebar (desktop) and stashable drawer (mobile).
    Includes optional help widget integration controlled via user preferences and HELP_WIDGET_AGENT_ID.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-page">
        <flux:sidebar sticky stashable class="border-e border-sidebar bg-sidebar">
            <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

            <a href="{{ config('app.url') }}" class="me-5 flex items-center space-x-2 rtl:space-x-reverse">
                <x-app-logo />
            </a>

            <flux:navlist variant="outline">
                <flux:navlist.group class="grid">
                    <flux:navlist.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>{{ __('Dashboard') }}</flux:navlist.item>
                    <flux:navlist.item icon="chat-bubble-left-right"
                        :href="($lastSession = \App\Models\ChatSession::where('user_id', auth()->id())->latest('updated_at')->first()) ? route('dashboard.research-chat.session', ['sessionId' => $lastSession->id]) : route('dashboard.research-chat')"
                        :current="request()->routeIs('dashboard.research-chat') || request()->routeIs('dashboard.research-chat.session')" wire:navigate>{{ __('Chat') }}</flux:navlist.item>
                    <flux:navlist.item icon="cpu-chip" :href="route('dashboard.agents')" :current="request()->routeIs('dashboard.agents')" wire:navigate>{{ __('Agents') }}</flux:navlist.item>
                    <flux:navlist.item icon="document-text" :href="route('dashboard.knowledge')" :current="request()->routeIs('dashboard.knowledge')" wire:navigate>{{ __('Knowledge') }}</flux:navlist.item>
                </flux:navlist.group>
            </flux:navlist>

            <flux:spacer />

            @if(auth()->user()->isAdmin())
                <flux:navlist variant="outline">
                    <flux:navlist.item
                        icon="users"
                        :href="route('dashboard.users')"
                        :current="request()->routeIs('dashboard.users')"
                        wire:navigate>
                        {{ __('Users') }}
                    </flux:navlist.item>
                </flux:navlist>
            @endif

            <flux:navlist variant="outline">
                <flux:navlist.item icon="folder-git-2" href="{{ config('github.bug_report.repository') ? 'https://github.com/' . config('github.bug_report.repository') : 'https://github.com/promptlyagentai/promptlyagentai' }}" target="_blank">
                {{ __('Repository') }}
                </flux:navlist.item>

                <flux:navlist.item icon="book-open-text" href="{{ route('scribe') }}" target="_blank">
                {{ __('Documentation') }}
                </flux:navlist.item>
            </flux:navlist>

            <flux:dropdown class="hidden lg:block" position="bottom" align="start">
                <flux:profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    icon:trailing="chevrons-up-down"
                />

                <flux:menu class="w-[220px]">
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-accent text-accent-foreground"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('settings.profile')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:sidebar>

        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <span class="relative flex h-8 w-8 shrink-0 overflow-hidden rounded-lg">
                                    <span
                                        class="flex h-full w-full items-center justify-center rounded-lg bg-accent text-accent-foreground"
                                    >
                                        {{ auth()->user()->initials() }}
                                    </span>
                                </span>

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <span class="truncate font-semibold">{{ auth()->user()->name }}</span>
                                    <span class="truncate text-xs">{{ auth()->user()->email }}</span>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('settings.profile')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">
                            {{ __('Log Out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{-- Impersonation Banner --}}
        <x-impersonation-banner />

        {{ $slot }}

        @livewireScripts
        @fluxScripts
        @stack('scripts')

        @auth
            @php
                $helpWidget = auth()->user()->preferences['help_widget'] ?? [];
                $widgetEnabled = $helpWidget['enabled'] ?? false;

                // Get agent ID from environment variable, or fallback to Promptly Manual agent
                $agentId = config('app.help_widget_agent_id') ?: \App\Models\Agent::where('name', 'Promptly Manual')->value('id');

                // If no agent found, show warning
                if ($widgetEnabled && !$agentId) {
                    Log::warning('Help widget enabled but no agent configured. Set HELP_WIDGET_AGENT_ID in .env or create a "Promptly Manual" agent.');
                }
            @endphp

            @if($widgetEnabled && $agentId)
                <script src="https://cdn.jsdelivr.net/npm/marked@16.1.0/marked.min.js"></script>
                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/highlight.js@11.11.1/styles/github.min.css">
                <script src="https://cdn.jsdelivr.net/npm/highlight.js@11.11.1/highlight.min.js"></script>
                <script src="{{ asset('js/support-widget/bug-report-capture.js') }}"></script>
                <script src="{{ asset('js/support-widget/promptly-support.js') }}"></script>
                <script>
                    // Get accent color from theme - use var to allow redeclaration during Livewire navigation
                    var accentColor = getComputedStyle(document.documentElement).getPropertyValue('--color-accent').trim() || '#3b82f6';

                    // Only initialize if not already initialized
                    if (typeof PromptlySupport !== 'undefined' && !PromptlySupport.state?.initialized) {
                        PromptlySupport.init({
                            apiBaseUrl: '{{ config('app.url') }}',
                            agentId: {{ $agentId }},
                            position: 'bottom-right',
                            primaryColor: accentColor,
                            debug: true  // Enable debug logging
                        });
                    }
                </script>
            @endif
        @endauth
    </body>
</html>
