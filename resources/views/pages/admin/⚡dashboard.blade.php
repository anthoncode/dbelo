<?php

use App\Models\Download;
use App\Models\Sound;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Stats;
use App\Support\Clock;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The panel's front door, and it answers exactly one question:
 * IS TODAY NORMAL, AND DOES ANYTHING NEED ME?
 *
 * Analytics answers a different one — why, and where are you losing people —
 * with a funnel, six charts, referrers and category splits. Building those
 * here as well would give the operator two screens for one question, and the
 * day they disagreed neither would be trusted. So this screen gets ONE chart.
 *
 * ───────────────────────────────────────────────────────────────────────
 * WHERE EACH NUMBER COMES FROM, BECAUSE IT IS NOT THE SAME PLACE
 *
 *   THE TILES, THE TOP TEN AND THE IDLE-CATALOGUE FIGURE read the source
 *   tables — sounds, downloads, users. Bounded, indexed, exact, and true
 *   whether or not anything is scheduled.
 *
 *   THE CHART reads stats_daily, because a time series over the raw table
 *   is the query that takes this page down once downloads reach seven
 *   figures. stats_daily is filled by an hourly command, and on a local
 *   machine that command is usually not running.
 *
 * That split is deliberate: it means at most ONE element on this screen can
 * be out of date, and that element says so out loud instead of drawing a
 * flat line. A dashboard where every number depends on a cron is a
 * dashboard that quietly reports zero traffic the week the worker dies.
 * ───────────────────────────────────────────────────────────────────────
 */
new #[Layout('layouts.admin')] #[Title('Dashboard')] class extends Component {
    /**
     * The chart's granularity, not its window.
     *
     * 'day' is thirty days drawn daily; 'month' is a year drawn monthly;
     * 'year' is five years drawn yearly. Looking at a year day by day is
     * noise, and looking at a month by month is one bar.
     */
    #[Url(except: 'day')]
    public string $range = 'day';

    /**
     * The top ten's window, which IS a window — and that is why the two
     * filter rows on this screen have similar words and do not agree. The
     * chart asks "how finely"; this asks "since when".
     */
    #[Url(except: 30)]
    public int $top = 30;

    /** Label → days, for the top ten. */
    public const WINDOWS = [1 => 'Today', 7 => 'Week', 30 => 'Month', 365 => 'Year'];

    /** The three granularities asked for, out of the five Stats offers. */
    public const GRAINS = ['day' => 'Day', 'month' => 'Month', 'year' => 'Year'];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    protected function stats(): Stats
    {
        return app(Stats::class);
    }

    /**
     * Changing the granularity must NOT re-render the canvas.
     *
     * <x-admin.chart> wraps the canvas in wire:ignore precisely because a
     * Livewire render replaces the DOM, and a replaced <canvas> is a blank
     * chart. The new numbers reach the live chart instance through a browser
     * event instead.
     */
    public function setRange(string $range): void
    {
        $this->range = array_key_exists($range, self::GRAINS) ? $range : 'day';

        $series = $this->stats()->series('downloads', $this->range);

        $this->dispatch('chart:downloads', labels: $series['labels'], values: $series['values']);
    }

    public function setTop(int $days): void
    {
        $this->top = array_key_exists($days, self::WINDOWS) ? $days : 30;
    }

    /**
     * The two filter rows, for the template.
     *
     * A ⚡ component's class is anonymous, so its constants have no name a
     * Blade expression could qualify. Exposing them here keeps ONE
     * definition — the same arrays setRange() and setTop() validate against
     * — instead of a second copy written inline in the markup that would
     * eventually offer a button the method rejects.
     */
    #[Computed]
    public function grains(): array
    {
        return self::GRAINS;
    }

    #[Computed]
    public function windows(): array
    {
        return self::WINDOWS;
    }

    /* ═════════════════════════ The chart ═════════════════════════ */

    #[Computed]
    public function downloadSeries(): array
    {
        return $this->stats()->series('downloads', $this->range);
    }

    #[Computed]
    public function rollup(): array
    {
        return $this->stats()->rollupStatus();
    }

    /* ═════════════════════════ The tiles ═════════════════════════ */

    /**
     * Four tiles, four different questions. None of them duplicates the
     * bell: "in review" is a to-do and lives there, in the sidebar badge and
     * in Needs attention below — three surfaces is already one too many, and
     * a fourth in the headline row would make the row about chores.
     *
     * ON THE COLOURS. Every accent here is earned rather than decorative,
     * because this project spends the semantic palette on meaning: brand is
     * the catalogue itself, success is the outcome you want, info is a
     * neutral fact, and the idle tile turns amber ONLY when the share is bad
     * enough to act on. Danger never appears on a tile — red is reserved for
     * the bell and the badges, and it is worth nothing if it is also a
     * decoration.
     */
    #[Computed]
    public function tiles(): array
    {
        $week = Clock::startOfDayUtc(6);
        $prevWeek = Clock::startOfDayUtc(13);

        $downloads7 = Download::where('created_at', '>=', $week)->count();

        // Half-open on purpose. whereBetween is inclusive at both ends, so
        // it would count the instant at $week in BOTH periods — which makes
        // the percentage wrong by exactly the traffic of one moment, and
        // wrong in a way nobody would ever think to check.
        $downloadsPrev7 = Download::where('created_at', '>=', $prevWeek)
            ->where('created_at', '<', $week)
            ->count();

        $idle = $this->stats()->deadStock(90);

        /*
         * ── MONEY ────────────────────────────────────────────────────────
         *
         * Read from the TRANSACTIONS TABLE, not from stats_daily, following
         * the rule at the top of this file: the tiles read source tables so
         * they are exact and true whether or not anything is scheduled.
         * Analytics reads the rollup because it draws a time series; this
         * draws three numbers, and three numbers can be counted directly.
         *
         * It also means the one figure an operator will check twice cannot
         * be stale because a cron entry was lost in a deploy.
         *
         * By paid_at, never created_at: a row is created when the webhook
         * lands, and the webhook can arrive the next day. The day the money
         * was taken is the only date that will match what PayPal reports.
         *
         * earned() excludes refunds entirely rather than netting them off.
         * A refund is not a smaller sale.
         */
        $gross = fn ($from = null, $to = null) => (int) Transaction::query()
            ->earned()
            ->when($from, fn ($q) => $q->where('paid_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('paid_at', '<', $to))
            ->sum('amount_cents');

        $earnedAll = $gross();
        $earned7 = $gross($week);

        // Half-open, like the downloads comparison above and for the same
        // reason: whereBetween would count the instant at $week in both
        // periods and make the percentage quietly wrong.
        $earnedPrev7 = $gross($prevWeek, $week);

        $netAll = (int) Transaction::query()->earned()->sum('net_cents');

        $money = fn (int $cents) => '$'.number_format($cents / 100, 2);

        return [
            [
                'label' => 'Sounds published',
                'icon' => 'waveform-lines',
                'tone' => 'brand',
                'value' => Sound::published()->count(),
                'delta' => Sound::published()->where('published_at', '>=', $week)->count(),
                'deltaLabel' => 'new this week',
                'change' => null,
            ],
            [
                'label' => 'Downloads',
                'icon' => 'arrow-down-to-line',
                'tone' => 'success',
                'value' => Download::count(),
                'delta' => $downloads7,
                'deltaLabel' => 'this week',
                // The count alone says nothing: four hundred downloads is
                // good news or bad news entirely depending on last week.
                'change' => $downloadsPrev7 > 0
                    ? (int) round(($downloads7 - $downloadsPrev7) / $downloadsPrev7 * 100)
                    : null,
            ],
            [
                'label' => 'People',
                'icon' => 'users',
                'tone' => 'info',
                'value' => User::real()->count(),
                'delta' => User::real()->where('created_at', '>=', $week)->count(),
                'deltaLabel' => 'signed up this week',
                'change' => null,
            ],
            [
                /*
                 * THE QUESTION THE OTHER FOUR TILES CANNOT ANSWER.
                 *
                 * Catalogue, demand, audience and waste — and not one of
                 * them says whether any of it pays. That is the question
                 * somebody opens this panel on a Monday to ask.
                 *
                 * Gross in the big figure and net in the note, both. Gross
                 * alone overstates the business by whatever the gateway
                 * charged that month, and net alone hides what the gateway
                 * is costing. The pair is the only honest version.
                 */
                'label' => 'Revenue',
                'icon' => 'dollar-sign',
                'tone' => 'success',
                'value' => $earnedAll,
                // The formatted string wins over number_format() in the
                // template. Cents in the data, dollars on the screen, and
                // the conversion in exactly one place.
                'display' => $money($earnedAll),
                'delta' => $earned7,
                'deltaDisplay' => $money($earned7),
                'deltaLabel' => 'this week',
                'change' => $earnedPrev7 > 0
                    ? (int) round(($earned7 - $earnedPrev7) / $earnedPrev7 * 100)
                    : null,
                'note' => $earnedAll > 0 ? $money($netAll).' after fees' : 'no payments yet',
            ],
            [
                /*
                 * The one figure here that is specific to a SOUND BANK.
                 *
                 * How much of the catalogue nobody has wanted in ninety
                 * days. If two thirds of what you paid to record has never
                 * been downloaded, the problem is not traffic and no amount
                 * of SEO fixes it — it is what you are buying. Nothing else
                 * on this screen can tell you that.
                 */
                'label' => 'Idle catalogue',
                'icon' => 'moon',
                'tone' => $idle['share'] >= 50 ? 'warning' : 'muted',
                'value' => $idle['idle'],
                'suffix' => $idle['total'] > 0 ? $idle['share'].'%' : null,
                'delta' => null,
                'deltaLabel' => null,
                'change' => null,
                'note' => 'no downloads in 90 days',
            ],
        ];
    }

    /* ═════════════════════════ The top ten ═════════════════════════ */

    /**
     * Read from the downloads table, NOT from sounds.downloads_count.
     *
     * That column is a lifetime counter with no notion of a window, so it
     * could not answer "this week" at all. It is also no longer comparable
     * with itself: since re-downloads stopped incrementing it, every row
     * written before that change is inflated relative to every row written
     * after. Ordering by it would put yesterday's genuinely popular sound
     * below one somebody re-fetched five times last March.
     */
    #[Computed]
    public function topSounds(): array
    {
        return $this->stats()->topSounds($this->top, 10);
    }

    /* ═════════════════════════ What needs you ═════════════════════════ */

    #[Computed]
    public function needsAttention(): array
    {
        $rows = [];

        if ($failed = Sound::whereNotNull('processing_error')->count()) {
            $rows[] = ['icon' => 'triangle-exclamation', 'tone' => 'danger',
                'text' => "{$failed} ".str('sound')->plural($failed).' failed to process',
                'action' => 'Review', 'route' => 'moderate'];
        }

        if ($pending = Sound::where('status', 'pending')->count()) {
            $rows[] = ['icon' => 'clipboard-check', 'tone' => 'info',
                'text' => "{$pending} ".str('sound')->plural($pending).' waiting for review',
                'action' => 'Moderate', 'route' => 'moderate'];
        }

        if ($orphans = Sound::published()->whereNull('category_id')->count()) {
            $rows[] = ['icon' => 'folder-xmark', 'tone' => 'warning',
                'text' => "{$orphans} published ".str('sound')->plural($orphans).' without a category',
                'action' => 'Fix', 'route' => 'admin.sounds'];
        }

        if (Sound::published()->whereNull('license_id')->exists()) {
            $rows[] = ['icon' => 'file-contract', 'tone' => 'warning',
                'text' => 'Some published sounds have no licence attached',
                'action' => 'Licenses', 'route' => 'admin.licenses'];
        }

        return $rows;
    }

    #[Computed]
    public function recent()
    {
        return Sound::with(['user', 'category'])->latest()->limit(8)->get();
    }
}; ?>

<div class="space-y-5">

    {{-- ══════════════════════════════════════════════════════════════
         TILES
         ══════════════════════════════════════════════════════════════ --}}
    {{-- Five across at xl, not four. The tiles are a little narrower and
         they stay one row — which is the whole point of a tile strip: five
         facts read in one sweep, without a second row that reads as a
         different, lesser group. --}}
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ($this->tiles as $tile)
            <div class="group rounded-2xl border border-hairline bg-panel p-5 transition duration-300 ease-dbelo hover:border-brand/30"
                 wire:key="tile-{{ $loop->index }}">

                <div class="flex items-start justify-between gap-3">
                    <span class="text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">{{ $tile['label'] }}</span>

                    <span @class([
                        'grid size-9 shrink-0 place-items-center rounded-full transition duration-200 ease-dbelo',
                        'bg-brand/15 text-brand' => $tile['tone'] === 'brand',
                        'bg-success/15 text-success' => $tile['tone'] === 'success',
                        'bg-info/15 text-info' => $tile['tone'] === 'info',
                        'bg-warning/15 text-warning' => $tile['tone'] === 'warning',
                        'bg-raised text-paper/40 group-hover:text-paper/70' => $tile['tone'] === 'muted',
                    ])>
                        <x-icon :name="$tile['icon']" style="solid" class="text-[13px]" />
                    </span>
                </div>

                <div class="mt-3 flex items-baseline gap-2">
                    {{-- `display` is for a tile whose value is not a plain
                         count — money, which is stored in cents and read in
                         dollars. Everything else still goes through
                         number_format, so a tile that does not set it
                         behaves exactly as before. --}}
                    <span class="text-[2rem] font-semibold leading-none tracking-[-0.03em] tabular-nums">
                        {{ $tile['display'] ?? number_format($tile['value']) }}
                    </span>

                    @if ($tile['suffix'] ?? null)
                        <span @class([
                            'text-[0.9rem] font-medium tabular-nums',
                            'text-warning' => $tile['tone'] === 'warning',
                            'text-paper/35' => $tile['tone'] !== 'warning',
                        ])>{{ $tile['suffix'] }}</span>
                    @endif
                </div>

                <div class="mt-2 flex flex-wrap items-baseline gap-x-2 gap-y-1 text-[0.78rem]">
                    @if ($tile['delta'] !== null)
                        <span @class([
                            'tabular-nums',
                            'text-success' => $tile['delta'] > 0,
                            'text-paper/30' => $tile['delta'] === 0,
                        ])>+{{ $tile['deltaDisplay'] ?? number_format($tile['delta']) }}</span>

                        <span class="text-paper/40">{{ $tile['deltaLabel'] }}</span>
                    @endif

                    {{-- Against the same length of time immediately before.
                         Green up, amber down — never red: a slow week is not
                         a fault, and spending danger on it would spend the
                         colour the bell needs. --}}
                    @if (($tile['change'] ?? null) !== null)
                        <span @class([
                            'rounded-full px-1.5 py-0.5 text-[0.7rem] tabular-nums',
                            'bg-success/12 text-success' => $tile['change'] > 0,
                            'bg-warning/12 text-warning' => $tile['change'] < 0,
                            'bg-paper/[0.06] text-paper/35' => $tile['change'] === 0,
                        ])>{{ $tile['change'] > 0 ? '+' : '' }}{{ $tile['change'] }}%</span>
                    @endif

                    @if ($tile['note'] ?? null)
                        <span class="text-paper/40">{{ $tile['note'] }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         THE ONLY THING ON THIS SCREEN THAT CAN BE OUT OF DATE
         ══════════════════════════════════════════════════════════════ --}}
    @if ($this->rollup['state'] !== 'ok')
        <div class="rounded-2xl border border-warning/25 bg-warning/[0.06] px-5 py-4">
            <div class="flex flex-wrap items-start gap-3.5">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-warning/15 text-warning">
                    <x-icon name="chart-line" style="solid" class="text-[0.82rem]" />
                </span>

                <div class="min-w-0 flex-1">
                    <div class="text-[0.92rem] text-paper/85">
                        @if ($this->rollup['state'] === 'missing')
                            The chart has nothing to draw yet
                        @else
                            The chart stops {{ $this->rollup['days'] }} days ago
                        @endif
                    </div>

                    <p class="mt-1 max-w-[80ch] text-[0.82rem] leading-relaxed text-paper/50">
                        Only the chart is affected — every number in the tiles, the top ten and the lists below is
                        read straight from the database and is correct right now. The time series comes from the
                        daily rollup, which runs hourly under <code class="rounded bg-rail px-1.5 py-0.5 font-mono text-[0.76rem] text-paper/70">php artisan schedule:work</code>.
                        @if ($this->rollup['state'] === 'stale')
                            It last wrote {{ \Illuminate\Support\Carbon::parse($this->rollup['last'])->format('j M') }}.
                        @endif
                    </p>

                    <div class="mt-3.5 flex flex-wrap items-center gap-2.5">
                        <code class="rounded-lg bg-rail px-3.5 py-2 font-mono text-[0.8rem] text-paper/80">php artisan stats:rollup --all</code>
                        <span class="text-[0.76rem] text-paper/30">rebuilds the whole history from the source tables</span>
                    </div>
                </div>

                <a href="{{ route('admin.diagnostics') }}" wire:navigate
                   class="shrink-0 rounded-lg bg-raised px-3 py-1.5 text-[0.78rem] transition hover:bg-brand hover:text-white">
                    Diagnostics
                </a>
            </div>
        </div>
    @endif

    {{-- ══════════════════════════════════════════════════════════════
         CHART  +  TOP TEN
         ══════════════════════════════════════════════════════════════ --}}
    <div class="grid gap-5 xl:grid-cols-[1.5fr_1fr]">

        <x-admin.chart id="downloads"
                       title="Downloads over time"
                       subtitle="From the daily rollup, so it survives a catalogue of any size."
                       icon="arrow-down-to-line"
                       colour="brand"
                       height="h-72"
                       empty="Nothing rolled up yet — see the note above."
                       :labels="$this->downloadSeries['labels']"
                       :values="$this->downloadSeries['values']">
            <x-slot:actions>
                <div class="flex gap-1 rounded-xl bg-canvas/50 p-1">
                    @foreach ($this->grains as $key => $label)
                        <button type="button" wire:click="setRange('{{ $key }}')"
                                @class([
                                    'rounded-lg px-3.5 py-1.5 text-[0.78rem] transition duration-200 ease-dbelo',
                                    'bg-raised text-paper' => $range === $key,
                                    'text-paper/45 hover:text-paper' => $range !== $key,
                                ])>{{ $label }}</button>
                    @endforeach
                </div>
            </x-slot:actions>
        </x-admin.chart>

        {{-- ── TOP TEN ── --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="flex flex-wrap items-center gap-3 border-b border-hairline px-5 py-4">
                <x-admin.icon-chip icon="trophy" tone="brand" />

                <div class="min-w-0 flex-1">
                    <h2 class="text-[0.95rem] font-medium">Top 10</h2>
                    <p class="mt-0.5 text-[0.75rem] text-paper/30">Counted from real downloads, not the lifetime column.</p>
                </div>

                <div class="flex gap-1 rounded-xl bg-canvas/50 p-1">
                    @foreach ($this->windows as $days => $label)
                        <button type="button" wire:click="setTop({{ $days }})"
                                @class([
                                    'rounded-lg px-2.5 py-1.5 text-[0.76rem] transition duration-200 ease-dbelo',
                                    'bg-raised text-paper' => $top === $days,
                                    'text-paper/45 hover:text-paper' => $top !== $days,
                                ])>{{ $label }}</button>
                    @endforeach
                </div>
            </div>

            @php $rows = $this->topSounds; $peak = max(1, (int) ($rows[0]['value'] ?? 1)); @endphp

            <div class="divide-y divide-hairline">
                @forelse ($rows as $index => $row)
                    <div class="flex items-center gap-3 px-5 py-2.5" wire:key="top-{{ $row['id'] }}">
                        <span @class([
                            'w-5 shrink-0 text-[0.75rem] tabular-nums',
                            'font-semibold text-brand' => $index < 3,
                            'text-paper/25' => $index >= 3,
                        ])>{{ $index + 1 }}</span>

                        <a href="{{ route('sounds.show', $row['slug']) }}" target="_blank" rel="noopener"
                           class="min-w-0 flex-1 truncate text-[0.85rem] text-paper/75 transition hover:text-brand">
                            {{ $row['title'] }}
                            @if ($row['is_premium'])
                                <x-icon name="crown" style="solid" class="ml-1 text-[0.6rem] text-warning" />
                            @endif
                        </a>

                        {{-- The bar is what turns a column of numbers into a
                             shape you can read without doing arithmetic:
                             one runaway hit and a flat tail look completely
                             different, and the difference is the point. --}}
                        <span class="hidden h-1.5 w-16 shrink-0 overflow-hidden rounded-full bg-paper/[0.07] sm:block">
                            <span class="block h-full rounded-full bg-gradient-to-r from-brand to-brand/40"
                                  style="width: {{ max(4, round($row['value'] / $peak * 100)) }}%"></span>
                        </span>

                        <span class="w-10 shrink-0 text-right text-[0.78rem] tabular-nums text-paper/45">
                            {{ number_format($row['value']) }}
                        </span>
                    </div>
                @empty
                    {{-- Says which window is empty. "No downloads" on a
                         screen with a Today button reads as a broken page. --}}
                    <div class="px-5 py-12 text-center">
                        <x-icon name="trophy" style="regular" class="text-[22px] text-paper/15" />
                        <p class="mt-2.5 text-[0.85rem] text-paper/35">
                            No downloads {{ $top === 1 ? 'today' : 'in the last '.$top.' days' }} yet.
                        </p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         NEEDS ATTENTION  +  RECENT UPLOADS
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel">
        <div class="flex items-center justify-between border-b border-hairline px-5 py-4">
            <h2 class="text-[0.95rem] font-medium">Needs attention</h2>
            @if ($this->needsAttention)
                <span class="rounded-full bg-warning/15 px-2.5 py-0.5 text-[0.7rem] font-semibold text-warning">
                    {{ count($this->needsAttention) }}
                </span>
            @endif
        </div>

        <div class="divide-y divide-hairline">
            @forelse ($this->needsAttention as $row)
                <div class="flex items-center gap-3 px-5 py-3.5" wire:key="att-{{ $loop->index }}">
                    <span @class([
                        'grid size-8 shrink-0 place-items-center rounded-full',
                        'bg-danger/15 text-danger' => $row['tone'] === 'danger',
                        'bg-warning/15 text-warning' => $row['tone'] === 'warning',
                        'bg-info/15 text-info' => $row['tone'] === 'info',
                    ])>
                        <x-icon :name="$row['icon']" style="solid" class="text-[0.75rem]" />
                    </span>

                    <span class="min-w-0 flex-1 text-[0.88rem] text-paper/75">{{ $row['text'] }}</span>

                    @if ($row['route'])
                        <a href="{{ route($row['route']) }}" wire:navigate
                           class="shrink-0 rounded-lg bg-raised px-3 py-1.5 text-[0.78rem] transition hover:bg-brand hover:text-white">
                            {{ $row['action'] }}
                        </a>
                    @endif
                </div>
            @empty
                <div class="px-5 py-10 text-center">
                    <x-icon name="circle-check" style="solid" class="text-[22px] text-success" />
                    <p class="mt-2 text-[0.88rem] text-paper/50">Nothing pending</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- ── RECENT UPLOADS ── --}}
    <div class="rounded-2xl border border-hairline bg-panel">
        <div class="flex items-center justify-between border-b border-hairline px-5 py-4">
            <h2 class="text-[0.95rem] font-medium">Recent uploads</h2>
            <a href="{{ route('moderate') }}" wire:navigate class="text-[0.8rem] text-paper/40 transition hover:text-brand">
                View all
            </a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-hairline text-[0.7rem] uppercase tracking-[0.12em] text-paper/30">
                        <th class="px-5 py-2.5 font-medium">Title</th>
                        <th class="px-5 py-2.5 font-medium">Category</th>
                        <th class="px-5 py-2.5 font-medium">By</th>
                        <th class="px-5 py-2.5 font-medium">Status</th>
                        <th class="px-5 py-2.5 text-right font-medium">Added</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-hairline">
                    @forelse ($this->recent as $sound)
                        <tr class="transition hover:bg-paper/[0.03]" wire:key="rec-{{ $sound->id }}">
                            <td class="max-w-0 px-5 py-3">
                                <span class="block truncate text-[0.88rem]">{{ $sound->title }}</span>
                            </td>
                            <td class="px-5 py-3 text-[0.83rem] text-paper/45">{{ $sound->category?->name ?? '—' }}</td>
                            <td class="px-5 py-3 text-[0.83rem] text-paper/45">{{ $sound->user?->name ?? '—' }}</td>
                            <td class="px-5 py-3">
                                @php
                                    $map = [
                                        'published' => ['Live', 'bg-success/15 text-success'],
                                        'pending' => ['In review', 'bg-info/15 text-info'],
                                        'processing' => ['Processing', 'bg-paper/10 text-paper/50'],
                                        'draft' => ['Queued', 'bg-paper/10 text-paper/50'],
                                        'rejected' => ['Rejected', 'bg-danger/12 text-danger'],
                                    ];
                                    [$label, $classes] = $map[$sound->status] ?? [$sound->status, 'bg-paper/10 text-paper/50'];
                                @endphp
                                <span class="rounded-full px-2.5 py-1 text-[0.72rem] {{ $classes }}">{{ $label }}</span>
                            </td>
                            <td class="px-5 py-3 text-right text-[0.8rem] text-paper/35">{{ $sound->created_at->diffForHumans(short: true) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-5 py-10 text-center text-[0.88rem] text-paper/40">Nothing uploaded yet</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
