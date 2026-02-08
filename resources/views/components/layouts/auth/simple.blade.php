{{--
    Auth Simple Layout (DEFAULT)

    Purpose: Primary authentication layout with centered form card

    Features:
    - Centered card design with logo
    - Enhanced accessibility with color overrides
    - Form element styling consistency
    - Hero logo image display
    - Dark mode support
    - Responsive padding

    Accessibility Enhancements:
    - Explicit text color inheritance
    - Form input focus states
    - High contrast borders
    - Consistent label styling
    - Placeholder color control

    Structure:
    - Full viewport height centering
    - Hero logo image (logo-hero.svg)
    - Card container with elevated background
    - Content slot for forms

    Used By:
    - Login page
    - Registration page
    - Password reset pages
    - Email verification

    Related:
    - components.layouts.auth: Wrapper that uses this layout
    - components.layouts.auth.card: Alternative compact design
    - components.layouts.auth.split: Two-column alternative
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
        <style>
            {{-- Accessibility improvements for consistent text colors --}}
            [data-flux-heading],
            h1, h2, h3, h4, h5, h6 {
                color: var(--color-text-primary) !important;
            }

            [data-flux-subheading],
            p {
                color: var(--color-text-secondary) !important;
            }

            /* Accessibility improvements for form elements */
            label,
            [data-flux-label] {
                color: var(--color-text-secondary) !important;
                font-weight: 500 !important;
            }

            input::placeholder,
            textarea::placeholder {
                color: var(--color-text-tertiary) !important;
            }

            input[type="text"],
            input[type="email"],
            input[type="password"],
            textarea {
                border: 1px solid var(--color-border-default) !important;
                color: var(--color-text-primary) !important;
                background-color: var(--color-surface-bg-elevated) !important;
            }

            input[type="text"]:focus,
            input[type="email"]:focus,
            input[type="password"]:focus,
            textarea:focus {
                border-color: var(--color-accent) !important;
                outline: 2px solid var(--color-accent) !important;
                outline-offset: 0px !important;
            }

            /* Ensure checkbox labels are dark */
            [data-flux-checkbox] + label {
                color: var(--color-text-secondary) !important;
            }
        </style>
    </head>
    <body class="min-h-screen antialiased bg-page">
        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-6">
                <a href="{{ route('home') }}" class="flex flex-col items-center gap-4" wire:navigate>
                    <img src="/images/logo-hero.svg" alt="{{ config('app.name', 'PromptlyAgent') }}" class="w-full max-w-md h-auto">
                    <span class="sr-only">{{ config('app.name', 'PromptlyAgent') }}</span>
                </a>
                <div class="flex flex-col gap-6 bg-surface-elevated p-8 rounded-xl border border-default shadow-sm">
                    {{ $slot }}
                </div>
            </div>
        </div>
        @fluxScripts
    </body>
</html>
