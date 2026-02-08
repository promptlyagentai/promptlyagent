{{--
    Password Update Settings Page

    Purpose: Secure password change functionality with current password verification

    Features:
    - Current password verification
    - Password strength requirements (Laravel defaults)
    - Password confirmation matching
    - Auto-reset form on validation error
    - Success notification

    Livewire Component Methods:
    - updatePassword(): Validate and update user password, reset form fields

    Events:
    - password-updated: Dispatched when password changed successfully

    Security:
    - Requires current password before changing
    - Uses Laravel's Password validation rules
    - Hashes new password with Hash::make()

    Form Fields:
    - current_password: Required for verification
    - password: New password (with confirmation)
    - password_confirmation: Must match password

    Validation:
    - current_password: required, string, current_password
    - password: required, string, Password::defaults(), confirmed
--}}
<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component {
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password', 'max:255'],
                'password' => ['required', 'string', 'max:255', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Update password')" :subheading="__('Ensure your account is using a long, random password to stay secure')">
        <form wire:submit="updatePassword" class="mt-6 space-y-6">
            <flux:input
                wire:model="current_password"
                :label="__('Current password')"
                type="password"
                required
                maxlength="255"
                autocomplete="current-password"
            />
            <flux:input
                wire:model="password"
                :label="__('New password')"
                type="password"
                required
                maxlength="255"
                autocomplete="new-password"
            />
            <flux:input
                wire:model="password_confirmation"
                :label="__('Confirm Password')"
                type="password"
                required
                maxlength="255"
                autocomplete="new-password"
            />

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full">{{ __('Save') }}</flux:button>
                </div>

                <x-action-message class="me-3" on="password-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>
    </x-settings.layout>
</section>
