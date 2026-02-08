<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
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

        $user = User::firstOrCreate(
            ['email' => $googleUser->getEmail()],
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
}
