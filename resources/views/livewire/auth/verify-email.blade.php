{{--
    Verify Email Page

    Purpose: Email verification prompt and resend functionality

    Features:
    - Displays email verification instructions
    - Resend verification email button
    - Success message when resent
    - Logout option
    - Auto-redirect if already verified

    Livewire Component Methods:
    - sendVerification(): Resend verification email or redirect if verified
    - logout(Logout $logout): Log out user and redirect to homepage

    Flow:
    1. User registers and is redirected here (if email verification enabled)
    2. Verification email sent automatically on registration
    3. User can resend email if needed
    4. Clicking link in email verifies account
    5. User can log out if needed

    Session Flash:
    - status: 'verification-link-sent' when email resent

    Security:
    - Only accessible to authenticated users
    - Checks if email already verified
    - Auto-redirects verified users to dashboard

    Layout:
    - Uses components.layouts.auth layout

    Related:
    - MustVerifyEmail contract on User model
    - verification.send route: Handles email sending
    - verification.verify route: Handles email link click
    - App\Livewire\Actions\Logout: Logout action
--}}
<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    /**
     * Send an email verification notification to the user.
     */
    public function sendVerification(): void
    {
        if (Auth::user()->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);

            return;
        }

        Auth::user()->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<div class="mt-4 flex flex-col gap-6">
    <flux:text class="text-center">
        {{ __('Please verify your email address by clicking on the link we just emailed to you.') }}
    </flux:text>

    @if (session('status') == 'verification-link-sent')
        <flux:text class="text-center font-medium !text-accent">
            {{ __('A new verification link has been sent to the email address you provided during registration.') }}
        </flux:text>
    @endif

    <div class="flex flex-col items-center justify-between space-y-3">
        <flux:button wire:click="sendVerification" variant="primary" class="w-full">
            {{ __('Resend verification email') }}
        </flux:button>

        <flux:link class="text-sm cursor-pointer" wire:click="logout">
            {{ __('Log out') }}
        </flux:link>
    </div>
</div>
