<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Sound;
use App\Support\Clock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Everything the Analytics screen reads.
 *
 * Two rules keep this fast as the catalogue grows:
 *
 *   1. Time series never touch the source tables. They read stats_daily,
 *      which has one row per day per metric — a year of history is a few
 *      hundred rows, not a few million.
 *
 *   2. The queries that DO touch source tables (top sounds, distributions)
 *      are bounded by a date range, backed by an index, and cached. They are
 *      the exception, and each one says why.
 */
class Stats
{
    /** How long a computed panel stays warm. Short enough to feel live. */
    protected const TTL = 300;

    /**
     * The five buttons on the chart, as (days back, bucket).
     *
     * They are granularities rather than plain ranges: looking at a year by
     * day is noise, and looking at a week by month is a single bar.
     */
    public const RANGES = [
        'day' => ['days' => 30, 'bucket' => 'day', 'label' => 'Day'],
        'week' => ['days' => 182, 'bucket' => 'week', 'label' => 'Week'],
        'month' => ['days' => 365, 'bucket' => 'month', 'label' => 'Month'],
        'year' => ['days' => 1825, 'bucket' => 'year', 'label' => 'Year'],
        'all' => ['days' => null, 'bucket' => 'month', 'label' => 'All'],
    ];

    // ---------------------------------------------------------------
    // Time series
    // ---------------------------------------------------------------

    /**
     * @return array{labels: array<int,string>, values: array<int,int>}
     */
    public function series(string $metric, string $range = 'day'): array
    {
        $config = self::RANGES[$range] ?? self::RANGES['day'];

        return Cache::remember("stats.series.{$metric}.{$range}", self::TTL, function () use ($metric, $config) {
            $rows = DB::table('stats_daily')
                ->where('metric', $metric)
                ->where('label', '')
                ->when($config['days'], fn ($q, $days) => $q->where('day', '>=', Clock::now()->subDays($days)->toDateString()))
                ->orderBy('day')
                ->get(['day', 'value']);

            return $this->bucket($rows, $config['bucket']);
        });
    }

    /**
     * Fold daily rows into weeks, months or years.
     *
     * Done in PHP rather than SQL because the input is already tiny — at most
     * five years of daily rows — and doing it here keeps the query portable
     * and free of database-specific date functions.
     *
     * @return array{labels: array<int,string>, values: array<int,int>}
     */
    protected function bucket($rows, string $bucket): array
    {
        $grouped = collect($rows)->groupBy(function ($row) use ($bucket) {
            $date = \Illuminate\Support\Carbon::parse($row->day);

            return match ($bucket) {
                'week' => $date->startOfWeek()->toDateString(),
                'month' => $date->format('Y-m'),
                'year' => $date->format('Y'),
                default => $date->toDateString(),
            };
        });

        $labels = [];
        $values = [];

        foreach ($grouped as $key => $group) {
            $labels[] = match ($bucket) {
                'week' => \Illuminate\Support\Carbon::parse($key)->format('j M'),
                'month' => \Illuminate\Support\Carbon::parse($key.'-01')->format('M Y'),
                'year' => $key,
                default => \Illuminate\Support\Carbon::parse($key)->format('j M'),
            };

            $values[] = (int) $group->sum('value');
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * Whether the chart can be believed.
     *
     * stats_daily is filled by RollupStatsCommand on an hourly schedule. If
     * schedule:work is not running — which on a local machine it usually is
     * not — the table is empty and every series comes back flat. The chart
     * then draws nothing, or worse draws a truthful-looking line that stops
     * three weeks ago, and the reader concludes the site has no traffic.
     *
     * A missing rollup is completely recoverable and the recovery is one
     * command, which makes silence the worst possible way to report it.
     *
     * IT ONLY SPEAKS WHEN THE CHART WOULD LIE. One day of lag is normal —
     * the rollup stamps today's row within the hour — and a card that
     * complained about it every morning would be a card nobody reads by
     * Thursday.
     *
     * @return array{state: string, last: ?string, days: int}
     */
    public function rollupStatus(): array
    {
        $last = DB::table('stats_daily')->max('day');

        if (! $last) {
            return ['state' => 'missing', 'last' => null, 'days' => 0];
        }

        $days = (int) \Illuminate\Support\Carbon::parse($last)
            ->startOfDay()
            ->diffInDays(Clock::now()->startOfDay());

        return [
            'state' => $days >= 2 ? 'stale' : 'ok',
            'last' => (string) $last,
            'days' => $days,
        ];
    }

    // ---------------------------------------------------------------
    // Headline numbers
    // ---------------------------------------------------------------

    public function today(string $metric): int
    {
        return (int) DB::table('stats_daily')
            ->where('metric', $metric)
            ->where('label', '')
            ->where('day', Clock::day())
            ->value('value');
    }

    public function sum(string $metric, int $days): int
    {
        return (int) DB::table('stats_daily')
            ->where('metric', $metric)
            ->where('label', '')
            ->where('day', '>=', Clock::now()->subDays($days - 1)->toDateString())
            ->sum('value');
    }

    /**
     * Percentage change against the equal period immediately before.
     *
     * The number on its own says nothing: 400 downloads is good news or bad
     * news depending entirely on what last week was.
     */
    public function change(string $metric, int $days): ?float
    {
        $current = $this->sum($metric, $days);

        $previous = (int) DB::table('stats_daily')
            ->where('metric', $metric)
            ->where('label', '')
            ->whereBetween('day', [
                Clock::now()->subDays($days * 2 - 1)->toDateString(),
                Clock::now()->subDays($days)->toDateString(),
            ])
            ->sum('value');

        if ($previous === 0) {
            return $current > 0 ? null : 0.0;   // null = "no basis to compare"
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    // ---------------------------------------------------------------
    // Breakdowns
    // ---------------------------------------------------------------

    /** @return array<int, array{label: string, value: int}> */
    public function breakdown(string $metric, int $days, int $limit = 8): array
    {
        return Cache::remember("stats.breakdown.{$metric}.{$days}.{$limit}", self::TTL, function () use ($metric, $days, $limit) {
            return DB::table('stats_daily')
                ->where('metric', $metric)
                ->where('label', '!=', '')
                ->where('day', '>=', Clock::now()->subDays($days - 1)->toDateString())
                ->groupBy('label')
                ->orderByDesc(DB::raw('SUM(value)'))
                ->limit($limit)
                ->get(['label', DB::raw('SUM(value) as total')])
                ->map(fn ($row) => ['label' => $row->label, 'value' => (int) $row->total])
                ->all();
        });
    }

    /**
     * Top sounds in a window.
     *
     * The one query here that reads the raw table. It is bounded by a date
     * range and served by the (created_at, sound_id) index added with
     * stats_daily — without that index this is the query that would take the
     * page down once downloads reach seven figures.
     */
    public function topSounds(int $days = 30, int $limit = 10): array
    {
        return Cache::remember("stats.top.sounds.{$days}.{$limit}", self::TTL, function () use ($days, $limit) {
            $rows = DB::table('downloads')
                ->where('created_at', '>=', Clock::startOfDayUtc($days - 1))
                ->groupBy('sound_id')
                ->orderByDesc(DB::raw('COUNT(*)'))
                ->limit($limit)
                ->get(['sound_id', DB::raw('COUNT(*) as total')]);

            $sounds = Sound::withTrashed()
                ->whereIn('id', $rows->pluck('sound_id'))
                ->get(['id', 'title', 'slug', 'is_premium'])
                ->keyBy('id');

            /*
            | Flattened to plain values before it goes into the cache, and
            | that is not tidiness — it is the bug this method used to have.
            |
            | An Eloquent model serialised into the cache carries its
            | protected properties, whose keys contain NUL bytes. This
            | project's cache store is a MySQL text column, and that round
            | trip does not survive them: the value comes back as
            | __PHP_Incomplete_Class and the next property read kills the
            | request. Worse, it only happens on the SECOND read, because
            | whoever writes the cache gets the real object back from the
            | closure — so it looks like an intermittent fault rather than a
            | caching mistake.
            |
            | Project rule: never cache Eloquent models or collections.
            */
            return $rows
                ->map(function ($row) use ($sounds) {
                    $sound = $sounds[$row->sound_id] ?? null;

                    return $sound ? [
                        'id' => (int) $sound->id,
                        'title' => (string) $sound->title,
                        'slug' => (string) $sound->slug,
                        'is_premium' => (bool) $sound->is_premium,
                        'value' => (int) $row->total,
                    ] : null;
                })
                ->filter()
                ->values()
                ->all();
        });
    }

    // ---------------------------------------------------------------
    // The panels that answer "where am I losing people?"
    // ---------------------------------------------------------------

    /**
     * Visits → searches → plays → downloads.
     *
     * The most useful thing on the screen. A total tells you how you are
     * doing; the shape of this tells you WHERE it goes wrong — a wide top
     * and a narrow middle is a discovery problem, a wide middle and a narrow
     * bottom is a catalogue or a paywall problem.
     */
    public function funnel(int $days = 30): array
    {
        return Cache::remember("stats.funnel.{$days}", self::TTL, function () use ($days) {
            $visits = $this->sum('visits', $days);
            $searches = $this->sum('searches', $days);
            $plays = $this->sum('plays', $days);
            $downloads = $this->sum('downloads', $days);

            $steps = [
                ['label' => 'Visits', 'value' => $visits, 'tone' => 'info'],
                ['label' => 'Searched', 'value' => $searches, 'tone' => 'brand'],
                ['label' => 'Listened', 'value' => $plays, 'tone' => 'warning'],
                ['label' => 'Downloaded', 'value' => $downloads, 'tone' => 'success'],
            ];

            $top = max(1, $visits);

            return array_map(function ($step, $i) use ($top, $steps) {
                $previous = $i > 0 ? $steps[$i - 1]['value'] : null;

                return [
                    ...$step,
                    'width' => round($step['value'] / $top * 100, 1),
                    // The drop from the step above, which is the number worth
                    // acting on rather than the absolute count.
                    'drop' => $previous && $previous > 0
                        ? round((1 - $step['value'] / $previous) * 100)
                        : null,
                ];
            }, $steps, array_keys($steps));
        });
    }

    /**
     * How much of the catalogue nobody wants.
     *
     * Specific to a sound bank: if two thirds of what you paid to record has
     * never been downloaded, the problem is not traffic, it is what you are
     * buying. No other panel here surfaces that.
     */
    public function deadStock(int $days = 90): array
    {
        return Cache::remember("stats.deadstock.{$days}", self::TTL * 4, function () use ($days) {
            $total = Sound::published()->count();

            if ($total === 0) {
                return ['total' => 0, 'idle' => 0, 'share' => 0.0];
            }

            $active = DB::table('downloads')
                ->where('created_at', '>=', Clock::startOfDayUtc($days - 1))
                ->distinct()
                ->count('sound_id');

            $idle = max(0, $total - $active);

            return [
                'total' => $total,
                'idle' => $idle,
                'share' => round($idle / $total * 100, 1),
            ];
        });
    }

    /** Free versus premium, which is the monetisation signal in one number. */
    public function premiumSplit(int $days = 30): array
    {
        return Cache::remember("stats.premium.{$days}", self::TTL, function () use ($days) {
            $rows = DB::table('downloads')
                ->join('sounds', 'sounds.id', '=', 'downloads.sound_id')
                ->where('downloads.created_at', '>=', Clock::startOfDayUtc($days - 1))
                ->groupBy('sounds.is_premium')
                ->get(['sounds.is_premium', DB::raw('COUNT(*) as total')]);

            $free = (int) ($rows->firstWhere('is_premium', 0)->total ?? 0);
            $premium = (int) ($rows->firstWhere('is_premium', 1)->total ?? 0);

            return ['free' => $free, 'premium' => $premium];
        });
    }

    /** @return array<int, array{label: string, value: int}> */
    public function categories(int $days = 30, int $limit = 8): array
    {
        $rows = $this->breakdown('downloads.category', $days, $limit);

        if ($rows === []) {
            return [];
        }

        $names = Category::whereIn('slug', array_column($rows, 'label'))
            ->pluck('name', 'slug');

        return array_map(
            fn ($row) => ['label' => $names[$row['label']] ?? $row['label'], 'value' => $row['value']],
            $rows,
        );
    }
}
