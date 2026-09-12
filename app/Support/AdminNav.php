<?php

namespace App\Support;

use App\Models\AbuseSignal;
use App\Models\Claim;
use App\Models\ErrorGroup;
use App\Models\NotFound;
use App\Models\Sound;
use App\Services\QueueHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * The admin navigation, defined once.
 *
 * Groups map to the icon rail; items map to the panel beside it. An item
 * with a null route renders as not-yet-built instead of a broken link, so
 * the whole map is visible while it is being filled in.
 */
class AdminNav
{
    public static function groups(): array
    {
        $counts = self::counts();

        return [
            'dashboard' => [
                'label' => 'Dashboard',
                'icon' => 'gauge-high',
                'section' => 'menu',
                // A single-item group renders as a plain link, not an
                // accordion: nothing to expand.
                'route' => 'admin.dashboard',
                'items' => [],
            ],

            'catalog' => [
                'section' => 'menu',
                'label' => 'Catalog',
                'icon' => 'waveform-lines',
                'items' => [
                    ['label' => 'Sounds', 'icon' => 'music', 'route' => 'admin.sounds'],
                    ['label' => 'Bulk upload', 'icon' => 'layer-plus', 'route' => 'admin.bulk-upload'],
                    ['label' => 'In review', 'icon' => 'clipboard-check', 'route' => 'moderate', ...self::badge($counts, 'review')],
                    ['label' => 'Categories', 'icon' => 'folder-tree', 'route' => 'admin.categories'],
                    ['label' => 'Tags', 'icon' => 'tags', 'route' => 'admin.tags'],
                    ['label' => 'Packs', 'icon' => 'box-open', 'route' => 'admin.packs'],
                    ['label' => 'Licenses', 'icon' => 'file-contract', 'route' => 'admin.licenses'],
                ],
            ],

            'billing' => [
                'section' => 'menu',
                'label' => 'Billing',
                'icon' => 'credit-card',
                'items' => [
                    ['label' => 'Plans', 'icon' => 'layer-group', 'route' => 'admin.plans'],
                    ['label' => 'Subscriptions', 'icon' => 'repeat', 'route' => 'admin.subscriptions'],
                    ['label' => 'Transactions', 'icon' => 'receipt', 'route' => 'admin.transactions'],
                    ['label' => 'Coupons', 'icon' => 'ticket', 'route' => 'admin.coupons'],

                    /*
                     * Last in the group on purpose: it is the screen you open
                     * when something is wrong or when setting up, not the one
                     * you open daily. Billing rather than Settings because
                     * everything on it — the webhook, plan sync, the event
                     * log — is an operation, not a preference.
                     */
                    ['label' => 'PayPal', 'icon' => 'plug', 'route' => 'admin.paypal'],
                    /*
                     * "Downloads" was here and has been dropped on purpose.
                     *
                     * The downloads table already feeds four things — the
                     * per-sound counter, the quota check in canDownload(),
                     * the dashboard chart and Analytics. A screen of its own
                     * would add only per-person forensics, which matters for
                     * a payment dispute and an abuse investigation, and
                     * neither of those exists yet. An empty menu item is a
                     * promise; this one was not being kept.
                     */
                ],
            ],

            'users' => [
                'section' => 'menu',
                'label' => 'Users',
                'icon' => 'users',
                'items' => [
                    ['label' => 'All users', 'icon' => 'user', 'route' => 'admin.users'],
                    ['label' => 'Contributors', 'icon' => 'user-music', 'route' => 'admin.contributors'],
                    ['label' => 'Claims', 'icon' => 'shield-exclamation', 'route' => 'admin.claims', ...self::badge($counts, 'claims')],
                ],
            ],

            'content' => [
                'section' => 'menu',
                'label' => 'Content',
                'icon' => 'file-lines',
                'items' => [
                    ['label' => 'Pages', 'icon' => 'file', 'route' => 'admin.pages'],
                    ['label' => 'Blog', 'icon' => 'newspaper', 'route' => 'admin.blog'],
                    // The blog's own taxonomy. Named plainly here because the
                    // Content group already says which side of the site they
                    // belong to; the catalogue keeps its own under Catalog.
                    ['label' => 'Categories', 'icon' => 'folder-tree', 'route' => 'admin.post-categories'],
                    ['label' => 'Tags', 'icon' => 'tags', 'route' => 'admin.post-tags'],
                ],
            ],

            'search' => [
                'section' => 'menu',
                'label' => 'Search',
                'icon' => 'magnifying-glass',
                // One screen, so a plain link rather than an accordion.
                //
                // Synonyms used to be a sibling entry; they are now the
                // action you take on a failed search, which is where the
                // decision actually gets made. Index health moved into the
                // same screen as a strip at the top — a report on search is
                // worthless if it does not say whether the engine answers.
                'route' => 'admin.search',
                'items' => [],
            ],

            'communications' => [
                'section' => 'menu',
                'label' => 'Communications',
                'icon' => 'paper-plane',
                'items' => [
                    ['label' => 'Subscribers', 'icon' => 'envelope-open-text', 'route' => 'admin.subscribers'],
                    ['label' => 'Campaigns', 'icon' => 'paper-plane', 'route' => 'admin.campaigns'],
                    // "Email log" was here. Dropped: Resend already keeps a
                    // better one, with the delivery status we cannot see from
                    // our own server. What we send a given person belongs on
                    // that person's page, which is where the question is asked.
                ],
            ],

            'security' => [
                'section' => 'menu',
                'label' => 'Security',
                'icon' => 'shield-halved',
                'items' => [
                    ['label' => 'Access log', 'icon' => 'right-to-bracket', 'route' => 'admin.security.access'],
                    ['label' => 'Blocks & abuse', 'icon' => 'ban', 'route' => 'admin.security.abuse', ...self::badge($counts, 'abuse')],
                ],
            ],

            'tools' => [
                'section' => 'menu',
                'label' => 'Tools',
                'icon' => 'wrench',
                'items' => [
                    ['label' => 'Analytics', 'icon' => 'chart-line', 'route' => 'admin.analytics'],
                    ['label' => 'SEO', 'icon' => 'magnifying-glass-chart', 'route' => 'admin.seo'],
                    // Moved out of Content: a redirect is not something you
                    // write, it is a repair to a URL that already existed.
                    ['label' => 'Redirects', 'icon' => 'arrow-turn-right', 'route' => 'admin.redirects', ...self::badge($counts, 'broken')],
                    ['label' => 'Queue', 'icon' => 'list-check', 'route' => 'admin.queue', ...self::badge($counts, 'queue')],
                    ['label' => 'Storage', 'icon' => 'hard-drive', 'route' => 'admin.storage'],
                    ['label' => 'Backups', 'icon' => 'box-archive', 'route' => 'admin.backups'],
                    ['label' => 'Activity log', 'icon' => 'clock-rotate-left', 'route' => 'admin.activity'],
                    ['label' => 'Error logs', 'icon' => 'triangle-exclamation', 'route' => 'admin.errors', ...self::badge($counts, 'errors')],
                    ['label' => 'Diagnostics', 'icon' => 'stethoscope', 'route' => 'admin.diagnostics'],
                ],
            ],

            'settings' => [
                'section' => 'other',
                'label' => 'Settings',
                'icon' => 'gear',
                'items' => [
                    ['label' => 'General', 'icon' => 'sliders', 'route' => 'admin.settings.general'],
                    ['label' => 'Homepage', 'icon' => 'house', 'route' => 'admin.settings.homepage'],
                    ['label' => 'Security', 'icon' => 'lock', 'route' => 'admin.settings.security'],
                    ['label' => 'Email & SMTP', 'icon' => 'envelope', 'route' => 'admin.settings.email'],
                    ['label' => 'Appearance', 'icon' => 'palette', 'route' => 'admin.settings.appearance'],
                    ['label' => 'Ads', 'icon' => 'rectangle-ad', 'route' => 'admin.settings.ads'],
                    // Its own entry rather than a section inside Ads: both
                    // store third-party JavaScript, but switching the
                    // advertising off must not switch off the analytics.
                    ['label' => 'Code & tracking', 'icon' => 'code', 'route' => 'admin.settings.code'],
                    ['label' => 'Downloads', 'icon' => 'download', 'route' => 'admin.settings.downloads'],
                ],
            ],

            'help' => [
                'section' => 'other',
                'label' => 'Help',
                'icon' => 'circle-question',
                'route' => null,
                'items' => [],
            ],
        ];
    }

    /**
     * Badge numbers. Cached for a minute: the sidebar renders on every
     * admin page and these are three aggregate queries.
     *
     * PUBLIC because App\Support\Notices reads it too. The alternative was
     * Notices running its own copies of these queries, which is how a bell
     * ends up saying "1 claim" while the sidebar says 3 — and after that
     * neither number is believed. One definition, two surfaces.
     */
    /**
     * Every reachable admin screen, flat, for the command palette.
     *
     * Derived from the same map the sidebar draws, so a screen added to the
     * menu is findable the same day without anybody remembering to register
     * it in a second list. Entries with a null route are the not-yet-built
     * ones and are left out: a palette result that goes nowhere is worse
     * than no result.
     *
     * NO DATABASE, NO CACHE. It is route() calls over a literal array, so
     * the palette can ship it into the page and filter it in the browser —
     * which is why typing "ads" answers before you finish the word.
     *
     * @return array<int, array{label: string, group: string, icon: string, url: string}>
     */
    public static function searchable(): array
    {
        $out = [];

        foreach (self::groups() as $group) {
            $groupLabel = $group['label'];

            if (empty($group['items'])) {
                if ($group['route'] ?? null) {
                    $out[] = [
                        'label' => $groupLabel,
                        'group' => 'Panel',
                        'icon' => $group['icon'],
                        'url' => route($group['route']),
                    ];
                }

                continue;
            }

            foreach ($group['items'] as $item) {
                if (! ($item['route'] ?? null)) {
                    continue;
                }

                $out[] = [
                    'label' => $item['label'],
                    'group' => $groupLabel,
                    'icon' => $item['icon'],
                    'url' => route($item['route']),
                ];
            }
        }

        return $out;
    }

    /**
     * A badge: how many, and what it means.
     *
     * Written as one call rather than two array keys so that a new counter
     * cannot arrive with a number and no level — which would render as a
     * grey pill that looks disabled.
     */
    protected static function badge(array $counts, string $key): array
    {
        return [
            'count' => $counts[$key] ?? 0,
            'level' => Notices::levelOf($key),
        ];
    }

    public static function counts(): array
    {
        return Cache::remember('admin.nav.counts', now()->addMinute(), fn () => [
            'review' => Sound::where('status', 'pending')->count(),
            'failed' => Sound::whereNotNull('processing_error')->count(),
            // NOT the pending-job count, which is what this used to be.
            // A queue with jobs in it is a queue doing its job, so that badge
            // was normally non-zero — and a badge that is normally non-zero
            // stops being read, at which point it cannot warn about anything.
            // QueueHealth::attention() counts only what needs a person: dead
            // jobs, broken sounds, and everything stuck behind a worker that
            // has stopped consuming.
            'queue' => Schema::hasTable('jobs') ? app(QueueHealth::class)->attention() : 0,
            // Guarded: the sidebar renders on every admin page, and a missing
            // table here would take the whole panel down before the
            // migration has been run.
            'claims' => Schema::hasTable('claims') ? Claim::open()->count() : 0,
            // Broken URLs nobody has decided about yet. Same guard, same
            // reason: a badge must never be able to take the panel down.
            'broken' => Schema::hasTable('not_founds') ? NotFound::open()->count() : 0,
            // Open error groups SEEN IN THE LAST WEEK. Not every open group:
            // one last seen in March is a decision nobody has got round to,
            // not a fire, and a badge that is permanently lit stops being
            // read — which is how the real one gets missed.
            // Open abuse signals. Deliberately NOT the count of blocked
            // addresses: a block is a decision already taken, and a badge
            // that counts decisions rather than questions never reaches zero.
            'abuse' => Schema::hasTable('abuse_signals') ? AbuseSignal::open()->count() : 0,
            'errors' => Schema::hasTable('error_groups')
                ? ErrorGroup::where('status', 'open')->where('last_seen_at', '>=', now()->subWeek())->count()
                : 0,
        ]);
    }

    /**
     * Is the current page this menu entry?
     *
     * The trailing wildcard matters: a detail page is named after its list
     * (admin.users → admin.users.show), and without it the sidebar goes
     * dark the moment you open a single record — which reads as "you left
     * the section" when you did not.
     */
    public static function matches(?string $route): bool
    {
        return $route !== null && request()->routeIs($route, $route.'.*');
    }

    /**
     * Which rail icon should be lit when the page loads.
     */
    public static function activeGroup(): string
    {
        foreach (self::groups() as $key => $group) {
            if (self::matches($group['route'] ?? null)) {
                return $key;
            }

            foreach ($group['items'] as $item) {
                if (self::matches($item['route'])) {
                    return $key;
                }
            }
        }

        return 'dashboard';
    }

    /**
     * Groups filtered by the block they belong to: the main list, or the
     * short one pinned at the bottom.
     */
    public static function section(string $section): array
    {
        return array_filter(
            self::groups(),
            fn ($group) => ($group['section'] ?? 'menu') === $section
        );
    }
}
