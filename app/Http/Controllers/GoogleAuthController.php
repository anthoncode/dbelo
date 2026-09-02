<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Security;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Signing in with Google.
 *
 * Two routes and one long comment, because almost everything that goes wrong
 * with social login goes wrong quietly and in this file.
 */
class GoogleAuthController extends Controller
{
    /**
     * Send the visitor to Google.
     */
    public function redirect(): RedirectResponse
    {
        abort_unless(Security::googleReady() && $this->installed(), 404);

        $this->configure();

        return Socialite::driver('google')->redirect();
    }

    /**
     * Back from Google, with a person attached — or not.
     */
    public function callback(): RedirectResponse
    {
        abort_unless(Security::googleReady() && $this->installed(), 404);

        $this->configure();

        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('login')
                ->withErrors(['email' => 'Google sign-in did not complete. Please try again, or use your password.']);
        }

        /*
         * THE CHECK THAT MATTERS.
         *
         * Below, an account whose email matches an existing one is linked to
         * it — which is convenient and correct, and is also an account
         * takeover the moment the email is not proven. Anybody can put any
         * address on an unverified account at some providers and then "sign
         * in as" whoever owns it here.
         *
         * Google does verify, and it says so in this flag. So the flag is
         * what we trust, not the fact that it is Google.
         */
        $verified = filter_var($google->user['email_verified'] ?? false, FILTER_VALIDATE_BOOL);

        if (! $verified || blank($google->getEmail())) {
            return redirect()->route('login')
                ->withErrors(['email' => 'That Google account has no confirmed email address, so it cannot be used to sign in here.']);
        }

        $user = $this->resolve($google);

        /*
         * No suspension check here on purpose. EnsureAccountIsActive runs on
         * every web request and already enforces it on the very next one —
         * duplicating the rule would mean two places to keep in step, and the
         * copy in this file is the one that would be forgotten.
         */

        /*
         * TWO-FACTOR IS NOT BYPASSED.
         *
         * Signing in with Google proves control of a Google account. It does
         * not prove the second factor this person chose to put on this site
         * — and if it let them past, anybody who gets into their Google
         * account has just walked around the 2FA they enabled here. That
         * would make our own 2FA a lie.
         *
         * This is Fortify's own mechanism: park the id in the session and
         * hand over to the challenge screen.
         */
        if (filled($user->two_factor_secret ?? null)
            && (! array_key_exists('two_factor_confirmed_at', $user->getAttributes()) || filled($user->two_factor_confirmed_at))) {
            session(['login.id' => $user->getKey(), 'login.remember' => false]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, remember: true);

        session()->regenerate();

        return redirect()->intended(
            $user->isAdmin() ? route('admin.dashboard') : route('library'),
        );
    }

    /**
     * The account this Google identity belongs to, creating it if new.
     */
    private function resolve($google): User
    {
        // Signed in here before with this same Google account.
        $user = User::where('oauth_provider', 'google')
            ->where('oauth_id', $google->getId())
            ->first();

        if ($user) {
            return $user;
        }

        $user = User::where('email', $google->getEmail())->first();

        if ($user) {
            // An existing password account, now also reachable through
            // Google. The password keeps working: taking it away would lock
            // them out of every device where they use it.
            $user->forceFill([
                'oauth_provider' => 'google',
                'oauth_id' => $google->getId(),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            ActivityLog::record('user.oauth.linked', $user, 'Google account linked', ['provider' => 'google']);

            return $user;
        }

        /*
         * A brand new account.
         *
         * The password is random and nobody knows it, INCLUDING the user —
         * on purpose, and in preference to making the column nullable. A
         * null password means every Hash::check in the framework is one day
         * going to be handed a null, and PHP 8.4 deprecates that; a random
         * one is simply a password that will never be guessed. If they ever
         * want one, "Forgot your password?" sends it to the same Google
         * address they just proved they own, and the normal flow does the
         * rest.
         *
         * NOT ADDED TO THE NEWSLETTER, unlike the registration form. That
         * form carries a ticked box and records the exact wording shown, so
         * consent can be proven. Here there is no box and no wording — so
         * there is no consent, and adding them anyway would be the kind of
         * thing this project has been careful not to do.
         */
        $user = User::create([
            'name' => $google->getName() ?: Str::before($google->getEmail(), '@'),
            'email' => $google->getEmail(),
            'password' => Hash::make(Str::random(64)),
            'email_verified_at' => now(),
            'oauth_provider' => 'google',
            'oauth_id' => $google->getId(),
        ]);

        ActivityLog::record('user.oauth.registered', $user, 'Account created with Google', ['provider' => 'google']);

        return $user;
    }

    /**
     * Credentials handed to Socialite at the moment of use.
     *
     * Not laid over config at boot. The client secret is decrypted here and
     * nowhere else, so it exists in memory only on the two requests that
     * actually talk to Google rather than on every page view of the site.
     *
     * The redirect is DERIVED from the route, never stored — a second copy
     * in the settings table would be a second definition of something
     * APP_URL already decides, and it would disagree in silence.
     */
    private function configure(): void
    {
        config([
            'services.google' => [
                'client_id' => Security::text('google_client_id'),
                'client_secret' => Security::text('google_client_secret'),
                'redirect' => Security::googleCallback(),
            ],
        ]);
    }

    private function installed(): bool
    {
        return class_exists(\Laravel\Socialite\SocialiteServiceProvider::class);
    }
}
