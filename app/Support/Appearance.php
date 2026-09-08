<?php

namespace App\Support;

use App\Support\Concerns\SettingFields;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * What the site looks like: its marks, the panel beside the sign-in form,
 * and the two colours that are its own.
 *
 * THE HARD CONSTRAINT, and the thing that decides the whole design of this
 * screen: Tailwind compiles at BUILD time. A colour chosen in the panel can
 * never become a Tailwind class, because `bg-brand` was baked into the
 * stylesheet before this setting existed.
 *
 * What it CAN do is move the CSS custom property that class points at.
 * resources/css/app.css declares the palette as `--color-brand` and friends
 * inside @theme, so an inline <style> in the head reassigns them at runtime
 * and every compiled class follows. That is the only mechanism that works,
 * and it is why this screen offers colours and not, say, spacing.
 *
 * ONLY TWO COLOURS ARE OFFERED, and the exclusion is deliberate:
 *
 *   brand   identity — the mark, the playhead, active states
 *   action  the primary button
 *
 * danger, warning, success and info are NOT here. They carry meaning rather
 * than taste: red means something failed, everywhere, in the panel and on
 * the site. Letting them be repainted turns every status colour in the
 * product into a lie, and the person who would be misled first is the one
 * who changed them.
 */
class Appearance
{
    use SettingFields;

    /** Everything uploaded here lands in one folder, on the media disk. */
    public const FOLDER = 'branding';

    /**
     * @var array<string, array{key: string, type: string, label: string, help: string}>
     */
    public const FIELDS = [

        /* ═══════════════════════════ The marks ═══════════════════════════ */

        'logo_light' => [
            'key' => 'appearance.logo_light',
            'type' => 'image',
            'label' => 'Logo',
            'help' => 'Shown in the header. Replaces the icon-and-wordmark that is there now. SVG keeps its edges at any size; a PNG should be about 240px wide.',
        ],
        'logo_dark' => [
            'key' => 'appearance.logo_dark',
            'type' => 'image',
            'label' => 'Logo for dark mode',
            'help' => 'Optional. Without it the same logo is used on both, which is right for a mark that already reads on either background and wrong for one that is dark ink on transparent.',
        ],
        'favicon' => [
            'key' => 'appearance.favicon',
            'type' => 'image',
            'label' => 'Favicon',
            'help' => 'The tab icon. A square SVG or a 512px PNG; the browser scales it down.',
        ],
        'social_image' => [
            'key' => 'appearance.social_image',
            'type' => 'image',
            'label' => 'Social share image',
            'help' => 'The picture that appears when a link to the site is pasted into WhatsApp, Slack or X. 1200×630 is the size every platform crops from.',
        ],

        /* ═══════════════════════════ The login page ═══════════════════════════ */

        'login_image' => [
            'key' => 'appearance.login_image',
            'type' => 'image',
            'label' => 'Login image',
            'help' => 'The picture beside the sign-in form on Log in and Sign up. Shown from 1024px up only — on a phone the form takes the whole screen and the panel is not rendered at all. Portrait or square works best; about 1200px on the short side. Without one the panel is a brand gradient.',
        ],
        'login_title' => [
            'key' => 'appearance.login_title',
            'type' => 'text',
            'label' => 'Login headline',
            'help' => 'One line over that image — a release, a new pack, whatever is worth saying to somebody who is signing in. Up to 80 characters. Leave it empty and the panel is just the picture.',
        ],

        /* ═══════════════════════════ The colours ═══════════════════════════ */

        'brand_color' => [
            'key' => 'appearance.brand_color',
            'type' => 'color',
            'label' => 'Brand colour',
            'help' => 'Identity: the mark, the playhead, the highlighted word in a headline, active states. Not a button colour.',
        ],
        'action_color' => [
            'key' => 'appearance.action_color',
            'type' => 'color',
            'label' => 'Action colour',
            'help' => 'The primary button, everywhere. Kept separate from brand on purpose — a page where the identity colour and the "press this" colour are the same has nothing left to point with.',
        ],
    ];

    /**
     * @var array<string, array{label: string, note: string, fields: array<int, string>}>
     */
    public const SECTIONS = [
        'marks' => [
            'label' => 'Marks',
            'note' => 'The images that stand for the site. All four are optional; each falls back to what is built in.',
            'fields' => ['logo_light', 'logo_dark', 'favicon', 'social_image'],
        ],
        'login' => [
            'label' => 'Login page',
            'note' => 'What sits beside the sign-in form on Log in and Sign up. Desktop only, on purpose: on a phone the form has the screen to itself, because somebody who came to sign in should not have to scroll past a picture to do it.',
            'fields' => ['login_image', 'login_title'],
        ],
        'colours' => [
            'label' => 'Colours',
            'note' => 'Two, and only two. The status colours — red, amber, green, blue — are not here because they mean something rather than decorate.',
            'fields' => ['brand_color', 'action_color'],
        ],
    ];

    /* ═══════════════════════════ Files ═══════════════════════════ */

    private static function disk(): string
    {
        return (string) config('dbelo.storage.media', 'public');
    }

    /** The public URL of an uploaded mark, or null when none was set. */
    public static function url(string $field): ?string
    {
        $path = self::text($field);

        if (blank($path)) {
            return null;
        }

        try {
            return Storage::disk(self::disk())->url($path);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The dark-mode logo, falling back to the light one.
     *
     * A single mark that reads on both backgrounds is the common case and
     * should not require uploading the same file twice.
     */
    public static function logoDark(): ?string
    {
        return self::url('logo_dark') ?? self::url('logo_light');
    }

    /* ═══════════════════════════ Colour ═══════════════════════════ */

    /**
     * The palette override, or nothing at all.
     *
     * Emitted ONLY for colours somebody actually changed. With nothing
     * stored this returns an empty string and resources/css/app.css stays
     * the single source of truth for the palette — which is what keeps the
     * default in one place instead of two that can disagree.
     */
    public static function styleTag(): HtmlString
    {
        $rules = [];

        if ($brand = self::hex('brand_color')) {
            $rules[] = "--color-brand:{$brand}";

            /*
             * The brand shadows carry the colour too — app.css writes them
             * as rgb(163 46 183 / …), which is the same purple spelled a
             * second way. Recomputing them here is what stops a repainted
             * brand from leaving its own glow behind in the old colour on
             * every primary card and button.
             */
            if ($rgb = self::rgb($brand)) {
                $rules[] = "--shadow-brand:0 8px 22px -8px rgb({$rgb} / 0.65)";
                $rules[] = "--shadow-brand-lg:0 14px 32px -10px rgb({$rgb} / 0.85)";
            }
        }

        if ($action = self::hex('action_color')) {
            $rules[] = "--color-action:{$action}";
        }

        if ($rules === []) {
            return new HtmlString('');
        }

        return new HtmlString('<style>:root{'.implode(';', $rules).'}</style>');
    }

    /**
     * A stored colour, or null.
     *
     * Validated on the way OUT as well as on the way in. This string is
     * printed inside a <style> block, so anything that is not six hex digits
     * is a stranger in a CSS context — and the cost of being wrong there is
     * a stylesheet somebody else wrote running on your site.
     */
    public static function hex(string $field): ?string
    {
        $value = trim(self::text($field));

        return preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : null;
    }

    /** "#a32eb7" → "163 46 183", the space-separated form modern CSS wants. */
    private static function rgb(string $hex): ?string
    {
        if (! preg_match('/^#([0-9a-f]{6})$/i', $hex, $m)) {
            return null;
        }

        [$r, $g, $b] = sscanf($m[1], '%2x%2x%2x');

        return "{$r} {$g} {$b}";
    }
}
