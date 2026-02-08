{{--
    Registration Page

    Purpose: New user account creation

    Features:
    - Name, email, password registration
    - Password confirmation matching
    - Password strength requirements (Laravel defaults)
    - Email uniqueness validation
    - Auto-login after registration
    - Fires Registered event
    - Redirect to dashboard

    Livewire Component Properties:
    - @property string $name Full name
    - @property string $email Email address (unique)
    - @property string $password Password (confirmed)
    - @property string $password_confirmation Password confirmation field

    Livewire Component Methods:
    - register(): Validate, create user, login, redirect

    Validation:
    - name: required, string, max:255
    - email: required, email, unique in users table
    - password: required, confirmed, Laravel Password defaults

    Security:
    - Password hashed with Hash::make()
    - Email converted to lowercase
    - Fires Registered event (for email verification, etc.)

    Flow:
    1. User fills form
    2. Validation runs
    3. User created in database
    4. Registered event fired (triggers verification email if configured)
    5. User logged in automatically
    6. Redirected to dashboard

    Layout:
    - Uses components.layouts.auth layout

    Related:
    - Registered event: Illuminate\Auth\Events\Registered
    - Can trigger email verification workflow
--}}
<?php

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Handle an incoming registration request.
     */
    public function register(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'password' => ['required', 'string', 'confirmed', 'max:255', Rules\Password::defaults()],
        ]);

        $validated['password'] = Hash::make($validated['password']);

        event(new Registered(($user = User::create($validated))));

        Auth::login($user);

        $this->redirectIntended(route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="register" class="flex flex-col gap-6">
        <!-- Name -->
        <flux:input
            wire:model="name"
            :label="__('Name')"
            type="text"
            required
            autofocus
            autocomplete="name"
            :placeholder="__('Full name')"
        />

        <!-- Email Address -->
        <flux:input
            wire:model="email"
            :label="__('Email address')"
            type="email"
            required
            autocomplete="email"
            placeholder="email@example.com"
        />

        <!-- Password -->
        <flux:input
            wire:model="password"
            :label="__('Password')"
            type="password"
            required
            maxlength="255"
            autocomplete="new-password"
            :placeholder="__('Password')"
            viewable
        />

        <!-- Confirm Password -->
        <flux:input
            wire:model="password_confirmation"
            :label="__('Confirm password')"
            type="password"
            required
            maxlength="255"
            autocomplete="new-password"
            :placeholder="__('Confirm password')"
            viewable
        />

        <div class="flex items-center justify-end">
            <flux:button type="submit" variant="primary" class="w-full bg-accent hover:bg-accent-hover border-accent">
                {{ __('Create account') }}
            </flux:button>
        </div>
    </form>

    <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-secondary">
        <span>{{ __('Already have an account?') }}</span>
        <flux:link :href="route('login')" wire:navigate class="font-semibold text-accent">{{ __('Log in') }}</flux:link>
    </div>
</div>
