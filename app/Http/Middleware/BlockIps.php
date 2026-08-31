<?php

namespace App\Http\Middleware;

use App\Models\IpBlock;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Refuses addresses on the block list.
 *
 * The list is cached, so the normal case — nobody blocked — costs one cache
 * read and no query at all. A database lookup on every request to discover
 * that almost nobody is blocked would be the worst trade in the application.
 *
 * 403 rather than a redirect or a silent drop: whoever is on the list should
 * be told plainly, and anybody blocked by mistake needs to be able to say
 * what they saw.
 */
class BlockIps
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $blocked = IpBlock::active();
        } catch (Throwable) {
            // Before the migration has run, or if the database is down.
            // Failing open is deliberate: a broken block list must not take
            // the whole site offline.
            return $next($request);
        }

        $ip = $request->ip();

        if (! isset($blocked[$ip])) {
            return $next($request);
        }

        $this->countHit($ip);

        abort(403, 'This address has been blocked. If you believe this is a mistake, contact support.');
    }

    /**
     * Count the refusal, cheaply and without ever failing.
     *
     * A raw increment rather than a model: this runs for an address already
     * judged hostile, and loading a model to save one integer is exactly the
     * work that address is trying to make you do.
     */
    private function countHit(string $ip): void
    {
        try {
            if (Schema::hasTable('ip_blocks')) {
                DB::table('ip_blocks')->where('ip_address', $ip)->increment('hits');
            }
        } catch (Throwable) {
            // Never let bookkeeping decide whether the block applies.
        }
    }
}
