<?php

namespace App\Support;

use App\Support\Concerns\SettingFields;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;

/**
 * Advertising: where it appears, and the several places it must not.
 *
 * THIS SCREEN STORES THIRD-PARTY JAVASCRIPT AND RUNS IT ON THE PUBLIC SITE.
 * That is not an oversight to be sanitised away — an ad tag IS a script from
 * somebody else, and a "safe" version of it would simply not work. Every
 * other free-text field in this project refuses HTML for exactly this
 * reason; this one cannot, so the honest thing is to say so on the screen,
 * keep it to admins, and be strict about everything around it instead:
 *
 *   · never in the admin panel
 *   · never on the legal pages, pricing, login or registration
 *   · never for somebody who pays not to see it
 *
 * NAMED BLOCKS, NOT FREE PLACEMENT. Three slots that exist in specific
 * templates, each with its own code. One shared snippet pasted everywhere
 * would report as one number, and "which placement earns anything" would be
 * unanswerable — which is the only question that decides whether to keep
 * doing this at all.
 */
class Ads
{
    use SettingFields;

    /**
     * Routes that never carry advertising.
     *
     * Legal pages because an ad beside your privacy policy undermines the
     * document it sits next to. Pricing, login and registration because they
     * are the three screens where a distraction costs more than the ad pays
     * — and Google's own policy is unhappy about ads adjacent to a form.
     *
     * @var array<int, string>
     */
    public const NEVER = [
        'legal.*', 'claims.create',
        'login', 'register', 'password.*', 'two-factor.*', 'verification.*',
        'admin.*', 'moderate', 'users', 'upload', 'library',
    ];

    /**
     * @var array<string, array{key: string, type: string, label: string, help: string}>
     */
    public const FIELDS = [

        /* ═══════════════════════════ The network ═══════════════════════════ */

        'enabled' => [
            'key' => 'ads.enabled',
            'type' => 'bool',
            'label' => 'Show advertising',
            'help' => 'The master switch. Off means no ad markup and no third-party script anywhere on the site — not a hidden block, nothing in the page at all.',
        ],
        'loader' => [
            'key' => 'ads.loader',
            'type' => 'code',
            'label' => 'Network script',
            'help' => 'The one tag the network gives you for the <head> — for AdSense, the adsbygoogle.js line with your publisher id. Loaded once per page. The blocks below are the individual units.',
        ],
        'test_mode' => [
            'key' => 'ads.test_mode',
            'type' => 'bool',
            'label' => 'Test mode',
            'help' => 'Draws a labelled grey box the exact size of each block instead of calling the network. The way to judge whether a placement ruins the page without loading anything from Google, and without registering an impression that never had a reader.',
            'default_on' => true,
        ],
        'hide_for_subscribers' => [
            'key' => 'ads.hide_for_subscribers',
            'type' => 'bool',
            'label' => 'No ads for subscribers',
            'help' => 'Showing advertising to somebody who pays you is the complaint people actually write in about — and "no ads" is one of the few things a paid plan can promise that costs nothing to deliver.',
            'default_on' => true,
        ],

        /* ═══════════════════════════ The blocks ═══════════════════════════ */

        'sound_on' => [
            'key' => 'ads.sound.enabled',
            'type' => 'bool',
            'label' => 'Show this block',
            'help' => '',
            'default_on' => true,
        ],
        'sound_code' => [
            'key' => 'ads.sound.code',
            'type' => 'code',
            'label' => 'Ad unit',
            'help' => 'Placed under the player and above the description — deliberately far from the download button. An ad next to Download collects accidental clicks, and accidental clicks are what gets an account suspended.',
        ],
        'sound_height' => [
            'key' => 'ads.sound.height',
            'type' => 'number',
            'label' => 'Reserved height',
            'help' => 'Pixels held open before the ad arrives. Without it the page jumps when the ad loads, which is layout shift — a ranking signal, and the SEO screen will report it.',
        ],

        'catalog_on' => [
            'key' => 'ads.catalog.enabled',
            'type' => 'bool',
            'label' => 'Show this block',
            'help' => '',
        ],
        'catalog_code' => [
            'key' => 'ads.catalog.code',
            'type' => 'code',
            'label' => 'Ad unit',
            'help' => 'One block inside the results list, at a natural break rather than at the top.',
        ],
        'catalog_after' => [
            'key' => 'ads.catalog.after',
            'type' => 'number',
            'label' => 'After how many results',
            'help' => 'Far enough down that somebody has seen real results first. Too high on the page and it reads as the site being the advert.',
        ],
        'catalog_height' => [
            'key' => 'ads.catalog.height',
            'type' => 'number',
            'label' => 'Reserved height',
            'help' => '',
        ],

        'blog_on' => [
            'key' => 'ads.blog.enabled',
            'type' => 'bool',
            'label' => 'Show this block',
            'help' => '',
            'default_on' => true,
        ],
        'blog_code' => [
            'key' => 'ads.blog.code',
            'type' => 'code',
            'label' => 'Ad unit',
            'help' => 'At the end of the article, where somebody who read it is deciding what to do next.',
        ],
        'blog_height' => [
            'key' => 'ads.blog.height',
            'type' => 'number',
            'label' => 'Reserved height',
            'help' => '',
        ],

        /* ═══════════════════════════ ads.txt ═══════════════════════════ */

        'ads_txt' => [
            'key' => 'ads.ads_txt',
            'type' => 'code',
            'label' => 'ads.txt',
            'help' => 'Served at the root of the domain. It names who is allowed to sell your inventory, and buyers that cannot find it treat the traffic as unauthorised — so a missing ads.txt is quietly worth less money rather than an error anybody sees. AdSense gives you the exact line.',
        ],
    ];

    /**
     * @var array<string, array{label: string, note: string, fields: array<int, string>}>
     */
    public const SECTIONS = [
        'network' => [
            'label' => 'Network',
            'note' => 'The switch, the loader script, and the two rules that apply everywhere.',
            'fields' => ['enabled', 'loader', 'test_mode', 'hide_for_subscribers'],
        ],
        'sound' => [
            'label' => 'Sound page — under the player',
            'note' => 'The highest-traffic page in a sound library, and the one people arrive on from Google.',
            'fields' => ['sound_on', 'sound_code', 'sound_height'],
        ],
        'catalog' => [
            'label' => 'Catalogue — inside the results',
            'note' => 'One block partway down the list.',
            'fields' => ['catalog_on', 'catalog_code', 'catalog_after', 'catalog_height'],
        ],
        'blog' => [
            'label' => 'Blog post — after the article',
            'note' => '',
            'fields' => ['blog_on', 'blog_code', 'blog_height'],
        ],
        'txt' => [
            'label' => 'ads.txt',
            'note' => 'The file every buyer checks before bidding.',
            'fields' => ['ads_txt'],
        ],
    ];

    /* ═══════════════════════════ Deciding ═══════════════════════════ */

    /**
     * Should this visitor, on this page, see advertising at all?
     *
     * Asked once and answered here rather than in three templates. Three
     * copies of a rule this consequential is three chances to forget the
     * exclusion list on the next placement somebody adds.
     */
    public static function allowed(?Request $request = null): bool
    {
        if (! self::flag('enabled')) {
            return false;
        }

        $request ??= request();

        if ($request->routeIs(...self::NEVER)) {
            return false;
        }

        if (self::flag('hide_for_subscribers') && self::paying($request->user())) {
            return false;
        }

        return true;
    }

    /** Someone on a paid plan. A free account has no subscription row at all. */
    private static function paying($user): bool
    {
        if (! $user) {
            return false;
        }

        $subscription = rescue(fn () => $user->activeSubscription(), null, false);

        if (! $subscription) {
            return false;
        }

        // Belt and braces: if a free plan ever does get a subscription row,
        // it must not start counting as "pays not to see ads".
        return ! rescue(fn () => (bool) $subscription->plan?->isFree(), false, false);
    }

    /**
     * Everything one block needs, or null when it should not render.
     *
     * @return array{id: string, code: HtmlString, height: int, test: bool}|null
     */
    public static function block(string $slot): ?array
    {
        if (! self::allowed()) {
            return null;
        }

        if (! self::flag($slot.'_on')) {
            return null;
        }

        $test = self::flag('test_mode');
        $code = trim(self::text($slot.'_code'));

        // Nothing pasted and not in test mode: render nothing rather than an
        // empty reserved gap. A hole where an ad would be is worse looking
        // than no ad, and it is what an unconfigured site would show.
        if ($code === '' && ! $test) {
            return null;
        }

        return [
            'id' => 'ad-'.$slot,
            'code' => new HtmlString($code),
            'height' => max(60, self::number($slot.'_height')),
            'test' => $test,
        ];
    }

    /** The head tag, once per page, and only when a block might use it. */
    public static function loader(): HtmlString
    {
        if (! self::allowed() || self::flag('test_mode')) {
            return new HtmlString('');
        }

        return new HtmlString(trim(self::text('loader')));
    }

    public static function catalogAfter(): int
    {
        return max(1, self::number('catalog_after'));
    }
}
