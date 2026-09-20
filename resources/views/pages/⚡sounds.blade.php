<?php

use App\Models\Category;
use App\Models\Sound;
use App\Services\SearchLogger;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Meilisearch\Endpoints\Indexes;

new #[Layout('layouts.site')] #[Title('Sound effects')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $duration = '';

    #[Url(except: false)]
    public bool $freeOnly = false;

    #[Url(except: false)]
    public bool $loopsOnly = false;

    #[Url(except: 'relevance')]
    public string $sort = 'relevance';

    /** True when Meilisearch did not answer and the database took over. */
    public bool $degraded = false;

    public function mount(): void
    {
        $this->shareSeo();
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    /**
     * A filtered or searched catalogue is thin, duplicated content: it must
     * not be indexed, and it must point back at the clean /sounds URL.
     */
    protected function shareSeo(): void
    {
        $category = $this->category
            ? Category::where('slug', $this->category)->first()
            : null;

        $isFiltered = filled($this->search) || filled($this->duration)
            || $this->freeOnly || $this->loopsOnly;

        view()->share('seo', [
            'title' => $category
                ? $category->name.' sound effects'
                : 'Sound effects library',
            'description' => $category
                ? sprintf('Free %s sound effects, cleared for commercial use. Listen and download instantly.', strtolower($category->name))
                : 'Browse thousands of studio-grade sound effects. Free to listen, cleared for commercial use.',
            /*
             * ── THE PAGE NUMBER SURVIVES, EVERYTHING ELSE DOES NOT ──────
             *
             * This was the bare category URL, which meant page four of the
             * doors category told Google it was a copy of page one — and
             * with it went every sound linked from pages two onwards, which
             * on a catalogue is most of them.
             *
             * Canonical::for keeps the base's own query (the category IS
             * the page) and merges only ?page. The filters never reach it:
             * a filtered catalogue is noindex on the line below, so it has
             * no canonical worth arguing about.
             */
            'canonical' => \App\Support\Canonical::for(
                $category
                    ? route('sounds.index', ['category' => $category->slug])
                    : route('sounds.index'),
                request()->query(),
            ),
            'noindex' => $isFiltered,
        ]);
    }

    public function setCategory(string $slug): void
    {
        $this->category = $this->category === $slug ? '' : $slug;
        $this->resetPage();
    }

    public function setDuration(string $bucket): void
    {
        $this->duration = $this->duration === $bucket ? '' : $bucket;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'category', 'duration', 'freeOnly', 'loopsOnly', 'sort']);
        $this->resetPage();
    }

    #[Computed]
    public function categories()
    {
        return Category::roots()->withCount(['sounds' => fn ($q) => $q->published()])->get();
    }

    /**
     * Meilisearch filter expression built from the active facets.
     * Only the attributes listed as filterable in config/scout.php can
     * appear here.
     */
    protected function filterExpression(): string
    {
        $clauses = [];

        if ($this->category) {
            // Matches both a parent category and any of its children.
            $clauses[] = sprintf(
                '(category_slug = "%s" OR parent_category_slug = "%s")',
                $this->category,
                $this->category
            );
        }

        $clauses[] = match ($this->duration) {
            'short' => 'duration_ms < 1000',
            'medium' => 'duration_ms >= 1000 AND duration_ms <= 5000',
            'long' => 'duration_ms > 5000 AND duration_ms <= 30000',
            'xlong' => 'duration_ms > 30000',
            default => null,
        };

        if ($this->freeOnly) {
            $clauses[] = 'is_premium = false';
        }

        if ($this->loopsOnly) {
            $clauses[] = 'is_loopable = true';
        }

        return collect($clauses)->filter()->implode(' AND ');
    }

    /**
     * Relevance only means something when there is a query. Browsing with an
     * empty box falls back to newest first, otherwise Meilisearch would
     * return documents in index order, which looks random.
     */
    protected function sortExpression(): array
    {
        return match ($this->sort) {
            'newest' => ['published_at:desc'],
            'popular' => ['downloads_count:desc'],
            'shortest' => ['duration_ms:asc'],
            'longest' => ['duration_ms:desc'],
            'az' => ['title:asc'],
            default => $this->search === '' ? ['published_at:desc'] : [],
        };
    }

    #[Computed]
    public function sounds()
    {
        $filter = $this->filterExpression();
        $sort = $this->sortExpression();

        try {
            return Sound::search(
                $this->search,
                function (Indexes $index, string $query, array $options) use ($filter, $sort) {
                    if ($filter !== '') {
                        $options['filter'] = $filter;
                    }

                    if ($sort !== []) {
                        $options['sort'] = $sort;
                    }

                    return $index->search($query, $options);
                }
            )
                // Scout returns ids; this hydrates them with their relations in
                // one query instead of one per row.
                ->query(fn ($builder) => $builder->with(['files', 'category', 'license']))
                ->paginate(20);
        } catch (\Throwable $e) {
            // Meilisearch runs as a separate process. When it stops, the
            // catalogue must NOT stop with it: a visitor should get a worse
            // search, never a stack trace. Still reported, so it reaches the
            // log instead of disappearing.
            report($e);

            $this->degraded = true;

            return $this->fromDatabase();
        }
    }

    /**
     * The lifeboat: a plain LIKE over MySQL.
     *
     * No typo tolerance, no synonyms, no relevance ranking — it is visibly
     * worse and that is fine. It keeps the catalogue browsable, keeps every
     * filter working, and keeps Google's crawler seeing pages instead of
     * 500s while the engine is restarted.
     */
    protected function fromDatabase()
    {
        return Sound::published()
            ->with(['files', 'category', 'license'])
            ->when($this->search, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")
                ->orWhereHas('tags', fn ($t) => $t->where('name', 'like', "%{$term}%"))))
            ->when($this->category, fn ($q, $slug) => $q->whereHas('category',
                fn ($c) => $c->where('slug', $slug)
                    ->orWhereHas('parent', fn ($p) => $p->where('slug', $slug))))
            ->when($this->duration === 'short', fn ($q) => $q->where('duration_ms', '<', 1000))
            ->when($this->duration === 'medium', fn ($q) => $q->whereBetween('duration_ms', [1000, 5000]))
            ->when($this->duration === 'long', fn ($q) => $q->whereBetween('duration_ms', [5001, 30000]))
            ->when($this->duration === 'xlong', fn ($q) => $q->where('duration_ms', '>', 30000))
            ->when($this->freeOnly, fn ($q) => $q->where('is_premium', false))
            ->when($this->loopsOnly, fn ($q) => $q->where('is_loopable', true))
            ->tap(fn ($q) => match ($this->sort) {
                'popular' => $q->orderByDesc('downloads_count'),
                'shortest' => $q->orderBy('duration_ms'),
                'longest' => $q->orderByDesc('duration_ms'),
                'az' => $q->orderBy('title'),
                default => $q->latest('published_at'),
            })
            ->paginate(20);
    }

    /**
     * Called from the browser 1.5 seconds after typing stops.
     *
     * Not on every keystroke, and not on the 250 ms debounce that drives the
     * results: at 250 ms the log fills with "pue", "puer", "puert" and the
     * ranking that should tell us what to record becomes a ranking of
     * prefixes. By 1.5 seconds the word is finished. SearchLogger still
     * collapses whatever slips through.
     */
    public function logSearch(): void
    {
        if (trim($this->search) === '') {
            return;
        }

        app(SearchLogger::class)->record($this->search, $this->sounds->total());
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return filled($this->search) || filled($this->category) || filled($this->duration)
            || $this->freeOnly || $this->loopsOnly || $this->sort !== 'relevance';
    }
}; ?>

<div x-data="{
        /* Remembered across navigations so the panel does not spring back
           open every time a sound page is visited and left. */
        filters: window.__dbeloFilters ?? true,
        toggle() { this.filters = ! this.filters; window.__dbeloFilters = this.filters },
     }">

    {{-- ══════ HERO ══════ --}}
    <div class="relative py-10 text-center">
        <div class="pointer-events-none absolute inset-x-0 top-8 -z-10 mx-auto max-w-[700px] px-6 opacity-50 dark:opacity-40"
             style="mask-image: linear-gradient(to right, transparent, #000 25%, #000 75%, transparent);
                    -webkit-mask-image: linear-gradient(to right, transparent, #000 25%, #000 75%, transparent);">
            <x-ambient-wave :bars="140" height="h-24" />
        </div>

        <h1 class="text-[clamp(2.2rem,5vw,3.4rem)] font-bold">
            Find your <span class="key">sound</span>
        </h1>

        <div class="mx-auto mt-8 flex max-w-[660px] items-center gap-3 rounded-full bg-surface py-2.5 pl-6 pr-2.5 shadow-soft-md transition duration-400 ease-dbelo focus-within:shadow-soft-lg dark:bg-surface-dark">
            <x-icon name="magnifying-glass" style="solid" class="shrink-0 text-ink/35 dark:text-paper/35" />

            {{-- Two clocks on purpose: 250 ms drives the results so typing
                 feels instant, 1.5 s decides what gets written to the log. --}}
            <input type="search" wire:model.live.debounce.250ms="search"
                   x-data="{ t: null }"
                   @input="clearTimeout(t); t = setTimeout(() => $wire.logSearch(), 1500)"
                   placeholder="door creak, thunder, laser, footsteps on gravel…"
                   class="min-w-0 flex-1 border-0 bg-transparent p-0 text-base font-light placeholder:text-ink/35 focus:outline-none focus:ring-0 dark:placeholder:text-paper/35" />

            <span wire:loading wire:target="search" class="micro shrink-0 pr-3">Searching…</span>
        </div>

        <p class="micro mt-3">Typos are fine — “thundr” finds thunder</p>
    </div>

    {{-- Said plainly rather than hidden: results really are worse right now,
         and a visitor who cannot find something deserves to know why. --}}
    @if ($degraded)
        <div class="mb-5 flex flex-wrap items-center gap-3 rounded-card bg-warning/10 px-5 py-3.5">
            <x-icon name="triangle-exclamation" style="solid" class="text-warning" />
            <span class="text-[0.86rem] text-ink/70 dark:text-paper/70">
                Search is running in reduced mode — no typo tolerance and results are not ranked by relevance.
                Try a simpler word, or browse by category.
            </span>
        </div>
    @endif

    <div class="flex flex-col gap-6 lg:flex-row lg:items-start">

        {{-- ══════════════════════════════════════════════════════════
             FILTERS
             ══════════════════════════════════════════════════════════ --}}

        {{-- Mobile: a button that opens the same panel as a sheet. The panel
             below is hidden rather than duplicated — two copies of a filter
             list is two places for a checkbox to be wrong. --}}
        <button x-on:click="toggle()"
                class="flex items-center justify-between gap-3 rounded-card bg-surface px-5 py-3.5 shadow-soft-sm lg:hidden dark:bg-surface-dark">
            <span class="flex items-center gap-2.5 text-[0.9rem]">
                <x-icon name="sliders" style="solid" class="text-[0.85rem] text-ink/45 dark:text-paper/45" />
                Filters
                @if ($this->hasFilters)
                    <span class="rounded-full bg-brand px-2 py-0.5 text-[0.65rem] font-semibold text-white">on</span>
                @endif
            </span>
            <x-icon name="chevron-down" style="solid" class="text-[0.7rem] text-ink/35 transition dark:text-paper/35"
                    x-bind:class="filters && 'rotate-180'" />
        </button>

        {{-- Plain x-show rather than x-collapse: collapse animates height,
             which is right for an accordion and wrong for a column that
             should simply stop taking up width. --}}
        <aside x-show="filters" style="display: none"
               x-transition:enter="transition duration-300 ease-dbelo"
               x-transition:enter-start="opacity-0 -translate-x-2"
               x-transition:enter-end="opacity-100 translate-x-0"
               class="w-full shrink-0 lg:sticky lg:top-24 lg:w-[248px]">
            <div class="rounded-card bg-surface p-5 shadow-soft-md dark:bg-surface-dark">

                <div class="mb-5 flex items-center gap-2.5">
                    <x-icon name="sliders" style="solid" class="text-[0.85rem] text-ink/45 dark:text-paper/45" />
                    <h2 class="flex-1 text-[0.95rem] font-medium">Filters</h2>

                    <button x-on:click="toggle()" aria-label="Hide filters"
                            class="hidden size-7 place-items-center rounded-full text-ink/30 transition duration-300 ease-dbelo hover:bg-ink/[0.06] hover:text-ink lg:grid dark:text-paper/30 dark:hover:bg-paper/10 dark:hover:text-paper">
                        <x-icon name="chevron-left" style="solid" class="text-[0.7rem]" />
                    </button>
                </div>

                {{-- Category --}}
                <div class="micro mb-2.5">Category</div>
                <div class="-mx-1.5 max-h-72 space-y-0.5 overflow-y-auto pr-1">
                    <button wire:click="$set('category', '')"
                            @class([
                                'flex w-full items-center gap-2.5 rounded-control px-3 py-2 text-left text-[0.86rem] transition duration-200 ease-dbelo',
                                'bg-brand/10 text-brand' => $category === '',
                                'text-ink/65 hover:bg-ink/[0.04] dark:text-paper/65 dark:hover:bg-paper/[0.07]' => $category !== '',
                            ])>
                        <x-icon :name="$category === '' ? 'circle-dot' : 'circle'" :style="$category === '' ? 'solid' : 'regular'" class="text-[0.7rem]" />
                        All categories
                    </button>

                    @foreach ($this->categories as $cat)
                        <button wire:click="setCategory('{{ $cat->slug }}')" wire:key="cat-{{ $cat->id }}"
                                @class([
                                    'flex w-full items-center gap-2.5 rounded-control px-3 py-2 text-left text-[0.86rem] transition duration-200 ease-dbelo',
                                    'bg-brand/10 text-brand' => $category === $cat->slug,
                                    'text-ink/65 hover:bg-ink/[0.04] dark:text-paper/65 dark:hover:bg-paper/[0.07]' => $category !== $cat->slug,
                                ])>
                            <x-icon :name="$category === $cat->slug ? 'circle-dot' : 'circle'" :style="$category === $cat->slug ? 'solid' : 'regular'" class="shrink-0 text-[0.7rem]" />
                            <span class="min-w-0 flex-1 truncate">{{ $cat->name }}</span>
                        </button>
                    @endforeach
                </div>

                {{-- Duration --}}
                <div class="micro mb-2.5 mt-6">Length</div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ([
                        'short' => 'Under 1s',
                        'medium' => '1 – 5s',
                        'long' => '5 – 30s',
                        'xlong' => 'Over 30s',
                    ] as $bucket => $label)
                        <button wire:click="setDuration('{{ $bucket }}')"
                                @class([
                                    'rounded-full px-3 py-1.5 text-[0.78rem] transition duration-200 ease-dbelo',
                                    'bg-brand text-white' => $duration === $bucket,
                                    'bg-ink/[0.05] text-ink/60 hover:bg-ink/[0.09] dark:bg-paper/[0.08] dark:text-paper/60 dark:hover:bg-paper/[0.14]' => $duration !== $bucket,
                                ])>{{ $label }}</button>
                    @endforeach
                </div>

                {{-- Switches --}}
                <div class="micro mb-2.5 mt-6">Only show</div>
                <div class="space-y-0.5">
                    @foreach ([
                        ['freeOnly', $freeOnly, 'Free sounds', 'gift'],
                        ['loopsOnly', $loopsOnly, 'Loops', 'repeat'],
                    ] as [$prop, $on, $label, $icon])
                        <button wire:click="$toggle('{{ $prop }}')"
                                class="flex w-full items-center gap-2.5 rounded-control px-3 py-2 text-left text-[0.86rem] transition duration-200 ease-dbelo hover:bg-ink/[0.04] dark:hover:bg-paper/[0.07]">
                            <x-icon :name="$on ? 'square-check' : 'square'" :style="$on ? 'solid' : 'regular'"
                                    @class(['text-[0.85rem]', 'text-brand' => $on, 'text-ink/25 dark:text-paper/25' => ! $on]) />
                            <span @class(['flex-1', 'text-ink/65 dark:text-paper/65' => ! $on])>{{ $label }}</span>
                            <x-icon :name="$icon" style="solid" class="text-[0.7rem] text-ink/25 dark:text-paper/25" />
                        </button>
                    @endforeach
                </div>

                @if ($this->hasFilters)
                    <button wire:click="clearFilters"
                            class="mt-5 flex w-full items-center justify-center gap-2 rounded-control bg-ink/[0.05] py-2.5 text-[0.83rem] text-ink/60 transition duration-200 ease-dbelo hover:bg-ink/[0.09] dark:bg-paper/[0.08] dark:text-paper/60 dark:hover:bg-paper/[0.14]">
                        <x-icon name="arrow-rotate-left" style="solid" class="text-[0.7rem]" />
                        Clear filters
                    </button>
                @endif
            </div>
        </aside>

        {{-- ══════════════════════════════════════════════════════════
             RESULTS
             ══════════════════════════════════════════════════════════ --}}
        <div class="min-w-0 flex-1">

            <div class="mb-3 flex flex-wrap items-center gap-3">
                {{-- Only when the panel is hidden, so there is never a
                     control on screen that does nothing. --}}
                <button x-show="! filters" style="display: none" x-on:click="toggle()"
                        class="hidden items-center gap-2 rounded-full bg-surface px-4 py-2 text-[0.83rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 lg:flex dark:bg-surface-dark">
                    <x-icon name="sliders" style="solid" class="text-[0.75rem]" />
                    Filters
                </button>

                <span class="micro">{{ number_format($this->sounds->total()) }} {{ Str::plural('result', $this->sounds->total()) }}</span>

                <div class="ml-auto flex items-center gap-2.5">
                    <span class="micro hidden sm:block">Sort</span>
                    <select wire:model.live="sort"
                            class="rounded-full border-0 bg-surface px-4 py-2 text-[0.83rem] shadow-soft-sm focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-surface-dark">
                        <option value="relevance">Best match</option>
                        <option value="newest">Newest</option>
                        <option value="popular">Most downloaded</option>
                        <option value="shortest">Shortest first</option>
                        <option value="longest">Longest first</option>
                        <option value="az">A – Z</option>
                    </select>
                </div>
            </div>

            <div class="rounded-card bg-surface p-3 shadow-soft-md dark:bg-surface-dark">
                @forelse ($this->sounds as $sound)
                    <x-sound-row :sound="$sound" wire:key="sound-{{ $sound->id }}" />

                    {{-- After N results, never at the top: somebody has to
                         see the catalogue before they see the advertising,
                         or the site reads as the advertising. --}}
                    @if ($loop->iteration === \App\Support\Ads::catalogAfter())
                        <x-ad-slot name="catalog" />
                    @endif
                @empty
                    <div class="py-20 text-center">
                        <x-icon name="waveform-lines" style="regular" class="text-[26px] text-ink/15 dark:text-paper/15" />
                        <p class="mt-3 text-lg">No sounds found</p>
                        <p class="mt-1 text-sm text-ink/50 dark:text-paper/50">
                            @if (filled($search))
                                Nothing matches “{{ $search }}”. Try a broader word.
                            @elseif ($this->hasFilters)
                                Try removing some filters.
                            @else
                                Nothing has been published yet.
                            @endif
                        </p>

                        @if ($this->hasFilters)
                            <button wire:click="clearFilters"
                                    class="mt-5 rounded-full bg-action px-5 py-2.5 text-[0.85rem] font-medium text-white transition duration-300 ease-dbelo hover:-translate-y-0.5">
                                Clear filters
                            </button>
                        @endif
                    </div>
                @endforelse
            </div>

            <div class="mt-8">{{ $this->sounds->links() }}</div>
        </div>
    </div>
</div>
