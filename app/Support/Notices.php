<?php

namespace App\Support;

use App\Services\Diagnostics;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Everything in the panel that wants a person's attention, in one list.
 *
 * THE REASON THIS CLASS EXISTS RATHER THAN A notifications TABLE.
 *
 * Every signal worth a bell already existed before the bell did: open
 * claims, abuse signals, error groups seen this week, a stuck queue, broken
 * links, sounds waiting on review, and the twenty checks Diagnostics runs.
 * Each already had a badge, a screen and a definition of "how many".
 *
 * A notifications table would have been a SECOND answer to the same
 * questions — written by whichever code paths remembered to write it, and
 * silently wrong for the ones that did not. The first time the sidebar said
 * "Claims 3" and the bell said 1, neither number would be believed again.
 *
 * So the bell reads the same counts the sidebar reads, and the same checks
 * the diagnostics screen reads. One definition, three surfaces.
 *
 * THE CONSEQUENCE, AND IT IS A FEATURE: nothing here can be marked as read.
 * A notice leaves when the thing it is about is fixed. "Mark all as read"
 * on a list of operational problems is a button for hiding your own fires,
 * and the day it gets pressed out of habit is the day the real one goes
 * with it.
 */
class Notices
{
    /** Broken, hostile, or losing you something. Red. */
    public const DANGER = 'danger';

    /** Needs a person this week, not this minute. Amber. */
    public const WARNING = 'warning';

    /** Work waiting in a queue that is working. Neutral blue. */
    public const INFO = 'info';

    /** Sort order, and how "the worst one" is decided. */
    private const RANK = [self::DANGER => 3, self::WARNING => 2, self::INFO => 1];

    /**
     * What each sidebar count MEANS, which is not the same as how big it is.
     *
     * This map is the whole answer to "should the badge be red". Making
     * every badge red was the request and it is the wrong answer, for a
     * reason this project has already paid for twice: a badge that is
     * permanently lit stops being read. "12 sounds in review" is a healthy
     * catalogue with contributors in it — colouring that the same as "1 open
     * copyright claim" teaches the eye to skip both.
     *
     * Red is a promise. It has to be kept for it to be worth anything.
     */
    public const COUNT_LEVELS = [
        // Somebody is claiming you are publishing their work. There is a
        // legal clock on this one.
        'claims' => self::DANGER,
        // Somebody is attacking the site right now.
        'abuse' => self::DANGER,
        // The site is throwing errors at real visitors this week.
        'errors' => self::DANGER,
        // Uploads that never became playable sounds. Silent data loss.
        'failed' => self::DANGER,

        // Work that has stopped moving. Bad, but nothing is on fire and
        // nobody outside can tell.
        'queue' => self::WARNING,
        // Links that 404. Costs ranking, slowly.
        'broken' => self::WARNING,

        // Contributors waiting on you. This is the system working.
        'review' => self::INFO,
    ];

    /**
     * Checks the bell deliberately does NOT repeat.
     *
     * Both already have a permanent banner across the top of every admin
     * page. A bell that also lists them is the same warning twice on one
     * screen, and two copies of a warning read as two problems.
     */
    private const ALREADY_BANNERED = ['build.assets', 'build.fresh'];

    /**
     * How a Diagnostics check is named for somebody who did not open the
     * diagnostics screen. Absent keys fall back to the check's own label.
     */
    private const SYSTEM_ICONS = [
        'Backups' => 'box-archive',
        'Background' => 'list-check',
        'Database' => 'database',
        'Environment' => 'gear',
        'Mail' => 'envelope',
        'Media' => 'file-audio',
        'Search' => 'magnifying-glass',
        'Security' => 'shield-exclamation',
        'Site' => 'globe',
        'Storage' => 'hard-drive',
    ];

    /* ═══════════════════════════ The list ═══════════════════════════ */

    /** Built once per request: the bell asks three times on one render. */
    private static ?array $memo = null;

    /**
     * Every open notice, worst first.
     *
     * @return array<int, array{level: string, icon: string, title: string, detail: string, route: ?string, count: ?int}>
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $notices = array_merge(self::fromCounts(), self::fromSystem());

        usort($notices, fn ($a, $b) => (self::RANK[$b['level']] ?? 0) <=> (self::RANK[$a['level']] ?? 0));

        return self::$memo = $notices;
    }

    public static function count(): int
    {
        return count(self::all());
    }

    /** The colour the bell itself wears, or null when there is nothing. */
    public static function worst(): ?string
    {
        $levels = array_column(self::all(), 'level');

        if ($levels === []) {
            return null;
        }

        usort($levels, fn ($a, $b) => (self::RANK[$b] ?? 0) <=> (self::RANK[$a] ?? 0));

        return $levels[0];
    }

    /**
     * The level a sidebar badge should wear.
     *
     * Defaults to INFO rather than DANGER for an unmapped key: a new counter
     * added tomorrow should arrive quiet and be promoted deliberately, not
     * arrive shouting and have to be argued down.
     */
    public static function levelOf(?string $countKey): string
    {
        return self::COUNT_LEVELS[$countKey] ?? self::INFO;
    }

    /** The worst level among a group's children, for the collapsed badge. */
    public static function worstOf(array $levels): string
    {
        $best = self::INFO;

        foreach ($levels as $level) {
            if ((self::RANK[$level] ?? 0) > (self::RANK[$best] ?? 0)) {
                $best = $level;
            }
        }

        return $best;
    }

    /* ═══════════════════════════ Sources ═══════════════════════════ */

    /**
     * The operational queues — the same numbers the sidebar badges show.
     *
     * AdminNav::counts() is already cached for a minute, so asking it again
     * here costs nothing and guarantees the two can never disagree.
     */
    private static function fromCounts(): array
    {
        $counts = AdminNav::counts();

        $shape = [
            'claims' => ['shield-exclamation', 'admin.claims',
                fn ($n) => $n === 1 ? 'One open copyright claim' : "{$n} open copyright claims",
                'A sound is unavailable while its claim is open, and there is a legal clock on the answer.'],

            'abuse' => ['ban', 'admin.security.abuse',
                fn ($n) => $n === 1 ? 'One abuse signal to look at' : "{$n} abuse signals to look at",
                'Traffic that tripped a rule and that nobody has decided about yet.'],

            'errors' => ['triangle-exclamation', 'admin.errors',
                fn ($n) => $n === 1 ? 'One error is hitting visitors' : "{$n} errors are hitting visitors",
                'Open error groups seen in the last week — so these are live, not history.'],

            'failed' => ['file-circle-xmark', 'admin.sounds',
                fn ($n) => $n === 1 ? 'One upload never finished processing' : "{$n} uploads never finished processing",
                'The file arrived and never became a playable sound. Nobody outside can tell, and it does not fix itself.'],

            'queue' => ['list-check', 'admin.queue',
                fn ($n) => $n === 1 ? 'One job needs a person' : "{$n} jobs need a person",
                'Dead jobs and anything stuck behind a worker that stopped consuming.'],

            'broken' => ['arrow-turn-right', 'admin.redirects',
                fn ($n) => $n === 1 ? 'One broken URL with no redirect' : "{$n} broken URLs with no redirect",
                'Addresses that 404. Each one is a link somewhere pointing at nothing.'],

            'review' => ['clipboard-check', 'moderate',
                fn ($n) => $n === 1 ? 'One sound waiting on review' : "{$n} sounds waiting on review",
                'Contributors are waiting. This is the queue working, not a fault.'],
        ];

        $notices = [];

        foreach ($shape as $key => [$icon, $route, $title, $detail]) {
            $n = (int) ($counts[$key] ?? 0);

            if ($n < 1) {
                continue;
            }

            $notices[] = [
                'level' => self::levelOf($key),
                'icon' => $icon,
                'title' => $title($n),
                'detail' => $detail,
                'route' => $route,
                'count' => $n,
            ];
        }

        return $notices;
    }

    /**
     * The health checks, as notices.
     *
     * Cached for FIVE minutes rather than one. Diagnostics::run() shells out
     * for ffmpeg, stats the disk and touches the mail config — affordable on
     * a screen you open deliberately, not on every admin page render. Five
     * minutes is well inside "you will see it before you finish what you are
     * doing" and well outside "this is running constantly".
     */
    private static function fromSystem(): array
    {
        try {
            return Cache::remember('admin.notices.system', now()->addMinutes(5), function () {
                $notices = [];

                foreach (app(Diagnostics::class)->run() as $check) {
                    if ($check['status'] === Diagnostics::OK) {
                        continue;
                    }

                    if (in_array($check['key'], self::ALREADY_BANNERED, true)) {
                        continue;
                    }

                    $notices[] = [
                        'level' => $check['status'] === Diagnostics::FAIL ? self::DANGER : self::WARNING,
                        'icon' => self::SYSTEM_ICONS[$check['group']] ?? 'circle-exclamation',
                        'title' => $check['label'],
                        'detail' => $check['detail'],
                        // Every check knows where it is fixed; the ones that
                        // do not send you to the screen that explains them.
                        'route' => $check['route'] ?: 'admin.diagnostics',
                        'count' => null,
                    ];
                }

                return $notices;
            });
        } catch (Throwable $e) {
            /*
             * Degrade over crash. The bell hangs in the layout of every
             * admin page, so a check that blows up must not take the panel
             * down — least of all when the panel is the thing you opened to
             * find out what is broken.
             */
            return [[
                'level' => self::WARNING,
                'icon' => 'circle-exclamation',
                'title' => 'Health checks could not run',
                'detail' => $e->getMessage(),
                'route' => 'admin.diagnostics',
                'count' => null,
            ]];
        }
    }
}
