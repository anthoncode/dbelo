<?php

namespace App\Support;

/**
 * Can a machine somewhere on the internet actually open this address?
 *
 * ── WHY THIS IS A CLASS AND NOT AN `if` ──────────────────────────────────
 *
 * This question is asked from two places that must never disagree:
 *
 *   SyncPayPalPlansCommand   before sending home_url to PayPal
 *   CheckoutController       before handing PayPal a return_url
 *
 * It started life as a private method on the first of those, and the second
 * surface was found the hard way: the sync was fixed, the plans were created,
 * the button lit up — and then PayPal's approval screen simply hung, because
 * the return_url it had been given was `http://dbelo.test/checkout/return`.
 * The same defect, in a second place, discovered an hour later.
 *
 * One definition, every surface. A copy of this logic anywhere else is a bug
 * waiting for its own hour.
 *
 * ── WHAT IT DOES NOT DO ──────────────────────────────────────────────────
 *
 * It makes no network call. This is a judgement about the SHAPE of an
 * address, not a liveness check: a firewalled public host is still a public
 * host, and a DNS lookup inside a checkout would add latency and a new way
 * to fail. False negatives are deliberate — `dbelo.test` is not "probably
 * unreachable", it is guaranteed never to resolve.
 */
class PublicUrl
{
    /**
     * Reserved by RFC 2606 and RFC 6761.
     *
     * These are not merely unusual suffixes. They are set aside so they can
     * never exist in the public DNS, which is exactly why Herd, Valet,
     * Docker and friends hand them out for local work. `.home` and `.lan`
     * are not formally reserved but are router conventions with the same
     * practical effect.
     */
    public const RESERVED_SUFFIXES = [
        'test', 'local', 'localhost', 'invalid', 'example', 'internal', 'home', 'lan',
    ];

    /**
     * True when an outside server could plausibly reach this address.
     */
    public static function reachable(?string $url): bool
    {
        return self::orNull($url) !== null;
    }

    /**
     * The address, trimmed of a trailing slash — or null when nothing
     * outside this machine could reach it.
     */
    public static function orNull(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);

        // parse_url returns false on something malformed enough to be
        // unparseable, and an array with no host on a bare path.
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        /*
         * An IP address or a single-label host is reachable from somewhere —
         * just never from a payment provider. 127.0.0.1 and `localhost` are
         * the obvious cases; a bare `myserver` is the one that bites inside
         * a container.
         *
         * Public IPs are refused too, and that is a choice: a provider that
         * needs to show a buyer where they are paying should be given a
         * name, not four numbers.
         */
        if (filter_var($host, FILTER_VALIDATE_IP) || ! str_contains($host, '.')) {
            return null;
        }

        $labels = explode('.', $host);

        if (in_array(end($labels), self::RESERVED_SUFFIXES, true)) {
            return null;
        }

        return rtrim($url, '/');
    }

    /**
     * Why an address was refused, in words an operator can act on.
     *
     * Returns null when there is nothing wrong with it. Used in log lines
     * and, with APP_DEBUG on, shown on screen — because in local development
     * the operator and the person staring at the hung page are the same
     * person, and sending them to the log file is how an afternoon goes.
     */
    public static function problem(?string $url): ?string
    {
        if (self::reachable($url)) {
            return null;
        }

        $url = trim((string) $url);

        if ($url === '') {
            return 'the address is empty';
        }

        $parts = parse_url($url);
        $parts = is_array($parts) ? $parts : [];

        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $labels = explode('.', $host);
        $suffix = (string) end($labels);

        return match (true) {
            $host === '' => 'it has no host name',
            ! in_array($scheme, ['http', 'https'], true) => "its scheme is '{$scheme}', not http or https",
            (bool) filter_var($host, FILTER_VALIDATE_IP) => "{$host} is an IP address, not a name",
            ! str_contains($host, '.') => "'{$host}' only resolves on this machine",
            default => "'.{$suffix}' is a reserved suffix that never resolves on the public internet",
        };
    }
}
