<?php

use App\Models\ActivityLog;
use App\Support\ActivityCatalog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Activity log')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $actor = '';

    #[Url(except: '30')]
    public string $days = '30';

    /** Which row has its detail open. One at a time: this is a list, not a form. */
    public ?int $expanded = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    /**
     * Any filter change puts you back on page one.
     *
     * Livewire keeps the page number across updates, so without this a
     * filter applied from page 6 lands on an empty page 6 of the new
     * result — which reads as "no results" and is the wrong answer.
     */
    public function updated(string $key): void
    {
        if (in_array($key, ['search', 'category', 'actor', 'days'], true)) {
            $this->resetPage();
            $this->expanded = null;
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'category', 'actor', 'days', 'expanded']);
        $this->resetPage();
    }

    public function toggle(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    private function base()
    {
        return ActivityLog::query()
            ->search($this->search)
            ->when($this->category, fn ($q) => $q->category($this->category))
            ->when($this->actor, fn ($q) => $q->where('user_id', $this->actor))
            ->when($this->days !== 'all', fn ($q) => $q->where('created_at', '>=', now()->subDays((int) $this->days)))
            ->latest('created_at');
    }

    #[Computed]
    public function entries()
    {
        return $this->base()->paginate(30);
    }

    /**
     * Entries grouped by calendar day.
     *
     * A flat list of timestamps is hard to read; the question people bring
     * to this screen is nearly always "what happened around the time X went
     * wrong", and days are how they remember it.
     */
    #[Computed]
    public function grouped(): array
    {
        return $this->entries->groupBy(fn ($entry) => $entry->created_at->toDateString())->all();
    }

    /**
     * Who has ever done anything, for the actor filter.
     *
     * Read from the log itself, not from users: the point of the snapshot
     * column is that somebody who no longer has an account can still be the
     * answer to "who did this".
     */
    #[Computed]
    public function actors(): array
    {
        return ActivityLog::query()
            ->whereNotNull('user_id')
            ->select('user_id', 'actor_name')
            ->distinct()
            ->orderBy('actor_name')
            ->get()
            ->map(fn ($row) => ['id' => $row->user_id, 'name' => $row->actor_name ?: 'Unknown'])
            ->unique('id')
            ->values()
            ->all();
    }

    /** Counts per category within the current date range, for the filter chips. */
    #[Computed]
    public function categoryCounts(): array
    {
        $counts = [];

        foreach (array_keys(ActivityCatalog::CATEGORIES) as $category) {
            $counts[$category] = ActivityLog::query()
                ->category($category)
                ->when($this->days !== 'all', fn ($q) => $q->where('created_at', '>=', now()->subDays((int) $this->days)))
                ->count();
        }

        return $counts;
    }

    #[Computed]
    public function oldest(): ?string
    {
        $date = ActivityLog::query()->min('created_at');

        return $date ? \Illuminate\Support\Carbon::parse($date)->diffForHumans() : null;
    }

    #[Computed]
    public function total(): int
    {
        return ActivityLog::query()->count();
    }

    /**
     * The filtered set as CSV.
     *
     * The reason this exists: when somebody asks for an audit trail they
     * almost never want to look at a screen, they want the rows in a file
     * they can attach to something. Exporting what is on screen — the same
     * filters, not the whole table — is what makes it an answer to a
     * question rather than a data dump.
     *
     * Streamed, so a large export does not build the whole file in memory.
     */
    public function export()
    {
        $filename = 'dbelo-activity-'.now()->format('Y-m-d-His').'.csv';

        // The query is resolved inside the callback: by the time the stream
        // runs, the response has already been sent to the client.
        $query = $this->base();

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['When (UTC)', 'Actor', 'Action', 'Category', 'Description', 'Subject', 'IP', 'Details']);

            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->created_at?->toIso8601String(),
                        $row->actor_name ?? 'System',
                        $row->action,
                        ActivityCatalog::categoryOf($row->action),
                        $row->sentence(),
                        $row->subject_label,
                        $row->ip_address,
                        $row->meta ? json_encode($row->meta) : '',
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}; ?>

<div class="space-y-5">

    {{-- ══════════════════════════════════════════════════════════════
         FILTERS
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel">
        <div class="flex flex-wrap items-center gap-3 border-b border-hairline px-5 py-4">

            <div class="relative min-w-[220px] flex-1">
                {{-- The icon sits in a fixed-width well, not at a left
                     offset. Positioned by `left-3.5` its right edge depends
                     on how wide the glyph happens to render — which changes
                     with the icon, the font size, and whether Font Awesome
                     has loaded yet — so the text could start before the icon
                     ended. A well the same width as the padding cannot
                     collide: the two are the same number by construction. --}}
                <span class="pointer-events-none absolute inset-y-0 left-0 grid w-11 place-items-center">
                    <x-icon name="magnifying-glass" style="solid" class="text-[0.78rem] text-paper/30" />
                </span>
                <input type="search" wire:model.live.debounce.300ms="search"
                       placeholder="Who, what it was called, an IP…"
                       class="w-full rounded-lg border-0 bg-raised py-2.5 pl-11 pr-3.5 text-[0.84rem] placeholder:text-paper/30 focus:outline-none focus:ring-1 focus:ring-brand" />
            </div>

            <select wire:model.live="actor"
                    class="rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand">
                <option value="">Anyone</option>
                @foreach ($this->actors as $person)
                    <option value="{{ $person['id'] }}">{{ $person['name'] }}</option>
                @endforeach
            </select>

            <select wire:model.live="days"
                    class="rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand">
                <option value="7">Last 7 days</option>
                <option value="30">Last 30 days</option>
                <option value="90">Last 90 days</option>
                <option value="365">Last year</option>
                <option value="all">Everything kept</option>
            </select>

            <button type="button" wire:click="export"
                    class="flex items-center gap-2 rounded-lg bg-raised px-3.5 py-2.5 text-[0.82rem] transition hover:bg-brand hover:text-white">
                <x-icon name="file-csv" style="solid" class="text-[0.78rem]" />
                Export
            </button>
        </div>

        {{-- Category chips carry their counts. A filter that shows how much
             is behind it saves the click that finds out it was zero. --}}
        <div class="flex flex-wrap gap-2 px-5 py-3.5">
            <button type="button" wire:click="$set('category', '')"
                    @class([
                        'rounded-full px-3.5 py-1.5 text-[0.78rem] transition duration-200 ease-dbelo',
                        'bg-brand text-white' => $category === '',
                        'bg-raised text-paper/55 hover:text-paper' => $category !== '',
                    ])>
                Everything
            </button>

            @foreach (App\Support\ActivityCatalog::CATEGORIES as $key => $meta)
                <button type="button" wire:click="$set('category', '{{ $key }}')" wire:key="cat-{{ $key }}"
                        @class([
                            'flex items-center gap-2 rounded-full px-3.5 py-1.5 text-[0.78rem] transition duration-200 ease-dbelo',
                            'bg-brand text-white' => $category === $key,
                            'bg-raised text-paper/55 hover:text-paper' => $category !== $key,
                        ])>
                    <x-icon :name="$meta['icon']" style="solid" class="text-[0.7rem]" />
                    {{ $meta['label'] }}
                    <span class="tabular-nums opacity-50">{{ $this->categoryCounts[$key] }}</span>
                </button>
            @endforeach

            @if ($search || $category || $actor || $days !== '30')
                <button type="button" wire:click="clearFilters"
                        class="rounded-full px-3 py-1.5 text-[0.78rem] text-paper/40 transition hover:text-paper">
                    Clear
                </button>
            @endif
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         THE TRAIL
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel">

        @forelse ($this->grouped as $day => $entries)
            <div class="sticky top-0 z-10 border-b border-hairline bg-panel/95 px-5 py-2.5 backdrop-blur">
                <span class="text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">
                    {{ \Illuminate\Support\Carbon::parse($day)->isToday() ? 'Today' : \Illuminate\Support\Carbon::parse($day)->format('D j M Y') }}
                </span>
            </div>

            <div class="divide-y divide-hairline">
                @foreach ($entries as $entry)
                    @php
                        $meaning = $entry->meaning();
                        $tone = App\Support\ActivityCatalog::toneClasses($meaning['severity']);
                    @endphp

                    <div wire:key="entry-{{ $entry->id }}">
                        <button type="button" wire:click="toggle({{ $entry->id }})"
                                class="flex w-full items-start gap-3.5 px-5 py-3.5 text-left transition duration-200 ease-dbelo hover:bg-raised/40">

                            <span class="mt-2 size-1.5 shrink-0 rounded-full {{ $tone['dot'] }}"></span>

                            <span class="grid size-8 shrink-0 place-items-center rounded-full {{ $tone['chip'] }}">
                                <x-icon :name="$meaning['icon']" style="solid" class="text-[12px]" />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block text-[0.88rem] text-paper/80">{{ $entry->sentence() }}</span>

                                <span class="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[0.75rem] text-paper/35">
                                    <span>{{ $entry->actor_name ?? 'System' }}</span>
                                    <span class="opacity-40">·</span>
                                    <span>{{ $meaning['label'] }}</span>

                                    @if ($entry->ip_address)
                                        <span class="opacity-40">·</span>
                                        <span class="font-mono">{{ $entry->ip_address }}</span>
                                    @endif

                                    @if ($entry->isChange())
                                        <span class="rounded-full bg-raised px-2 py-0.5 text-[0.68rem] text-paper/45">
                                            before → after
                                        </span>
                                    @endif
                                </span>
                            </span>

                            <span class="shrink-0 text-right">
                                {{-- Exact time on hover: relative time is how
                                     people scan, absolute time is what they
                                     need the moment they are comparing this
                                     against a log somewhere else. --}}
                                <span class="block text-[0.75rem] tabular-nums text-paper/40"
                                      title="{{ $entry->created_at->toDayDateTimeString() }}">
                                    {{ $entry->created_at->format('H:i') }}
                                </span>
                            </span>
                        </button>

                        @if ($this->expanded === $entry->id)
                            <div class="border-t border-hairline bg-rail/40 px-5 py-4 pl-[4.4rem]">
                                <dl class="grid gap-x-8 gap-y-2 text-[0.8rem] sm:grid-cols-[auto_1fr]">

                                    <dt class="text-paper/35">Action</dt>
                                    <dd><code class="rounded bg-raised px-1.5 py-0.5 text-[0.74rem] text-paper/70">{{ $entry->action }}</code></dd>

                                    <dt class="text-paper/35">When</dt>
                                    <dd class="text-paper/70">
                                        {{ $entry->created_at->toDayDateTimeString() }}
                                        <span class="text-paper/30">({{ $entry->created_at->diffForHumans() }})</span>
                                    </dd>

                                    @if ($entry->subject_label)
                                        <dt class="text-paper/35">Subject</dt>
                                        <dd class="text-paper/70">
                                            {{ $entry->subject_label }}
                                            <span class="text-paper/30">
                                                {{ class_basename($entry->subject_type) }} #{{ $entry->subject_id }}
                                            </span>
                                        </dd>
                                    @endif

                                    @if ($entry->isChange())
                                        <dt class="text-paper/35">Changed</dt>
                                        <dd class="flex flex-wrap items-center gap-2">
                                            <span class="rounded bg-danger/10 px-2 py-1 text-[0.76rem] text-paper/60 line-through decoration-danger/50">
                                                {{ is_scalar($entry->meta['from'] ?? null) ? $entry->meta['from'] : json_encode($entry->meta['from'] ?? null) }}
                                            </span>
                                            <x-icon name="arrow-right" style="solid" class="text-[0.66rem] text-paper/25" />
                                            <span class="rounded bg-success/10 px-2 py-1 text-[0.76rem] text-paper/80">
                                                {{ is_scalar($entry->meta['to'] ?? null) ? $entry->meta['to'] : json_encode($entry->meta['to'] ?? null) }}
                                            </span>
                                        </dd>
                                    @endif

                                    @php
                                        $extra = collect($entry->meta ?? [])->except(['from', 'to'])->all();
                                    @endphp

                                    @if ($extra)
                                        <dt class="text-paper/35">Details</dt>
                                        <dd>
                                            <pre class="overflow-x-auto rounded-lg bg-rail px-3 py-2 font-mono text-[0.72rem] leading-relaxed text-paper/60">{{ json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                        </dd>
                                    @endif
                                </dl>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @empty
            <div class="px-5 py-16 text-center">
                <x-icon name="clock-rotate-left" style="solid" class="text-[24px] text-paper/20" />
                <p class="mt-3 text-[0.9rem] text-paper/45">Nothing recorded in this range</p>
                <p class="mt-1 text-[0.8rem] text-paper/30">
                    An empty trail on a quiet week is the normal state, not a fault.
                </p>
            </div>
        @endforelse

        <div class="border-t border-hairline">
            <x-admin.pagination :paginator="$this->entries" prefix="act" />
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         RETENTION

         On the page rather than in a document, because the question "how far
         back does this go?" is asked while looking at the log, and the honest
         answer is different per category.
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-4">
            <div>
                <h2 class="text-[0.95rem] font-medium">How long entries are kept</h2>
                <p class="mt-0.5 text-[0.78rem] text-paper/40">
                    Different questions have different shelf lives, so the log is pruned per category, not all at once.
                </p>
            </div>

            <span class="text-[0.78rem] tabular-nums text-paper/35">
                {{ number_format($this->total) }} entries{{ $this->oldest ? ' · oldest '.$this->oldest : '' }}
            </span>
        </div>

        <table class="w-full text-[0.84rem]">
            <tbody class="divide-y divide-hairline">
                @foreach (App\Support\ActivityCatalog::CATEGORIES as $key => $meta)
                    <tr wire:key="keep-{{ $key }}">
                        <td class="px-5 py-2.5">
                            <span class="flex items-center gap-2.5 text-paper/70">
                                <x-icon :name="$meta['icon']" style="solid" class="text-[0.78rem] text-paper/35" />
                                {{ $meta['label'] }}
                            </span>
                        </td>
                        <td class="px-5 py-2.5 text-right tabular-nums text-paper/55">
                            {{ $meta['keepDays'] >= 365 ? round($meta['keepDays'] / 365, 1).' years' : $meta['keepDays'].' days' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="border-t border-hairline px-5 py-3.5">
            <p class="text-[0.78rem] leading-relaxed text-paper/40">
                <x-icon name="lock" style="solid" class="mr-1 text-[0.72rem] text-info" />
                There is no delete button here, and there will not be one. A log that a person can rewrite is not evidence of
                anything, and whoever the awkward entry is about is the one most motivated to edit it. Rows leave only by the
                policy above, run daily by
                <code class="rounded bg-raised px-1.5 py-0.5 text-[0.72rem] text-paper/60">php artisan model:prune</code>.
            </p>
        </div>
    </div>
</div>
