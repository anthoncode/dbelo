<?php

namespace App\Support;

use App\Models\Sound;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * What to offer somebody who landed on a dead URL.
 *
 * ── THE IDEA ─────────────────────────────────────────────────────────────
 *
 * A 404 already knows something about the visitor: the address they asked
 * for. `/sounds/heavy-rain-thunder` is not noise — it is a person who wanted
 * heavy rain and thunder, arriving from a mistyped link, a renamed sound or
 * a five-year-old forum post. Answering that with "page not found" throws
 * away the only piece of intent the request carried.
 *
 * So the path is read back as search terms and run against the catalogue.
 * When that finds nothing — or when there was nothing to read — the page
 * falls back to the most downloaded sounds, which is never wrong and is
 * still better than a dead end.
 *
 * ── WHY THIS LIVES HERE AND NOT IN THE VIEW ──────────────────────────────
 *
 * Two callers: the 404 page, and the 410 page a sound gets after a rights
 * claim. Same question, same answer. A rule written in two views is a rule
 * that disagrees with itself the first time one of them is edited.
 *
 * ── THE THREE THINGS THAT WOULD MAKE THIS A BAD IDEA ─────────────────────
 *
 *  1. BOTS. Most 404s on a public site are scans for /wp-login.php and
 *     /.env, not lost humans. Running a search engine query for every one of
 *     them turns an error page into a free load generator pointed at your
 *     own infrastructure. isWorthSearching() is the gate: only paths that
 *     look like content get a query, everything else goes straight to the
 *     cached popular list.
 *
 *  2. THE SEARCH ENGINE BEING DOWN. If Meilisearch is not answering — which
 *     it was not, for most of today — a 404 page that calls Scout throws,
 *     and the error page becomes a 500. Every lookup here is wrapped, and
 *     failure degrades to the fallback instead of propagating.
 *
 *  3. COST PER RENDER. The popular list is the same for everybody, so it is
 *     cached. Only the guess is per-request, and only when it is worth it.
 */
class ErrorSuggestions
{
    /** Enough words to describe a sound; more is a path, not a phrase. */
    private const MAX_WORDS = 6;

    /** Sections whose URLs name a thing worth looking for. */
    private const CONTENT_PREFIXES = ['sounds', 'packs', 'collections', 'blog', 'categories', 'tags'];

    /**
     * Fragments that only ever appear in an automated scan.
     *
     * Not a security measure — nothing here is protected by this list. It is
     * purely about not spending a search on a request that was never a
     * person.
     */
    private const PROBES = [
        'wp-', 'wordpress', 'phpmyadmin', 'phpunit', 'xmlrpc', 'cgi-bin',
        '.php', '.asp', '.jsp', '.env', '.git', '.sql', '.bak', '.yml',
        '.json', '.xml', '.ini', '.log', '.zip', '.tar', '.sh',
        'admin', 'login.', 'autodiscover', 'owa/', '.well-known',
    ];

    /**
     * Everything the error page needs, in one call.
     *
     * @return array{terms: string, sounds: Collection, guessed: bool}
     */
    public static function forPath(?string $path, int $limit = 6): array
    {
        $path = trim((string) $path, '/');
        $terms = self::isWorthSearching($path) ? self::terms($path) : '';

        $sounds = $terms === '' ? collect() : self::matching($terms, $limit);

        if ($sounds->isNotEmpty()) {
            return ['terms' => $terms, 'sounds' => $sounds, 'guessed' => true];
        }

        return ['terms' => $terms, 'sounds' => self::popular($limit), 'guessed' => false];
    }

    /* ═══════════════════════════ Reading the path ═══════════════════════════ */

    public static function isWorthSearching(string $path): bool
    {
        if ($path === '' || strlen($path) > 120) {
            return false;
        }

        $lower = strtolower($path);

        foreach (self::PROBES as $probe) {
            if (str_contains($lower, $probe)) {
                return false;
            }
        }

        $segments = explode('/', $lower);

        // Deeper than this is not a URL anybody typed or linked.
        if (count($segments) > 3) {
            return false;
        }

        // A bare slug at the root is a page that moved — worth a look.
        if (count($segments) === 1) {
            return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)+$/', $segments[0]);
        }

        return in_array($segments[0], self::CONTENT_PREFIXES, true);
    }

    /**
     * The last segment of the path, read back as words.
     *
     * The last segment on purpose: in /sounds/heavy-rain-thunder the useful
     * half is the part that names the thing, and the prefix is a section
     * name that would only dilute the query.
     */
    public static function terms(string $path): string
    {
        $last = (string) Str::of($path)->afterLast('/');

        // A UUID or a bare number describes nothing. It is a real 404 — just
        // not one with a hint in it.
        if (preg_match('/^[0-9]+$/', $last) || Str::isUuid($last)) {
            return '';
        }

        $words = Str::of($last)
            ->replaceMatches('/[_+.]/', '-')
            ->replaceMatches('/[^a-z0-9\-]/i', '')
            ->explode('-')
            // Trailing "-2" from a slug collision, and single letters, add
            // nothing to a search and can push a real match out of the list.
            ->filter(fn ($word) => strlen($word) > 1 && ! is_numeric($word))
            ->take(self::MAX_WORDS);

        return $words->implode(' ');
    }

    /* ═══════════════════════════ Finding sounds ═══════════════════════════ */

    /**
     * Sounds that match those words.
     *
     * Scout first. If the engine is unreachable — or simply finds nothing —
     * a plain LIKE on the title still answers, because a search page that
     * only works when Meilisearch is up is a search page that is down every
     * time the error page matters most.
     */
    public static function matching(string $terms, int $limit = 6): Collection
    {
        try {
            $found = Sound::search($terms)->take($limit)->get();

            if ($found->isNotEmpty()) {
                return $found->load(['user', 'category']);
            }
        } catch (Throwable) {
            // Engine down. Fall through to the database.
        }

        try {
            return Sound::published()
                ->with(['user', 'category'])
                ->where(function ($query) use ($terms) {
                    foreach (explode(' ', $terms) as $word) {
                        $query->orWhere('title', 'like', '%'.$word.'%');
                    }
                })
                ->orderByDesc('downloads_count')
                ->take($limit)
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * The most downloaded sounds, cached.
     *
     * Identical for every visitor and changes slowly, so re-running it for
     * each scan of /wp-login.php would be pure waste.
     */
    public static function popular(int $limit = 6): Collection
    {
        try {
            return Cache::remember("errors.popular.{$limit}", now()->addMinutes(30), function () use ($limit) {
                return Sound::published()
                    ->with(['user', 'category'])
                    ->orderByDesc('downloads_count')
                    ->orderByDesc('published_at')
                    ->take($limit)
                    ->get();
            });
        } catch (Throwable) {
            // The database is the thing that is broken. An error page must
            // still render; it just renders with less on it.
            return collect();
        }
    }
}
