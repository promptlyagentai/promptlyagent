{{--
    Forgot Password Page

    Purpose: Request password reset link via email

    Features:
    - Email input for reset request
    - Sends password reset email
    - Generic success message (security: doesn't reveal if account exists)
    - Link to return to login

    Livewire Component Properties:
    - @property string $email Email address for reset

    Livewire Component Methods:
    - sendPasswordResetLink(): Send reset email via Laravel's Password facade

    Security:
    - Generic success message regardless of whether account exists
    - Message: "A reset link will be sent if the account exists"
    - Prevents email enumeration attacks
    - Uses Laravel's built-in password reset system

    Flow:
    1. User enters email
    2. System sends reset email if account exists
    3. Generic success message shown
    4. Email contains link to reset-password page with token

    Session Flash:
    - status: "A reset link will be sent if the account exists"

    Layout:
    - Uses components.layouts.auth layout

    Related:
    - Password::sendResetLink(): Laravel password reset
    - reset-password route: Handles token validation and reset
--}}
<?php

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $email = '';

    /**
     * Send a password reset link to the provided email address.
     */
    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        Password::sendResetLink($this->only('email'));

        session()->flash('status', __('A reset link will be sent if the account exists.'));
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('Forgot password')" :description="__('Enter your email to receive a password reset link')" />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="sendPasswordResetLink" class="flex flex-col gap-6">
        <!-- Email Address -->
        <flux:input
            wire:model="email"
            :label="__('Email Address')"
            type="email"
            required
            autofocus
            placeholder="email@example.com"
        />

        <flux:button variant="primary" type="submit" class="w-full">{{ __('Email password reset link') }}</flux:button>
    </form>

    <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-tertiary">
        <span>{{ __('Or, return to') }}</span>
        <flux:link :href="route('login')" wire:navigate>{{ __('log in') }}</flux:link>
    </div>
</div>
