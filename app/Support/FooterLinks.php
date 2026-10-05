<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Setting;
use App\Models\Sound;
use Illuminate\Support\Facades\Cache;

/**
 * What the footer links to, decided here rather than in the layout.
 *
 * ── WHY THIS IS A CLASS ──────────────────────────────────────────────────
 *
 * The footer renders on every page of the site, which makes every query in
 * it a query on every page. It also needs four or five conditionals — is
 * there a blog yet, is anything listed in the directory — and the layout is
 * a Blade file that already carries a warning about what happens when a
 * @ php block meets an inline one. The same reasoning that moved
 * Post::footerPages() out of the template applies to the rest of it.
 *
 * ── WHY COLUMNS AND NOT ONE ROW ──────────────────────────────────────────
 *
 * The old footer was a single line of twelve links: the four nav items, a
 * separator, five legal pages, and every page the operator had published
 * appended after them. It was already at the length where people stop
 * reading, and the only thing that could happen to it was another page.
 *
 * Grouping is not decoration. Nobody looks for "terms" and "licences" in
 * the same moment as they look for the converter, and a list that mixes the
 * two teaches the eye to skip the whole block.
 *
 * ── WHY THE CATEGORIES AND THE CONVERTER PAIRS ARE IN THERE ──────────────
 *
 * Because the footer is on every page, it is the only place from which
 * every page links to the category hubs — and the converter pair pages,
 * which were written to bring strangers in, had almost nothing pointing at
 * them from inside the site.
 */
class FooterLinks
{
    /** Enough to be useful, few enough that the column is still a column. */
    private const CATEGORIES = 6;

    /**
     * Converter pairs worth a permanent link.
     *
     * Named rather than "the first three", so the choice is a decision
     * somebody made and can argue with, not an accident of array order.
     * These are the three conversions people actually search for.
     */
    private const PAIRS = ['wav-to-mp3', 'm4a-to-mp3', 'mp3-to-wav'];

    /**
     * A link carries `navigate` when wire:navigate must NOT be put on it.
     *
     * wire:navigate fetches the target with JavaScript and swaps the body
     * of the current document with it. That is right for every page of the
     * site and wrong for the one entry here that is not a page: the RSS
     * feed answers with XML, and asking Livewire to render XML as a page
     * gives a blank screen rather than a feed.
     *
     * @return array<int, array{title: string, links: array<int, array{label: string, href: string, navigate?: bool}>}>
     */
    public static function columns(): array
    {
        return array_values(array_filter([
            self::catalogue(),
            self::tools(),
            self::resources(),
            self::legal(),
        ], fn ($column) => $column['links'] !== []));
    }

    private static function catalogue(): array
    {
        $links = [
            ['label' => 'All sounds', 'href' => route('sounds.index')],
        ];

        // Same rule as the nav bar: a link to an empty page is worse than no
        // link, so both of these are drawn only once there is something
        // behind them.
        if (self::hasPacks()) {
            $links[] = ['label' => 'Packs', 'href' => route('packs.index')];
        }

        if (self::hasListedCollections()) {
            $links[] = ['label' => 'Collections', 'href' => route('collections.index')];
        }

        // Music, above the categories: it is a half of the catalogue, not a
        // slice of one. The categories below all link into /sounds.
        if (self::hasMusic()) {
            $links[] = ['label' => 'Music', 'href' => route('music.index')];
        }

        foreach (self::categories() as $category) {
            $links[] = [
                'label' => $category['name'],
                'href' => route('sounds.index', ['category' => $category['slug']]),
            ];
        }

        return ['title' => 'Catalogue', 'links' => $links];
    }

    private static function tools(): array
    {
        $links = [
            ['label' => 'Audio converter', 'href' => route('converter')],
        ];

        foreach (self::PAIRS as $pair) {
            if (! ConverterPairs::exists($pair)) {
                continue;
            }

            $data = ConverterPairs::find($pair);

            $links[] = [
                'label' => strtoupper($data['from']).' to '.strtoupper($data['to']),
                'href' => route('converter.pair', $pair),
            ];
        }

        return ['title' => 'Tools', 'links' => $links];
    }

    private static function resources(): array
    {
        $links = [];

        if (Post::blogHasPosts()) {
            $links[] = ['label' => 'Blog', 'href' => route('blog')];
            $links[] = ['label' => 'RSS feed', 'href' => route('blog.feed'), 'navigate' => false];
        }

        /*
         * The pages the operator TICKED for the footer, in the order chosen
         * there, capped at Post::FOOTER_MAX.
         *
         * They used to be appended to the end of the legal row, which is why
         * an "About" page and a privacy policy read as the same kind of
         * thing. This is where they belong: things somebody might want to
         * read, as opposed to things somebody has to agree to.
         *
         * The ticking and the cap are both Post::footerPages()' business,
         * not this class's. One query decides which pages are in the footer,
         * and the admin screens read that same answer back — a second rule
         * here would be a second answer to "is my page in the footer", and
         * the two would disagree the first time either changed.
         */
        foreach (Post::footerPages() as $page) {
            $links[] = [
                'label' => $page['title'],
                'href' => route('pages.show', $page['slug']),
            ];
        }

        return ['title' => 'Resources', 'links' => $links];
    }

    private static function legal(): array
    {
        return [
            'title' => 'Legal',
            'links' => [
                ['label' => 'Licences', 'href' => route('legal.licenses')],
                ['label' => 'Terms of use', 'href' => route('legal.terms')],
                ['label' => 'Privacy', 'href' => route('legal.privacy')],
                ['label' => 'Contributor agreement', 'href' => route('legal.contributor')],
                ['label' => 'Copyright complaint', 'href' => route('claims.create')],
            ],
        ];
    }

    /* ═══════════════════════ The copyright line ═══════════════════════ */

    /**
     * The sentence in the bottom bar, with {year} and {site} filled in.
     *
     * ── WHY IT IS A TEMPLATE AND NOT A SENTENCE ──────────────────────────
     *
     * The old line was written in the layout as "© {{ date('Y') }} ... —
     * sound effects library", which is fine until somebody wants to change
     * the wording and finds it in a Blade file. Making it a setting is easy;
     * making it a setting WITHOUT a year token is the trap — whoever types
     * "© 2026 dbelo" into the admin panel has just frozen the year, and
     * nobody notices until January.
     *
     * So the stored value keeps the tokens, and they are replaced here. The
     * default lives in config/dbelo.php like every other setting, which is
     * what makes an empty box mean "use the default" rather than "print
     * nothing".
     */
    public static function copyright(): string
    {
        return self::fillTokens((string) Setting::read('site.copyright', ''));
    }

    /**
     * The token substitution on its own, so the admin panel can preview a
     * line that has not been saved yet.
     *
     * Public for exactly that reason: the settings screen shows "reads as…"
     * under the field while it is being typed, and a preview that ran its
     * own copy of this would eventually show something the footer does not.
     */
    public static function fillTokens(string $line): string
    {
        $line = trim($line);

        if ($line === '') {
            return '';
        }

        return strtr($line, [
            '{year}' => date('Y'),
            '{site}' => (string) config('app.name', 'dbelo'),
        ]);
    }

    /* ═══════════════════════ The cached parts ═══════════════════════ */

    /*
     * Slugs and names are cached, never the URLs built from them.
     *
     * A cached absolute URL is a cached HOST, and the day the site answers
     * on a second address — a staging domain, a preview, dbelo.com after
     * dbelo.test — the footer would quietly link somewhere else. Cheap to
     * rebuild, expensive to debug.
     */

    /**
     * Drop all three.
     *
     * Called from Collection and Category when one is saved or deleted, the
     * same way Post already drops footer.pages. An hour is a fine lifetime
     * for a footer; an hour between publishing a pack and seeing the link
     * appear is not, because the person who publishes it is the person who
     * goes looking for it a second later.
     */
    public static function flush(): void
    {
        Cache::forget('footer.categories');
        Cache::forget('footer.has_packs');
        Cache::forget('footer.has_collections');
        Cache::forget('footer.has_music');
    }

    /**
     * @return array<int, array{slug: string, name: string}>
     */
    private static function categories(): array
    {
        return Cache::remember('footer.categories', now()->addHour(), fn () => Category::query()
            ->whereNull('parent_id')
            // Grouped, not chained: without the closure the OR would escape
            // the parent_id check and subcategories would appear here too.
            // Same shape the sitemap uses, and for the same reason — a top
            // category with nothing of its own but four full children is a
            // full page.
            // ->sfx() for the same reason the sitemap now has it: /sounds
            // shows sound effects only, so a category holding nothing but
            // music is a footer link to a page that says "No sounds found".
            ->where(fn ($q) => $q
                ->whereHas('sounds', fn ($s) => $s->published()->sfx())
                ->orWhereHas('children.sounds', fn ($s) => $s->published()->sfx()))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(self::CATEGORIES)
            ->get(['slug', 'name'])
            ->map(fn ($category) => ['slug' => $category->slug, 'name' => $category->name])
            ->all());
    }

    /*
     * These two are public because the NAV BAR asks the same questions.
     *
     * It was running Collection::featured()->exists() and
     * Collection::listed()->exists() inline, on every page, uncached — and
     * the footer was about to ask the same two things on the same page. Two
     * answers to one question is how a site ends up with Packs in the header
     * and no Packs in the footer.
     */
    public static function hasPacks(): bool
    {
        return Cache::remember('footer.has_packs', now()->addHour(),
            fn () => Collection::featured()->exists());
    }

    public static function hasListedCollections(): bool
    {
        return Cache::remember('footer.has_collections', now()->addHour(),
            fn () => Collection::listed()->exists());
    }

    /**
     * Is there any published music at all?
     *
     * Same rule as Packs and Collections: a nav entry leading to an empty
     * page is worse than no entry. dbelo is a sound-effects library that
     * happens to carry some music, so /music earns its link by having
     * something on it, not by existing.
     *
     * ── THE HOUR IS THE REAL MECHANISM, NOT flush() ──────────────────────
     *
     * flush() is called when a collection is saved, which is not what
     * changes this answer — publishing a sound is. Nothing hooks that, so
     * the first track ever published takes up to an hour to put Music in
     * the nav.
     *
     * Left that way on purpose. It happens exactly once in the life of the
     * site, and the alternative is a cache-busting hook on every sound save
     * — a live query on every page view's worth of complexity to make one
     * event faster. The key is listed in flush() anyway so that clearing
     * the caches by hand does the obvious thing.
     */
    public static function hasMusic(): bool
    {
        return Cache::remember('footer.has_music', now()->addHour(),
            fn () => Sound::published()->music()->exists());
    }
}
