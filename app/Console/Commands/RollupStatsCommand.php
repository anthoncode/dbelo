<?php

namespace App\Console\Commands;

use App\Models\StatDaily;
use App\Support\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Turns the source tables into the daily numbers the dashboard reads.
 *
 * Runs hourly and recomputes the last few days rather than only yesterday:
 * a download recorded at 23:59 lands in a row the 00:00 run would otherwise
 * miss, and a worker that was down for an afternoon needs its gap filled.
 * Every metric here is a full recount for the day, so running it twice is
 * harmless and running it late fixes itself.
 *
 * It deliberately does NOT touch visits, visitors, referrers or paths. Those
 * are counted live by the middleware and have no source table to recount
 * from — recomputing them would set them all to zero.
 */
class RollupStatsCommand extends Command
{
    protected $signature = 'stats:rollup {--days=3 : How many days back to recompute} {--all : Rebuild the whole history}';

    protected $description = 'Recompute the daily analytics counters from the source tables';

    public function handle(): int
    {
        $days = $this->option('all') ? $this->historyLength() : (int) $this->option('days');

        $this->info("Recomputing {$days} day(s)…");

        for ($back = 0; $back < $days; $back++) {
            $day = Clock::now()->subDays($back)->toDateString();

            $this->rollupDay($day, $back);
        }

        // The screen caches its panels; a fresh rollup makes those stale.
        Cache::flush();

        $this->info('Done.');

        return self::SUCCESS;
    }

    protected function rollupDay(string $day, int $back): void
    {
        $from = Clock::startOfDayUtc($back);
        $to = Clock::endOfDayUtc($back);

        // ── Downloads ──
        StatDaily::put($day, 'downloads', '',
            DB::table('downloads')->whereBetween('created_at', [$from, $to])->count());

        // By category. Low cardinality, so a row each is cheap and it powers
        // the breakdown without ever touching the raw table again.
        DB::table('downloads')
            ->join('sounds', 'sounds.id', '=', 'downloads.sound_id')
            ->leftJoin('categories', 'categories.id', '=', 'sounds.category_id')
            ->whereBetween('downloads.created_at', [$from, $to])
            ->groupBy('categories.slug')
            ->get(['categories.slug', DB::raw('COUNT(*) as total')])
            ->each(fn ($row) => StatDaily::put($day, 'downloads.category', $row->slug ?? 'uncategorised', (int) $row->total));

        // Premium versus free, the monetisation signal.
        DB::table('downloads')
            ->join('sounds', 'sounds.id', '=', 'downloads.sound_id')
            ->whereBetween('downloads.created_at', [$from, $to])
            ->groupBy('sounds.is_premium')
            ->get(['sounds.is_premium', DB::raw('COUNT(*) as total')])
            ->each(fn ($row) => StatDaily::put($day, 'downloads.tier',
                $row->is_premium ? 'premium' : 'free', (int) $row->total));

        // ── People ──
        StatDaily::put($day, 'signups', '',
            DB::table('users')->whereBetween('created_at', [$from, $to])->count());

        if (DB::getSchemaBuilder()->hasTable('subscriptions')) {
            StatDaily::put($day, 'subscriptions', '',
                DB::table('subscriptions')->whereBetween('created_at', [$from, $to])->count());
        }

        // ── Catalogue ──
        StatDaily::put($day, 'published', '',
            DB::table('sounds')->whereNull('deleted_at')->whereBetween('published_at', [$from, $to])->count());

        // ── Search ──
        // The searches table is aggregated per term, so the day's total comes
        // from its own daily rollup rather than a timestamp range.
        if (DB::getSchemaBuilder()->hasTable('search_daily')) {
            StatDaily::put($day, 'searches', '',
                (int) DB::table('search_daily')->where('day', $day)->sum('count'));
        }

        // ── Plays ──
        // plays_count is a running total on the sound, so there is no
        // per-day source to recount. Today's figure is the difference against
        // the last known total, stored as its own metric.
        $this->rollupPlays($day, $back);
    }

    /**
     * Plays are a counter, not an event log.
     *
     * sounds.plays_count only ever goes up, so the day's plays are the
     * difference between the total now and the total at the last snapshot.
     * Only meaningful for today — older days keep whatever was recorded when
     * they were current, which is why this one is not recomputed backwards.
     */
    protected function rollupPlays(string $day, int $back): void
    {
        if ($back > 0) {
            return;
        }

        $total = (int) DB::table('sounds')->sum('plays_count');
        $previous = (int) DB::table('stats_daily')
            ->where('metric', 'plays.total')
            ->where('label', '')
            ->orderByDesc('day')
            ->value('value');

        if ($previous > 0 && $total >= $previous) {
            StatDaily::put($day, 'plays', '', $total - $previous
                + (int) DB::table('stats_daily')
                    ->where(['metric' => 'plays', 'label' => '', 'day' => $day])
                    ->value('value'));
        }

        StatDaily::put($day, 'plays.total', '', $total);
    }

    /** Days between the first download and today, capped at five years. */
    protected function historyLength(): int
    {
        $first = DB::table('downloads')->min('created_at')
            ?? DB::table('users')->min('created_at');

        if (! $first) {
            return 1;
        }

        return min(1825, Clock::now()->diffInDays($first) + 1);
    }
}
