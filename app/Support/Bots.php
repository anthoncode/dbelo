<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Is this request a program rather than a person?
 *
 * ── WHAT THIS IS FOR, AND WHAT IT IS NOT FOR ─────────────────────────────
 *
 * It decides whether something gets COUNTED. It does not decide whether
 * anything gets served.
 *
 * That line is deliberate and it is the whole design. A user agent is a
 * string the client chooses; it is a claim, not an identity. Refusing a
 * download on the strength of one eventually refuses a person with an
 * unusual browser, a screen reader, a corporate proxy that rewrites the
 * header — and the cost of that is somebody who cannot get a file they are
 * entitled to. The cost of serving one extra file to a crawler is
 * bandwidth.
 *
 * Counting is where the real damage is. `downloads_count` orders the
 * categories on the public site, drives "most downloaded", and is every
 * figure on the analytics screen. A crawler that walks the catalogue does
 * not just add noise — it adds noise shaped like popularity, and the
 * catalogue reorganises itself around it.
 *
 * ── WHY THIS EXISTS AT ALL, GIVEN robots.txt ─────────────────────────────
 *
 * robots.txt already asks nobody to fetch /sounds/*&#47;download. 471 rows in
 * the downloads table, every one of them with no account, say what that ask
 * is worth: 98 from Mediapartners-Google alone, which by Google's own
 * documentation ignores the global `*` group entirely.
 *
 * A polite request and a measured number are different things. The request
 * stays in robots.txt because the well-behaved majority honour it and it
 * saves the bandwidth. This is what makes the number true regardless.
 *
 * ── THE LIST IS SHORT AND SELF-DECLARED ──────────────────────────────────
 *
 * Only agents that say what they are. No heuristics about request rate, no
 * IP ranges, no fingerprinting — those produce false positives on real
 * people and are a maintenance job nobody signs up for.
 *
 * Which means a scraper pretending to be Chrome is NOT caught here, and
 * saying so is the point: this trims the honest robots, not the dishonest
 * ones. If the figures still look wrong after this, the remainder is
 * something lying about itself, and that is a different problem with a
 * different answer.
 */
class Bots
{
    /**
     * Fragments that appear in the user agent of something automated.
     *
     * Lowercase, matched as substrings. 'bot' alone catches Googlebot,
     * bingbot, AhrefsBot, SemrushBot and the long tail that follows the
     * convention — which is most of them, because identifying yourself is
     * the convention.
     */
    public const SIGNATURES = [
        'bot',
        'crawl',
        'spider',
        'slurp',

        // AdSense and Ad Manager. Neither contains "bot", and
        // Mediapartners-Google is the single largest source in this
        // catalogue's download table.
        'mediapartners',
        'adsbot',
        'google-adwords',
        'google-read-aloud',
        'googleother',
        'feedfetcher',

        // Model and dataset collectors.
        'gptbot',
        'ccbot',
        'claudebot',
        'anthropic',
        'perplexity',
        'bytespider',

        // Link unfurlers in chat apps. A pasted link in Slack or WhatsApp
        // fetches the URL to build a preview card, and a download URL
        // pasted into a chat would otherwise count once per person who
        // sees the message.
        'facebookexternalhit',
        'whatsapp',
        'telegrambot',
        'slackbot',
        'discordbot',
        'embedly',
        'quora link preview',
        'skypeuripreview',

        // Command-line and library clients. Not malicious, not people.
        'curl/',
        'wget',
        'python-requests',
        'python-urllib',
        'go-http-client',
        'java/',
        'okhttp',
        'axios/',
        'libwww-perl',
        'httpclient',
        'headlesschrome',

        // Uptime and preview services.
        'uptimerobot',
        'pingdom',
        'statuscake',
        'lighthouse',
        'pagespeed',
    ];

    /**
     * Does this user agent declare itself automated?
     *
     * An EMPTY agent counts as automated. Every real browser sends one;
     * something that sends none is a script that did not bother, and
     * counting it as a person is the more expensive mistake of the two.
     */
    public static function looksAutomated(?string $userAgent): bool
    {
        $agent = Str::lower(trim((string) $userAgent));

        if ($agent === '') {
            return true;
        }

        return Str::contains($agent, self::SIGNATURES);
    }
}
