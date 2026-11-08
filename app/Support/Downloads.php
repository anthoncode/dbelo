<?php

namespace App\Support;

use App\Models\Sound;
use App\Support\Concerns\SettingFields;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * How many sounds somebody gets before we ask who they are.
 *
 * TWO MECHANISMS, AND THEY ARE NOT THE SAME ONE. Confusing them is the
 * mistake this class exists to avoid:
 *
 *   THE FREE ALLOWANCE IS A NUDGE. Its job is turning a curious visitor into
 *   a registered one. It is counted in a COOKIE, and anybody can reset it by
 *   opening a private window — which is fine, and is the point. Somebody who
 *   does that was never going to register, and it cost one file.
 *
 *   THE WALL IS SOMEWHERE ELSE. Stopping a script taking the whole catalogue
 *   is a different problem, aimed at machines, and it is already built:
 *   RateLimiter::for('downloads'), WatchTraffic, SecurityWatch, BlockIps.
 *
 * Making the nudge do the wall's job is what leads people to count by IP —
 * and then an office, a university or a whole mobile carrier behind CGNAT is
 * one "person", and a hundred real visitors are blocked to fail to stop one.
 * A cookie also asks no questions of a privacy policy.
 */
class Downloads
{
    use SettingFields;

    /**
     * Not a setting: a bug fix with one right answer.
     *
     * Re-downloading a sound you already have is not a second download. It
     * used to cost another slot off the daily quota AND increment
     * downloads_count — which is what orders the categories on the home
     * page, so somebody re-fetching one file five times was pushing it up
     * the rankings.
     *
     * Thirty days is long enough to cover a lost laptop and short enough
     * that a file grabbed last year is honestly a new download.
     */
    public const REGRAB_DAYS = 30;

    /** Sound ids a guest has already taken. Encrypted, like every other cookie. */
    public const COOKIE = 'dbelo_free';

    /** A guest cookie holds ids, not a count, so a re-grab is free for them too. */
    private const COOKIE_DAYS = 365;

    /**
     * @var array<string, array{key: string, type: string, label: string, help: string}>
     */
    public const FIELDS = [
        'guest_enabled' => [
            'key' => 'downloads.guest.enabled',
            'type' => 'bool',
            'label' => 'Let visitors download without an account',
            'help' => 'Off means the sign-in wall is where it was: an account before a single file. On is the trade most libraries make — a few files first, because somebody who has never heard the product has no reason to fill in a form.',
            'default_on' => true,
        ],
        'guest_limit' => [
            'key' => 'downloads.guest.limit',
            'type' => 'number',
            'label' => 'How many, before signing in',
            'help' => 'Three is enough to actually judge the catalogue — one sound convinces nobody — and few enough that the site is not usable without an account. This is the number worth changing when you can see what conversion looks like.',
        ],
        'guest_message' => [
            'key' => 'downloads.guest.message',
            'type' => 'text',
            'label' => 'Line on the sign-up prompt',
            'help' => 'Optional. Shown on the page somebody reaches after their last free download — the highest-intent moment on the site. Empty uses the standard wording.',
        ],
    ];

    /**
     * @var array<string, array{label: string, note: string, fields: array<int, string>}>
     */
    public const SECTIONS = [
        'guest' => [
            'label' => 'Downloads without an account',
            'note' => 'The one decision on this screen that is genuinely a decision.',
            'fields' => ['guest_enabled', 'guest_limit', 'guest_message'],
        ],
    ];

    /* ═══════════════════════════ The allowance ═══════════════════════════ */

    public static function guestsAllowed(): bool
    {
        return self::flag('guest_enabled') && self::allowance() > 0;
    }

    public static function allowance(): int
    {
        return max(0, self::number('guest_limit'));
    }

    /**
     * The sound ids this visitor has already taken, from their cookie.
     *
     * Ids rather than a tally, so re-downloading something they already have
     * costs a guest nothing — the same rule signed-in users get, for the
     * same reason.
     *
     * @return array<int, int>
     */
    public static function guestTaken(Request $request): array
    {
        $raw = (string) $request->cookie(self::COOKIE, '');

        if ($raw === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            // A cookie is client-side and can be anything. Capping the list
            // stops a hand-edited value turning into an unbounded array on
            // every request.
            ->take(200)
            ->values()
            ->all();
    }

    public static function guestRemaining(Request $request): int
    {
        return max(0, self::allowance() - count(self::guestTaken($request)));
    }

    /** Already taken this exact sound: free, and does not count again. */
    public static function guestHasTaken(Request $request, int $soundId): bool
    {
        return in_array($soundId, self::guestTaken($request), true);
    }

    /**
     * The cookie to attach to the response.
     *
     * Returned rather than queued so the caller decides — a cookie set on a
     * streamed download response has to be attached to that response, and a
     * queued one can be lost when the stream is what gets sent.
     */
    public static function rememberGuest(Request $request, int $soundId): \Symfony\Component\HttpFoundation\Cookie
    {
        $ids = self::guestTaken($request);
        $ids[] = $soundId;

        return cookie(
            self::COOKIE,
            implode(',', array_slice(array_unique($ids), -200)),
            self::COOKIE_DAYS * 24 * 60,
        );
    }

    /**
     * The wording used when nothing has been typed into the setting.
     *
     * A constant rather than a string inside prompt(), so the settings
     * screen can show it as the placeholder. A placeholder that echoed the
     * saved value instead would leave "empty" looking like a broken field
     * rather than a legible state.
     */
    public const DEFAULT_PROMPT = 'Create a free account to keep downloading — it takes a minute, and your downloads are saved so you can find them again.';

    /** The line shown when the allowance runs out. */
    public static function prompt(): string
    {
        $custom = trim(self::text('guest_message'));

        return $custom !== '' ? $custom : self::DEFAULT_PROMPT;
    }

    /* ═══════════════════════════ Filenames ═══════════════════════════ */

    /**
     * What the file is called on somebody's desktop.
     *
     * It used to be the bare title: "thunder-clap.mp3". The site name in
     * front costs nothing and does two things — it is the only piece of
     * marketing that survives into an editor's timeline, and it makes
     * "what was the file called?" answerable in support.
     */
    public static function filename(string $title, string $extension = 'mp3'): string
    {
        $site = Str::slug((string) config('app.name', 'dbelo'));
        $name = Str::slug($title) ?: 'sound';

        return trim($site.'-'.$name, '-').'.'.$extension;
    }

    /* ═══════════════════════════ The link ═══════════════════════════ */

    /**
     * How long a download link stays good for.
     *
     * Twelve hours, and the number is a compromise between two real costs.
     *
     * TOO SHORT and a page left open over lunch hands somebody an expired
     * link, which is a working site that appears broken. TOO LONG and a
     * link harvested once keeps working, which is the thing this exists to
     * prevent.
     *
     * Half a day covers every session a person actually has, and a scraper
     * that wants a link that lasts has to come back and render the page
     * again — which is the cost being imposed.
     */
    public const LINK_HOURS = 12;

    /**
     * The download link for a sound: signed, and good for half a day.
     *
     * ── WHAT THIS BUYS, AND WHAT IT DOES NOT ─────────────────────────────
     *
     * The first 471 rows in the downloads table had no account on a single
     * one of them. 97 were AdSense's crawler. The rest arrived one or two
     * requests per IP, from dozens of addresses — several of them Alibaba
     * and Google Cloud ranges — every one claiming to be a desktop browser
     * on a Mac. A Mac does not live in a datacenter. That is one scraper
     * rotating addresses so that no address looks busy.
     *
     * Against that shape the existing defences do nothing. The rate limiter
     * counts per IP and they make one request per IP. robots.txt is a
     * request and they are already lying about who they are. And the URL
     * itself was guessable: /sounds/{slug}/download, with every slug listed
     * in the sitemap. The catalogue could be taken without ever loading a
     * page of it.
     *
     * A signature breaks exactly that: the link now has to be ISSUED, and
     * it is only issued while rendering the page the sound lives on.
     *
     * What it does NOT do, said plainly so nobody expects more: it does not
     * stop anyone willing to fetch the page first and read the link out of
     * it. That is a few more lines in a scraper. The point is not that it
     * cannot be done — it is that it can no longer be done from the sitemap
     * alone, and it now costs a page render per file instead of a guessed
     * URL per file.
     *
     * The route keeps NO 'signed' middleware. The controller checks the
     * signature itself and redirects an expired link back to the sound,
     * where a fresh one is waiting. Laravel's middleware answers 403, and a
     * 403 at the single highest-intent moment on the site — somebody who
     * wants a file — is the same mistake abort(429) was making before the
     * limit screen replaced it.
     */
    public static function url(Sound $sound): string
    {
        return URL::temporarySignedRoute(
            'sounds.download',
            now()->addHours(self::LINK_HOURS),
            $sound,
        );
    }

    /**
     * The same for a whole pack.
     *
     * Signed for the same reason and more urgently: one request against
     * this URL is the entire curated set, where a sound's URL is one file.
     * The thing worth hardest protecting is the one that costs the most to
     * lose.
     *
     * Takes the slug rather than the model because the controller looks the
     * pack up through featured() — any collection would resolve through
     * route model binding, and most collections are somebody's private
     * folder.
     */
    public static function packUrl(\App\Models\Collection $pack): string
    {
        return URL::temporarySignedRoute(
            'packs.download',
            now()->addHours(self::LINK_HOURS),
            ['pack' => $pack->slug],
        );
    }

    /**
     * Parameters a shared link may pick up without breaking its signature.
     *
     * A signature covers the whole query string, so a link that someone
     * pastes into a campaign tool, or that a social network decorates on
     * the way, arrives with extra parameters and fails verification. The
     * failure then looks like the download being broken rather than like a
     * parameter having been appended — and the person who sees it is
     * somebody who was about to get a file.
     *
     * Analytics and referral tags only. Nothing here is read by the
     * application, so ignoring them cannot change what is served.
     */
    public const IGNORED_QUERY = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
        'gclid', 'fbclid', 'ref',
    ];

    /**
     * Was this link actually issued by us, and is it still fresh?
     *
     * ── WHY THIS LIVES HERE AND NOT IN THE CONTROLLERS ──────────────────
     *
     * This class already creates the links. Having it verify them too is
     * the only arrangement where the list of ignored parameters cannot
     * drift away from the list used to sign — and the cost of that drift
     * is every link failing verification at once.
     *
     * ── AND WHY IT IS URL:: AND NOT $request-> ──────────────────────────
     *
     * Both controllers used to call `$request->hasValidSignature(ignoreQuery: …)`.
     * That reads perfectly and does not exist: hasValidSignature on the
     * request is a MACRO taking only $absolute, so PHP resolved the named
     * argument against Macroable::__call and threw
     *
     *   Error: Unknown named parameter $ignoreQuery
     *
     * — a 500 on every single download and every pack, for anyone, every
     * time. The method that takes ignoreQuery is the one on the URL
     * generator, which is what this calls.
     *
     * It shipped because the suite was never run whole; DownloadQuotaTest
     * had been failing on exactly this the entire time.
     */
    public static function signatureIsValid(Request $request): bool
    {
        return URL::hasValidSignature($request, absolute: true, ignoreQuery: self::IGNORED_QUERY);
    }
}
