<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class SocialiteController extends Controller
{
    /**
     * Redirect the user to the Google authentication page.
     */
    public function redirectToGoogle(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Obtain the user information from Google.
     */
    public function handleGoogleCallback(): RedirectResponse
    {
        $googleUser = Socialite::driver('google')->stateless()->user();
        $email = strtolower((string) $googleUser->getEmail());

        if (! $this->isAllowedGoogleEmail($email)) {
            Log::warning('Rejected Google login for disallowed email domain', [
                'email_domain' => str_contains($email, '@') ? substr(strrchr($email, '@'), 1) : null,
            ]);

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Your Google account is not allowed to access this application.']);
        }

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $googleUser->getName() ?? $googleUser->getNickname() ?? $googleUser->getEmail(),
                'password' => bcrypt(uniqid('google_', true)),
            ]
        );

        // Check if email is in auto-admin list (configured per environment)
        $autoAdminEmails = array_filter(explode(',', env('GOOGLE_AUTO_ADMIN_EMAILS', '')));

        if (in_array($user->email, $autoAdminEmails)) {
            $user->is_admin = true;
            $user->save();
        }

        Auth::login($user, true);

        return redirect()->intended(route('dashboard'));
    }

    private function isAllowedGoogleEmail(string $email): bool
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $allowedDomains = array_map(
            static fn (string $domain): string => strtolower(ltrim(trim($domain), '@')),
            config('services.google.allowed_domains', [])
        );

        $allowedDomains = array_filter($allowedDomains);

        if ($allowedDomains === []) {
            return true;
        }

        $emailDomain = substr(strrchr($email, '@'), 1);

        return in_array($emailDomain, $allowedDomains, true);
    }
}
