<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The site's social profiles, as the operator entered them.
 *
 * ── WHY A LIST AND NOT ONE FIELD PER NETWORK ─────────────────────────────
 *
 * Fixed fields are simpler to build and wrong within a year. The set of
 * places worth having a profile changes — Bluesky did not exist, Vine did,
 * and whatever replaces TikTok is not named yet — and a fixed set means
 * every one of those is a migration and a deploy.
 *
 * So the row is what is stored, and the operator adds and removes rows.
 *
 * ── BUT THE PLATFORM IS STILL CHOSEN FROM A LIST ─────────────────────────
 *
 * A free-text "network name" would mean asking somebody to pick an icon,
 * and an icon picker is a worse screen than the one it saves. PLATFORMS is
 * a catalogue: choose Facebook and the right brand mark appears. Anything
 * not in it is `link`, which draws a generic chain and takes the label the
 * operator types — so nothing is blocked, it just looks generic until the
 * platform earns an entry here.
 *
 * ── STORED AS ONE JSON STRING ────────────────────────────────────────────
 *
 * settings is a key/value store of strings, which is exactly enough. One
 * row, one key, read through the same cache as everything else in there —
 * a table of its own would be a join on every page view for a list of five
 * links that changes twice a year.
 */
class Social
{
    public const KEY = 'social.links';

    /** Nobody has more than this, and a footer with more is not a footer. */
    public const MAX = 10;

    /**
     * slug => [label, Font Awesome brand name].
     *
     * The icon name is the Font Awesome BRAND name and is drawn with
     * style="brands". Adding a platform is one line here and nothing else.
     */
    public const PLATFORMS = [
        'facebook' => ['label' => 'Facebook', 'icon' => 'facebook'],
        'x' => ['label' => 'X', 'icon' => 'x-twitter'],
        'instagram' => ['label' => 'Instagram', 'icon' => 'instagram'],
        'youtube' => ['label' => 'YouTube', 'icon' => 'youtube'],
        'tiktok' => ['label' => 'TikTok', 'icon' => 'tiktok'],
        'bluesky' => ['label' => 'Bluesky', 'icon' => 'bluesky'],
        'threads' => ['label' => 'Threads', 'icon' => 'threads'],
        'linkedin' => ['label' => 'LinkedIn', 'icon' => 'linkedin'],
        'discord' => ['label' => 'Discord', 'icon' => 'discord'],
        'github' => ['label' => 'GitHub', 'icon' => 'github'],
        'soundcloud' => ['label' => 'SoundCloud', 'icon' => 'soundcloud'],
        'spotify' => ['label' => 'Spotify', 'icon' => 'spotify'],
        'patreon' => ['label' => 'Patreon', 'icon' => 'patreon'],
        'reddit' => ['label' => 'Reddit', 'icon' => 'reddit'],
        'telegram' => ['label' => 'Telegram', 'icon' => 'telegram'],
        // The escape hatch. Not a brand icon and not drawn with style
        // "brands" — see icon() below.
        'link' => ['label' => 'Other', 'icon' => 'link'],
    ];

    /**
     * Decoded and cleaned once per request.
     *
     * The footer asks twice on every page — any(), then links() — and the
     * home page asks a third time for sameAs. None of that is expensive, but
     * it is three json_decodes and three passes of validation for a list of
     * five links that cannot change mid-request.
     *
     * @var array<int, array{platform: string, label: string, url: string, icon: string, brand: bool}>|null
     */
    private static ?array $memo = null;

    /**
     * The rows to draw, cleaned.
     *
     * @return array<int, array{platform: string, label: string, url: string, icon: string, brand: bool}>
     */
    public static function links(): array
    {
        return self::$memo ??= self::clean(self::stored());
    }

    /** Is there anything to draw at all? The footer asks before it opens a row. */
    public static function any(): bool
    {
        return self::links() !== [];
    }

    /**
     * Just the addresses, for schema.org's sameAs on the home page.
     *
     * sameAs means "these are the same entity, elsewhere" — official
     * profiles only. It is why this is the whole list and not a curated
     * subset: every row here IS an official profile, or it should not have
     * been entered.
     *
     * @return array<int, string>
     */
    public static function sameAs(): array
    {
        return array_values(array_map(fn ($row) => $row['url'], self::links()));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function save(array $rows): void
    {
        $clean = self::clean($rows);

        // The memo is a per-request cache and this is the one thing in a
        // request that can invalidate it. Without this line the admin screen
        // would re-read its own list from before the save it just made.
        self::$memo = null;

        Setting::put(self::KEY, json_encode(
            array_map(fn ($row) => [
                'platform' => $row['platform'],
                'label' => $row['label'],
                'url' => $row['url'],
            ], $clean),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ), 'general');
    }

    /* ═══════════════════════ Trusting nothing ═══════════════════════ */

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function stored(): array
    {
        $raw = Setting::read(self::KEY, []);

        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array{platform: string, label: string, url: string, icon: string, brand: bool}>
     */
    private static function clean(array $rows): array
    {
        $out = [];
        $seen = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $url = is_string($row['url'] ?? null) ? trim($row['url']) : '';

            /*
             * http and https only, and the scheme has to be there.
             *
             * A row saved as "facebook.com/dbelo" would render as a link
             * relative to the current page — /sounds/facebook.com/dbelo —
             * which 404s from every page except the home one. And rejecting
             * anything that is not http keeps javascript: out of an
             * attribute that lands on every page of the site.
             */
            if ($url === '' || ! preg_match('~^https?://~i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                continue;
            }

            $platform = is_string($row['platform'] ?? null) ? $row['platform'] : 'link';

            if (! array_key_exists($platform, self::PLATFORMS)) {
                $platform = 'link';
            }

            // One profile per platform, except the generic one — somebody may
            // reasonably have two "other" links and only one Facebook.
            if ($platform !== 'link') {
                if (isset($seen[$platform])) {
                    continue;
                }

                $seen[$platform] = true;
            }

            $label = is_string($row['label'] ?? null) ? trim($row['label']) : '';

            $out[] = [
                'platform' => $platform,
                // The catalogue's name wins for a known platform: "Facebook"
                // is what a screen reader should say, whatever was typed.
                'label' => $platform === 'link'
                    ? ($label !== '' ? mb_substr($label, 0, 40) : 'Link')
                    : self::PLATFORMS[$platform]['label'],
                'url' => mb_substr($url, 0, 300),
                'icon' => self::PLATFORMS[$platform]['icon'],
                'brand' => $platform !== 'link',
            ];

            if (count($out) === self::MAX) {
                break;
            }
        }

        return $out;
    }
}
