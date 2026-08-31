<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended account must not be able to keep browsing on a session opened
 * before the suspension. Checking only at login would leave that door open
 * for as long as the session lives.
 *
 * Also doubles as the "last seen" tracker, throttled to one write every
 * five minutes so it does not turn every page view into an UPDATE.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->status === 'suspended') {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => $user->suspension_reason
                    ? "Your account is suspended: {$user->suspension_reason}"
                    : 'Your account is suspended. Contact support.',
            ]);
        }

        if (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinutes(5))) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
