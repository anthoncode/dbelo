<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The legal pages, once they stopped being Blade files.
 *
 * ── WHY THE COMPANY DETAILS ARE TOKENS AND NOT TEXT ──────────────────────
 *
 * The Terms, the Privacy Policy and the Contributor Agreement each name the
 * operating company, and two of them also print its registered address. As
 * Blade files they read `{{ $legal['entity'] }}` and the value came from one
 * place. Moving the text into the database would have turned that into the
 * company name typed out in five spots across three documents — and the day
 * it changes, whoever changes it will find four of them.
 *
 * So the body keeps a token, `{entity}`, and it is replaced when the page is
 * drawn. Same idea as `{year}` in the footer copyright, and the same reason:
 * a value that can change does not belong inside prose.
 *
 * ── WHY THE REPLACEMENT HAPPENS AFTER THE HTML CACHE ─────────────────────
 *
 * Post::html() caches the rendered markdown for ever, keyed by post id, and
 * drops it when the post is saved. These values are not in the post: they
 * live in config and change with the .env. Filling them before the cache
 * would bake the old company name into a cache nothing invalidates.
 *
 * So the page calls Legal::fill() on the OUTPUT of html(). The cache still
 * saves the expensive part — parsing markdown — and the cheap part, six
 * string replacements, happens on every render where it can stay honest.
 */
final class Legal
{
    /**
     * Slugs the site cannot do without.
     *
     * These pages are linked from the footer, the sitemap, the shell at the
     * bottom of each legal page, and from inside the Terms themselves. They
     * are seeded by migration and marked is_system, which is what stops the
     * slug being edited and the row being deleted.
     *
     * `licenses` is deliberately NOT here. That page is a view over the
     * licenses table, rendered from the rows Admin → Licenses maintains —
     * it has no body to edit, and freezing it as text would let the page
     * drift away from the licence actually attached to each sound.
     */
    public const PAGES = ['terms', 'privacy', 'contributors'];

    /**
     * Route name → slug.
     *
     * The four legal URLs keep their route names so that every existing
     * route('legal.terms') in the footer, the sitemap and the shell goes on
     * working. The page component reads this to know which page it is
     * serving, because those routes carry no {page} parameter.
     */
    public const ROUTES = [
        'legal.terms' => 'terms',
        'legal.privacy' => 'privacy',
        'legal.contributor' => 'contributors',
    ];

    /**
     * token => config key under dbelo.legal.
     *
     * @var array<string, string>
     */
    private const TOKENS = [
        'entity' => 'entity',
        'jurisdiction' => 'jurisdiction',
        'address' => 'address',
        'email' => 'email',
        'privacy_email' => 'privacy_email',
        'support_email' => 'support_email',
    ];

    /** Fill the tokens in a rendered legal page. */
    public static function fill(string $html): string
    {
        $map = [];

        foreach (self::TOKENS as $token => $key) {
            $map['{'.$token.'}'] = (string) config('dbelo.legal.'.$key, '');
        }

        // Handy in a sentence like "© {year} {site}". Cheap to offer and
        // the alternative is somebody typing the year into a document that
        // is then wrong from January.
        $map['{year}'] = date('Y');
        $map['{site}'] = (string) config('app.name', 'dbelo');

        return strtr($html, $map);
    }

    /**
     * The tokens an editor may use, for the note under the body field.
     *
     * Returned with their current values, because a list of names tells
     * somebody what they may write and not what it will say.
     *
     * @return array<string, string>
     */
    public static function tokenHelp(): array
    {
        $out = [];

        foreach (array_keys(self::TOKENS) as $token) {
            $out['{'.$token.'}'] = self::fill('{'.$token.'}');
        }

        return $out;
    }

    /** Is this page one the site cannot lose? */
    public static function isSystemSlug(?string $slug): bool
    {
        return $slug !== null && in_array($slug, self::PAGES, true);
    }

    /**
     * The date printed under every legal heading.
     *
     * Read from config rather than from the page's own updated_at: a typo
     * fixed on a Tuesday is not a new version of an agreement, and moving
     * the effective date every time somebody touches a comma would make the
     * one date that has legal meaning meaningless.
     */
    public static function effectiveDate(): ?Carbon
    {
        $raw = config('dbelo.legal.effective_date');

        return $raw ? rescue(fn () => Carbon::parse($raw), null, false) : null;
    }
}
