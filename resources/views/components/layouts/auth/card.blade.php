{{--
    Auth Card Layout

    Purpose: Centered card layout for authentication pages

    Features:
    - Centered card design
    - Logo link to homepage
    - Gradient background (light/dark mode)
    - Responsive padding
    - Max width constraint (md)
    - Shadow and border styling

    Structure:
    - Full viewport height centering
    - Logo at top (with icon)
    - Card container with content slot
    - Flux UI scripts included

    Used For:
    - Alternative auth page design
    - More compact than split layout
    - Simpler than split with quote

    Related:
    - components.layouts.auth.simple: Currently used default
    - components.layouts.auth.split: Two-column alternative
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" >
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-neutral-100 antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div class="bg-muted flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-md flex-col gap-6">
                {{-- App Logo Link --}}
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-2 font-medium" wire:navigate>
                    <span class="flex h-9 w-9 items-center justify-center rounded-md">
                        <x-app-logo-icon class="size-9 fill-current text-black dark:text-white" />
                    </span>

                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>

                <div class="flex flex-col gap-6">
                    <div class="rounded-xl border bg-surface border-default text-primary shadow-xs">
                        <div class="px-10 py-8">{{ $slot }}</div>
                    </div>
                </div>
            </div>
        </div>
        @fluxScripts
    </body>
</html>
