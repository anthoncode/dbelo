<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * What every action in the activity log MEANS.
 *
 * An audit trail is only as useful as the discipline about what goes into
 * it. The rule this file encodes:
 *
 *     Log it when it changes something for somebody else, or when you would
 *     one day need it to answer "who did this, and why?".
 *
 * Everything else stays out. Page views, downloads and searches each have
 * their own table built for counting, and copying them here would bury the
 * twenty rows a year that actually matter under a million that do not — at
 * which point nobody opens the screen and the log has failed at its only
 * job.
 *
 * Two axes, deliberately separate:
 *
 *   category  what kind of business this is. Drives filtering AND how long
 *             the row is kept.
 *   severity  how alarming it is to see. Drives colour only.
 *
 * They are not the same question. Publishing a page and deleting a page are
 * both `content`; only one of them is worth a red dot.
 */
class ActivityCatalog
{
    /**
     * Categories, with how long their rows are kept.
     *
     * One retention number for the whole table is always wrong in both
     * directions at once: keep 90 days and the security trail is gone before
     * anyone asks for it; keep three years and the table fills with routine
     * edits nobody will ever read. Different questions have different
     * shelf lives.
     */
    public const CATEGORIES = [
        'security' => [
            'label' => 'Security',
            'icon' => 'shield-halved',
            // Three years. This is the trail somebody asks for long after
            // the fact, and always for an unpleasant reason.
            'keepDays' => 1095,
        ],
        'billing' => [
            'label' => 'Billing',
            'icon' => 'credit-card',
            // Same three years: money disputes and tax questions arrive late.
            'keepDays' => 1095,
        ],
        'config' => [
            'label' => 'Settings',
            'icon' => 'sliders',
            // "When did downloads start failing?" is answered by finding the
            // setting somebody changed months ago.
            'keepDays' => 1095,
        ],
        'moderation' => [
            'label' => 'Moderation',
            'icon' => 'clipboard-check',
            // Two years, which outlives the window in which a copyright
            // complaint about a decision can still turn up.
            'keepDays' => 730,
        ],
        'content' => [
            'label' => 'Content',
            'icon' => 'file-lines',
            'keepDays' => 180,
        ],
        'system' => [
            'label' => 'System',
            'icon' => 'gear',
            'keepDays' => 180,
        ],
    ];

    /**
     * Known actions.
     *
     * Names are `noun.verb`, past tense, most general first — so a prefix
     * match still works for anything added later without touching this file.
     *
     * @var array<string, array{label: string, icon: string, category: string, severity: string}>
     */
    public const ACTIONS = [
        // ─── Accounts, done by staff to somebody else ────────────────────
        'user.role.changed' => ['label' => 'Role changed', 'icon' => 'user-shield', 'category' => 'security', 'severity' => 'warning'],
        'user.suspended' => ['label' => 'Account suspended', 'icon' => 'user-slash', 'category' => 'security', 'severity' => 'danger'],
        'user.restored' => ['label' => 'Account restored', 'icon' => 'user-check', 'category' => 'security', 'severity' => 'success'],
        'user.verified.manually' => ['label' => 'Verified by hand', 'icon' => 'circle-check', 'category' => 'security', 'severity' => 'info'],
        'user.anonymised' => ['label' => 'Account anonymised', 'icon' => 'user-secret', 'category' => 'security', 'severity' => 'danger'],
        'user.impersonated' => ['label' => 'Impersonation started', 'icon' => 'user-secret', 'category' => 'security', 'severity' => 'danger'],
        'user.impersonation.stopped' => ['label' => 'Impersonation ended', 'icon' => 'arrow-right-from-bracket', 'category' => 'security', 'severity' => 'info'],
        'user.password.reset' => ['label' => 'Password reset by staff', 'icon' => 'key', 'category' => 'security', 'severity' => 'danger'],
        'user.password.changed' => ['label' => 'Password changed', 'icon' => 'key', 'category' => 'security', 'severity' => 'info'],
        'user.password.reset_by_owner' => ['label' => 'Password reset by its owner', 'icon' => 'key', 'category' => 'security', 'severity' => 'warning'],
        'user.sessions.revoked' => ['label' => 'Sessions ended', 'icon' => 'right-from-bracket', 'category' => 'security', 'severity' => 'warning'],
        'user.2fa.disabled' => ['label' => 'Two-factor disabled', 'icon' => 'shield-xmark', 'category' => 'security', 'severity' => 'danger'],

        // ─── Moderation ─────────────────────────────────────────────────
        'sound.approved' => ['label' => 'Sound approved', 'icon' => 'circle-check', 'category' => 'moderation', 'severity' => 'success'],
        'sound.rejected' => ['label' => 'Sound rejected', 'icon' => 'circle-xmark', 'category' => 'moderation', 'severity' => 'warning'],
        'sound.deleted' => ['label' => 'Sound deleted', 'icon' => 'trash', 'category' => 'moderation', 'severity' => 'danger'],
        'sound.unpublished' => ['label' => 'Sound unpublished', 'icon' => 'eye-slash', 'category' => 'moderation', 'severity' => 'warning'],
        'claim.reviewing' => ['label' => 'Claim under review', 'icon' => 'gavel', 'category' => 'moderation', 'severity' => 'info'],
        'claim.accepted' => ['label' => 'Claim accepted', 'icon' => 'gavel', 'category' => 'moderation', 'severity' => 'danger'],
        'claim.rejected' => ['label' => 'Claim rejected', 'icon' => 'gavel', 'category' => 'moderation', 'severity' => 'info'],
        'claim.sound.taken_down' => ['label' => 'Sound taken down', 'icon' => 'ban', 'category' => 'moderation', 'severity' => 'danger'],
        'claim.sound.restored' => ['label' => 'Sound restored', 'icon' => 'rotate-left', 'category' => 'moderation', 'severity' => 'success'],
        'claim.contributor.notified' => ['label' => 'Contributor notified', 'icon' => 'envelope', 'category' => 'moderation', 'severity' => 'info'],

        // ─── Money ──────────────────────────────────────────────────────
        'plan.price.changed' => ['label' => 'Plan price changed', 'icon' => 'tag', 'category' => 'billing', 'severity' => 'warning'],
        'subscription.granted' => ['label' => 'Subscription granted by hand', 'icon' => 'gift', 'category' => 'billing', 'severity' => 'warning'],
        'subscription.cancelled' => ['label' => 'Subscription cancelled', 'icon' => 'circle-xmark', 'category' => 'billing', 'severity' => 'warning'],
        'payment.refunded' => ['label' => 'Payment refunded', 'icon' => 'arrow-rotate-left', 'category' => 'billing', 'severity' => 'danger'],

        // ─── Content ────────────────────────────────────────────────────
        'post.created' => ['label' => 'Page created', 'icon' => 'file-circle-plus', 'category' => 'content', 'severity' => 'info'],
        'post.updated' => ['label' => 'Page updated', 'icon' => 'pen', 'category' => 'content', 'severity' => 'info'],
        'post.deleted' => ['label' => 'Page deleted', 'icon' => 'trash', 'category' => 'content', 'severity' => 'danger'],
        'category.deleted' => ['label' => 'Category deleted', 'icon' => 'trash', 'category' => 'content', 'severity' => 'danger'],
        'pack.deleted' => ['label' => 'Pack deleted', 'icon' => 'trash', 'category' => 'content', 'severity' => 'danger'],
        'redirect.created' => ['label' => 'Redirect created', 'icon' => 'arrow-turn-right', 'category' => 'content', 'severity' => 'info'],
        'campaign.sent' => ['label' => 'Campaign sent', 'icon' => 'paper-plane', 'category' => 'content', 'severity' => 'warning'],

        // ─── Configuration ──────────────────────────────────────────────
        'settings.updated' => ['label' => 'Settings changed', 'icon' => 'sliders', 'category' => 'config', 'severity' => 'warning'],
        'search.synonyms.updated' => ['label' => 'Synonyms changed', 'icon' => 'arrow-right-arrow-left', 'category' => 'config', 'severity' => 'info'],
        'storage.migrated' => ['label' => 'Files moved between disks', 'icon' => 'hard-drive', 'category' => 'config', 'severity' => 'warning'],
    ];

    /** Prefix fallbacks, so an unlisted action still lands somewhere sane. */
    private const PREFIXES = [
        'user' => ['category' => 'security', 'icon' => 'user', 'severity' => 'info'],
        'claim' => ['category' => 'moderation', 'icon' => 'gavel', 'severity' => 'info'],
        'sound' => ['category' => 'moderation', 'icon' => 'waveform-lines', 'severity' => 'info'],
        'plan' => ['category' => 'billing', 'icon' => 'tag', 'severity' => 'info'],
        'subscription' => ['category' => 'billing', 'icon' => 'credit-card', 'severity' => 'info'],
        'payment' => ['category' => 'billing', 'icon' => 'credit-card', 'severity' => 'info'],
        'post' => ['category' => 'content', 'icon' => 'file-lines', 'severity' => 'info'],
        'settings' => ['category' => 'config', 'icon' => 'sliders', 'severity' => 'info'],
        'search' => ['category' => 'config', 'icon' => 'magnifying-glass', 'severity' => 'info'],
        'storage' => ['category' => 'config', 'icon' => 'hard-drive', 'severity' => 'info'],
    ];

    /**
     * Never let the screen break on an action nobody registered.
     *
     * A registry that must be updated before a new action can be displayed
     * is a registry somebody will forget, and the failure would land on the
     * one screen whose whole job is to be trustworthy. Unknown actions get
     * a readable label derived from their own name.
     *
     * @return array{label: string, icon: string, category: string, severity: string}
     */
    public static function describe(string $action): array
    {
        if (isset(self::ACTIONS[$action])) {
            return self::ACTIONS[$action];
        }

        $fallback = self::PREFIXES[Str::before($action, '.')] ?? [
            'category' => 'system',
            'icon' => 'circle-info',
            'severity' => 'info',
        ];

        return $fallback + [
            'label' => Str::of($action)->after('.')->replace(['.', '_'], ' ')->ucfirst()->toString(),
        ];
    }

    public static function categoryOf(string $action): string
    {
        return self::describe($action)['category'];
    }

    public static function keepDaysFor(string $action): int
    {
        $category = self::categoryOf($action);

        return self::CATEGORIES[$category]['keepDays'] ?? 180;
    }

    /**
     * Action names grouped by category, for the pruner.
     *
     * @return array<string, array<int, string>>
     */
    public static function actionsByCategory(): array
    {
        $grouped = [];

        foreach (self::ACTIONS as $action => $meta) {
            $grouped[$meta['category']][] = $action;
        }

        return $grouped;
    }

    /** Semantic colour for a severity. Meaning, never decoration. */
    public static function toneClasses(string $severity): array
    {
        return match ($severity) {
            'danger' => ['dot' => 'bg-danger', 'chip' => 'bg-danger/15 text-danger'],
            'warning' => ['dot' => 'bg-warning', 'chip' => 'bg-warning/15 text-warning'],
            'success' => ['dot' => 'bg-success', 'chip' => 'bg-success/15 text-success'],
            default => ['dot' => 'bg-info', 'chip' => 'bg-info/15 text-info'],
        };
    }
}
