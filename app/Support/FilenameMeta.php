<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Reads what it can out of a filename so fifty rows do not start empty.
 *
 * Everything here is a PROPOSAL. Nothing it returns is saved without the
 * admin seeing it first — which is what makes it safe to guess at all. A
 * wrong suggestion costs one keystroke to fix; an empty field costs a
 * sentence to type, fifty times.
 *
 * Two shapes are understood:
 *
 *   bicycle-bell-ring.wav
 *       → title "Bicycle Bell Ring"
 *
 *   BIKEBell_Bicycle Bell Single Ring_MARCO_DBELO.wav
 *       → the Universal Category System convention, which most
 *         professional effects libraries follow. The first segment encodes
 *         the category, the second is the name, and the rest is the
 *         creator and library. Worth reading because a contributor who
 *         already names files this way has done the categorising for us.
 */
class FilenameMeta
{
    /**
     * @return array{title:string, ucs:?string, category_id:?int, confidence:int}
     */
    public static function parse(string $filename): array
    {
        $base = pathinfo($filename, PATHINFO_FILENAME);

        $ucs = self::ucsCategoryId($base);

        $title = $ucs
            ? self::clean(explode('_', $base)[1] ?? $base)
            : self::clean($base);

        [$categoryId, $confidence] = self::proposeCategory($ucs, $title);

        return [
            'title' => $title,
            'ucs' => $ucs,
            'category_id' => $categoryId,
            'confidence' => $confidence,
        ];
    }

    // ---------------------------------------------------------------
    // Title
    // ---------------------------------------------------------------

    /**
     * Filename to something a person would have typed.
     *
     * The trailing-number strip is deliberately narrow: "Door Slam 03" is a
     * take number and should go, but "Boeing 747" and "Countdown 3 2 1" are
     * the name. Only a separated group of digits at the very end is removed,
     * and only when something is left afterwards.
     */
    public static function clean(string $value): string
    {
        $value = preg_replace('/[_\-.]+/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', trim($value));

        $stripped = preg_replace('/\s+\d{1,3}$/', '', $value);

        if ($stripped !== '' && str_word_count($stripped) > 0) {
            $value = $stripped;
        }

        return Str::headline($value) ?: 'Untitled';
    }

    // ---------------------------------------------------------------
    // UCS
    // ---------------------------------------------------------------

    /**
     * The CatID, if the filename follows the convention.
     *
     * Requires at least two underscore-separated segments and a first
     * segment that is all caps or CamelCase-after-caps — "BIKEBell",
     * "AMBWind", "DOORWood". Anything looser starts matching ordinary
     * filenames that happen to contain an underscore.
     */
    public static function ucsCategoryId(string $base): ?string
    {
        $segments = explode('_', $base);

        if (count($segments) < 2) {
            return null;
        }

        $first = trim($segments[0]);

        return preg_match('/^[A-Z]{2,}[A-Za-z0-9]*$/', $first) ? $first : null;
    }

    /**
     * Split "BIKEBell" into "BIKE" and "Bell".
     *
     * The lookahead is what makes the greedy match stop in the right place:
     * without it, [A-Z]+ swallows the B of "Bell" and the split lands one
     * character late.
     *
     * @return array{0:string, 1:?string}
     */
    public static function splitCatId(string $catId): array
    {
        if (preg_match('/^([A-Z0-9]+)(?=[A-Z][a-z])(.*)$/', $catId, $m)) {
            return [$m[1], $m[2] ?: null];
        }

        return [$catId, null];
    }

    // ---------------------------------------------------------------
    // Category
    // ---------------------------------------------------------------

    /**
     * Best guess at which of dbelo's own categories this belongs to.
     *
     * Two passes, in order of how much the signal is worth. A UCS CatID was
     * written by someone deliberately categorising the file, so it is tried
     * first and against the whole name. The title is a much weaker signal,
     * so it only counts on a strong match, and singulars are compared
     * because a category is called "Doors" and the file is called "Door".
     *
     * @return array{0:?int, 1:int}  category id and how sure, 0-100
     */
    protected static function proposeCategory(?string $ucs, string $title): array
    {
        $categories = self::categories();

        if ($categories === []) {
            return [null, 0];
        }

        if ($ucs) {
            [$main, $sub] = self::splitCatId($ucs);

            foreach ([$sub, $main] as $needle) {
                if (! $needle) {
                    continue;
                }

                $hit = self::bestMatch($needle, $categories, 72);

                if ($hit) {
                    return $hit;
                }
            }
        }

        foreach (preg_split('/\s+/', strtolower($title)) as $word) {
            if (strlen($word) < 4) {
                continue;
            }

            $hit = self::bestMatch($word, $categories, 85);

            if ($hit) {
                return $hit;
            }
        }

        return [null, 0];
    }

    /**
     * @param  array<int, array{id:int, name:string, slug:string}>  $categories
     * @return array{0:int, 1:int}|null
     */
    protected static function bestMatch(string $needle, array $categories, int $floor): ?array
    {
        $needle = Str::singular(strtolower($needle));
        $best = null;
        $bestScore = 0;

        foreach ($categories as $category) {
            foreach ([$category['name'], $category['slug']] as $candidate) {
                similar_text($needle, Str::singular(strtolower($candidate)), $percent);

                if ($percent > $bestScore) {
                    $bestScore = $percent;
                    $best = $category['id'];
                }
            }
        }

        return $bestScore >= $floor ? [$best, (int) round($bestScore)] : null;
    }

    /**
     * The categories, as plain arrays.
     *
     * PLAIN ARRAYS, not models, and that is the whole point of this method.
     *
     * An Eloquent collection put into the cache is serialised with every
     * model's internals — including protected properties, whose keys carry
     * NUL bytes. This project's cache store is a MySQL text column, and that
     * round trip does not survive them: what comes back unserialises into
     * __PHP_Incomplete_Class, and the next method call on it takes down the
     * request with "tried to call a method on an incomplete object".
     *
     * The bug only appears from the SECOND read onward, because whoever
     * writes the cache gets the real collection back from the closure. So it
     * looks like a problem with uploading several files rather than a
     * problem with caching.
     *
     * Project rule: never cache Eloquent models or collections. Cache the
     * three columns you actually need.
     *
     * @return array<int, array{id:int, name:string, slug:string}>
     */
    protected static function categories(): array
    {
        $cached = Cache::remember('filename.categories', now()->addMinute(),
            fn () => Category::query()
                ->get(['id', 'name', 'slug'])
                ->map(fn ($category) => [
                    'id' => (int) $category->id,
                    'name' => (string) $category->name,
                    'slug' => (string) $category->slug,
                ])
                ->all());

        // Belt and braces: a cache entry written by an older version of this
        // file would still be a serialised collection. Rather than crash on
        // it, fall through to a fresh read.
        return is_array($cached) ? $cached : [];
    }
}
