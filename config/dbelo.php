<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Legal identity
    |--------------------------------------------------------------------------
    |
    | Every legal document reads from here, so the operating entity and the
    | governing law are filled in once instead of hunted across four pages.
    |
    | Set these in .env before publishing. The placeholders are deliberately
    | ugly so an unfinished document is impossible to miss.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Storage roles
    |--------------------------------------------------------------------------
    |
    | Which disk each kind of file lives on. Named by ROLE, not by driver,
    | so moving to Cloudflare R2 is three lines in .env instead of a search
    | through the codebase.
    |
    |   master   the original upload. Private, never served directly.
    |   download the MP3 users receive. Private, served through the quota gate.
    |   preview  the low quality MP3 for the player. Public and cacheable.
    |
    */

    'storage' => [
        'master' => env('DBELO_DISK_MASTER', 'sounds_private'),
        'download' => env('DBELO_DISK_DOWNLOAD', 'sounds_private'),
        'preview' => env('DBELO_DISK_PREVIEW', 'public'),

        // Images from the editor: covers and anything inserted in a post.
        // Public by definition — they are meant to be hotlinked by browsers.
        'media' => env('DBELO_DISK_MEDIA', 'public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    |
    | Where the day starts for everything the admin reads. Timestamps stay in
    | UTC in the database, which is correct; this is only about which day a
    | given moment belongs to when it is counted.
    |
    | "Downloads today" has to mean the day the operator is living in — a
    | counter that resets in the middle of the afternoon agrees with nothing.
    |
    */

    'timezone' => env('DBELO_TIMEZONE', 'America/La_Paz'),

    /*
    |--------------------------------------------------------------------------
    | Identity, and the door
    |--------------------------------------------------------------------------
    |
    | Defaults for Admin → Settings → General. As everywhere else in this
    | file, the table holds only what somebody changed, so these are what a
    | fresh install runs on and what an emptied field falls back to.
    |
    | Deliberately absent, and not by oversight:
    |
    |   site.url       APP_URL already builds every link and signs every
    |                  download. A second copy here would not replace it, it
    |                  would disagree with it — silently.
    |   site.keywords  Ignored by every search engine since 2009. The useful
    |                  half of the idea is the synonyms list under Search.
    |   site.author    Lands in a meta tag nothing reads. Authorship that
    |                  matters is stored per sound and per post.
    |
    */

    'site' => [
        'name' => env('APP_NAME', 'dbelo'),

        /* Used only by pages that have no description of their own. */
        'description' => 'Sound effects for video, games and podcasts. Free to browse, licensed to use.',

        'footer' => 'A growing library of sound effects, catalogued and licensed so you can use them without reading the small print twice.',

        /*
         * Internal. Where a failed backup, a new error group or a blocked
         * address gets reported once alerts are switched on. Never rendered
         * on the site — the public address is legal.support_email.
         */
        'admin_email' => env('DBELO_ADMIN_EMAIL', 'admin@dbelo.com'),

        /*
         * live · soon · maintenance — see App\Support\SiteStatus.
         *
         * The default is `live` on purpose. A default of `soon` would mean a
         * fresh clone comes up closed, and the first five minutes of the
         * project would be spent working out why the site is a placeholder.
         */
        'status' => 'live',

        'status_message' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom code and verification
    |--------------------------------------------------------------------------
    |
    | Defaults for Admin → Settings → Code & tracking. All empty: a fresh
    | clone must not carry a stranger's script.
    |
    | Kept apart from the `ads` block above even though both hold third-party
    | JavaScript. They are switched, and switched off, independently —
    | turning advertising off must not take the analytics with it.
    |
    */

    'code' => [
        'verify' => [
            'google' => null,
            'bing' => null,
        ],

        'head' => null,
        'body_start' => null,
        'body_end' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Advertising
    |--------------------------------------------------------------------------
    |
    | Defaults for Admin → Settings → Ads. The placement rules and the
    | never-list live in App\Support\Ads.
    |
    | OFF, and in test mode. A fresh clone must not be capable of loading a
    | third party's JavaScript because somebody pasted a tag and forgot which
    | switch was which — and the first thing anybody wants to see is the hole
    | an ad would leave in the page, not the ad.
    |
    | The heights are the standard leaderboard and rectangle. They are
    | reserved BEFORE the ad arrives: an unreserved slot pushes the page down
    | when it fills, which is layout shift, which the SEO screen reports and
    | Google counts.
    |
    */

    'ads' => [
        'enabled' => false,
        'loader' => null,
        'test_mode' => true,
        'hide_for_subscribers' => true,

        'sound' => [
            'enabled' => true,
            'code' => null,
            'height' => 280,
        ],

        'catalog' => [
            'enabled' => false,
            'code' => null,
            /* Deep enough that the catalogue is seen before the advertising. */
            'after' => 8,
            'height' => 250,
        ],

        'blog' => [
            'enabled' => true,
            'code' => null,
            'height' => 280,
        ],

        'ads_txt' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Downloads
    |--------------------------------------------------------------------------
    |
    | Defaults for Admin → Settings → Downloads. The reading lives in
    | App\Support\Downloads.
    |
    | ONE DECISION ONLY, and it is deliberately small. How many files a
    | visitor gets before we ask who they are is a judgement about
    | conversion that changes with the catalogue; everything else that
    | sounds like it belongs here does not:
    |
    |   Daily limits per plan live on the plans table, because that is
    |   what a plan IS. A second copy here would disagree with it.
    |
    |   The re-download grace period is Downloads::REGRAB_DAYS — a bug fix
    |   with one right answer, not a preference.
    |
    |   Stopping a script is the rate limiter and BlockIps. A number on
    |   this screen would look like it did that job and would not.
    |
    | 'enabled' => false restores the old behaviour exactly: an account
    | before a single file.
    */
    'downloads' => [
        'guest' => [
            'enabled' => true,

            /*
             * Three, because one sound convinces nobody — a person judging
             * a sound library needs to hear how two or three sit together
             * — and because a catalogue that is usable without an account
             * never gets one created.
             */
            'limit' => 3,

            /* Empty uses the wording in Downloads::prompt(). */
            'message' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Appearance
    |--------------------------------------------------------------------------
    |
    | Defaults for Admin → Settings → Appearance.
    |
    | The marks are all null: with nothing uploaded the site falls back to
    | the built-in wordmark and the files in public/, which is what a fresh
    | clone has to look finished with.
    |
    | THE TWO COLOURS MIRROR resources/css/app.css, and that file is the real
    | source of truth. They are repeated here only so the colour picker has
    | somewhere to start and can print "Built in: #a32eb7" — nothing reads
    | them to paint anything. Appearance::styleTag() emits an override ONLY
    | for a colour somebody actually changed, so an untouched palette is
    | still defined in exactly one place.
    |
    */

    'appearance' => [
        'logo_light' => null,
        'logo_dark' => null,
        'favicon' => null,
        'social_image' => null,

        'brand_color' => '#a32eb7',
        'action_color' => '#f9510f',
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | Defaults for Admin → Settings → Security. The field list and the
    | reading live in App\Support\Security.
    |
    | EVERYTHING OFF, and no keys. A captcha default of "on" with no keys
    | would mean a fresh clone rejects every sign-up, and the reason would be
    | invisible. The switches below matter only once a provider and its two
    | keys are filled in.
    |
    | The two secrets are NOT here and never will be. They are stored
    | encrypted in the settings table; a client secret in a file that is
    | committed to git is a secret you have published.
    |
    */

    'security' => [

        'captcha' => [
            'provider' => 'off',
            'site_key' => null,
            'score' => '0.5',

            /*
             * The forms default to protected, which sounds contradictory
             * next to provider => off. It is not: with no provider nothing
             * is checked, so these say what SHOULD be protected the moment
             * one is configured. Otherwise switching the provider on would
             * silently protect nothing.
             */
            'register' => true,
            'password' => true,
            'contact' => true,

            /*
             * Failures before login asks for a captcha. Five matches the
             * Fortify login throttle already in FortifyServiceProvider, so
             * the two defences arrive together rather than one of them
             * quietly never firing.
             */
            'login_after' => 5,
        ],

        'google' => [
            'enabled' => false,
            'client_id' => null,
        ],

        'accounts' => [
            /*
             * Requiring a verified email to download probably stops more
             * bots than the captcha: a throwaway signup is free, a working
             * inbox is not. Browsing and listening stay open — only the
             * download is gated, so nobody is asked to prove anything before
             * they know whether the site is worth it.
             */
            'verify_email' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | The landing page
    |--------------------------------------------------------------------------
    |
    | Defaults for Admin → Settings → Homepage. The field list, the labels
    | and the reading are all in App\Support\Homepage; this is only what the
    | page says before anybody has changed anything.
    |
    | An ASTERISK PAIR marks the highlighted word — "your *story* needs"
    | becomes the brand-coloured span. The table never holds HTML; see
    | App\Support\Copy for why that matters more than it looks.
    |
    | A NEWLINE in a headline becomes a line break on the page.
    |
    */

    'home' => [

        /*
         * The strip above the navigation.
         *
         * Off, and with no message, by default. A promotional banner that
         * ships switched on is a banner announcing something nobody wrote.
         */
        'bar' => [
            'enabled' => false,
            'message' => null,
            'button' => null,
            'link' => null,
            'tone' => 'brand',

            /*
             * The landing page, not every page.
             *
             * A banner on every screen of a catalogue is furniture within
             * two clicks — read once, stepped over afterwards. On the
             * landing page it is read, because that is the page a visitor
             * arrives on with nothing else in mind yet. Widen it per
             * message, from the panel, when the message earns it.
             */
            'scope' => 'home',
            'audience' => 'everyone',
            'dismissible' => true,
            'until' => null,
        ],

        'hero' => [
            'badge' => true,
            'title' => "Every sound your\n*story* needs",
            'subtitle' => 'Studio-grade sound effects, cleared for commercial use. Listen to everything *free*.',
            'placeholder' => 'door creak, thunder, laser, footsteps on gravel…',
        ],

        'newest' => [
            'enabled' => true,
            'limit' => 5,
            'eyebrow' => 'Newest',
            'title' => 'Press play. That is the whole demo.',
            'lead' => 'Nothing here is a preview clip. Every sound plays end to end, for free, without an account.',
        ],

        'categories' => [
            'enabled' => true,
            'limit' => 6,
            'eyebrow' => 'Categories',
            'title' => 'Browse by what the sound *is*',
            'lead' => 'The six our visitors reach for most. Everything else is one click further in.',
        ],

        'packs' => [
            'enabled' => true,
            'limit' => 4,
            'eyebrow' => 'Packs',
            'title' => 'Or by what you are *making*',
            'lead' => 'Sets pulled from across the catalogue for one job. A podcast intro needs a sting, a room tone and a click — three categories, one afternoon of work.',
        ],

        'plans' => [
            'enabled' => true,
            'eyebrow' => 'Plans',
            'title' => 'Listen free. *Download* without limits.',
        ],

        'cta' => [
            'enabled' => true,
            'title' => 'Start with a *free* account',
            'lead' => 'Five downloads a day, no card, no trial clock. Upgrade the day you need more, not before.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail
    |--------------------------------------------------------------------------
    |
    | Marketing leaves from a different address than password resets and
    | verification links. If a run of offers collects spam complaints, the
    | reputation damage lands on this sender — and the emails people actually
    | need keep arriving.
    |
    | Once dbelo.com is live this should be a subdomain of its own
    | (news@mail.dbelo.com) with its own SPF and DKIM records, which
    | insulates the root domain completely.
    |
    */


    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | Defaults only. What the admin chooses in Admin → Backups is stored in
    | the settings table and wins over these; the table holds only what was
    | changed, so these still apply to everything nobody has touched.
    |
    */

    'backup' => [

        /*
         * daily · weekly · monthly · off
         *
         * Read together with `keep`, this decides HOW FAR BACK you can go —
         * which is the number that actually matters. Three daily copies is
         * three days; the case that hurts is corrupting data on Friday and
         * noticing on Tuesday, by which point the good copy is gone.
         */
        'frequency' => 'daily',

        /* The hour it runs, 0–23, in the app timezone. */
        'hour' => 2,

        /*
         * How many are kept. The newest push the oldest out.
         *
         * Configurable rather than fixed at three, because the day somebody
         * needs a fourth is the day they find out three was not enough, and
         * that should be a number to change rather than a deployment.
         */
        'keep' => 3,
    ],

    'mail' => [
        'marketing_from' => env('DBELO_MARKETING_FROM', 'news@dbelo.com'),

        /*
         * Defaults for Admin → Settings → Email & SMTP. The field list and
         * the reading live in App\Support\Email.
         *
         * `transport` is empty rather than 'log': empty means "nobody has
         * chosen, leave .env in charge", which is what a fresh clone needs.
         * A default of 'log' here would silently override MAIL_MAILER for
         * anyone who had set it properly in .env.
         *
         * NO PASSWORD AND NO API KEY. Both are secrets, stored encrypted in
         * the settings table. A credential in a file that is committed to
         * git is a credential you have published.
         */
        'transport' => null,
        'from_address' => env('MAIL_FROM_ADDRESS'),
        'from_name' => null,

        'smtp' => [
            'host' => null,
            'port' => 587,
            'encryption' => 'tls',
            'username' => null,
        ],

        'resend' => [],

        'alerts' => [
            'backup' => true,
            'claim' => true,
        ],
    ],

    'legal' => [
        'entity' => env('DBELO_LEGAL_ENTITY', '[LEGAL ENTITY NAME]'),
        'jurisdiction' => env('DBELO_JURISDICTION', '[COUNTRY]'),
        'address' => env('DBELO_ADDRESS', '[REGISTERED ADDRESS]'),
        'email' => env('DBELO_LEGAL_EMAIL', 'legal@dbelo.com'),
        'support_email' => env('DBELO_SUPPORT_EMAIL', 'support@dbelo.com'),
        'privacy_email' => env('DBELO_PRIVACY_EMAIL', 'privacy@dbelo.com'),
        'effective_date' => env('DBELO_LEGAL_DATE', '2026-08-18'),
    ],
];
