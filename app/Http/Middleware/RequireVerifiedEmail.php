<?php

namespace App\Http\Middleware;

use App\Support\Security;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A confirmed inbox, but only where it is worth asking for.
 *
 * NOT Laravel's `verified` middleware, for one reason: that one is on or off
 * in the route file, and this has to be a setting somebody can turn off at
 * two in the morning when it turns out to be blocking real people.
 *
 * Applied to downloading and uploading, and to nothing else. Browsing,
 * listening and having an account all stay open — asking somebody to prove
 * an email address before they have heard a single sound is asking them to
 * work before they know whether the site is worth it.
 *
 * The reason it earns its place: a throwaway signup is free and a working
 * inbox is not, so this filters more automated accounts than the captcha
 * does, and it does it without making one real person solve a puzzle.
 */
class RequireVerifiedEmail
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Security::flag('verify_email')) {
            return $next($request);
        }

        $user = $request->user();

        /*
         * Not signed in at all is somebody else's decision, and since the
         * free-download allowance shipped it is a real one rather than an
         * impossible state: the download route no longer carries `auth`,
         * because whether a visitor may take a file is a setting, and a
         * middleware cannot enforce a setting it does not read. So a guest
         * reaching here is expected. DownloadController decides what they
         * get; this class only ever has an opinion about an ACCOUNT whose
         * address is unconfirmed.
         */
        if (! $user || $user->hasVerifiedEmail()) {
            return $next($request);
        }

        /*
         * An account created with Google never receives our verification
         * email, and it does not need one — Google confirmed the address
         * before it ever reached us. The controller only accepts a Google
         * identity whose email_verified flag is true, and stamps
         * email_verified_at at that moment, so this is belt and braces for
         * any account linked before that rule existed.
         */
        if (filled($user->oauth_provider ?? null)) {
            return $next($request);
        }

        return redirect()->route('verification.notice')
            ->with('status', 'Confirm your email address to download. We have sent you a link.');
    }
}
