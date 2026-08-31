<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Every date in the Analytics screen goes through here.
 *
 * The database stores timestamps in UTC, which is correct and should stay
 * that way. But "downloads today" has to mean the day the person reading the
 * screen is living in — otherwise the number resets in the middle of their
 * afternoon and nothing on the page agrees with their intuition.
 *
 * One place decides where the day starts, so the KPI row, the charts and the
 * rollup can never drift apart.
 */
class Clock
{
    public static function timezone(): string
    {
        return config('dbelo.timezone', config('app.timezone', 'UTC'));
    }

    /** Now, in the site's timezone. */
    public static function now(): CarbonInterface
    {
        return now()->setTimezone(self::timezone());
    }

    /** Today's date as Y-m-d, locally. The key used all over stats_daily. */
    public static function day(): string
    {
        return self::now()->toDateString();
    }

    /**
     * Local midnight, expressed in UTC, ready for a where clause.
     *
     * This conversion is the whole reason this class exists: comparing a
     * local date against a UTC column without it silently shifts every
     * bucket by the offset.
     */
    public static function startOfDayUtc(int $daysAgo = 0): CarbonInterface
    {
        return self::now()->subDays($daysAgo)->startOfDay()->utc();
    }

    public static function endOfDayUtc(int $daysAgo = 0): CarbonInterface
    {
        return self::now()->subDays($daysAgo)->endOfDay()->utc();
    }

    /**
     * The SQL fragment that turns a UTC column into a local date.
     *
     * MySQL needs the offset spelled out rather than a zone name, because
     * the named-timezone tables are not loaded on a default install and the
     * conversion would silently return NULL.
     */
    public static function localDateSql(string $column): string
    {
        $offset = self::now()->format('P');   // -04:00

        return "DATE(CONVERT_TZ({$column}, '+00:00', '{$offset}'))";
    }
}
