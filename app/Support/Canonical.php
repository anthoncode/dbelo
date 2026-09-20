<?php

namespace App\Support;

/**
 * The one address a page admits to having.
 *
 * ── WHY url()->current() WAS NOT ENOUGH ──────────────────────────────────
 *
 * It returns the path and throws the query string away, which is right for
 * the junk — a visit arriving with ?utm_source=newsletter is the same page
 * as one arriving without it, and saying so is the whole point of a
 * canonical.
 *
 * It is wrong for one parameter: ?page. A listing's second page is NOT the
 * same page as its first, and a canonical saying it is tells Google to drop
 * it — along with every post linked from it. Google's guidance on
 * pagination is explicit that each page should point at itself; the old
 * rel=next/prev pair was retired in 2019 and nothing replaced it, so the
 * canonical is the only signal left and it has to be honest.
 *
 * ── WHY AN ALLOW-LIST AND NOT A BLOCK-LIST ───────────────────────────────
 *
 * Because the list of tracking parameters has no end — utm_*, fbclid,
 * gclid, mc_cid, and whatever the next platform invents. Naming the two
 * that MATTER is a list that stays short and cannot be outrun.
 */
class Canonical
{
    /**
     * Query parameters that genuinely change which page this is.
     *
     * `page` is Livewire's default; the library screen paginates its
     * collections under `collections` so that two paginators on one screen
     * do not move together. Anything not named here is a variant of the
     * same page and is dropped.
     */
    public const KEEP = ['page', 'collections'];

    /** The canonical URL of the request being served. */
    public static function current(): string
    {
        return self::for(url()->current(), request()->query());
    }

    /**
     * A base URL plus whichever of $params genuinely changes the page.
     *
     * ── THE BASE MAY ALREADY CARRY A QUERY, AND IT IS KEPT ───────────────
     *
     * route('sounds.index', ['category' => 'doors']) comes back as
     * /sounds?category=doors, and that parameter is not tracking junk — it
     * IS the page. So the base's own query survives untouched and only the
     * allow-listed ones are merged in after it.
     *
     * Base first, then the allow-list, always in that order: a page with two
     * canonical spellings depending on how a visitor's link was written is
     * the duplicate this class exists to prevent.
     *
     * @param  array<string, mixed>  $params
     */
    public static function for(string $url, array $params = []): string
    {
        [$path, $existing] = self::split($url);

        $kept = $existing;

        foreach (self::KEEP as $key) {
            $value = $params[$key] ?? null;

            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            // Page one is the bare URL. ?page=1 and the address with no page
            // at all are the same thing, and letting both exist is the
            // duplicate this class is here to prevent.
            if ((string) $value === '1') {
                continue;
            }

            $kept[$key] = $value;
        }

        return $kept === [] ? $path : $path.'?'.http_build_query($kept);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function split(string $url): array
    {
        if (! str_contains($url, '?')) {
            return [$url, []];
        }

        [$path, $query] = explode('?', $url, 2);

        parse_str($query, $existing);

        return [$path, $existing];
    }
}
