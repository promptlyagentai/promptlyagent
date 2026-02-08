{{--
    Auth Session Status Component

    Purpose: Display session status messages on auth pages

    Features:
    - Conditional rendering (only if status exists)
    - Accent-colored text
    - Customizable via attributes

    Component Props:
    - @props string|null $status Status message to display

    Usage:
    <x-auth-session-status :status="session('status')" />
    <x-auth-session-status class="text-center" :status="session('status')" />

    Common Status Messages:
    - "verification-link-sent": Email verification resent
    - "A reset link will be sent...": Password reset initiated
    - Custom messages from controllers

    Styling:
    - font-medium, text-sm by default
    - text-accent color
    - Mergeable classes via attributes

    Used By:
    - login.blade.php
    - register.blade.php
    - forgot-password.blade.php
    - reset-password.blade.php
    - verify-email.blade.php
--}}
@props([
    'status',
])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-accent']) }}>
        {{ $status }}
    </div>
@endif
