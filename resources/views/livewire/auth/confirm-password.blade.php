{{--
    Confirm Password Page

    Purpose: Re-authentication for sensitive operations

    Features:
    - Password confirmation for authenticated users
    - Required for accessing secure areas
    - Session-based confirmation tracking
    - Redirects to intended destination after confirmation

    Livewire Component Properties:
    - @property string $password User's current password

    Livewire Component Methods:
    - confirmPassword(): Verify password, store confirmation timestamp, redirect

    Security:
    - Validates current password against authenticated user
    - Stores confirmation timestamp in session
    - Used as middleware requirement for sensitive operations
    - Confirmation expires after configured time period

    Use Cases:
    - Changing password
    - Deleting account
    - Accessing sensitive settings
    - Two-factor authentication setup

    Flow:
    1. User attempts to access secure area
    2. Redirected to password confirmation if not recently confirmed
    3. User enters current password
    4. Password validated
    5. Confirmation timestamp stored in session
    6. User redirected to intended destination

    Session:
    - auth.password_confirmed_at: Unix timestamp of confirmation

    Error Handling:
    - Incorrect password: Shows auth.password error message

    Layout:
    - Uses components.layouts.auth layout

    Related:
    - password.confirm middleware: Triggers this page
    - Auth::guard('web')->validate(): Password verification
--}}
<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $password = '';

    /**
     * Confirm the current user's password.
     */
    public function confirmPassword(): void
    {
        $this->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('web')->validate([
            'email' => Auth::user()->email,
            'password' => $this->password,
        ])) {
            throw ValidationException::withMessages([
                'password' => __('auth.password'),
            ]);
        }

        session(['auth.password_confirmed_at' => time()]);

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        :title="__('Confirm password')"
        :description="__('This is a secure area of the application. Please confirm your password before continuing.')"
    />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="confirmPassword" class="flex flex-col gap-6">
        <!-- Password -->
        <flux:input
            wire:model="password"
            :label="__('Password')"
            type="password"
            required
            autocomplete="new-password"
            :placeholder="__('Password')"
            viewable
        />

        <flux:button variant="primary" type="submit" class="w-full">{{ __('Confirm') }}</flux:button>
    </form>
</div>
