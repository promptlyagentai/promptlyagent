{{--
    Reset Password Page

    Purpose: Complete password reset process with token validation

    Features:
    - Token validation from email link
    - Email pre-filled from query string
    - New password with confirmation
    - Password strength requirements
    - Fires PasswordReset event
    - Regenerates remember token

    Livewire Component Properties:
    - @property string $token Reset token from URL (locked to prevent tampering)
    - @property string $email Email address (from query string)
    - @property string $password New password
    - @property string $password_confirmation Password confirmation

    Livewire Component Methods:
    - mount(token): Initialize with token from route, extract email from query
    - resetPassword(): Validate token, update password, regenerate remember token

    Security:
    - Token locked with #[Locked] attribute (prevents client modification)
    - Password hashed with Hash::make()
    - Remember token regenerated (invalidates existing sessions)
    - Token validated by Laravel's Password::reset()

    Flow:
    1. User clicks reset link in email (contains token + email)
    2. Form pre-filled with email
    3. User enters new password + confirmation
    4. Token validated against database
    5. Password updated
    6. PasswordReset event fired
    7. Redirected to login with success message

    Error Handling:
    - Invalid/expired token: Error message displayed
    - Email doesn't match: Error message displayed
    - Password mismatch: Validation error

    Layout:
    - Uses components.layouts.auth layout

    Related:
    - Password::reset(): Laravel password reset validation
    - PasswordReset event: Illuminate\Auth\Events\PasswordReset
--}}
<?php

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    #[Locked]
    public string $token = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Mount the component.
     */
    public function mount(string $token): void
    {
        $this->token = $token;

        $this->email = request()->string('email');
    }

    /**
     * Reset the password for the given user.
     */
    public function resetPassword(): void
    {
        $this->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', 'max:255', Rules\Password::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $this->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) {
                $user->forceFill([
                    'password' => Hash::make($this->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        if ($status != Password::PasswordReset) {
            $this->addError('email', __($status));

            return;
        }

        Session::flash('status', __($status));

        $this->redirectRoute('login', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('Reset password')" :description="__('Please enter your new password below')" />

    <!-- Session Status -->
    <x-auth-session-status class="text-center" :status="session('status')" />

    <form wire:submit="resetPassword" class="flex flex-col gap-6">
        <!-- Email Address -->
        <flux:input
            wire:model="email"
            :label="__('Email')"
            type="email"
            required
            autocomplete="email"
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
            <flux:button type="submit" variant="primary" class="w-full">
                {{ __('Reset password') }}
            </flux:button>
        </div>
    </form>
</div>
