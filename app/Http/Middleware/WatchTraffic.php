<?php

namespace App\Http\Middleware;

use App\Models\AbuseSignal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Notices when one address is behaving like a machine.
 *
 * Two rules shape this:
 *
 * COUNT IN THE CACHE, WRITE ONLY THE EXCEPTION. A row per request is the
 * table that outgrows every other table on the site and is never read row by
 * row — the same mistake RecordVisit already refuses to make. Counters live
 * in the cache for one window; a row appears only when a threshold is
 * crossed, which is the only part anybody will ever look at.
 *
 * IT RUNS IN terminate(). After the response has gone. A visitor never waits
 * for this, and neither does an attacker — which matters, because work done
 * before the response is work their flood is making you do.
 *
 * What it does NOT do is decide who is a bot. The busiest crawler on a site
 * like this is Googlebot, and this site lives on search traffic; a rule that
 * blocks robots by name is self-harm dressed as security. Rate is the
 * signal, because rate does not care about intent and does not mistake a
 * search engine for an enemy.
 */
class WatchTraffic
{
    /** Requests from one address within a window before it is a signal. */
    public const RATE_THRESHOLD = 300;

    /** Sound detail pages from one address within a window. */
    public const ENUMERATION_THRESHOLD = 120;

    /** Minutes per counting window. */
    public const WINDOW = 10;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if ($request->is('livewire/*', 'build/*', 'storage/*', 'up')) {
                return;
            }

            $ip = $request->ip();

            if (! $ip) {
                return;
            }

            $window = (int) floor(time() / (self::WINDOW * 60));

            $this->tally("sec.rate.{$ip}.{$window}", self::RATE_THRESHOLD, fn ($count) => AbuseSignal::raise(
                'rate', $ip, null,
                "{$count} requests in ".self::WINDOW.' minutes', $count,
            ));

            // Sound detail pages specifically: a high total could be a person
            // with a busy tab, but a hundred DIFFERENT sound pages in ten
            // minutes is somebody copying the catalogue.
            if ($request->routeIs('sounds.show')) {
                $this->tally("sec.enum.{$ip}.{$window}", self::ENUMERATION_THRESHOLD, fn ($count) => AbuseSignal::raise(
                    'enumeration', $ip, null,
                    "{$count} sound pages in ".self::WINDOW.' minutes', $count,
                ));
            }
        } catch (Throwable) {
            // Watching traffic must never be able to break serving it.
        }
    }

    /**
     * Increment a window counter and fire once, on the crossing.
     *
     * Once, not every request past the threshold: a flood of 5,000 would
     * otherwise write 4,700 rows, which is the flood succeeding by another
     * route. A separate "fired" key holds the latch for the window.
     */
    private function tally(string $key, int $threshold, callable $onCross): void
    {
        if (Cache::add($key, 1, self::WINDOW * 60)) {
            return;   // first request of the window; nothing to compare yet
        }

        $count = (int) Cache::increment($key);

        if ($count < $threshold) {
            return;
        }

        if (Cache::add($key.'.fired', true, self::WINDOW * 60)) {
            $onCross($count);
        }
    }
}
