<?php

use App\Models\ActivityLog;
use App\Models\Search;
use App\Models\Synonym;
use App\Services\SearchIndex;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Search')] class extends Component {
    use WithPagination;

    #[Url(except: 'not-found')] public string $tab = 'not-found';
    #[Url(as: 'q', except: '')] public string $search = '';
    #[Url(except: false)] public bool $includeHandled = false;

    /** Inline "turn this failed search into a synonym" row. */
    public ?int $linking = null;
    public string $synonymTerm = '';
    public string $synonymWords = '';

    // ── Synonyms tab ──
    public string $newTerm = '';
    public string $newWords = '';
    public ?int $editingSynonym = null;
    public string $editWords = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    // ---------------------------------------------------------------
    // Health & numbers
    // ---------------------------------------------------------------

    #[Computed]
    public function health(): array
    {
        return app(SearchIndex::class)->health();
    }

    #[Computed]
    public function expected(): int
    {
        return app(SearchIndex::class)->expected();
    }

    #[Computed]
    public function stats(): array
    {
        $searches = (int) Search::recent(30)->sum('count');
        $downloads = (int) Search::recent(30)->sum('downloads');

        return [
            [
                'label' => 'Searches · 30d',
                'value' => number_format($searches),
                'icon' => 'magnifying-glass',
                'tone' => 'neutral',
            ],
            [
                'label' => 'Found nothing',
                'value' => Search::open()->notFound()->count(),
                'icon' => 'circle-xmark',
                'tone' => 'danger',
                'tab' => 'not-found',
            ],
            [
                'label' => 'No clicks',
                'value' => Search::open()->noClicks()->count(),
                'icon' => 'hand-pointer',
                'tone' => 'warning',
                'tab' => 'no-clicks',
            ],
            [
                'label' => 'Synonyms',
                'value' => count(app(SearchIndex::class)->synonyms()),
                'icon' => 'arrow-right-arrow-left',
                'tone' => 'info',
                'tab' => 'synonyms',
            ],
            [
                // The one number that says whether search is doing its job.
                'label' => 'Search → download',
                'value' => $searches ? round($downloads / $searches * 100).'%' : '—',
                'icon' => 'arrow-down-to-line',
                'tone' => 'success',
            ],
        ];
    }

    #[Computed]
    public function rows()
    {
        return Search::query()
            ->when($this->tab === 'not-found', fn ($q) => $q->notFound())
            ->when($this->tab === 'no-clicks', fn ($q) => $q->noClicks())
            ->unless($this->includeHandled || $this->tab === 'all', fn ($q) => $q->open())
            ->when($this->search, fn ($q, $s) => $q->where('term', 'like', "%{$s}%"))
            ->with('synonym')
            // Most searched first: the term twenty people wanted matters more
            // than the one somebody tried once.
            ->orderByDesc('count')
            ->orderByDesc('last_seen_at')
            ->paginate(12);
    }

    #[Computed]
    public function synonyms()
    {
        $index = app(SearchIndex::class);
        $custom = Synonym::with('search')->orderBy('term')->get()->keyBy('term');

        return collect($index->builtIn())
            ->map(fn ($words, $term) => [
                'term' => $term,
                'words' => $words,
                'model' => $custom[$term] ?? null,
                'builtIn' => true,
            ])
            ->merge(
                $custom->reject(fn ($row) => isset($index->builtIn()[$row->term]))
                    ->map(fn ($row) => [
                        'term' => $row->term,
                        'words' => $row->replacements,
                        'model' => $row,
                        'builtIn' => false,
                    ])
            )
            ->sortBy('term')
            ->values();
    }

    // ---------------------------------------------------------------
    // Working the list
    // ---------------------------------------------------------------

    public function setStatus(int $id, string $status): void
    {
        abort_unless(in_array($status, [Search::STATUS_OPEN, Search::STATUS_RESOLVED, Search::STATUS_IGNORED], true), 422);

        Search::whereKey($id)->update(['status' => $status]);

        unset($this->rows, $this->stats);
    }

    public function startLink(int $id): void
    {
        $row = Search::findOrFail($id);

        $this->linking = $id;
        // Pre-filled with the term that failed: nine times out of ten the
        // fix is "this word means the same as one I already use".
        $this->synonymTerm = $row->term;
        $this->synonymWords = '';
        $this->resetErrorBag();
    }

    public function cancelLink(): void
    {
        $this->reset(['linking', 'synonymTerm', 'synonymWords']);
        $this->resetErrorBag();
    }

    public function saveLink(): void
    {
        $this->validate([
            'synonymTerm' => ['required', 'string', 'max:80'],
            'synonymWords' => ['required', 'string', 'max:400'],
        ], [
            'synonymWords.required' => 'Which words should find the same thing? Separate them with commas.',
        ]);

        $words = Synonym::parse($this->synonymWords);

        if ($words === []) {
            $this->addError('synonymWords', 'Nothing usable in there.');

            return;
        }

        $term = Search::normalise($this->synonymTerm);

        Synonym::updateOrCreate(
            ['term' => $term],
            ['replacements' => $words, 'search_id' => $this->linking, 'user_id' => auth()->id()],
        );

        // Solved by a synonym, so it stops cluttering the list.
        Search::whereKey($this->linking)->update(['status' => Search::STATUS_RESOLVED]);

        $this->publish("Synonym added: {$term} = ".implode(', ', $words));
        $this->cancelLink();
    }

    // ---------------------------------------------------------------
    // Synonyms tab
    // ---------------------------------------------------------------

    public function addSynonym(): void
    {
        $this->validate([
            'newTerm' => ['required', 'string', 'max:80'],
            'newWords' => ['required', 'string', 'max:400'],
        ]);

        $words = Synonym::parse($this->newWords);
        $term = Search::normalise($this->newTerm);

        if ($words === []) {
            $this->addError('newWords', 'Nothing usable in there.');

            return;
        }

        Synonym::updateOrCreate(
            ['term' => $term],
            ['replacements' => $words, 'user_id' => auth()->id()],
        );

        $this->reset(['newTerm', 'newWords']);
        $this->publish("Synonym saved: {$term}");
    }

    /**
     * Built-in groups live in a config file. Editing one copies it into the
     * database first, so the file is never the thing you have to go and
     * change.
     */
    public function editSynonym(string $term): void
    {
        $index = app(SearchIndex::class);
        $words = $index->synonyms()[$term] ?? [];

        $model = Synonym::firstOrCreate(
            ['term' => $term],
            ['replacements' => $words, 'user_id' => auth()->id()],
        );

        $this->editingSynonym = $model->id;
        $this->editWords = implode(', ', $words);
        $this->resetErrorBag();
    }

    public function saveSynonym(): void
    {
        $this->validate(['editWords' => ['required', 'string', 'max:400']]);

        $words = Synonym::parse($this->editWords);

        if ($words === []) {
            $this->addError('editWords', 'Nothing usable in there.');

            return;
        }

        Synonym::whereKey($this->editingSynonym)->update(['replacements' => $words]);

        $this->reset(['editingSynonym', 'editWords']);
        $this->publish('Synonym updated.');
    }

    public function deleteSynonym(int $id): void
    {
        $synonym = Synonym::findOrFail($id);
        $term = $synonym->term;

        $synonym->delete();

        $this->publish("“{$term}” removed. If it also exists in the config file, the built-in group applies again.");
    }

    /** Save is not enough: the engine has to be told. */
    protected function publish(string $message): void
    {
        $error = app(SearchIndex::class)->push();

        ActivityLog::record('search.synonyms.updated', null, $message);

        unset($this->synonyms, $this->stats, $this->rows, $this->health);

        if ($error) {
            // The row IS in the database. Only the engine missed it, and the
            // next successful push sends everything at once — so say that,
            // instead of leaving him wondering whether the work was lost.
            session()->flash('error', $error.' Your change is saved but not live yet — press “Push synonyms” once it is running.');

            return;
        }

        session()->flash('ok', $message.' The index has been updated.');
    }

    public function reindex(): void
    {
        $error = app(SearchIndex::class)->push();

        unset($this->health);

        session()->flash($error ? 'error' : 'ok',
            $error ?: count(app(SearchIndex::class)->synonyms()).' synonym groups sent to Meilisearch.');
    }
}; ?>

<div>
    @if (session('ok'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-success/30 bg-success/10 px-4 py-3">
            <x-icon name="circle-check" style="solid" class="text-success" />
            <span class="text-[0.88rem]">{{ session('ok') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-5 flex items-start gap-3 rounded-xl border border-danger/30 bg-danger/10 px-4 py-3.5">
            <x-icon name="triangle-exclamation" style="solid" class="mt-0.5 text-danger" />

            <div class="min-w-0 flex-1">
                <p class="text-[0.88rem]">{{ session('error') }}</p>

                {{-- An error message that does not say what to do next is
                     half an error message. --}}
                @unless ($this->health['up'])
                    <p class="mt-2 text-[0.8rem] text-paper/45">Start the engine in its own terminal:</p>
                    <code class="mt-1.5 block rounded-lg bg-rail px-3.5 py-2.5 font-mono text-[0.78rem] text-paper/70">~/meilisearch/meilisearch</code>
                @endunless
            </div>
        </div>
    @endif

    {{-- ══════ ENGINE HEALTH ══════
         First thing on the screen because everything below it is a lie when
         the engine is down — and when it is down, nothing else says so. --}}
    <div @class([
        'mb-5 flex flex-wrap items-center gap-4 rounded-2xl border px-5 py-4',
        'border-danger/30 bg-danger/10' => ! $this->health['up'],
        'border-warning/30 bg-warning/10' => $this->health['up'] && $this->health['documents'] !== $this->expected,
        'border-hairline bg-panel' => $this->health['up'] && $this->health['documents'] === $this->expected,
    ])>
        <span @class([
            'grid size-10 shrink-0 place-items-center rounded-full',
            'bg-danger/20 text-danger' => ! $this->health['up'],
            'bg-warning/20 text-warning' => $this->health['up'] && $this->health['documents'] !== $this->expected,
            'bg-success/15 text-success' => $this->health['up'] && $this->health['documents'] === $this->expected,
        ])>
            <x-icon :name="$this->health['up'] ? ($this->health['documents'] === $this->expected ? 'circle-check' : 'triangle-exclamation') : 'plug-circle-xmark'"
                    style="solid" class="text-[14px]" />
        </span>

        <div class="min-w-0 flex-1">
            @if (! $this->health['up'])
                <div class="text-[0.92rem] font-medium">Meilisearch is not answering</div>
                <div class="mt-0.5 truncate text-[0.78rem] text-paper/45">
                    The catalogue cannot be searched right now. {{ Str::limit($this->health['error'], 90) }}
                </div>
            @elseif ($this->health['documents'] !== $this->expected)
                <div class="text-[0.92rem] font-medium">The index is out of step</div>
                <div class="mt-0.5 text-[0.78rem] text-paper/45">
                    {{ number_format($this->health['documents']) }} indexed, {{ number_format($this->expected) }} published.
                    @if ($this->health['indexing']) Indexing right now — give it a moment. @endif
                </div>
            @else
                <div class="text-[0.92rem] font-medium">
                    Search is healthy
                    <span class="ml-1.5 text-[0.8rem] font-normal text-paper/40">{{ number_format($this->health['documents']) }} sounds indexed</span>
                </div>
            @endif
        </div>

        <div class="flex items-center gap-2">
            <button wire:click="reindex"
                    @class([
                        'flex items-center gap-2 rounded-lg px-4 py-2.5 text-[0.83rem] transition duration-200 ease-dbelo',
                        'bg-raised text-paper/70 hover:bg-paper/[0.10] hover:text-paper' => $this->health['up'],
                        'bg-danger/15 text-danger hover:bg-danger/25' => ! $this->health['up'],
                    ])>
                <x-icon :name="$this->health['up'] ? 'arrows-rotate' : 'rotate-right'" style="solid"
                        class="text-[11px]" wire:loading.class="fa-spin" wire:target="reindex" />
                {{ $this->health['up'] ? 'Push synonyms' : 'Try again' }}
            </button>

            @if (! $this->health['up'] || $this->health['documents'] !== $this->expected)
                <span class="group/tip relative">
                    <x-icon name="circle-question" style="solid" class="text-[13px] text-paper/30" />
                    <span class="pointer-events-none absolute bottom-full right-0 z-50 mb-2 w-72 rounded-lg bg-paper px-3.5 py-3 text-left text-[0.75rem] leading-relaxed text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">
                        Start the engine with <span class="font-mono">~/meilisearch/meilisearch</span>, then run
                        <span class="font-mono">php artisan scout:import "App\Models\Sound"</span> to refill the index.
                    </span>
                </span>
            @endif
        </div>
    </div>

    {{-- ══════ STATS ══════ --}}
    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ($this->stats as $stat)
            @php $isLink = isset($stat['tab']); @endphp

            {{-- The three tiles that map to a tab are buttons: seeing "12
                 found nothing" and not being able to click it is a small
                 daily annoyance. The other two are plain readouts. --}}
            <button type="button"
                @if ($isLink) wire:click="$set('tab', '{{ $stat['tab'] }}')" @endif
                @class([
                    'rounded-2xl border bg-panel p-5 text-left transition duration-300 ease-dbelo',
                    'border-paper/20' => $isLink && $tab === ($stat['tab'] ?? null),
                    'border-hairline hover:border-paper/15' => $isLink && $tab !== ($stat['tab'] ?? null),
                    'border-hairline cursor-default' => ! $isLink,
                ])>
                <div class="flex items-start justify-between">
                    <span class="text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">{{ $stat['label'] }}</span>
                    <span @class([
                        'grid size-9 place-items-center rounded-full transition duration-200 ease-dbelo',
                        'bg-danger/15 text-danger' => $stat['tone'] === 'danger' && $stat['value'] > 0,
                        'bg-warning/15 text-warning' => $stat['tone'] === 'warning' && $stat['value'] > 0,
                        'bg-info/15 text-info' => $stat['tone'] === 'info',
                        'bg-success/15 text-success' => $stat['tone'] === 'success',
                        'bg-raised text-paper/40' => $stat['tone'] === 'neutral'
                            || (in_array($stat['tone'], ['danger', 'warning'], true) && ! $stat['value']),
                    ])>
                        <x-icon :name="$stat['icon']" style="solid" class="text-[13px]" />
                    </span>
                </div>
                <div class="mt-3 text-[1.9rem] font-semibold leading-none tracking-[-0.03em]">{{ $stat['value'] }}</div>
            </button>
        @endforeach
    </div>

    {{-- ══════ TABS ══════ --}}
    <div class="mb-5 flex flex-wrap gap-1.5 rounded-2xl border border-hairline bg-panel p-1.5">
        @foreach ([
            'not-found' => ['Found nothing', 'circle-xmark'],
            'no-clicks' => ['No clicks', 'hand-pointer'],
            'all' => ['Every search', 'list'],
            'synonyms' => ['Synonyms', 'arrow-right-arrow-left'],
        ] as $key => [$label, $icon])
            <button wire:click="$set('tab', '{{ $key }}')"
                    @class([
                        'flex items-center gap-2 rounded-xl px-4 py-2.5 text-[0.84rem] transition duration-200 ease-dbelo',
                        'bg-raised text-paper' => $tab === $key,
                        'text-paper/45 hover:text-paper' => $tab !== $key,
                    ])>
                <x-icon :name="$icon" style="solid" class="text-[11px]" />
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($tab !== 'synonyms')
        {{-- ══════════════════════════════════════════════
             THE LISTS
        ══════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">

            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
                <div class="min-w-0">
                    <h2 class="text-[0.95rem] font-medium">
                        @if ($tab === 'not-found') Searched, found nothing
                        @elseif ($tab === 'no-clicks') Found results, nobody clicked
                        @else Every search
                        @endif
                        <span class="ml-1.5 text-paper/35">{{ $this->rows->total() }}</span>
                    </h2>
                    <p class="mt-0.5 text-[0.75rem] text-paper/30">
                        @if ($tab === 'not-found')
                            Your shopping list: what to record or license next.
                        @elseif ($tab === 'no-clicks')
                            You have something, and it is not what they wanted. Usually a tagging or titling fix.
                        @else
                            Everything visitors typed, most searched first.
                        @endif
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($tab !== 'all')
                        <button wire:click="$toggle('includeHandled')"
                                @class([
                                    'rounded-full px-3.5 py-2 text-[0.78rem] transition duration-200 ease-dbelo',
                                    'bg-raised text-paper' => $includeHandled,
                                    'text-paper/45 hover:text-paper' => ! $includeHandled,
                                ])>
                            Show handled
                        </button>
                    @endif

                    <div class="flex items-center gap-2.5 rounded-lg bg-raised px-3 py-2">
                        <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Filter…"
                               class="w-32 border-0 bg-transparent p-0 text-[0.83rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-0" />
                    </div>
                </div>
            </div>

            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                        <th class="w-12 px-5 py-2.5 font-medium">#</th>
                        <th class="px-3 py-2.5 font-medium">Term</th>
                        <th class="w-20 px-3 py-2.5 font-medium">Times</th>
                        <th class="w-24 px-3 py-2.5 font-medium">Results</th>
                        <th class="w-24 px-3 py-2.5 font-medium">Clicks</th>
                        <th class="w-32 px-3 py-2.5 font-medium">Last 14 days</th>
                        <th class="w-28 px-3 py-2.5 font-medium">Last seen</th>
                        <th class="w-[150px] px-5 py-2.5 text-right font-medium">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-hairline">
                    @php $row = ($this->rows->currentPage() - 1) * $this->rows->perPage(); @endphp

                    @forelse ($this->rows as $item)
                        @php
                            $row++;
                            $trend = $item->trend(14);
                            $peak = max(1, max($trend));
                        @endphp

                        <tr wire:key="s-{{ $item->id }}" @class([
                            'transition hover:bg-paper/[0.03]',
                            'opacity-45' => ! $item->isOpen(),
                        ])>
                            <td class="px-5 py-3 text-[0.8rem] tabular-nums text-paper/25">{{ $row }}</td>

                            <td class="px-3 py-3">
                                <div class="flex items-center gap-2">
                                    <span class="truncate text-[0.89rem]">{{ $item->raw }}</span>

                                    @if ($item->isRising())
                                        <span class="group/tip relative shrink-0">
                                            <x-icon name="arrow-trend-up" style="solid" class="text-[11px] text-success" />
                                            <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">
                                                Searched more this week than last
                                            </span>
                                        </span>
                                    @endif

                                    @if ($item->synonym)
                                        <span class="group/tip relative shrink-0">
                                            <x-icon name="arrow-right-arrow-left" style="solid" class="text-[11px] text-info" />
                                            <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">
                                                Has a synonym: {{ implode(', ', $item->synonym->replacements) }}
                                            </span>
                                        </span>
                                    @endif

                                    @unless ($item->isOpen())
                                        <span @class([
                                            'shrink-0 rounded-full px-2 py-0.5 text-[0.65rem]',
                                            'bg-success/15 text-success' => $item->status === 'resolved',
                                            'bg-raised text-paper/35' => $item->status === 'ignored',
                                        ])>{{ $item->status }}</span>
                                    @endunless
                                </div>
                            </td>

                            <td class="px-3 py-3 text-[0.82rem] tabular-nums text-paper/60">{{ number_format($item->count) }}</td>

                            <td class="px-3 py-3">
                                <span @class([
                                    'text-[0.82rem] tabular-nums',
                                    'text-danger' => $item->results === 0,
                                    'text-paper/55' => $item->results > 0,
                                ])>{{ number_format($item->results) }}</span>
                            </td>

                            <td class="px-3 py-3">
                                <span @class([
                                    'text-[0.82rem] tabular-nums',
                                    'text-warning' => $item->results > 0 && $item->clicks === 0,
                                    'text-success' => $item->clicks > 0,
                                    'text-paper/20' => $item->results === 0,
                                ])>{{ $item->results === 0 ? '—' : number_format($item->clicks) }}</span>
                            </td>

                            {{-- Same thin bars as the waveform: the shape is
                                 the information, the numbers are in the tooltip. --}}
                            <td class="px-3 py-3">
                                <div class="group/tip relative flex h-6 items-end gap-[2px]">
                                    @foreach ($trend as $day)
                                        <span class="flex-1 rounded-full {{ $day ? 'bg-brand' : 'bg-paper/10' }}"
                                              style="height: {{ $day ? max(12, round($day / $peak * 100)) : 8 }}%"></span>
                                    @endforeach
                                    <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">
                                        {{ array_sum($trend) }} in 14 days
                                    </span>
                                </div>
                            </td>

                            <td class="px-3 py-3 text-[0.78rem] text-paper/40">
                                {{ $item->last_seen_at?->diffForHumans(short: true) ?? '—' }}
                            </td>

                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('sounds.index', ['q' => $item->raw]) }}" target="_blank">
                                        <x-admin.icon-button icon="arrow-up-right-from-square" label="Try it on the site" />
                                    </a>

                                    <x-admin.icon-button icon="arrow-right-arrow-left" label="Make it a synonym"
                                                         class="!text-info hover:!bg-info/15"
                                                         wire:click="startLink({{ $item->id }})" />

                                    @if ($item->isOpen())
                                        <x-admin.icon-button icon="check" label="Mark as handled"
                                                             class="hover:!bg-success/15 hover:!text-success"
                                                             wire:click="setStatus({{ $item->id }}, 'resolved')" />

                                        <x-admin.icon-button icon="eye-slash" label="Ignore — noise, not demand"
                                                             variant="muted"
                                                             wire:click="setStatus({{ $item->id }}, 'ignored')" />
                                    @else
                                        <x-admin.icon-button icon="rotate-left" label="Put back on the list"
                                                             wire:click="setStatus({{ $item->id }}, 'open')" />
                                    @endif
                                </div>
                            </td>
                        </tr>

                        @if ($linking === $item->id)
                            <tr wire:key="link-{{ $item->id }}" class="bg-raised">
                                <td colspan="8" class="px-5 py-4">
                                    <div class="flex items-start gap-3">
                                        <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full bg-info/15 text-info">
                                            <x-icon name="arrow-right-arrow-left" style="solid" class="text-[12px]" />
                                        </span>

                                        <div class="min-w-0 flex-1">
                                            <p class="text-[0.88rem] font-medium">Teach the search that these mean the same</p>
                                            <p class="mt-1 text-[0.78rem] leading-relaxed text-paper/45">
                                                Most failed searches are not missing audio — they are a word you do not use.
                                                If someone looks for “{{ $item->raw }}” and your files say something else,
                                                this fixes it without recording anything.
                                            </p>

                                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                                <input type="text" wire:model="synonymTerm"
                                                       class="w-44 rounded-lg border-0 bg-panel px-3.5 py-2.5 text-[0.88rem] text-paper focus:outline-none focus:ring-2 focus:ring-info/40" />

                                                <x-icon name="equals" style="solid" class="text-[11px] text-paper/25" />

                                                <input type="text" wire:model="synonymWords" autofocus
                                                       wire:keydown.enter="saveLink"
                                                       placeholder="car, automobile, vehicle"
                                                       class="min-w-0 flex-1 rounded-lg border-0 bg-panel px-3.5 py-2.5 text-[0.88rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-info/40" />

                                                <button wire:click="saveLink"
                                                        class="flex items-center gap-2 rounded-lg bg-action px-5 py-2.5 text-[0.85rem] font-medium text-white transition hover:brightness-110">
                                                    <x-icon name="check" style="solid" class="text-[11px]" />
                                                    Save
                                                </button>
                                                <button wire:click="cancelLink"
                                                        class="rounded-lg bg-panel px-4 py-2.5 text-[0.85rem] text-paper/55 transition hover:text-paper">
                                                    Cancel
                                                </button>
                                            </div>

                                            @error('synonymWords') <p class="mt-2 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                                            @error('synonymTerm') <p class="mt-2 text-[0.8rem] text-danger">{{ $message }}</p> @enderror

                                            <p class="mt-2 text-[0.75rem] text-paper/30">
                                                Saved and pushed to the engine straight away. The term is marked as handled.
                                            </p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="8" class="px-5 py-16 text-center">
                                <x-icon name="magnifying-glass" style="regular" class="text-[24px] text-paper/20" />
                                <p class="mt-3 text-[0.9rem] text-paper/45">
                                    @if ($search)
                                        Nothing matches that filter
                                    @elseif ($tab === 'not-found')
                                        Nothing came back empty. Either the catalogue covers what people want, or nobody has searched yet.
                                    @elseif ($tab === 'no-clicks')
                                        Every search that returned something got a click.
                                    @else
                                        No searches recorded yet
                                    @endif
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if ($this->rows->hasPages())
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-hairline px-5 py-3.5">
                    <span class="text-[0.78rem] text-paper/35">
                        {{ $this->rows->firstItem() }}–{{ $this->rows->lastItem() }} of {{ $this->rows->total() }}
                    </span>

                    <div class="flex items-center gap-1.5">
                        <x-admin.icon-button icon="chevron-left" variant="muted" label="Previous"
                                             wire:click="previousPage" @disabled($this->rows->onFirstPage()) />

                        @foreach ($this->rows->getUrlRange(max(1, $this->rows->currentPage() - 2), min($this->rows->lastPage(), $this->rows->currentPage() + 2)) as $page => $url)
                            <button wire:click="gotoPage({{ $page }})" wire:key="spg-{{ $page }}"
                                    @class([
                                        'grid size-8 place-items-center rounded-full text-[0.78rem] tabular-nums transition duration-200 ease-dbelo',
                                        'bg-brand text-white' => $page === $this->rows->currentPage(),
                                        'text-paper/40 hover:bg-paper/[0.10] hover:text-paper' => $page !== $this->rows->currentPage(),
                                    ])>{{ $page }}</button>
                        @endforeach

                        <x-admin.icon-button icon="chevron-right" variant="muted" label="Next"
                                             wire:click="nextPage" @disabled(! $this->rows->hasMorePages()) />
                    </div>
                </div>
            @endif
        </div>
    @else
        {{-- ══════════════════════════════════════════════
             SYNONYMS — two columns, add on the left
        ══════════════════════════════════════════════ --}}
        <div class="grid gap-5 xl:grid-cols-[340px_1fr]">

            <div class="h-fit space-y-5">
                <div class="rounded-2xl border border-hairline bg-panel">
                    <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                        <x-admin.icon-chip icon="arrow-right-arrow-left" tone="brand" />
                        <h2 class="text-[0.95rem] font-medium">Add a group</h2>
                    </div>

                    <form wire:submit="addSynonym" class="space-y-4 p-5">
                        <div>
                            <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Term</label>
                            <input type="text" wire:model="newTerm" placeholder="car"
                                   class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.9rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                            @error('newTerm') <p class="mt-1.5 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Means the same as</label>
                            <input type="text" wire:model="newWords" placeholder="automobile, vehicle, auto"
                                   class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.9rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                            @error('newWords') <p class="mt-1.5 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                            <p class="mt-1.5 text-[0.75rem] leading-relaxed text-paper/30">
                                Separated by commas. Someone searching any of them finds everything tagged with the term.
                            </p>
                        </div>

                        <button type="submit"
                                class="flex w-full items-center justify-center gap-2 rounded-lg bg-action py-2.5 text-[0.88rem] font-medium text-white transition duration-200 ease-dbelo hover:brightness-110">
                            <x-icon name="plus" style="solid" class="text-[12px]" />
                            Add and push
                        </button>
                    </form>
                </div>

                <div class="rounded-2xl border border-hairline bg-panel p-5">
                    <div class="flex items-center gap-3">
                        <x-admin.icon-chip icon="lightbulb" tone="brand" />
                        <div class="min-w-0 flex-1">
                            <div class="text-[0.88rem]">{{ count($this->synonyms) }} groups</div>
                            <div class="text-[0.75rem] text-paper/30">pushed to the engine on save</div>
                        </div>
                    </div>

                    <p class="mt-4 text-[0.75rem] leading-relaxed text-paper/30">
                        Groups marked <span class="text-paper/50">built-in</span> come from
                        <span class="font-mono text-paper/50">config/scout.php</span>. Editing one copies it into the
                        database, so you never have to touch the file.
                    </p>
                </div>
            </div>

            <div class="min-w-0 rounded-2xl border border-hairline bg-panel">
                <div class="border-b border-hairline px-5 py-3.5">
                    <h2 class="text-[0.95rem] font-medium">
                        All groups <span class="ml-1.5 text-paper/35">{{ count($this->synonyms) }}</span>
                    </h2>
                </div>

                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                            <th class="w-12 px-5 py-2.5 font-medium">#</th>
                            <th class="w-40 px-3 py-2.5 font-medium">Term</th>
                            <th class="px-3 py-2.5 font-medium">Means the same as</th>
                            <th class="w-24 px-3 py-2.5 font-medium">Source</th>
                            <th class="w-[110px] px-5 py-2.5 text-right font-medium">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-hairline">
                        @forelse ($this->synonyms as $group)
                            <tr wire:key="syn-{{ $group['term'] }}" class="transition hover:bg-paper/[0.03]">
                                <td class="px-5 py-3 text-[0.8rem] tabular-nums text-paper/25">{{ $loop->iteration }}</td>

                                <td class="px-3 py-3 text-[0.89rem]">{{ $group['term'] }}</td>

                                <td class="px-3 py-3">
                                    @if ($editingSynonym && $group['model'] && $editingSynonym === $group['model']->id)
                                        <div class="flex items-center gap-2">
                                            <input type="text" wire:model="editWords" autofocus wire:keydown.enter="saveSynonym"
                                                   class="min-w-0 flex-1 rounded-lg border-0 bg-raised px-3 py-2 text-[0.85rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                                            <x-admin.icon-button icon="check" label="Save and push"
                                                                 class="!text-success hover:!bg-success/15" wire:click="saveSynonym" />
                                            <x-admin.icon-button icon="xmark" variant="muted" label="Cancel"
                                                                 wire:click="$set('editingSynonym', null)" />
                                        </div>
                                        @error('editWords') <p class="mt-1.5 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                                    @else
                                        <div class="flex flex-wrap gap-1.5">
                                            @foreach ($group['words'] as $word)
                                                <span class="rounded-full bg-raised px-2.5 py-1 text-[0.75rem] text-paper/60">{{ $word }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>

                                <td class="px-3 py-3">
                                    @if ($group['model'] && ! $group['builtIn'])
                                        <span class="rounded-full bg-info/15 px-2.5 py-1 text-[0.7rem] text-info">custom</span>
                                    @elseif ($group['model'])
                                        <span class="rounded-full bg-brand/15 px-2.5 py-1 text-[0.7rem] text-brand">edited</span>
                                    @else
                                        <span class="rounded-full bg-raised px-2.5 py-1 text-[0.7rem] text-paper/35">built-in</span>
                                    @endif
                                </td>

                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-admin.icon-button icon="pen" label="Edit"
                                                             wire:click="editSynonym(@js($group['term']))" />

                                        @if ($group['model'])
                                            <x-admin.icon-button icon="trash" label="Delete"
                                                                 class="hover:!bg-danger/15 hover:!text-danger"
                                                                 wire:click="deleteSynonym({{ $group['model']->id }})"
                                                                 wire:confirm="Remove this group? Searches relying on it stop matching." />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-16 text-center">
                                    <x-icon name="arrow-right-arrow-left" style="regular" class="text-[24px] text-paper/20" />
                                    <p class="mt-3 text-[0.9rem] text-paper/45">No synonym groups yet</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
