<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Pages live at the root — /about, /pricing — which is what makes them look
 * like part of the site instead of a bolted-on CMS. The cost is that a page
 * slug can shadow a real route: create a page called "sounds" and the
 * catalogue disappears.
 *
 * The list is built from the routes that actually exist rather than typed by
 * hand, so it stays correct as the site grows. A route added next month is
 * protected without anyone remembering to come back here.
 */
class ReservedSlugs
{
    /** Never reachable as routes, but reserved all the same. */
    protected const EXTRA = [
        'admin', 'api', 'storage', 'livewire', 'blog', 'assets', 'vendor',
        'build', 'sitemap', 'robots', 'feed', 'rss', 'up', 'login', 'register',
        'logout', 'password', 'email', 'user', 'settings', 'two-factor',
    ];

    public static function all(): array
    {
        return Cache::remember('reserved.slugs', now()->addHour(), function () {
            return collect(Route::getRoutes()->getRoutes())
                ->map(fn ($route) => explode('/', $route->uri())[0])
                // The catch-all page route itself is "{page}": skip anything
                // that is a parameter rather than a literal segment.
                ->reject(fn ($segment) => $segment === '' || $segment === '/' || str_starts_with($segment, '{'))
                ->merge(self::EXTRA)
                ->map(fn ($segment) => strtolower($segment))
                ->unique()
                ->sort()
                ->values()
                ->all();
        });
    }

    public static function taken(string $slug): bool
    {
        return in_array(strtolower(trim($slug, '/')), self::all(), true);
    }
}
