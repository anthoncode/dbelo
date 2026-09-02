<?php

namespace App\Support;

use App\Support\Concerns\SettingFields;

/**
 * What the landing page says, and what it shows.
 *
 * ONE MAP, TWO SURFACES. The settings screen renders its fields from it and
 * ⚡home reads its values through it, so a field cannot exist without a
 * reader and a reader cannot drift from its field. That is not a stylistic
 * preference: the first version of Settings → General shipped three
 * controls that saved perfectly and changed nothing on the site, because the
 * screen and the page were written separately and only one of them was
 * finished. This shape makes that particular mistake unrepresentable.
 *
 * It is also why the keys appear here as literals. Diagnostics scans the
 * source for them; a key assembled at runtime out of "home." and a variable
 * would be invisible to it, and the check would go quiet exactly where it
 * is most needed.
 *
 * NO OVERLAY ENTRY IS NEEDED for any of these. AppServiceProvider::OVERLAY
 * exists so that code calling config() sees a stored value without knowing
 * settings exist; everything here goes through Setting::read(), which
 * consults the table first and falls back to config/dbelo.php on its own.
 *
 * Deliberately NOT here: which packs are featured (that is a flag on the
 * pack, decided on the pack's own page), and anything about colour, type or
 * spacing, which belongs to Appearance.
 */
class Homepage
{
    use SettingFields;

    /**
     * @var array<string, array{key: string, type: string, label: string, help: string}>
     */
    public const FIELDS = [

        /* ═══════════════════════ The notification bar ═══════════════════════ */

        'bar_on' => [
            'key' => 'home.bar.enabled',
            'type' => 'bool',
            'label' => 'Show the bar',
            'help' => 'It also stays hidden while the message is empty, so there are two ways to switch it off and neither can surprise you.',
        ],
        'bar_message' => [
            'key' => 'home.bar.message',
            'type' => 'text',
            'label' => 'Message',
            'help' => 'Short. It has to survive being read at a glance, on a phone, by somebody who came for something else.',
        ],
        'bar_button' => [
            'key' => 'home.bar.button',
            'type' => 'text',
            'label' => 'Button text',
            'help' => 'Optional. Without it the whole bar is just the sentence.',
        ],
        'bar_link' => [
            'key' => 'home.bar.link',
            'type' => 'url',
            'label' => 'Button link',
            'help' => 'A path like /packs, or a full https:// address. An external one opens in a new tab on its own — nobody should lose your site by clicking your own banner.',
        ],
        'bar_tone' => [
            'key' => 'home.bar.tone',
            'type' => 'select',
            'label' => 'Colour',
            'help' => 'Red is missing on purpose: everywhere else on this site red means something failed, and spending it on a discount is how a real warning stops being believed.',
            'options' => [
                'brand' => 'Brand — purple',
                'action' => 'Action — orange',
                'info' => 'Info — blue',
                'success' => 'Success — green',
                'warning' => 'Warning — amber',
            ],
        ],
        'bar_scope' => [
            'key' => 'home.bar.scope',
            'type' => 'select',
            'label' => 'Where it shows',
            'help' => 'The landing page by default, which is where an announcement is read rather than stepped over. Widen it to every page when the message is something a visitor needs mid-visit — a maintenance window, a price change — not for a promotion.',
            'options' => [
                'home' => 'The landing page only',
                'all' => 'Every page',
            ],
        ],
        'bar_audience' => [
            'key' => 'home.bar.audience',
            'type' => 'select',
            'label' => 'Who sees it',
            'help' => 'An offer on a first subscription, shown to somebody who already pays, is noise from the one person you most want to keep.',
            'options' => [
                'everyone' => 'Everyone',
                'guests' => 'Signed-out visitors only',
                'members' => 'Signed-in users only',
            ],
        ],
        'bar_dismissible' => [
            'key' => 'home.bar.dismissible',
            'type' => 'bool',
            'label' => 'Can be closed',
            'help' => 'Closing is remembered per message, not per visitor: change the wording and it comes back for everybody, so an announcement is never buried by a promotion somebody dismissed in March.',
        ],
        'bar_until' => [
            'key' => 'home.bar.until',
            'type' => 'date',
            'label' => 'Hide after',
            'help' => 'Optional, and the most useful field here. The classic failure of a promo bar is not that it never appeared — it is that it was still advertising a Black Friday sale in March.',
        ],

        /* ───────────────────────────── Hero ───────────────────────────── */

        'hero_badge' => [
            'key' => 'home.hero.badge',
            'type' => 'bool',
            'label' => 'Show the counter',
            'help' => 'The pill above the headline counting published sounds.',
        ],
        'hero_title' => [
            'key' => 'home.hero.title',
            'type' => 'rich',
            'label' => 'Headline',
            'help' => 'The first line of the site. A line break here is a line break there.',
        ],
        'hero_subtitle' => [
            'key' => 'home.hero.subtitle',
            'type' => 'rich',
            'label' => 'Sub-headline',
            'help' => 'One sentence under the headline.',
        ],
        'hero_placeholder' => [
            'key' => 'home.hero.placeholder',
            'type' => 'text',
            'label' => 'Search box hint',
            'help' => 'The grey text inside the search box. It is the only place that teaches a first-time visitor what kind of thing to type.',
        ],

        /* ──────────────────────────── Newest ──────────────────────────── */

        'newest_on' => [
            'key' => 'home.newest.enabled',
            'type' => 'bool',
            'label' => 'Show this section',
            'help' => 'Hidden anyway while nothing is published.',
        ],
        'newest_limit' => [
            'key' => 'home.newest.limit',
            'type' => 'number',
            'label' => 'How many sounds',
            'help' => 'Rows in the player list. Each one is a full track, so more is slower.',
        ],
        'newest_eyebrow' => [
            'key' => 'home.newest.eyebrow',
            'type' => 'text',
            'label' => 'Eyebrow',
            'help' => 'The small word above the heading.',
        ],
        'newest_title' => [
            'key' => 'home.newest.title',
            'type' => 'rich',
            'label' => 'Heading',
            'help' => '',
        ],
        'newest_lead' => [
            'key' => 'home.newest.lead',
            'type' => 'text',
            'label' => 'Lead paragraph',
            'help' => '',
        ],

        /* ────────────────────────── Categories ────────────────────────── */

        'categories_on' => [
            'key' => 'home.categories.enabled',
            'type' => 'bool',
            'label' => 'Show this section',
            'help' => '',
        ],
        'categories_limit' => [
            'key' => 'home.categories.limit',
            'type' => 'number',
            'label' => 'How many categories',
            'help' => 'The grid is three across, so multiples of three fill the last row.',
        ],
        'categories_eyebrow' => [
            'key' => 'home.categories.eyebrow',
            'type' => 'text',
            'label' => 'Eyebrow',
            'help' => '',
        ],
        'categories_title' => [
            'key' => 'home.categories.title',
            'type' => 'rich',
            'label' => 'Heading',
            'help' => '',
        ],
        'categories_lead' => [
            'key' => 'home.categories.lead',
            'type' => 'text',
            'label' => 'Lead paragraph',
            'help' => '',
        ],

        /* ──────────────────────────── Packs ───────────────────────────── */

        'packs_on' => [
            'key' => 'home.packs.enabled',
            'type' => 'bool',
            'label' => 'Show this section',
            'help' => 'While it is on and there are no packs yet, only an admin sees it — a section you cannot look at is a section that never gets finished.',
        ],
        'packs_limit' => [
            'key' => 'home.packs.limit',
            'type' => 'number',
            'label' => 'How many packs',
            'help' => 'Beside the tall "All in one" card, which is always there.',
        ],
        'packs_eyebrow' => [
            'key' => 'home.packs.eyebrow',
            'type' => 'text',
            'label' => 'Eyebrow',
            'help' => '',
        ],
        'packs_title' => [
            'key' => 'home.packs.title',
            'type' => 'rich',
            'label' => 'Heading',
            'help' => '',
        ],
        'packs_lead' => [
            'key' => 'home.packs.lead',
            'type' => 'text',
            'label' => 'Lead paragraph',
            'help' => '',
        ],

        /* ──────────────────────────── Plans ───────────────────────────── */

        'plans_on' => [
            'key' => 'home.plans.enabled',
            'type' => 'bool',
            'label' => 'Show this section',
            'help' => 'The prices themselves are edited under Billing → Plans.',
        ],
        'plans_eyebrow' => [
            'key' => 'home.plans.eyebrow',
            'type' => 'text',
            'label' => 'Eyebrow',
            'help' => '',
        ],
        'plans_title' => [
            'key' => 'home.plans.title',
            'type' => 'rich',
            'label' => 'Heading',
            'help' => '',
        ],

        /* ───────────────────────── The last word ──────────────────────── */

        'cta_on' => [
            'key' => 'home.cta.enabled',
            'type' => 'bool',
            'label' => 'Show this section',
            'help' => '',
        ],
        'cta_title' => [
            'key' => 'home.cta.title',
            'type' => 'rich',
            'label' => 'Heading',
            'help' => '',
        ],
        'cta_lead' => [
            'key' => 'home.cta.lead',
            'type' => 'text',
            'label' => 'Paragraph',
            'help' => '',
        ],
    ];

    /**
     * The screen is laid out in the order the page is read.
     *
     * Not alphabetically and not by field type. Somebody editing the
     * homepage is looking at the homepage, and a settings screen that runs
     * in a different order than the thing it edits makes them translate
     * between the two on every change.
     *
     * @var array<string, array{label: string, note: string, fields: array<int, string>}>
     */
    public const SECTIONS = [
        'bar' => [
            'label' => 'Notification bar',
            'note' => 'The strip above the navigation. Empty message, no bar — so clearing the text is a complete way to turn it off, not a half-configured state.',
            // The switch comes FIRST. It was last, under nine other fields,
            // and the first person to open the screen had to ask how to turn
            // the bar on — which is the only answer that question ever needs.
            'fields' => ['bar_on', 'bar_message', 'bar_button', 'bar_link', 'bar_tone', 'bar_scope', 'bar_audience', 'bar_dismissible', 'bar_until'],
        ],
        'hero' => [
            'label' => 'Hero',
            'note' => 'The first screen, above everything else.',
            'fields' => ['hero_title', 'hero_subtitle', 'hero_placeholder', 'hero_badge'],
        ],
        'newest' => [
            'label' => 'Newest sounds',
            'note' => 'The player list. It is placed high on purpose: a sound library whose front page makes no sound is asking to be believed instead of demonstrating.',
            'fields' => ['newest_on', 'newest_limit', 'newest_eyebrow', 'newest_title', 'newest_lead'],
        ],
        'categories' => [
            'label' => 'Categories',
            'note' => 'Browsing by what the sound is.',
            'fields' => ['categories_on', 'categories_limit', 'categories_eyebrow', 'categories_title', 'categories_lead'],
        ],
        'packs' => [
            'label' => 'Packs',
            'note' => 'Browsing by what you are making. Reads as the answer to the categories heading, so the two are worth rewriting together.',
            'fields' => ['packs_on', 'packs_limit', 'packs_eyebrow', 'packs_title', 'packs_lead'],
        ],
        'plans' => [
            'label' => 'Plans',
            'note' => 'Renders from Billing → Plans; nothing here changes a price.',
            'fields' => ['plans_on', 'plans_eyebrow', 'plans_title'],
        ],
        'cta' => [
            'label' => 'The last word',
            'note' => 'The sign-up panel at the foot of the page. Signed-out visitors see it; you see it too, with a note saying so.',
            'fields' => ['cta_on', 'cta_title', 'cta_lead'],
        ],
    ];

    /* ═══════════════════════════ Reading ═══════════════════════════ */

    /*
     * raw() · text() · choice() · rich() · defaultValue() · hasSecret() all
     * come from the trait, shared with App\Support\Security. The two below
     * are overridden because their behaviour genuinely differs here — and
     * writing that difference down is the point. A trait method silently
     * doing the wrong thing for one of two users is worse than two copies.
     */

    /**
     * A toggle, defaulting to SHOWN when the stored value is unreadable.
     *
     * The opposite of the trait's default. A homepage section is part of the
     * page; if a value is ever corrupted, a section that appears is a bug you
     * can see, and a section that vanishes is a bug you cannot.
     */
    public static function flag(string $field): bool
    {
        return filter_var(self::raw($field), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /** Floored at one: a section showing zero items is a bug wearing a setting. */
    public static function number(string $field): int
    {
        return max(1, (int) self::raw($field));
    }

    /* ═════════════════════════ The notification bar ═════════════════════════ */

    /**
     * Not encrypted, unlike every other cookie this app sets.
     *
     * It is written by JavaScript the moment somebody clicks the close
     * button, and JavaScript cannot produce Laravel's encrypted format — an
     * encrypted-by-default cookie written from the browser is silently
     * discarded on the next request, so the bar would come straight back and
     * the close button would look broken. Exempted in bootstrap/app.php.
     *
     * It holds a hash of the message and nothing else: no identifier, no
     * account, nothing that says who the visitor is.
     */
    public const BAR_COOKIE = 'dbelo_bar';

    /**
     * Everything the bar needs, or null when there is no bar.
     *
     * All the deciding happens here rather than in the template. A banner
     * with five conditions spread across a Blade file is a banner nobody can
     * predict, and the question "why is it not showing?" then has five
     * possible answers in five places. explain() below answers it in one.
     *
     * @return array<string, mixed>|null
     */
    public static function bar(): ?array
    {
        if (self::explain() !== null) {
            return null;
        }

        $message = trim(self::text('bar_message'));
        $link = trim(self::text('bar_link'));
        $tone = self::choice('bar_tone');

        return [
            'message' => $message,
            'button' => trim(self::text('bar_button')),
            'link' => $link,
            // An external link opens in a new tab. Not a setting: there is no
            // case where sending a visitor off your own site, with no way
            // back, is what you meant by a promotional banner.
            'external' => $link !== '' && str_starts_with($link, 'http'),
            'dismissible' => self::flag('bar_dismissible'),
            'hash' => self::barHash($message),
            'tone' => $tone,
            'icon' => self::iconFor($tone),
        ];
    }

    /** Derived, not configured: one less box to fill in for no loss of choice. */
    public static function iconFor(string $tone): string
    {
        return match ($tone) {
            'success' => 'circle-check',
            'warning' => 'triangle-exclamation',
            'info' => 'circle-info',
            'action' => 'bolt',
            default => 'sparkles',
        };
    }

    /**
     * Why the bar is off for EVERYBODY, or null when it is live.
     *
     * Split from explain() because the two answer different questions, and
     * conflating them produced a false alarm that would have been maddening:
     * with the bar set to the landing page only, the settings screen —
     * which lives at /admin/settings/homepage, and is therefore never the
     * landing page — would have reported "not showing" permanently, while
     * every visitor saw it perfectly.
     *
     * These three conditions are the ones that hide it from everyone. The
     * rest are per-visitor and belong in explain().
     */
    public static function dormant(): ?string
    {
        if (! self::flag('bar_on')) {
            return 'The bar is switched off.';
        }

        if (trim(self::text('bar_message')) === '') {
            return 'There is no message, and an empty bar is not a bar.';
        }

        $until = trim(self::text('bar_until'));

        if ($until !== '') {
            $end = rescue(fn () => \Illuminate\Support\Carbon::parse($until)->endOfDay(), null, false);

            if ($end && $end->isPast()) {
                return 'The end date passed on '.$end->format('j F Y').'.';
            }
        }

        return null;
    }

    /**
     * Why THIS visitor, on THIS page, is not seeing it. Null when they are.
     *
     * What the component asks before rendering.
     */
    public static function explain(): ?string
    {
        if ($dormant = self::dormant()) {
            return $dormant;
        }

        if (self::choice('bar_scope') === 'home' && ! request()->routeIs('home')) {
            return 'It is set to the landing page only, and this is not the landing page.';
        }

        $audience = self::choice('bar_audience');

        if ($audience === 'guests' && auth()->check()) {
            return 'It is for signed-out visitors, and you are signed in.';
        }

        if ($audience === 'members' && auth()->guest()) {
            return 'It is for signed-in users, and this visitor is not.';
        }

        if (self::flag('bar_dismissible')
            && request()->cookie(self::BAR_COOKIE) === self::barHash(trim(self::text('bar_message')))) {
            return 'You closed this message. Changing the wording brings it back for everybody.';
        }

        return null;
    }

    /**
     * Where a live bar reaches, in words. For the settings screen.
     */
    public static function reach(): string
    {
        $where = self::choice('bar_scope') === 'home'
            ? 'on the landing page'
            : 'on every page';

        $who = match (self::choice('bar_audience')) {
            'guests' => 'to signed-out visitors',
            'members' => 'to signed-in users',
            default => 'to everyone',
        };

        $until = trim(self::text('bar_until'));
        $ends = '';

        if ($until !== '') {
            $end = rescue(fn () => \Illuminate\Support\Carbon::parse($until), null, false);
            $ends = $end ? ', until '.$end->format('j F Y') : '';
        }

        return ucfirst($where).', '.$who.$ends.'.';
    }

    /**
     * The identity of a message, for remembering that it was closed.
     *
     * Keyed on the TEXT, not on a bar id. A visitor who dismissed last
     * month's promotion has not dismissed next month's announcement, and
     * tying the memory to the wording is what makes that true without any
     * bookkeeping.
     */
    public static function barHash(string $message): string
    {
        return substr(sha1($message), 0, 12);
    }
}
