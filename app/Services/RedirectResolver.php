<?php

namespace App\Services;

use App\Models\NotFound;
use App\Models\Redirect;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The last thing that runs before a 404 becomes a 404.
 *
 * Two properties are worth stating, because both are the reason this design
 * was chosen over a middleware:
 *
 *   It costs nothing when nothing is broken. A working URL never reaches
 *   this class, so the table is never queried on a normal page view. A
 *   middleware would check every single request for the sake of the few that
 *   fail.
 *
 *   A real route can never be shadowed. By the time we are here Laravel has
 *   already decided nothing matches, so an admin cannot take the catalogue
 *   offline by typing "sounds" into a form.
 */
class RedirectResolver
{
    public const MAP = 'redirects.map';

    /**
     * Turn a request into a redirect, or record the miss and stand aside.
     *
     * Returning null lets Laravel render its own 404, which is what should
     * happen for everything we have no rule for.
     */
    public function handle(Request $request, ?Throwable $exception = null): ?Response
    {
        // POST to a missing URL is a broken form or a probe, never a moved
        // page — and redirecting it would drop the body anyway.
        if (! $request->isMethod('GET')) {
            return null;
        }

        $path = self::normalise($request->path());

        if ($path === '') {
            return null;
        }

        try {
            $match = $this->lookup($path);
        } catch (Throwable $e) {
            // Before the migration has run, or if the cache store is down.
            // A broken redirect table must not turn every 404 into a 500.
            report($e);

            return null;
        }

        if ($match === null) {
            $this->record($request, $path);

            return null;
        }

        $this->countHit($match['id']);

        if ((int) $match['status'] === 410) {
            return $this->gone($exception);
        }

        return redirect()->away(
            $this->destination($match['to'], $request),
            (int) $match['status'],
        );
    }

    // ---------------------------------------------------------------
    // Matching
    // ---------------------------------------------------------------

    /**
     * Exact match first, wildcards only if that misses.
     *
     * The exact map is an array lookup — O(1), whatever the table grows to.
     * Wildcards are a loop, which is why they are kept in their own much
     * shorter list and never consulted unless the fast path failed.
     */
    protected function lookup(string $path): ?array
    {
        $map = $this->map();

        if (isset($map['exact'][$path])) {
            return $map['exact'][$path];
        }

        foreach ($map['wildcards'] as $rule) {
            $prefix = rtrim($rule['from'], '*');
            $prefix = rtrim($prefix, '/');

            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                $tail = ltrim(substr($path, strlen($prefix)), '/');

                return $rule + ['to' => $this->expand($rule['to'], $tail)];
            }
        }

        return null;
    }

    /** `blog/*` with a tail of "hello" becomes `blog/hello`. */
    protected function expand(?string $to, string $tail): ?string
    {
        if ($to === null) {
            return null;
        }

        return str_contains($to, '*')
            ? str_replace('*', $tail, $to)
            : $to;
    }

    /**
     * The whole table, in memory, in the shape the lookup wants.
     *
     * Cached forever and dropped by the model's saved/deleted hooks, so it is
     * built once per deploy rather than once per broken link.
     */
    protected function map(): array
    {
        return Cache::rememberForever(self::MAP, function () {
            if (! Schema::hasTable('redirects')) {
                return ['exact' => [], 'wildcards' => []];
            }

            $exact = [];
            $wildcards = [];

            DB::table('redirects')
                ->select('id', 'from', 'to', 'status', 'is_wildcard')
                ->orderByRaw('LENGTH(`from`) DESC')  // most specific wildcard wins
                ->get()
                ->each(function ($row) use (&$exact, &$wildcards) {
                    $rule = [
                        'id' => (int) $row->id,
                        'from' => $row->from,
                        'to' => $row->to,
                        'status' => (int) $row->status,
                    ];

                    if ($row->is_wildcard) {
                        $wildcards[] = $rule;
                    } else {
                        $exact[$row->from] = $rule;
                    }
                });

            return ['exact' => $exact, 'wildcards' => $wildcards];
        });
    }

    // ---------------------------------------------------------------
    // Building the response
    // ---------------------------------------------------------------

    /**
     * Absolute targets are left alone; everything else is resolved against
     * this site. The original query string is carried over unless the rule
     * brought its own, so ?utm_source survives a move.
     */
    protected function destination(string $to, Request $request): string
    {
        $url = str_starts_with($to, 'http://') || str_starts_with($to, 'https://')
            ? $to
            : url('/'.ltrim($to, '/'));

        $query = $request->getQueryString();

        if ($query && ! str_contains($url, '?')) {
            $url .= '?'.$query;
        }

        return $url;
    }

    /**
     * 410 Gone: this existed, it is not coming back, stop asking.
     *
     * Worth the extra status over a 404 for anything removed on purpose — a
     * sound taken down after a claim, say. Google retires a 410 much faster,
     * which is the whole point when the reason for removal is legal.
     */
    protected function gone(?Throwable $exception): Response
    {
        foreach (['errors.410', 'errors.404'] as $view) {
            if (view()->exists($view)) {
                return response()->view($view, ['exception' => $exception], 410);
            }
        }

        return response('Gone', 410);
    }

    // ---------------------------------------------------------------
    // Counting
    // ---------------------------------------------------------------

    /**
     * One cheap update per redirected request. Wrapped because a counter is
     * never worth failing a redirect over.
     */
    protected function countHit(int $id): void
    {
        try {
            DB::table('redirects')->where('id', $id)->update([
                'hits' => DB::raw('hits + 1'),
                'last_hit_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    protected function record(Request $request, string $path): void
    {
        try {
            if (! Schema::hasTable('not_founds')) {
                return;
            }

            NotFound::bump(
                $path,
                $request->headers->get('referer'),
                $request->userAgent(),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    // ---------------------------------------------------------------
    // Shared normalisation
    // ---------------------------------------------------------------

    /**
     * The one definition of what a path is, used by the resolver, the admin
     * form and the claim hook alike.
     *
     * Lowercased and stripped of slashes and query strings, so /Sounds/Rain/
     * and /sounds/rain?x=1 are the same rule. Two rules that differ only in
     * case would be two rules nobody can tell apart in a table.
     */
    public static function normalise(string $path): string
    {
        $path = parse_url(trim($path), PHP_URL_PATH) ?: $path;
        $path = preg_replace('#/{2,}#', '/', rawurldecode($path));

        return strtolower(trim($path, "/ \t\n\r\0\x0B"));
    }
}
