<?php

namespace App\Http\Middleware;

use App\Models\StatDaily;
use App\Support\Clock;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Counts visits without following anyone around.
 *
 * Two decisions worth knowing about:
 *
 * NO COOKIE. A returning visitor is recognised by a hash of their address,
 * browser and the day, salted with the app key. It cannot be reversed, it
 * cannot be joined to anything, and it stops being valid at midnight. That
 * is enough to count people once a day, and it is the reason dbelo needs no
 * cookie banner for its own analytics.
 *
 * NO ROW PER REQUEST. A hits table is the one that outgrows every other
 * table on the site and it is never read row by row. The counters go
 * straight into stats_daily, which stays at a few rows per day forever.
 *
 * All of it runs in terminate(), after the response has already been sent,
 * so a visitor never waits for the bookkeeping.
 */
class RecordVisit
{
    /** Never counted: they are not people reading pages. */
    protected const IGNORE = [
        'admin', 'admin/*', 'livewire/*', 'unsubscribe/*', 'storage/*',
        'build/*', 'up', 'sitemap.xml', 'robots.txt', 'blog/feed',
        'sounds/*/download',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->countable($request, $response)) {
            return;
        }

        try {
            StatDaily::bump('visits');

            if ($this->isNewToday($request)) {
                StatDaily::bump('visitors');
            }

            if ($referrer = $this->referrer($request)) {
                StatDaily::bump('visits.referrer', $referrer);
            }

            StatDaily::bump('visits.path', $this->path($request));

            $this->countSoundView($request);
        } catch (\Throwable $e) {
            // Analytics must never be the reason a page fails. The response
            // has already gone out by now anyway.
            report($e);
        }
    }

    /**
     * One view for one sound.
     *
     * A direct increment rather than a counter batched in the cache. It is a
     * single UPDATE on an indexed primary key, it runs in terminate() after
     * the response has gone, and it is only on sound detail pages — so it
     * costs a visitor nothing. If this site ever gets busy enough for one
     * write per view to matter, the place to batch it is here, the same way
     * WatchTraffic accumulates in the cache and flushes on a window.
     *
     * Bots are already excluded by countable(): a view count inflated by
     * crawlers would answer "which sounds does nobody look at" with a
     * confident lie.
     */
    protected function countSoundView(Request $request): void
    {
        if (! $request->routeIs('sounds.show')) {
            return;
        }

        $sound = $request->route('sound');

        // Resolved by route-model binding on a normal request; a bare slug
        // if anything bypassed it, and then one lookup is the honest cost.
        $id = is_object($sound)
            ? ($sound->id ?? null)
            : DB::table('sounds')->where('slug', $sound)->value('id');

        if ($id) {
            DB::table('sounds')->where('id', $id)->increment('views_count');
        }
    }

    protected function countable(Request $request, Response $response): bool
    {
        return $request->isMethod('GET')
            && $response->getStatusCode() < 400
            && ! $request->ajax()
            && ! $request->hasHeader('X-Livewire')
            && ! $request->is(...self::IGNORE)
            && ! $this->looksLikeABot($request);
    }

    /**
     * Crude on purpose. Catching every crawler is impossible; catching the
     * ones that announce themselves removes most of the noise, and a
     * dashboard inflated by Googlebot is worse than no dashboard.
     */
    protected function looksLikeABot(Request $request): bool
    {
        $agent = Str::lower((string) $request->userAgent());

        if ($agent === '') {
            return true;
        }

        foreach (['bot', 'crawler', 'spider', 'slurp', 'curl', 'wget', 'python', 'headless', 'lighthouse', 'preview'] as $needle) {
            if (str_contains($agent, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Have we already counted this person today?
     *
     * The hash exists only in the cache and only until midnight. Nothing is
     * written to the database that could identify anyone.
     */
    protected function isNewToday(Request $request): bool
    {
        $fingerprint = hash_hmac(
            'sha256',
            $request->ip().'|'.$request->userAgent().'|'.Clock::day(),
            config('app.key'),
        );

        $key = 'visitor:'.$fingerprint;

        if (Cache::has($key)) {
            return false;
        }

        Cache::put($key, true, Clock::now()->endOfDay());

        return true;
    }

    /** The sending site, not the full URL: the host is the useful part. */
    protected function referrer(Request $request): ?string
    {
        $referrer = $request->headers->get('referer');

        if (! $referrer) {
            return null;
        }

        $host = parse_url($referrer, PHP_URL_HOST);

        if (! $host || $host === $request->getHost()) {
            return null;   // internal navigation is not a referral
        }

        return Str::of($host)->lower()->replaceFirst('www.', '')->toString();
    }

    /**
     * Grouped rather than exact.
     *
     * Storing every sound URL separately would put a row per sound per day in
     * the table and tell you nothing you cannot get from top downloads. What
     * matters here is which KIND of page people land on.
     */
    protected function path(Request $request): string
    {
        $path = '/'.trim($request->path(), '/');

        return match (true) {
            $path === '/' => '/',
            str_starts_with($path, '/sounds/') => '/sounds/:sound',
            str_starts_with($path, '/blog/category/') => '/blog/category/:slug',
            str_starts_with($path, '/blog/tag/') => '/blog/tag/:slug',
            $path !== '/blog' && str_starts_with($path, '/blog/') => '/blog/:post',
            str_starts_with($path, '/collections/') => '/collections/:pack',
            default => Str::limit($path, 60, ''),
        };
    }
}
