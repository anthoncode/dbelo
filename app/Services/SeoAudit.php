<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\Post;
use App\Models\Sound;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Which pages are wasting their chance to rank.
 *
 * That is the only question this asks, and it is deliberately not a
 * checklist of SEO features. Every finding here is computed from the
 * database — no crawler, no external service, no API key — because the
 * things that actually keep a catalogue out of search results are things
 * you can see from the inside:
 *
 *   thin pages, duplicate titles, orphans nothing links to, and URLs
 *   missing from the sitemap.
 *
 * What it does NOT do is offer a place to edit a meta title. That belongs
 * on the form where the sound is edited, next to the content it describes.
 * A central list of every meta field is a second editor for data that
 * already has one, and two editors for one field is how they start
 * disagreeing.
 */
class SeoAudit
{
    /** Google shows roughly this much of a title before cutting it. */
    private const TITLE_MAX = 60;

    private const DESCRIPTION_MIN = 70;

    private const DESCRIPTION_MAX = 160;

    /** A sound with less than this much description is a thin page. */
    private const THIN_DESCRIPTION = 80;

    /**
     * Everything worth fixing, worst first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findings(): array
    {
        $findings = array_filter([
            $this->duplicateTitles(),
            $this->duplicateDescriptions(),
            $this->thinDescriptions(),
            $this->longTitles(),
            $this->shortDescriptions(),
            $this->uncategorised(),
            $this->untagged(),
            $this->neverViewed(),
            $this->noindexedPages(),
        ]);

        // Worst first, then by how many pages it affects: a person works
        // down this list and stops when they run out of evening.
        usort($findings, function ($a, $b) {
            $order = ['high' => 0, 'medium' => 1, 'low' => 2];

            return [$order[$a['severity']], -$a['count']] <=> [$order[$b['severity']], -$b['count']];
        });

        return $findings;
    }

    /* ═══════════════════════════ The findings ═══════════════════════════ */

    /**
     * The same meta title on more than one page.
     *
     * The classic ranking killer, and bulk import produces it by the
     * hundred: two pages claiming to be about the same thing means a search
     * engine picks one and drops the other.
     */
    private function duplicateTitles(): ?array
    {
        $rows = $this->duplicatesOf('meta_title');

        return $rows === 0 ? null : $this->finding(
            'duplicate-titles', 'high', $rows,
            'Sounds sharing a meta title with another sound',
            'Two pages claiming to be about the same thing means a search engine keeps one and discards the other. Bulk uploads produce these in batches, from filenames that differ only by a number.',
            'admin.sounds',
        );
    }

    private function duplicateDescriptions(): ?array
    {
        $rows = $this->duplicatesOf('meta_description');

        return $rows === 0 ? null : $this->finding(
            'duplicate-descriptions', 'medium', $rows,
            'Sounds sharing a meta description',
            'Less damaging than a duplicate title, and the same cause. The description is what appears under the link in the results — identical text across a hundred pages reads as a generated catalogue, which is what it is.',
            'admin.sounds',
        );
    }

    /** How many published sounds share a non-empty value in this column. */
    private function duplicatesOf(string $column): int
    {
        if (! Schema::hasTable('sounds')) {
            return 0;
        }

        try {
            return (int) DB::table('sounds')
                ->whereNull('deleted_at')
                ->where('status', 'published')
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->select($column)
                ->groupBy($column)
                ->havingRaw('COUNT(*) > 1')
                ->get()
                ->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Published, and with almost nothing on the page.
     *
     * A sound page is a title, a waveform and a description. Take the
     * description away and there is no text for a search engine to match a
     * query against — the page exists and cannot rank for anything.
     */
    private function thinDescriptions(): ?array
    {
        $count = $this->publishedSounds()
            ->where(fn ($q) => $q->whereNull('description')
                ->orWhereRaw('CHAR_LENGTH(description) < ?', [self::THIN_DESCRIPTION]))
            ->count();

        return $count === 0 ? null : $this->finding(
            'thin', 'high', $count,
            'Published sounds with little or no description',
            'The description is the only text on a sound page. Without it there is nothing for a search to match, so the page can exist for months and never rank for a single query.',
            'admin.sounds',
        );
    }

    private function longTitles(): ?array
    {
        $count = $this->publishedSounds()
            ->whereNotNull('meta_title')
            ->whereRaw('CHAR_LENGTH(meta_title) > ?', [self::TITLE_MAX])
            ->count();

        return $count === 0 ? null : $this->finding(
            'long-titles', 'low', $count,
            'Meta titles longer than '.self::TITLE_MAX.' characters',
            'Cut off in the results with an ellipsis. Not a ranking penalty — just the end of your sentence, which is usually where the useful words were.',
            'admin.sounds',
        );
    }

    private function shortDescriptions(): ?array
    {
        $count = $this->publishedSounds()
            ->whereNotNull('meta_description')
            ->where('meta_description', '!=', '')
            ->whereRaw('CHAR_LENGTH(meta_description) < ?', [self::DESCRIPTION_MIN])
            ->count();

        return $count === 0 ? null : $this->finding(
            'short-descriptions', 'low', $count,
            'Meta descriptions under '.self::DESCRIPTION_MIN.' characters',
            'Between '.self::DESCRIPTION_MIN.' and '.self::DESCRIPTION_MAX.' is the space you are given under the link. Leaving it half empty wastes the only sentence you get to write about the page.',
            'admin.sounds',
        );
    }

    /**
     * Nothing links to them.
     *
     * A sound with no category and no tags is reachable only from the full
     * listing and the sitemap. Crawlers follow links, and a page with no
     * route in is a page that gets visited late and treated as unimportant.
     */
    private function uncategorised(): ?array
    {
        $count = $this->publishedSounds()->whereNull('category_id')->count();

        return $count === 0 ? null : $this->finding(
            'uncategorised', 'medium', $count,
            'Published sounds with no category',
            'Nothing internal links to them except the full listing. Crawlers follow links, and a page with no route in is reached late and read as unimportant.',
            'admin.sounds',
        );
    }

    private function untagged(): ?array
    {
        if (! Schema::hasTable('sound_tag')) {
            return null;
        }

        $count = $this->publishedSounds()
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('sound_tag')
                ->whereColumn('sound_tag.sound_id', 'sounds.id'))
            ->count();

        return $count === 0 ? null : $this->finding(
            'untagged', 'medium', $count,
            'Published sounds with no tags',
            'Tags are both the internal link graph and most of the vocabulary a visitor actually searches for. An untagged sound is missing from every tag page it should be on.',
            'admin.tags',
        );
    }

    /**
     * Published a while ago and never looked at.
     *
     * The most direct answer to "is any of this working". Thirty days is
     * long enough that a page has had its chance and short enough to still
     * be worth acting on.
     */
    private function neverViewed(): ?array
    {
        if (! Schema::hasColumn('sounds', 'views_count')) {
            return null;
        }

        $count = $this->publishedSounds()
            ->where('views_count', 0)
            ->where('published_at', '<', now()->subDays(30))
            ->count();

        return $count === 0 ? null : $this->finding(
            'unseen', 'medium', $count,
            'Published over a month ago and never viewed',
            'Nobody has opened these pages — not from search, not from the catalogue, not from a link. Usually the same sounds that appear in the thin and untagged counts above; fixing those is what fixes this.',
            'admin.sounds',
        );
    }

    /** A published page nobody can find, usually by accident. */
    private function noindexedPages(): ?array
    {
        if (! Schema::hasTable('posts') || ! Schema::hasColumn('posts', 'noindex')) {
            return null;
        }

        $count = DB::table('posts')
            ->whereNull('deleted_at')
            ->where('status', 'published')
            ->where('noindex', true)
            ->count();

        return $count === 0 ? null : $this->finding(
            'noindex', 'medium', $count,
            'Published pages marked noindex',
            'Published and explicitly hidden from search at the same time. Sometimes deliberate — a thank-you page, a landing page for one campaign — and sometimes a switch left on from when it was a draft.',
            'admin.pages',
        );
    }

    /* ══════════════════════════ Sitemap & robots ══════════════════════════ */

    /**
     * What the sitemap will contain, counted the same way it builds it.
     *
     * @return array<string, mixed>
     */
    public function sitemap(): array
    {
        $counts = [
            'sounds' => Schema::hasTable('sounds') ? Sound::published()->count() : 0,
            'categories' => Schema::hasTable('categories') ? DB::table('categories')->count() : 0,
            'packs' => Schema::hasTable('collections') ? Collection::featured()->count() : 0,
            'pages' => Schema::hasTable('posts') ? Post::live()->count() : 0,
        ];

        return [
            'counts' => $counts,
            'total' => array_sum($counts) + 3,   // home, /sounds, /packs
            'url' => route('sitemap'),
        ];
    }

    /**
     * Is robots.txt telling crawlers the truth?
     *
     * The Sitemap line is an absolute URL written by hand. It is right in
     * production and quietly wrong everywhere else — and "quietly" is the
     * problem, because nothing fails, the file just points somewhere that is
     * not this site.
     *
     * @return array<string, mixed>
     */
    public function robots(): array
    {
        $path = public_path('robots.txt');

        if (! file_exists($path)) {
            return ['exists' => false, 'matches' => false, 'declared' => null, 'expected' => route('sitemap')];
        }

        $contents = (string) file_get_contents($path);
        $expected = route('sitemap');

        preg_match('/^\s*Sitemap:\s*(\S+)/mi', $contents, $m);
        $declared = $m[1] ?? null;

        return [
            'exists' => true,
            'contents' => $contents,
            'declared' => $declared,
            'expected' => $expected,
            'matches' => $declared !== null && rtrim($declared, '/') === rtrim($expected, '/'),
            'blocksEverything' => (bool) preg_match('/^\s*Disallow:\s*\/\s*$/mi', $contents),
        ];
    }

    /* ═══════════════════════ Internal broken links ═══════════════════════ */

    /**
     * Links inside your own pages that lead nowhere.
     *
     * Different from the 404s the Redirects screen collects: those are the
     * ones a VISITOR hit. These are the ones nobody has hit yet, sitting in
     * a blog post, waiting for a crawler to follow them and conclude the
     * site is poorly kept.
     *
     * Only internal links are checked. Testing external ones means making
     * HTTP requests to other people's servers from inside a page render,
     * which is slow, unreliable and rude.
     *
     * @return array<int, array<string, mixed>>
     */
    public function brokenLinks(int $limit = 40): array
    {
        if (! Schema::hasTable('posts')) {
            return [];
        }

        $broken = [];
        $checked = [];

        $posts = DB::table('posts')
            ->whereNull('deleted_at')
            ->where('status', 'published')
            ->select('id', 'title', 'slug', 'type', 'body')
            ->limit(200)
            ->get();

        foreach ($posts as $post) {
            preg_match_all('/href=["\']([^"\'#]+)["\']/i', (string) $post->body, $matches);

            foreach (array_unique($matches[1] ?? []) as $href) {
                if (count($broken) >= $limit) {
                    return $broken;
                }

                $path = $this->internalPath($href);

                if ($path === null) {
                    continue;
                }

                $key = $post->id.'|'.$path;

                if (isset($checked[$key])) {
                    continue;
                }

                $checked[$key] = true;

                if (! $this->resolves($path)) {
                    $broken[] = [
                        'post' => $post->title,
                        'type' => $post->type,
                        'id' => $post->id,
                        'href' => $href,
                    ];
                }
            }
        }

        return $broken;
    }

    /** The path part, or null when the link points off this site. */
    private function internalPath(string $href): ?string
    {
        if (Str::startsWith($href, ['mailto:', 'tel:', 'javascript:', 'data:'])) {
            return null;
        }

        if (Str::startsWith($href, ['http://', 'https://', '//'])) {
            $host = parse_url($href, PHP_URL_HOST);

            if (! $host || $host !== request()->getHost()) {
                return null;
            }

            return parse_url($href, PHP_URL_PATH) ?: '/';
        }

        return Str::start($href, '/');
    }

    /**
     * Does anything answer at this path?
     *
     * Matched against the route table rather than fetched: a real request
     * per link would be dozens of round trips through the whole middleware
     * stack, on a page that is supposed to load. Route matching misses the
     * case where the route exists and the model behind it does not — so a
     * bound parameter is looked up as well.
     */
    private function resolves(string $path): bool
    {
        try {
            $request = \Illuminate\Http\Request::create($path, 'GET');
            $route = Route::getRoutes()->match($request);

            foreach ($route->parameterNames() as $name) {
                $value = $route->parameter($name);

                if ($value === null) {
                    continue;
                }

                if (! $this->parameterExists($name, (string) $value)) {
                    return false;
                }
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function parameterExists(string $name, string $value): bool
    {
        $table = match ($name) {
            'sound' => 'sounds',
            'post', 'page' => 'posts',
            'pack', 'collection' => 'collections',
            'category' => 'categories',
            default => null,
        };

        if (! $table || ! Schema::hasTable($table)) {
            return true;   // not something we can check; assume the route is right
        }

        return DB::table($table)
            ->where(fn ($q) => $q->where('slug', $value)->orWhere('id', $value))
            ->exists();
    }

    /* ═════════════════════════════ Helpers ═════════════════════════════ */

    private function publishedSounds()
    {
        return DB::table('sounds')->whereNull('deleted_at')->where('status', 'published');
    }

    private function finding(string $key, string $severity, int $count, string $label, string $why, ?string $route = null): array
    {
        return compact('key', 'severity', 'count', 'label', 'why', 'route');
    }
}
