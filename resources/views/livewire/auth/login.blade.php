{{--
    Login Page

    Purpose: User authentication with email/password or Google OAuth

    Features:
    - Email/password login
    - Google OAuth authentication
    - Remember me functionality
    - Rate limiting (5 attempts per email + IP)
    - Forgot password link
    - Registration link
    - Session regeneration on successful login

    Livewire Component Properties:
    - @property string $email User's email address
    - @property string $password User's password
    - @property bool $remember Remember me checkbox state

    Livewire Component Methods:
    - login(): Authenticate user, handle rate limiting, redirect to dashboard
    - ensureIsNotRateLimited(): Check for too many login attempts
    - throttleKey(): Generate unique key for rate limiting

    Rate Limiting:
    - 5 attempts allowed
    - Per email + IP address combination
    - Fires Lockout event when exceeded
    - Returns seconds/minutes remaining in error

    Security:
    - Password is never exposed
    - Session regenerated on login
    - Rate limiting prevents brute force
    - Intended redirect after login

    OAuth:
    - Google Sign-In button
    - Routes to auth.google.redirect

    Layout:
    - Uses components.layouts.auth layout

    Related Routes:
    - password.request: Forgot password
    - register: Create account
    - auth.google.redirect: Google OAuth flow
--}}
<?php

use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        // Check if user account is suspended
        if (Auth::user()->isSuspended()) {
            Auth::logout();
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('Your account has been suspended. Please contact support for assistance.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }

    /**
     * Ensure the authentication request is not rate limited.
     */
    protected function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the authentication rate limiting throttle key.
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email and password below to log in')" />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="login" class="flex flex-col gap-6">
        <!-- Email Address -->
        <flux:input
            wire:model="email"
            :label="__('Email address')"
            type="email"
            required
            autofocus
            autocomplete="email"
            placeholder="email@example.com"
        />

        <!-- Password -->
        <div class="relative">
            <flux:input
                wire:model="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Password')"
                viewable
            />

            @if (Route::has('password.request'))
                <flux:link class="absolute end-0 top-0 text-sm font-semibold text-accent" :href="route('password.request')" wire:navigate>
                    {{ __('Forgot your password?') }}
                </flux:link>
            @endif
        </div>

        <!-- Remember Me -->
        <flux:checkbox wire:model="remember" :label="__('Remember me')" />

        <div class="flex items-center justify-end">
            <flux:button variant="primary" type="submit" class="w-full bg-accent hover:bg-accent-hover border-accent">{{ __('Log in') }}</flux:button>
        </div>
    </form>

    <div class="flex items-center my-4">
        <div class="flex-grow border-t border-default"></div>
        <span class="mx-2 text-tertiary text-xs">{{ __('or') }}</span>
        <div class="flex-grow border-t border-default"></div>
    </div>

    <div class="flex flex-col gap-2">
        <a href="{{ route('auth.google.redirect') }}"
           class="flex items-center justify-center gap-2 px-4 py-2 border border-default rounded-md bg-surface-elevated hover:bg-surface transition text-primary font-medium shadow-sm"
        >
            <svg class="w-5 h-5" viewBox="0 0 48 48"><g><path fill="#4285F4" d="M24 9.5c3.54 0 6.7 1.22 9.2 3.6l6.9-6.9C36.6 2.7 30.8 0 24 0 14.8 0 6.7 5.8 2.7 14.1l8.1 6.3C12.6 13.7 17.8 9.5 24 9.5z"/><path fill="#34A853" d="M46.1 24.6c0-1.6-.1-3.1-.4-4.6H24v9.1h12.4c-.5 2.7-2.1 5-4.4 6.6l7 5.4c4.1-3.8 6.5-9.4 6.5-16.5z"/><path fill="#FBBC05" d="M10.8 28.2c-1-2.7-1-5.7 0-8.4l-8.1-6.3C.6 17.2 0 20.5 0 24s.6 6.8 1.7 10l8.1-6.3z"/><path fill="#EA4335" d="M24 48c6.5 0 12-2.1 16-5.7l-7-5.4c-2 1.4-4.6 2.2-9 2.2-6.2 0-11.4-4.2-13.3-10l-8.1 6.3C6.7 42.2 14.8 48 24 48z"/></g></svg>
            {{ __('Continue with Google') }}
        </a>
    </div>

    @if (Route::has('register'))
        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-secondary">
            <span>{{ __('Don\'t have an account?') }}</span>
            <flux:link :href="route('register')" wire:navigate class="font-semibold text-accent">{{ __('Sign up') }}</flux:link>
        </div>
    @endif
</div>
