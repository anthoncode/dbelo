<?php

namespace App\Support;

use App\Support\Concerns\SettingFields;
use Illuminate\Support\HtmlString;

/**
 * Somebody else's tags, in the three places they have to go.
 *
 * Separate from Ads on purpose, even though both store third-party
 * JavaScript. They have different jobs and different lifetimes: switching
 * advertising off must not switch off your analytics, and the "no ads for
 * subscribers" rule must not take a support widget away from the people
 * paying for support. One switch doing two things ends up doing the one you
 * did not mean.
 *
 * THREE POSITIONS, NOT TWO. Panels usually offer "header" and "footer", and
 * then the Google Tag Manager <noscript> — which must sit immediately after
 * <body> — has nowhere correct to go. It gets pasted into the header, does
 * not work, and nothing says why.
 *
 * NEVER IN THE ADMIN PANEL. Analytics that counts your own visits to the
 * panel is analytics you cannot read, and a chat widget has nothing to do
 * on a screen only you can reach.
 */
class CustomCode
{
    use SettingFields;

    /**
     * @var array<string, array{key: string, type: string, label: string, help: string}>
     */
    public const FIELDS = [

        /* ═════════════════════════ Verification ═════════════════════════ */

        'google_verification' => [
            'key' => 'code.verify.google',
            'type' => 'text',
            'label' => 'Google Search Console',
            'help' => 'Only the code, not the whole tag — and if you paste the whole tag anyway it is read out of it, because everybody pastes the whole tag. This is the first step the SEO screen is waiting on: impressions and positions do not exist until the domain is verified.',
        ],
        'bing_verification' => [
            'key' => 'code.verify.bing',
            'type' => 'text',
            'label' => 'Bing Webmaster Tools',
            'help' => 'Same again. Worth doing once: Bing also feeds DuckDuckGo and Ecosia.',
        ],

        /* ════════════════════════════ Slots ════════════════════════════ */

        'head' => [
            'key' => 'code.head',
            'type' => 'code',
            'label' => 'Before </head>',
            'help' => 'Analytics, verification for anything not listed above, preconnect hints. Printed LAST in the head, after the site\'s own stylesheet and scripts — so a tag with a syntax error in it cannot stop the site\'s CSS from loading.',
        ],
        'body_start' => [
            'key' => 'code.body_start',
            'type' => 'code',
            'label' => 'Just after <body>',
            'help' => 'The position most panels forget. Google Tag Manager\'s <noscript> block requires it, and so do several chat widgets — put them in the head instead and they simply do not run.',
        ],
        'body_end' => [
            'key' => 'code.body_end',
            'type' => 'code',
            'label' => 'Before </body>',
            'help' => 'Chat widgets, support bubbles, anything that draws on the page. Down here it loads after the page is readable rather than delaying it.',
        ],
    ];

    /**
     * @var array<string, array{label: string, note: string, fields: array<int, string>}>
     */
    public const SECTIONS = [
        'verify' => [
            'label' => 'Search engine verification',
            'note' => 'The one tag each engine asks for to prove the site is yours. A labelled box rather than the raw tag, because a raw tag pasted into the wrong slot fails silently.',
            'fields' => ['google_verification', 'bing_verification'],
        ],
        'slots' => [
            'label' => 'Custom code',
            'note' => 'Three positions, because the right one depends on what the tag is — and a tag in the wrong position usually does nothing rather than complain.',
            'fields' => ['head', 'body_start', 'body_end'],
        ],
    ];

    /* ═══════════════════════════ Rendering ═══════════════════════════ */

    /**
     * Does custom code belong on this request at all?
     *
     * Reuses the advertising never-list, minus the pages where a tracking
     * tag is legitimate but an advert is not: somebody reading the terms is
     * still a visit worth counting, and a chat widget on the pricing page is
     * the entire point of a chat widget.
     */
    public static function allowed(): bool
    {
        return ! request()->routeIs('admin.*', 'moderate', 'users');
    }

    public static function head(): HtmlString
    {
        if (! self::allowed()) {
            return new HtmlString('');
        }

        $out = self::metaTag('google-site-verification', self::token('google_verification'))
            .self::metaTag('msvalidate.01', self::token('bing_verification'))
            .trim(self::text('head'));

        return new HtmlString($out);
    }

    public static function bodyStart(): HtmlString
    {
        return new HtmlString(self::allowed() ? trim(self::text('body_start')) : '');
    }

    public static function bodyEnd(): HtmlString
    {
        return new HtmlString(self::allowed() ? trim(self::text('body_end')) : '');
    }

    /**
     * The verification code, however it was pasted.
     *
     * Everybody pastes the whole <meta> tag, because that is what Google
     * shows you. Rejecting it would be technically correct and useless; the
     * code is pulled out of it instead, and a bare code passes through
     * untouched.
     */
    public static function token(string $field): string
    {
        $value = trim(self::text($field));

        if ($value === '') {
            return '';
        }

        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $value, $m)) {
            $value = $m[1];
        }

        // Verification codes are a restricted alphabet. Anything else is not
        // one, and this string goes into an attribute — so it is filtered
        // rather than escaped and hoped for.
        return preg_match('/^[A-Za-z0-9_\-]{8,128}$/', $value) ? $value : '';
    }

    private static function metaTag(string $name, string $content): string
    {
        return $content === ''
            ? ''
            : '<meta name="'.$name.'" content="'.e($content).'">'."\n";
    }

    /** Is anything at all configured? For the screen's verdict strip. */
    public static function anything(): bool
    {
        foreach (array_keys(self::FIELDS) as $field) {
            if (trim(self::text($field)) !== '') {
                return true;
            }
        }

        return false;
    }
}
