<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Is the site open, and to whom.
 *
 * Three states rather than a maintenance_mode boolean, because the two
 * closed states are not the same thing and must not look the same to a
 * search engine or to a visitor:
 *
 *   live         everything normal.
 *   soon         not launched yet. Nothing to see, and nothing that should
 *                be indexed — a placeholder page cached by Google as the
 *                homepage outlives the launch by weeks.
 *   maintenance  temporarily down while something is being fixed. Same
 *                closed door, a different sentence, and the same 503 so
 *                nobody's crawler mistakes it for the real site.
 *
 * ADMINS ALWAYS PASS THROUGH. A switch in the panel that can lock the
 * operator out of the panel is a switch that gets flipped once and then
 * needs a database client to unflip. Same reasoning as the restore flow:
 * the dangerous operation keeps a way back.
 *
 * Defined here rather than in the middleware because four places need to
 * agree about it — the gate, the banner, the settings screen and
 * Diagnostics. One definition; the moment there are two they drift.
 */
class SiteStatus
{
    public const LIVE = 'live';

    public const SOON = 'soon';

    public const MAINTENANCE = 'maintenance';

    /**
     * Paths that stay open even with the door shut.
     *
     * The login form is on this list for one reason: bypassing requires
     * being signed in, and being signed in requires reaching the login
     * form. Without it the only admin who can open the site is one who
     * happened to already have a session.
     *
     * `livewire/*` is open because the closed page and the login form are
     * Livewire components and stop working without it. It exposes nothing:
     * a component update needs a signed snapshot from a rendered page, and
     * the pages that would hand one out are exactly what is blocked.
     *
     * @var array<int, string>
     */
    public const OPEN_PATHS = [
        'login',
        'logout',
        'forgot-password',
        'reset-password/*',
        'two-factor-challenge',
        'user/confirm-password',
        'livewire/*',
        'up',
    ];

    public static function current(): string
    {
        $value = (string) Setting::read('site.status', self::LIVE);

        return in_array($value, [self::LIVE, self::SOON, self::MAINTENANCE], true)
            ? $value
            : self::LIVE;
    }

    public static function isLive(): bool
    {
        return self::current() === self::LIVE;
    }

    /** The optional line shown on the closed page. */
    public static function message(): ?string
    {
        $message = Setting::read('site.status_message');

        return filled($message) ? (string) $message : null;
    }

    /**
     * Staff see the real site regardless.
     *
     * isAdmin() rather than a broader staff check on purpose: "coming soon"
     * is meant to be closed to everyone who is not building it, and a
     * moderator has nothing to moderate before launch.
     */
    public static function bypasses(?User $user): bool
    {
        return (bool) $user?->isAdmin();
    }

    public static function allows(Request $request): bool
    {
        return $request->is(...self::OPEN_PATHS);
    }

    /**
     * The three states as the settings screen renders them.
     *
     * The description is not decoration — it is the sentence that tells you
     * which one you actually want, at the moment you are choosing.
     *
     * `tone` is a semantic NAME, not a class fragment. Never build a Tailwind
     * class out of it: a class assembled at runtime was never seen by the
     * compiler and simply does not exist in the stylesheet.
     *
     * @return array<string, array{label: string, icon: string, tone: string, blurb: string}>
     */
    public static function modes(): array
    {
        return [
            self::LIVE => [
                'label' => 'Live',
                'icon' => 'circle-check',
                'tone' => 'success',
                'blurb' => 'Open to everyone. Search engines are welcome.',
            ],
            self::SOON => [
                'label' => 'Coming soon',
                'icon' => 'hourglass-half',
                'tone' => 'info',
                'blurb' => 'A placeholder page for visitors, and a 503 for crawlers so nothing gets indexed before launch. You still see the real site.',
            ],
            self::MAINTENANCE => [
                'label' => 'Maintenance',
                'icon' => 'screwdriver-wrench',
                'tone' => 'warning',
                'blurb' => 'Temporarily closed while you fix something. Same 503, so an indexed page is not dropped from the results for being down for an hour.',
            ],
        ];
    }
}
