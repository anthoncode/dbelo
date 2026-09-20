<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | PayPal
    |--------------------------------------------------------------------------
    |
    | WHY THESE LIVE IN .env AND NOT IN THE settings TABLE.
    |
    | Google OAuth and the captcha keys are stored encrypted in `settings`
    | (see App\Support\Security) so they can be changed from Admin without a
    | deploy. PayPal deliberately does not follow that convention, for three
    | reasons:
    |
    |  1. Admin → Backups produces a downloadable database dump. A Google
    |     client secret in that file is bad; a live payment secret in that
    |     file is somebody taking money in dbelo's name. The blast radius is
    |     not the same, so the storage should not be the same.
    |
    |  2. Encrypted settings are encrypted with APP_KEY. Rotating APP_KEY
    |     makes them unreadable, and the moment that hurts most is mid-month
    |     with live subscriptions renewing against a gateway the app can no
    |     longer authenticate to.
    |
    |  3. Sandbox and live are different credentials, and .env is already the
    |     thing that differs between the laptop and the server. Putting the
    |     mode in the database means the local copy of a production dump
    |     would come up pointed at live.
    |
    | The trade-off accepted: changing these needs file access and an
    | `optimize:clear`, not a form. For a credential that changes roughly
    | never, that is the correct direction to be inconvenient in.
    |
    */

    'paypal' => [

        // Anything that is not exactly 'live' is treated as sandbox by
        // PayPalClient. A typo must never be the reason real cards get
        // charged — see PayPalClient::mode().
        'mode' => env('PAYPAL_MODE', 'sandbox'),

        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),

        // Given by PayPal when the webhook is registered, and required to
        // verify that an incoming webhook really came from PayPal. Without
        // it, anybody who learns the URL can grant themselves Pro.
        'webhook_id' => env('PAYPAL_WEBHOOK_ID'),

        // Shown on PayPal's own checkout screen. If it does not match what
        // the buyer saw a second earlier, they abandon.
        'brand_name' => env('PAYPAL_BRAND_NAME', 'dbelo'),

        // Plans store cents; PayPal wants a currency code alongside. Changing
        // this after the first subscription exists is a migration, not a
        // setting: PayPal plans are created in one currency and stay in it.
        'currency' => env('PAYPAL_CURRENCY', 'USD'),

        // Seconds. PayPal is normally fast; a gateway that is slow is a
        // gateway that is down, and a request hanging for 60s holds a PHP
        // worker hostage during checkout.
        'timeout' => (int) env('PAYPAL_TIMEOUT', 30),
        'connect_timeout' => (int) env('PAYPAL_CONNECT_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Metadata suggestions
    |--------------------------------------------------------------------------
    |
    | Two drivers, one switch. Both are written and both stay written, because
    | the only honest way to choose between them for THIS catalogue is to run
    | each over the same twenty real filenames and read the tags side by side.
    | A provider that has to be implemented before it can be compared never
    | gets compared.
    |
    | WHAT IS HERE AND WHAT IS NOT. This file holds the two keys' .env
    | fallback and the timeout, and nothing else. Which provider answers and
    | which model it uses are preferences, they live in config/dbelo.php, and
    | they are edited from Admin → Settings → Suggestions. Nothing is defined
    | in both files on purpose: two definitions of one value drift, and the
    | drift is silent.
    |
    | UNLIKE PAYPAL, the keys can ALSO be stored — encrypted — in the settings
    | table, and a stored one wins over what is here. That looks like an
    | inconsistency and is a deliberate one: a leaked model key spends a quota
    | and is revoked from a console in seconds, where a leaked payment secret
    | is somebody taking money in dbelo's name. App\Support\Suggestions has the
    | full argument.
    |
    | A NOTE ON THE FREE TIERS, because they are not the same offer:
    |
    |   gemini  free without a card. Google may use what is sent to improve
    |           their products.
    |   openai  no signup credits any more. Free daily tokens exist through an
    |           opt-in data-sharing programme — prompts and answers used for
    |           training — and a card is still required on the account.
    |
    | Everything dbelo sends is a filename and a duration for a sound that
    | will be public anyway. Nothing private goes through either.
    |
    */

    'ai' => [

        'gemini' => [
            'key' => env('GEMINI_API_KEY'),
        ],

        'openai' => [
            'key' => env('OPENAI_API_KEY'),
        ],

        // Seconds. Generous, because this runs in a queue where nobody is
        // waiting — but not unbounded, because a hung request holds a worker
        // that has forty-nine other sounds to get through.
        'timeout' => (int) env('AI_TIMEOUT', 45),
    ],

];
