<?php

namespace App\Http\Middleware;

use App\Support\SiteStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The door.
 *
 * Deliberately NOT Laravel's own `artisan down`. That one writes a file and
 * refuses everything including the panel, so re-opening the site means
 * getting to a terminal — which is the one thing you may not have when you
 * discover the site is closed. This is a setting, it is changed from the
 * screen, and the person who closed it can always still reach that screen.
 *
 * Fails open. If reading the setting throws — the database being down is
 * exactly when this code runs — the site stays up. A gate that closes on
 * its own error turns one broken query into a total outage.
 */
class CheckSiteStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (SiteStatus::isLive()) {
                return $next($request);
            }

            // Order matters: the open paths are checked BEFORE the bypass,
            // because reaching the login form is how you become someone
            // the bypass applies to.
            if (SiteStatus::allows($request) || SiteStatus::bypasses($request->user())) {
                return $next($request);
            }
        } catch (Throwable) {
            return $next($request);
        }

        $status = SiteStatus::current();

        // 503, not 200 and not 403.
        //
        // A crawler that gets 200 on a placeholder caches the placeholder as
        // your homepage; a crawler that gets 503 comes back later and keeps
        // whatever it already had. Retry-After says how much later, which is
        // the difference between "come back in an hour" and Google deciding
        // on its own that the site is gone.
        return response()
            ->view('site-closed', ['status' => $status, 'message' => SiteStatus::message()], 503)
            ->header('Retry-After', 3600)
            ->header('X-Robots-Tag', 'noindex');
    }
}
