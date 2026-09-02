<?php

namespace App\Support;

use App\Support\Concerns\SettingFields;

/**
 * The configuring half of Security. The watching half is the Access log and
 * Blocks & abuse screens, and the split is deliberate: looking at what
 * happened and deciding what should happen are different activities, done at
 * different moments, and a screen that tries to be both is good at neither.
 *
 * Same shape as App\Support\Homepage — one map that the settings screen
 * renders from and the application reads through, so a field cannot exist
 * without a reader.
 *
 * TWO OF THESE ARE SECRETS. They are stored encrypted, they are never
 * returned to the browser, and the reasoning is in the `secret` type: a
 * Livewire public property is serialised into the page, so a secret bound to
 * one is a secret in the DOM, in the HTML source and in every screenshot the
 * operator takes of the screen.
 */
class Security
{
    use SettingFields;

    /**
     * @var array<string, array{key: string, type: string, label: string, help: string}>
     */
    public const FIELDS = [

        /* ═══════════════════════════ Captcha ═══════════════════════════ */

        'captcha_provider' => [
            'key' => 'security.captcha.provider',
            'type' => 'select',
            'label' => 'Provider',
            'help' => 'Off means no captcha anywhere, whatever the switches below say — one place to pull the plug if it ever locks people out.',
            'options' => [
                'off' => 'Off',
                'recaptcha_v2' => 'reCAPTCHA v2 — the "I am not a robot" box',
                'recaptcha_v3' => 'reCAPTCHA v3 — invisible, scores each visit',
                'turnstile' => 'Cloudflare Turnstile — invisible, no cookies',
            ],
        ],
        'captcha_site_key' => [
            'key' => 'security.captcha.site_key',
            'type' => 'text',
            'label' => 'Site key',
            'help' => 'Public. It is printed into the page, so it is not a secret and does not need hiding.',
        ],
        'captcha_secret' => [
            'key' => 'security.captcha.secret',
            'type' => 'secret',
            'label' => 'Secret key',
            'help' => 'Encrypted in the database and never sent back to this screen. To change it, type a new one.',
        ],
        'captcha_score' => [
            'key' => 'security.captcha.score',
            'type' => 'select',
            'label' => 'v3 score threshold',
            'help' => 'reCAPTCHA v3 only. It returns 0.0 (almost certainly a bot) to 1.0 (almost certainly a person) and you decide where to cut. Google ships 0.5 and says to tune it — too high turns away real people who will never tell you.',
            'options' => [
                '0.3' => '0.3 — lenient, few false rejections',
                '0.5' => '0.5 — Google\'s default',
                '0.7' => '0.7 — strict, expect complaints',
            ],
        ],
        'captcha_register' => [
            'key' => 'security.captcha.register',
            'type' => 'bool',
            'label' => 'On the sign-up form',
            'help' => 'The one that matters. A bot account is a bot with a free download quota.',
            'default_on' => true,
        ],
        'captcha_login_after' => [
            'key' => 'security.captcha.login_after',
            'type' => 'number',
            'label' => 'On login, after N failures',
            'help' => 'Not on every login. A captcha at every sign-in is friction paid by all your real users to stop something the rate limiter already stops; after a few failures it is aimed at the machine that earned it. 0 turns it off.',
        ],
        'captcha_password' => [
            'key' => 'security.captcha.password',
            'type' => 'bool',
            'label' => 'On the password reset form',
            'help' => 'Stops a script asking for reset emails to hundreds of addresses in your name.',
            'default_on' => true,
        ],
        'captcha_contact' => [
            'key' => 'security.captcha.contact',
            'type' => 'bool',
            'label' => 'On the copyright complaint form',
            'help' => 'Public and unauthenticated, which is exactly the shape a spam bot looks for.',
            'default_on' => true,
        ],

        /* ═════════════════════════ Google sign-in ═════════════════════════ */

        'google_on' => [
            'key' => 'security.google.enabled',
            'type' => 'bool',
            'label' => 'Allow signing in with Google',
            'help' => 'Adds the button to the login and sign-up pages. With no credentials filled in, it stays hidden whatever this says.',
        ],
        'google_client_id' => [
            'key' => 'security.google.client_id',
            'type' => 'text',
            'label' => 'Client ID',
            'help' => 'From Google Cloud Console → Credentials → OAuth 2.0 Client IDs.',
        ],
        'google_client_secret' => [
            'key' => 'security.google.client_secret',
            'type' => 'secret',
            'label' => 'Client secret',
            'help' => 'Encrypted in the database and never sent back to this screen.',
        ],

        /* ═══════════════════════════ Accounts ═══════════════════════════ */

        'verify_email' => [
            'key' => 'security.accounts.verify_email',
            'type' => 'bool',
            'label' => 'Require a verified email to download',
            'help' => 'Probably stops more bots than the captcha does: a throwaway signup is free, a working inbox is not. People can still browse and listen without verifying — only the download is gated.',
            'default_on' => true,
        ],
    ];

    /**
     * @var array<string, array{label: string, note: string, fields: array<int, string>}>
     */
    public const SECTIONS = [
        'captcha' => [
            'label' => 'Captcha',
            'note' => 'Which forms a machine has to prove itself on. The provider comes first because Off there overrides everything under it.',
            'fields' => ['captcha_provider', 'captcha_site_key', 'captcha_secret', 'captcha_score',
                'captcha_register', 'captcha_login_after', 'captcha_password', 'captcha_contact'],
        ],
        'google' => [
            'label' => 'Sign in with Google',
            'note' => 'One less password for the visitor, and one less password for you to protect.',
            'fields' => ['google_on', 'google_client_id', 'google_client_secret'],
        ],
        'accounts' => [
            'label' => 'Accounts',
            'note' => '',
            'fields' => ['verify_email'],
        ],
    ];

    /* ═══════════════════════════ Google ═══════════════════════════ */

    /**
     * Is the button worth rendering?
     *
     * The switch alone is not enough. An enabled provider with no
     * credentials sends the visitor to Google and back to an error, and the
     * error is ours — so the button only exists once it can work.
     */
    public static function googleReady(): bool
    {
        return self::flag('google_on')
            && filled(self::text('google_client_id'))
            && self::hasSecret('google_client_secret');
    }

    /**
     * The callback address, DERIVED and never stored.
     *
     * A second copy in the settings table would be a second definition of
     * something APP_URL already decides — the same silent-drift trap that
     * kept site_url out of General. It is shown on the screen only so it can
     * be copied into Google Cloud Console, which is the one place it has to
     * be typed by hand.
     */
    public static function googleCallback(): string
    {
        return route('auth.google.callback');
    }
}
