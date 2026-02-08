{{--
    Impersonation Banner Component

    Displays a warning banner when an admin is impersonating another user.
    Shows the impersonated user's name and provides a button to leave impersonation.

    Usage: <x-impersonation-banner />
--}}
@impersonating($guard = null)
    <div class="fixed top-0 left-0 right-0 z-50 bg-[var(--palette-warning-400)] dark:bg-[var(--palette-warning-700)] text-[var(--palette-warning-950)] dark:text-[var(--palette-warning-50)] px-4 py-3 shadow-lg">
        <div class="container mx-auto flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <flux:icon.exclamation-triangle class="w-5 h-5" />
                <div>
                    <span class="font-semibold">Impersonating:</span>
                    <span class="font-medium">{{ auth()->user()->name }} ({{ auth()->user()->email }})</span>
                </div>
            </div>
            <div class="flex items-center space-x-4">
                <span class="text-sm opacity-90">
                    Actions performed will be as this user
                </span>
                <a
                    href="{{ route('impersonate.leave') }}"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-[var(--palette-warning-950)] dark:bg-[var(--palette-warning-100)] text-[var(--palette-warning-50)] dark:text-[var(--palette-warning-950)] font-medium rounded-lg hover:opacity-90 transition shadow">
                    <flux:icon.arrow-left class="w-4 h-4" />
                    Leave Impersonation
                </a>
            </div>
        </div>
    </div>
    <!-- Spacer to prevent content from being hidden under fixed banner -->
    <div class="h-[52px]"></div>
@endImpersonating
