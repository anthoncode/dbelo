<?php

use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Stats;
use App\Support\Clock;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Analytics')] class extends Component {
    #[Url(except: 'day')] public string $range = 'day';
    #[Url(except: 30)] public int $window = 30;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    protected function stats(): Stats
    {
        return app(Stats::class);
    }

    /**
     * Changing the range must not re-render the canvas — a replaced canvas
     * is a blank chart. The new numbers are pushed to the existing chart
     * instance as a browser event instead.
     */
    public function setRange(string $range): void
    {
        $this->range = array_key_exists($range, Stats::RANGES) ? $range : 'day';

        foreach (['downloads', 'visits', 'signups'] as $metric) {
            $series = $this->stats()->series($metric, $this->range);

            $this->dispatch("chart:{$metric}", labels: $series['labels'], values: $series['values']);
        }

        /*
         * Revenue is pushed separately because it is the only series that is
         * not already in the unit the chart should draw. It is stored in
         * cents and shown in dollars, and the conversion has to happen on
         * both paths — here and in the computed property below — or the axis
         * jumps by a factor of a hundred the first time somebody touches the
         * range selector.
         */
        $money = $this->stats()->series('revenue', $this->range);

        $this->dispatch('chart:revenue',
            labels: $money['labels'],
            values: array_map(fn ($cents) => round($cents / 100, 2), $money['values']));
    }

    public function setWindow(int $days): void
    {
        $this->window = in_array($days, [7, 30, 90], true) ? $days : 30;
    }

    // ---------------------------------------------------------------

    /* ═══════════════════════════ Money ═══════════════════════════
     *
     * Everything below reads stats_daily like every other panel on this
     * screen — it does not touch the transactions table. RollupStatsCommand
     * writes revenue, revenue.net, orders, refunds and revenue.plan once an
     * hour, and this reads them back.
     *
     * CENTS IN, DOLLARS OUT, converted as late as possible. The column is an
     * integer, money in a float is a rounding error waiting to be noticed,
     * and the only place a decimal appears is the string a person reads.
     * ═════════════════════════════════════════════════════════════ */

    /** Has any money ever moved? Decides whether the section draws at all. */
    #[Computed]
    public function hasRevenue(): bool
    {
        return Transaction::query()->earned()->exists();
    }

    /** Cents as something a person reads. */
    public function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }

    /**
     * The money tiles.
     *
     * Today and the last 30 days side by side on purpose: on a small site
     * most days are zero, and a screen whose only money figure is "today"
     * reads as "nothing is happening" on every one of them.
     */
    #[Computed]
    public function moneyKpis(): array
    {
        $stats = $this->stats();

        $gross = $stats->sum('revenue', 30);
        $net = $stats->sum('revenue.net', 30);
        $refunds = $stats->sum('refunds', 30);

        return [
            [
                'label' => 'Earned today',
                'value' => $this->money($stats->today('revenue')),
                'change' => $stats->change('revenue', 7),
                'note' => null,
                'tone' => 'success',
            ],
            [
                'label' => 'Last 30 days',
                'value' => $this->money($gross),
                'change' => null,
                'note' => 'before fees',
                'tone' => 'brand',
            ],
            [
                /*
                 * The one that pays for the server. The gap between this and
                 * the line above is the gateway's cut, and a gross figure on
                 * its own overstates the business by exactly that much —
                 * quietly, and by a different amount every month.
                 */
                'label' => 'Net, 30 days',
                'value' => $this->money($net),
                'change' => null,
                'note' => $gross > 0
                    ? number_format(($gross - $net) / $gross * 100, 1).'% kept by PayPal'
                    : null,
                'tone' => 'info',
            ],
            [
                // Not money. Revenue rising because one person bought a year
                // and revenue rising because thirty bought a day pass are
                // different events, and this is the only figure that tells
                // them apart.
                'label' => 'Payments, 30 days',
                'value' => number_format($stats->sum('orders', 30)),
                'change' => null,
                'note' => ($orders = $stats->sum('orders', 30)) > 0
                    ? 'average '.$this->money((int) round($gross / $orders))
                    : null,
                'tone' => 'neutral',
            ],
            [
                /*
                 * Shown even at zero, unlike the others' notes. A refund
                 * figure that disappears when it is zero is a figure you
                 * cannot tell apart from one that was never calculated — and
                 * this is the number somebody will want to be sure about.
                 */
                'label' => 'Refunded, 30 days',
                'value' => $this->money($refunds),
                'change' => null,
                'note' => $refunds > 0 ? 'excluded from the totals' : 'none',
                'tone' => $refunds > 0 ? 'warning' : 'neutral',
            ],
        ];
    }

    /** The revenue series, in dollars, for the chart. */
    #[Computed]
    public function revenue(): array
    {
        $series = $this->stats()->series('revenue', $this->range);

        return [
            'labels' => $series['labels'],
            'values' => array_map(fn ($cents) => round($cents / 100, 2), $series['values']),
        ];
    }

    /**
     * Which plans brought it in, with each one's share already worked out.
     *
     * ── THE SHARE IS CALCULATED HERE AND NOT IN THE TEMPLATE ─────────────
     *
     * It was an `@php(...)` line inside the loop, and that line is what
     * broke this screen: a ParseError reading `unexpected token "class"` on
     * the line AFTER it, which is the signature of a @php directive whose
     * PHP tag never closed. Everything below it was then parsed as PHP,
     * and the first HTML attribute it met was `class`.
     *
     * The directive is a sharp edge rather than a bug — one line of it
     * elsewhere in this project works fine — but a template is a poor place
     * to do arithmetic regardless, and a view that contains no PHP cannot
     * fail this way again.
     *
     * The total is floored at 1 so the division can never be by zero on a
     * day when the breakdown has rows and the daily total has not been
     * rolled up yet.
     *
     * @return array<int, array{label: string, value: int, share: int}>
     */
    #[Computed]
    public function revenueByPlan(): array
    {
        $total = max(1, $this->stats()->sum('revenue', $this->window));

        return array_map(fn (array $row) => [
            ...$row,
            // At least 1%, so a plan that earned something never draws a bar
            // of zero width — which reads as "nothing" rather than "a little".
            'share' => max(1, min(100, (int) round($row['value'] / $total * 100))),
        ], $this->stats()->breakdown('revenue.plan', $this->window));
    }

    #[Computed]
    public function kpis(): array
    {
        $stats = $this->stats();

        return [
            [
                'label' => 'Downloads today',
                'value' => number_format($stats->today('downloads')),
                'change' => $stats->change('downloads', 7),
                'icon' => 'arrow-down-to-line',
                'tone' => 'brand',
            ],
            [
                'label' => 'Visits today',
                'value' => number_format($stats->today('visits')),
                'change' => $stats->change('visits', 7),
                'icon' => 'eye',
                'tone' => 'info',
            ],
            [
                'label' => 'New users today',
                'value' => number_format($stats->today('signups')),
                'change' => $stats->change('signups', 7),
                'icon' => 'user-plus',
                'tone' => 'success',
            ],
            [
                'label' => 'Total users',
                'value' => number_format(User::real()->count()),
                'change' => null,
                'icon' => 'users',
                'tone' => 'neutral',
            ],
            [
                'label' => 'Subscribers',
                'value' => number_format(Subscription::query()->active()->count()),
                'change' => null,
                'icon' => 'crown',
                'tone' => 'warning',
            ],
        ];
    }

    #[Computed]
    public function downloads(): array
    {
        return $this->stats()->series('downloads', $this->range);
    }

    #[Computed]
    public function visits(): array
    {
        return $this->stats()->series('visits', $this->range);
    }

    #[Computed]
    public function signups(): array
    {
        return $this->stats()->series('signups', $this->range);
    }

    #[Computed]
    public function funnel(): array
    {
        return $this->stats()->funnel($this->window);
    }

    #[Computed]
    public function topSounds(): array
    {
        return $this->stats()->topSounds($this->window, 10);
    }

    #[Computed]
    public function categories(): array
    {
        return $this->stats()->categories($this->window, 8);
    }

    #[Computed]
    public function referrers(): array
    {
        return $this->stats()->breakdown('visits.referrer', $this->window, 8);
    }

    #[Computed]
    public function pages(): array
    {
        return $this->stats()->breakdown('visits.path', $this->window, 8);
    }

    #[Computed]
    public function premium(): array
    {
        return $this->stats()->premiumSplit($this->window);
    }

    #[Computed]
    public function deadStock(): array
    {
        return $this->stats()->deadStock(90);
    }

    #[Computed]
    public function lastRollup(): ?string
    {
        $at = \Illuminate\Support\Facades\DB::table('stats_daily')->max('updated_at');

        return $at ? \Illuminate\Support\Carbon::parse($at)->diffForHumans() : null;
    }
}; ?>

<div>
    {{-- ══════ RANGE ══════ --}}
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-[1.2rem] font-semibold tracking-[-0.02em]">Analytics</h1>
            <p class="mt-0.5 text-[0.78rem] text-paper/30">
                Times are {{ Clock::timezone() }}.
                @if ($this->lastRollup) Counters recomputed {{ $this->lastRollup }}. @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="text-[0.75rem] uppercase tracking-[0.14em] text-paper/30">Group by</span>
            <div class="flex gap-1 rounded-xl bg-panel p-1">
                @foreach (\App\Services\Stats::RANGES as $key => $config)
                    <button wire:click="setRange('{{ $key }}')"
                            @class([
                                'rounded-lg px-3.5 py-2 text-[0.8rem] transition duration-200 ease-dbelo',
                                'bg-raised text-paper' => $range === $key,
                                'text-paper/45 hover:text-paper' => $range !== $key,
                            ])>{{ $config['label'] }}</button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════
         MONEY
         ══════════════════════════════════════════════════════════════════
         First, and in its own block rather than mixed into the counters
         below, for a reason that is about axes and not about importance:
         a thousand downloads and fifty dollars cannot share a scale. On one
         chart, one of them is always a flat line along the bottom.

         THE WHOLE SECTION IS SKIPPED UNTIL A PAYMENT EXISTS. Five empty
         tiles at the top of the screen every day teach the eye to start
         lower down, and the day the first one arrives it lands in a place
         nobody looks any more. One quiet line says the same thing and keeps
         the position.
         ══════════════════════════════════════════════════════════════════ --}}
    @if ($this->hasRevenue)
        <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
            @foreach ($this->moneyKpis as $kpi)
                <div class="rounded-2xl border border-hairline bg-panel p-5">
                    <span class="text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">{{ $kpi['label'] }}</span>

                    <div @class([
                        'mt-3 text-[1.9rem] font-semibold leading-none tracking-[-0.03em] tabular-nums',
                        'text-success' => $kpi['tone'] === 'success',
                        'text-warning' => $kpi['tone'] === 'warning',
                    ])>{{ $kpi['value'] }}</div>

                    @if ($kpi['change'] !== null)
                        <div class="mt-2 flex items-center gap-1.5 text-[0.75rem]">
                            <x-icon :name="$kpi['change'] >= 0 ? 'arrow-trend-up' : 'arrow-trend-down'"
                                    style="solid"
                                    @class([
                                        'text-[10px]',
                                        'text-success' => $kpi['change'] > 0,
                                        'text-danger' => $kpi['change'] < 0,
                                        'text-paper/30' => $kpi['change'] == 0,
                                    ]) />
                            <span @class([
                                'text-success' => $kpi['change'] > 0,
                                'text-danger' => $kpi['change'] < 0,
                                'text-paper/30' => $kpi['change'] == 0,
                            ])>{{ $kpi['change'] > 0 ? '+' : '' }}{{ $kpi['change'] }}%</span>
                            <span class="text-paper/25">vs last week</span>
                        </div>
                    @elseif ($kpi['note'])
                        <div class="mt-2 text-[0.75rem] text-paper/30">{{ $kpi['note'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mb-5 grid gap-5 xl:grid-cols-[2fr_1fr]">
            {{-- Dollars, not cents. The conversion happens in the computed
                 property and in setRange() both, because the range selector
                 pushes new numbers straight to the live chart and would
                 otherwise send cents into an axis drawn in dollars. --}}
            <x-admin.chart id="revenue" title="Revenue over time" icon="dollar-sign"
                           subtitle="Gross, in dollars · grouped by {{ strtolower(\App\Services\Stats::RANGES[$range]['label']) }}"
                           colour="success" height="h-72"
                           :labels="$this->revenue['labels']" :values="$this->revenue['values']"
                           empty="No payments recorded yet" />

            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                    <x-admin.icon-chip icon="layer-group" tone="brand" />
                    <div class="min-w-0 flex-1">
                        <h2 class="text-[0.95rem] font-medium">Where it came from</h2>
                        <p class="mt-0.5 text-[0.75rem] text-paper/30">Last {{ $window }} days, by plan</p>
                    </div>
                </div>

                <div class="p-5">
                    @forelse ($this->revenueByPlan as $row)
                        <div class="{{ $loop->first ? '' : 'mt-4' }}">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="truncate text-[0.86rem]">{{ $row['label'] }}</span>
                                <span class="shrink-0 text-[0.86rem] tabular-nums text-paper/60">{{ $this->money($row['value']) }}</span>
                            </div>

                            {{-- A bar and the figure beside it, never a bar
                                 alone: the bar says which plan is bigger, the
                                 number says whether the difference matters. --}}
                            <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-raised">
                                <div class="h-full rounded-full bg-brand" style="width: {{ $row['share'] }}%"></div>
                            </div>
                        </div>
                    @empty
                        <p class="py-8 text-center text-[0.85rem] text-paper/35">Nothing attributed to a plan yet</p>
                    @endforelse
                </div>
            </div>
        </div>
    @else
        <div class="mb-5 flex flex-wrap items-center gap-3 rounded-2xl border border-hairline bg-panel px-5 py-4">
            <x-admin.icon-chip icon="dollar-sign" tone="brand" />
            <div class="min-w-0">
                <div class="text-[0.9rem]">No payments yet</div>
                <div class="text-[0.78rem] text-paper/40">
                    Revenue, fees, refunds and the per-plan breakdown appear here from the first completed payment.
                    They are recomputed hourly from the transactions table.
                </div>
            </div>
        </div>
    @endif

    {{-- ══════ KPIs ══════ --}}
    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ($this->kpis as $kpi)
            <div class="rounded-2xl border border-hairline bg-panel p-5">
                <div class="flex items-start justify-between">
                    <span class="text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">{{ $kpi['label'] }}</span>
                    <span @class([
                        'grid size-9 place-items-center rounded-full',
                        'bg-brand/15 text-brand' => $kpi['tone'] === 'brand',
                        'bg-info/15 text-info' => $kpi['tone'] === 'info',
                        'bg-success/15 text-success' => $kpi['tone'] === 'success',
                        'bg-warning/15 text-warning' => $kpi['tone'] === 'warning',
                        'bg-raised text-paper/40' => $kpi['tone'] === 'neutral',
                    ])>
                        <x-icon :name="$kpi['icon']" style="solid" class="text-[13px]" />
                    </span>
                </div>

                <div class="mt-3 text-[1.9rem] font-semibold leading-none tracking-[-0.03em]">{{ $kpi['value'] }}</div>

                {{-- The number alone says nothing: 400 downloads is good or
                     bad entirely depending on what last week was. --}}
                @if ($kpi['change'] !== null)
                    <div class="mt-2 flex items-center gap-1.5 text-[0.75rem]">
                        <x-icon :name="$kpi['change'] >= 0 ? 'arrow-trend-up' : 'arrow-trend-down'"
                                style="solid"
                                @class([
                                    'text-[10px]',
                                    'text-success' => $kpi['change'] > 0,
                                    'text-danger' => $kpi['change'] < 0,
                                    'text-paper/30' => $kpi['change'] == 0,
                                ]) />
                        <span @class([
                            'text-success' => $kpi['change'] > 0,
                            'text-danger' => $kpi['change'] < 0,
                            'text-paper/30' => $kpi['change'] == 0,
                        ])>{{ $kpi['change'] > 0 ? '+' : '' }}{{ $kpi['change'] }}%</span>
                        <span class="text-paper/25">vs last week</span>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- ══════ THE BIG ONE ══════ --}}
    <div class="mb-5">
        <x-admin.chart id="downloads" title="Downloads over time" icon="arrow-down-to-line"
                       subtitle="Grouped by {{ strtolower(\App\Services\Stats::RANGES[$range]['label']) }}"
                       colour="brand" height="h-80"
                       :labels="$this->downloads['labels']" :values="$this->downloads['values']"
                       empty="No downloads recorded yet" />
    </div>

    <div class="mb-5 grid gap-5 xl:grid-cols-2">
        <x-admin.chart id="visits" title="Visits" icon="eye" colour="info"
                       subtitle="Bots and the admin panel are not counted"
                       :labels="$this->visits['labels']" :values="$this->visits['values']"
                       empty="No visits recorded yet" />

        <x-admin.chart id="signups" title="New accounts" icon="user-plus" colour="success"
                       :labels="$this->signups['labels']" :values="$this->signups['values']"
                       empty="No accounts created yet" />
    </div>

    {{-- ══════ WINDOW FOR THE PANELS BELOW ══════ --}}
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-hairline bg-panel px-5 py-3.5">
        <div class="flex items-center gap-3">
            <x-admin.icon-chip icon="filter" tone="muted" />
            <div>
                <div class="text-[0.9rem] font-medium">Everything below</div>
                <div class="text-[0.75rem] text-paper/30">covers the last {{ $window }} days</div>
            </div>
        </div>

        <div class="flex gap-1 rounded-xl bg-canvas/50 p-1">
            @foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days'] as $days => $label)
                <button wire:click="setWindow({{ $days }})"
                        @class([
                            'rounded-lg px-3.5 py-2 text-[0.8rem] transition duration-200 ease-dbelo',
                            'bg-raised text-paper' => $window === $days,
                            'text-paper/45 hover:text-paper' => $window !== $days,
                        ])>{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- ══════ FUNNEL — where you lose people ══════ --}}
    <div class="mb-5 grid gap-5 xl:grid-cols-[1fr_360px]">
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                <x-admin.icon-chip icon="filter-circle-dollar" tone="brand" />
                <div class="min-w-0 flex-1">
                    <h2 class="text-[0.95rem] font-medium">From arriving to downloading</h2>
                    <p class="mt-0.5 text-[0.75rem] text-paper/30">
                        The shape matters more than the numbers: a narrow middle is a discovery problem,
                        a narrow bottom is a catalogue or paywall problem.
                    </p>
                </div>
            </div>

            <div class="space-y-4 p-5">
                @foreach ($this->funnel as $step)
                    <div>
                        <div class="mb-1.5 flex items-baseline justify-between gap-3">
                            <span class="text-[0.85rem]">{{ $step['label'] }}</span>
                            <span class="flex items-baseline gap-2">
                                <span class="text-[0.95rem] font-semibold tabular-nums">{{ number_format($step['value']) }}</span>
                                @if ($step['drop'] !== null)
                                    <span @class([
                                        'text-[0.75rem] tabular-nums',
                                        'text-danger' => $step['drop'] >= 70,
                                        'text-warning' => $step['drop'] >= 40 && $step['drop'] < 70,
                                        'text-paper/30' => $step['drop'] < 40,
                                    ])>−{{ $step['drop'] }}%</span>
                                @endif
                            </span>
                        </div>

                        <div class="h-3 overflow-hidden rounded-full bg-raised">
                            <div @class([
                                'h-full rounded-full transition-all duration-700 ease-dbelo',
                                'bg-gradient-to-r from-info to-info/60' => $step['tone'] === 'info',
                                'bg-gradient-to-r from-brand to-brand/60' => $step['tone'] === 'brand',
                                'bg-gradient-to-r from-warning to-warning/60' => $step['tone'] === 'warning',
                                'bg-gradient-to-r from-success to-success/60' => $step['tone'] === 'success',
                            ]) style="width: {{ max(1.5, $step['width']) }}%"></div>
                        </div>
                    </div>
                @endforeach

                <p class="pt-1 text-[0.75rem] leading-relaxed text-paper/30">
                    Visits counts people who are not logged in as admin. Listened counts plays, which is the
                    step most sites never measure and the one that tells you whether your titles match what
                    people expected to hear.
                </p>
            </div>
        </div>

        {{-- Dead stock: specific to a sound bank --}}
        <div class="space-y-5">
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                    <x-admin.icon-chip icon="box-archive" :tone="$this->deadStock['share'] > 60 ? 'brand' : 'muted'" />
                    <h2 class="text-[0.95rem] font-medium">Catalogue nobody wants</h2>
                </div>

                <div class="p-5">
                    <div class="flex items-baseline gap-2">
                        <span @class([
                            'text-[2.2rem] font-semibold leading-none tracking-[-0.03em]',
                            'text-danger' => $this->deadStock['share'] > 75,
                            'text-warning' => $this->deadStock['share'] > 50 && $this->deadStock['share'] <= 75,
                        ])>{{ $this->deadStock['share'] }}%</span>
                        <span class="text-[0.8rem] text-paper/35">of the catalogue</span>
                    </div>

                    <p class="mt-2 text-[0.8rem] text-paper/45">
                        {{ number_format($this->deadStock['idle']) }} of {{ number_format($this->deadStock['total']) }}
                        published sounds have not been downloaded once in 90 days.
                    </p>

                    <p class="mt-3 rounded-lg bg-raised px-3.5 py-2.5 text-[0.75rem] leading-relaxed text-paper/50">
                        High here is not always bad — a deep catalogue has a long tail by design. It becomes a
                        problem when it climbs while downloads stay flat: that means you are adding sounds
                        nobody is looking for.
                    </p>
                </div>
            </div>

            <x-admin.chart id="premium" title="Free vs premium" icon="crown" type="doughnut"
                           subtitle="Of everything downloaded in the window"
                           height="h-48"
                           :labels="['Free', 'Premium']"
                           :values="[$this->premium['free'], $this->premium['premium']]"
                           :colours="['info', 'brand']"
                           empty="No downloads in this window" />
        </div>
    </div>

    {{-- ══════ WHAT AND WHERE ══════ --}}
    <div class="mb-5 grid gap-5 xl:grid-cols-2">
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                <x-admin.icon-chip icon="ranking-star" tone="brand" />
                <div class="min-w-0 flex-1">
                    <h2 class="text-[0.95rem] font-medium">Top downloads</h2>
                    <p class="mt-0.5 text-[0.75rem] text-paper/30">Last {{ $window }} days</p>
                </div>
            </div>

            @if ($this->topSounds === [])
                <p class="px-5 py-16 text-center text-[0.85rem] text-paper/35">No downloads in this window</p>
            @else
                @php $peak = max(array_column($this->topSounds, 'value')); @endphp

                <div class="divide-y divide-hairline">
                    @foreach ($this->topSounds as $i => $row)
                        <div class="flex items-center gap-4 px-5 py-3">
                            <span class="w-5 shrink-0 text-[0.78rem] tabular-nums text-paper/25">{{ $i + 1 }}</span>

                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('sounds.show', $row['slug']) }}" target="_blank"
                                       class="truncate text-[0.87rem] transition hover:text-brand">{{ $row['title'] }}</a>
                                    @if ($row['is_premium'])
                                        <span class="shrink-0 rounded-full bg-brand/15 px-2 py-0.5 text-[0.62rem] text-brand">Pro</span>
                                    @endif
                                </div>

                                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-raised">
                                    <div class="h-full rounded-full bg-gradient-to-r from-brand to-brand/50"
                                         style="width: {{ round($row['value'] / $peak * 100) }}%"></div>
                                </div>
                            </div>

                            <span class="shrink-0 text-[0.85rem] font-medium tabular-nums">{{ number_format($row['value']) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <x-admin.chart id="categories" title="Downloads by category" icon="folder-tree"
                       type="bar" horizontal colour="action" height="h-80"
                       subtitle="Where demand actually is — not where the catalogue is biggest"
                       :labels="array_column($this->categories, 'label')"
                       :values="array_column($this->categories, 'value')"
                       empty="No downloads in this window" />
    </div>

    {{-- ══════ TRAFFIC ══════ --}}
    <div class="grid gap-5 xl:grid-cols-2">
        <x-admin.chart id="referrers" title="Where visitors come from" icon="share-nodes"
                       type="bar" horizontal colour="info" height="h-72"
                       subtitle="Direct visits and internal links are not counted"
                       :labels="array_column($this->referrers, 'label')"
                       :values="array_column($this->referrers, 'value')"
                       empty="Nothing has linked to the site yet" />

        <x-admin.chart id="pages" title="Most visited pages" icon="file-lines"
                       type="bar" horizontal colour="success" height="h-72"
                       subtitle="Grouped by kind of page, not by individual URL"
                       :labels="array_column($this->pages, 'label')"
                       :values="array_column($this->pages, 'value')"
                       empty="No page views recorded yet" />
    </div>
</div>
