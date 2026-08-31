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

    'mail' => [
        'marketing_from' => env('DBELO_MARKETING_FROM', 'news@dbelo.com'),
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
