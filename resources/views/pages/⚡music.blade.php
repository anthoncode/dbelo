<?php

use App\Models\MusicAttribute;
use App\Models\Sound;
use App\Services\SearchLogger;
use App\Support\Music;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Meilisearch\Endpoints\Indexes;

/*
|--------------------------------------------------------------------------
| The music catalogue
|--------------------------------------------------------------------------
|
| A sibling of pages/⚡sounds.blade.php, not a variant of it. The two pages
| ask different questions and that is the whole reason this file exists:
|
|   /sounds   what is it?        → category: Doors, Footsteps, Weather
|   /music    what style is it?  → genre and mood
|
| For a sound effect the category IS the answer. For a track there is no
| "what is it" — everything is music — so the genre takes over that slot in
| the interface, and three filters appear that an effect has no use for:
| mood, tempo and whether anybody is singing.
|
| ── WHY NOT ONE PAGE WITH A TOGGLE ───────────────────────────────────────
|
| A single page switching its entire sidebar on a radio button is a page
| whose URL cannot describe what it is showing, whose <h1> has to be vague
| enough to cover both, and whose canonical tag has to pick a side. Two
| routes give each half a real page with a real title, which on a catalogue
| that lives off search traffic is the difference between ranking for
| "corporate background music" and ranking for nothing.
|
| ── WHAT IS DELIBERATELY SHARED ──────────────────────────────────────────
|
| The row component (x-sound-row), the waveform, the player, the duration
| buckets and the sort options. A track and an effect are the same kind of
| object with the same affordances — listen, download, see the licence — and
| duplicating that would be two places for a play button to break.
*/
new #[Layout('layouts.site')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /*
     * Genre and mood travel as SLUGS, resolved to labels by Music.
     *
     * The column holds "Hip Hop"; the URL holds "hip-hop". Keeping the
     * label in the query string would give two URLs for one page the first
     * time somebody shares it with a + instead of a %20, and Google counts
     * those as duplicates.
     */
    #[Url(except: '')]
    public string $genre = '';

    #[Url(except: '')]
    public string $mood = '';

    #[Url(except: '')]
    public string $duration = '';

    #[Url(except: false)]
    public bool $freeOnly = false;

    #[Url(except: false)]
    public bool $loopsOnly = false;

    /*
     * Three states, not a boolean: '' any, '1' with vocals, '0' instrumental.
     *
     * An editor cutting a video over dialogue needs instrumental and nothing
     * else — "no vocals" is the filter they arrive with, and a checkbox
     * labelled "Has vocals" cannot express it. A boolean could only offer
     * "only vocals" or "everything", which is the half nobody wants.
     */
    #[Url(except: '')]
    public string $vocals = '';

    #[Url(except: 'relevance')]
    public string $sort = 'relevance';

    /** True when Meilisearch was configured and did not answer. */
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
     * The title and description, which change with the genre.
     *
     * A genre page is the one filtered view here worth indexing: "corporate
     * background music" is a real search with real intent, and the page that
     * answers it is /music?genre=corporate. So genre alone stays indexable
     * and gets its own title, while every other combination is noindex —
     * mood crossed with length crossed with tempo is thousands of URLs
     * holding the same tracks in a different order, which is the definition
     * of thin content.
     */
    protected function shareSeo(): void
    {
        $genre = Music::genreFromSlug($this->genre);

        $onlyGenre = filled($this->genre)
            && blank($this->search) && blank($this->mood) && blank($this->duration)
            && blank($this->vocals) && ! $this->freeOnly && ! $this->loopsOnly;

        $isFiltered = filled($this->search) || filled($this->mood) || filled($this->duration)
            || filled($this->vocals) || $this->freeOnly || $this->loopsOnly;

        view()->share('seo', [
            'title' => $genre
                ? $genre.' music'
                : 'Royalty-free music',
            'description' => $genre
                ? sprintf('Free %s music tracks for video, games and podcasts. Listen without an account and see the exact licence on every track.', strtolower($genre))
                : 'Free music tracks for video, games and podcasts. Filter by genre, mood and tempo, listen without an account, and see the exact licence on every track.',
            'canonical' => \App\Support\Canonical::for(
                $genre
                    ? route('music.index', ['genre' => $this->genre])
                    : route('music.index'),
                request()->query(),
            ),
            'noindex' => $isFiltered && ! $onlyGenre,
        ]);
    }

    // ---------------------------------------------------------------
    // The filter controls
    // ---------------------------------------------------------------

    public function setGenre(string $slug): void
    {
        $this->genre = $this->genre === $slug ? '' : $slug;
        $this->resetPage();
        $this->shareSeo();
    }

    public function setMood(string $slug): void
    {
        $this->mood = $this->mood === $slug ? '' : $slug;
        $this->resetPage();
    }

    public function setDuration(string $bucket): void
    {
        $this->duration = $this->duration === $bucket ? '' : $bucket;
        $this->resetPage();
    }

    public function setVocals(string $value): void
    {
        $this->vocals = $this->vocals === $value ? '' : $value;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'genre', 'mood', 'duration', 'freeOnly', 'loopsOnly', 'vocals', 'sort']);
        $this->resetPage();
        $this->shareSeo();
    }

    /**
     * Only the genres that actually have tracks, with their counts.
     *
     * ── WHY NOT JUST LIST Music::GENRES ──────────────────────────────────
     *
     * Twenty-one links of which three lead anywhere is a sidebar that
     * teaches a visitor the catalogue is empty. The same rule the nav
     * already follows for Packs and Collections — an entry leading to an
     * empty page is worse than no entry — applies hardest here, where the
     * sidebar IS the navigation.
     *
     * Ordered by Music::GENRES rather than by count: the list has to be in
     * the same order every visit, or a visitor who learned where "Ambient"
     * sits finds it somewhere else tomorrow because one track was published.
     *
     * One grouped query over music_attributes, joined to its sounds for the
     * published check. Cached for ten minutes because it runs on every view
     * of this page and changes only when something is published.
     */
    #[Computed]
    public function genres(): array
    {
        return $this->facet('genre', Music::GENRES, Music::genreSlugs());
    }

    #[Computed]
    public function moods(): array
    {
        return $this->facet('mood', Music::MOODS, Music::moodSlugs());
    }

    /**
     * @param  array<int, string>  $order
     * @param  array<string, string>  $slugs
     * @return array<int, array{slug: string, label: string, count: int}>
     */
    protected function facet(string $column, array $order, array $slugs): array
    {
        $counts = Cache::remember(
            "music.facet.{$column}",
            now()->addMinutes(10),
            fn () => MusicAttribute::query()
                ->whereNotNull($column)
                ->whereHas('sound', fn ($q) => $q->published()->music())
                ->selectRaw("{$column} as value, COUNT(*) as total")
                ->groupBy($column)
                ->pluck('total', 'value')
                ->all(),
        );

        $labels = array_flip($slugs);
        $facets = [];

        foreach ($order as $label) {
            $count = (int) ($counts[$label] ?? 0);

            if ($count < 1) {
                continue;
            }

            $facets[] = [
                'slug' => $labels[$label],
                'label' => $label,
                'count' => $count,
            ];
        }

        return $facets;
    }

    // ---------------------------------------------------------------
    // Reading the catalogue
    // ---------------------------------------------------------------

    /**
     * Same question, and the same answer, as on the sound-effects page.
     *
     * Scout's DatabaseEngine calls this component's search callback with an
     * Eloquent builder where Meilisearch passes an Indexes object, so a
     * callback typed for one engine throws a TypeError under the other. That
     * is a configuration fact, not an exceptional event, so it is asked
     * rather than discovered by catching.
     */
    protected function hasSearchEngine(): bool
    {
        return config('scout.driver') === 'meilisearch';
    }

    protected function filterExpression(): string
    {
        // Music only, unconditional: this is what the page is, not something
        // the visitor chose. Mirrored by ->music() in fromDatabase().
        $clauses = ['type = "music"'];

        if ($label = Music::genreFromSlug($this->genre)) {
            $clauses[] = sprintf('genre = "%s"', $label);
        }

        if ($label = Music::moodFromSlug($this->mood)) {
            $clauses[] = sprintf('mood = "%s"', $label);
        }

        /*
         * Tempo buckets rather than a two-handled slider.
         *
         * A slider looks precise and asks the visitor to know what number
         * they want. Nobody scoring a scene thinks "between 104 and 118";
         * they think "something fast". The boundaries are the ones the
         * genres themselves fall on — 90 is where a ballad stops, 140 is
         * where dance music starts.
         */
        $clauses[] = match ($this->duration) {
            'short' => 'duration_ms < 30000',
            'medium' => 'duration_ms >= 30000 AND duration_ms <= 120000',
            'long' => 'duration_ms > 120000 AND duration_ms <= 240000',
            'xlong' => 'duration_ms > 240000',
            default => null,
        };

        $clauses[] = match ($this->vocals) {
            '1' => 'has_vocals = true',
            '0' => 'has_vocals = false',
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
        if (! $this->hasSearchEngine()) {
            return $this->fromDatabase();
        }

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
                ->query(fn ($builder) => $builder->with(['files', 'license', 'musicAttribute']))
                ->paginate(20);
        } catch (\Throwable $e) {
            report($e);

            $this->degraded = true;

            return $this->fromDatabase();
        }
    }

    /**
     * The Eloquent path — the one that runs wherever Meilisearch does not.
     *
     * EVERY clause above has to exist here too. A filter that only one of
     * the two paths applies is how a development machine and a server start
     * showing different catalogues with nothing failing anywhere, which is
     * the bug this whole page was written to avoid repeating.
     *
     * whereHas and not a join: genre and mood live on music_attributes, one
     * row per track. whereHas compiles to an EXISTS subquery, which cannot
     * duplicate a row the way a join can when a sound somehow has two — and
     * a paginator counting duplicates reports a total that does not match
     * the rows it shows.
     */
    protected function fromDatabase()
    {
        $genre = Music::genreFromSlug($this->genre);
        $mood = Music::moodFromSlug($this->mood);

        return Sound::published()
            ->music()
            ->with(['files', 'license', 'musicAttribute'])
            // Sound::scopeMatching — the same definition the effects page and
            // both cross-catalogue counts use, so the number offered in a
            // "nothing found" message matches the page it links to.
            ->when($this->search, fn ($q, $term) => $q->matching($term))
            ->when($genre, fn ($q, $g) => $q->whereHas('musicAttribute',
                fn ($m) => $m->where('genre', $g)))
            ->when($mood, fn ($q, $m) => $q->whereHas('musicAttribute',
                fn ($a) => $a->where('mood', $m)))
            ->when($this->vocals !== '', fn ($q) => $q->whereHas('musicAttribute',
                fn ($m) => $m->where('has_vocals', $this->vocals === '1')))
            ->when($this->duration === 'short', fn ($q) => $q->where('duration_ms', '<', 30000))
            ->when($this->duration === 'medium', fn ($q) => $q->whereBetween('duration_ms', [30000, 120000]))
            ->when($this->duration === 'long', fn ($q) => $q->whereBetween('duration_ms', [120001, 240000]))
            ->when($this->duration === 'xlong', fn ($q) => $q->where('duration_ms', '>', 240000))
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
     * Logged on the same two clocks as the effects page: 250 ms drives the
     * results, 1.5 s decides what reaches the log. At 250 ms the log fills
     * with prefixes.
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
        return filled($this->search) || filled($this->genre) || filled($this->mood)
            || filled($this->duration) || filled($this->vocals)
            || $this->freeOnly || $this->loopsOnly || $this->sort !== 'relevance';
    }

    #[Computed]
    public function typoTolerant(): bool
    {
        return $this->hasSearchEngine();
    }

    /**
     * How many sound effects the word finds, for the visitor who landed in
     * the wrong half.
     *
     * The mirror of elsewhere() on the effects page, and the direction that
     * will fire more often: this catalogue is the small one, so "thunder"
     * typed here is a near-certain miss with thirty hits one click away.
     *
     * See that method for why this is Eloquent on every driver and why it
     * only runs when there is a query.
     */
    #[Computed]
    public function elsewhere(): ?int
    {
        if (blank($this->search)) {
            return null;
        }

        $count = Sound::published()->sfx()->matching($this->search)->count();

        return $count > 0 ? $count : null;
    }

    /**
     * The heading, which names the genre when one is chosen.
     *
     * An <h1> that says "Music" on a page showing only Corporate tracks is
     * a heading that describes the route instead of the content — and the
     * heading is the strongest on-page signal there is for what a page is
     * about.
     */
    #[Computed]
    public function heading(): ?string
    {
        return Music::genreFromSlug($this->genre);
    }
}; ?>

<div x-data="{
        filters: window.__dbeloMusicFilters ?? true,
        toggle() { this.filters = ! this.filters; window.__dbeloMusicFilters = this.filters },
     }">

    {{-- ══════ HERO ══════ --}}
    <div class="relative py-10 text-center">
        <div class="pointer-events-none absolute inset-x-0 top-8 -z-10 mx-auto max-w-[700px] px-6 opacity-50 dark:opacity-40"
             style="mask-image: linear-gradient(to right, transparent, #000 25%, #000 75%, transparent);
                    -webkit-mask-image: linear-gradient(to right, transparent, #000 25%, #000 75%, transparent);">
            <x-ambient-wave :bars="140" height="h-24" />
        </div>

        <h1 class="text-[clamp(2.2rem,5vw,3.4rem)] font-bold">
            @if ($this->heading)
                {{ $this->heading }} <span class="key">music</span>
            @else
                Music for your <span class="key">story</span>
            @endif
        </h1>

        <div class="mx-auto mt-8 flex max-w-[660px] items-center gap-3 rounded-full bg-surface py-2.5 pl-6 pr-2.5 shadow-soft-md transition duration-400 ease-dbelo focus-within:shadow-soft-lg dark:bg-surface-dark">
            <x-icon name="magnifying-glass" style="solid" class="shrink-0 text-ink/35 dark:text-paper/35" />

            <input type="search" wire:model.live.debounce.250ms="search"
                   x-data="{ t: null }"
                   @input="clearTimeout(t); t = setTimeout(() => $wire.logSearch(), 1500)"
                   placeholder="uplifting corporate, dark trailer, lo-fi piano…"
                   class="min-w-0 flex-1 border-0 bg-transparent p-0 text-base font-light placeholder:text-ink/35 focus:outline-none focus:ring-0 dark:placeholder:text-paper/35" />

            <span wire:loading wire:target="search" class="micro shrink-0 pr-3">Searching…</span>
        </div>

        @if ($this->typoTolerant)
            <p class="micro mt-3">Typos are fine — “corporat” finds corporate</p>
        @endif
    </div>

    @if ($degraded)
        <div class="mb-5 flex flex-wrap items-center gap-3 rounded-card bg-warning/10 px-5 py-3.5">
            <x-icon name="triangle-exclamation" style="solid" class="text-warning" />
            <span class="text-[0.86rem] text-ink/70 dark:text-paper/70">
                Search is running in reduced mode — no typo tolerance and results are not ranked by relevance.
                Try a simpler word, or browse by genre.
            </span>
        </div>
    @endif

    <div class="flex flex-col gap-6 lg:flex-row lg:items-start">

        {{-- ══════ FILTERS ══════ --}}

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

                {{--
                    Genre, in the slot the category occupies on /sounds.

                    Only the genres with tracks are here, so this list grows
                    with the catalogue instead of promising twenty-one
                    sections that do not exist yet.

                    ── I HID THIS BELOW TWO GENRES AND IT WAS WRONG ────────

                    The argument was that a filter with one option narrows
                    nothing, which is true and beside the point. A young
                    catalogue has one genre, so the whole control was absent
                    — and an absent filter is indistinguishable from a broken
                    one. The first thing it had to prove was that it existed
                    at all, and it was hiding exactly when somebody was
                    looking for it.

                    One is enough now. The single entry carries its count,
                    which says plainly how small the catalogue is, and it
                    links to /music?genre=x — an indexable page in its own
                    right and the one in the sitemap. That is worth a row
                    even when it is the only one.
                --}}
                @if (count($this->genres) >= 1)
                    <div class="micro mb-2.5">Genre</div>
                    <div class="-mx-1.5 max-h-72 space-y-0.5 overflow-y-auto pr-1">
                        <button wire:click="setGenre('')"
                                @class([
                                    'flex w-full items-center gap-2.5 rounded-control px-3 py-2 text-left text-[0.86rem] transition duration-200 ease-dbelo',
                                    'bg-brand/10 text-brand' => $genre === '',
                                    'text-ink/65 hover:bg-ink/[0.04] dark:text-paper/65 dark:hover:bg-paper/[0.07]' => $genre !== '',
                                ])>
                            <x-icon :name="$genre === '' ? 'circle-dot' : 'circle'" :style="$genre === '' ? 'solid' : 'regular'" class="text-[0.7rem]" />
                            All genres
                        </button>

                        @foreach ($this->genres as $option)
                            <button wire:click="setGenre('{{ $option['slug'] }}')" wire:key="genre-{{ $option['slug'] }}"
                                    @class([
                                        'flex w-full items-center gap-2.5 rounded-control px-3 py-2 text-left text-[0.86rem] transition duration-200 ease-dbelo',
                                        'bg-brand/10 text-brand' => $genre === $option['slug'],
                                        'text-ink/65 hover:bg-ink/[0.04] dark:text-paper/65 dark:hover:bg-paper/[0.07]' => $genre !== $option['slug'],
                                    ])>
                                <x-icon :name="$genre === $option['slug'] ? 'circle-dot' : 'circle'" :style="$genre === $option['slug'] ? 'solid' : 'regular'" class="shrink-0 text-[0.7rem]" />
                                <span class="min-w-0 flex-1 truncate">{{ $option['label'] }}</span>
                                <span class="shrink-0 text-[0.72rem] text-ink/30 dark:text-paper/30">{{ $option['count'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif

                {{-- Mood: pills rather than a list, because they are one word
                     each and the whole set fits in the width. Shown from one,
                     for the same reason as the genres above. --}}
                @if (count($this->moods) >= 1)
                    <div class="micro mb-2.5 mt-6">Mood</div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($this->moods as $option)
                            <button wire:click="setMood('{{ $option['slug'] }}')" wire:key="mood-{{ $option['slug'] }}"
                                    @class([
                                        'rounded-full px-3 py-1.5 text-[0.78rem] transition duration-200 ease-dbelo',
                                        'bg-brand text-white' => $mood === $option['slug'],
                                        'bg-ink/[0.05] text-ink/60 hover:bg-ink/[0.09] dark:bg-paper/[0.08] dark:text-paper/60 dark:hover:bg-paper/[0.14]' => $mood !== $option['slug'],
                                    ])>{{ $option['label'] }}</button>
                        @endforeach
                    </div>
                @endif

                {{-- Length. The buckets are musical, not sonic: a 20-second
                     sting, a 60-second cut, a full track, a long bed. The
                     effects page splits at one and five SECONDS, which would
                     put every track in this catalogue in one bucket. --}}
                <div class="micro mb-2.5 mt-6">Length</div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach ([
                        'short' => 'Under 30s',
                        'medium' => '30s – 2m',
                        'long' => '2 – 4m',
                        'xlong' => 'Over 4m',
                    ] as $bucket => $label)
                        <button wire:click="setDuration('{{ $bucket }}')"
                                @class([
                                    'rounded-full px-3 py-1.5 text-[0.78rem] transition duration-200 ease-dbelo',
                                    'bg-brand text-white' => $duration === $bucket,
                                    'bg-ink/[0.05] text-ink/60 hover:bg-ink/[0.09] dark:bg-paper/[0.08] dark:text-paper/60 dark:hover:bg-paper/[0.14]' => $duration !== $bucket,
                                ])>{{ $label }}</button>
                    @endforeach
                </div>

                {{-- Vocals. Three states, and "Instrumental" is the one
                     people come for: music under dialogue cannot have
                     somebody singing over it. --}}
                <div class="micro mb-2.5 mt-6">Vocals</div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach (['0' => 'Instrumental', '1' => 'With vocals'] as $value => $label)
                        <button wire:click="setVocals('{{ $value }}')"
                                @class([
                                    'rounded-full px-3 py-1.5 text-[0.78rem] transition duration-200 ease-dbelo',
                                    'bg-brand text-white' => $vocals === $value,
                                    'bg-ink/[0.05] text-ink/60 hover:bg-ink/[0.09] dark:bg-paper/[0.08] dark:text-paper/60 dark:hover:bg-paper/[0.14]' => $vocals !== $value,
                                ])>{{ $label }}</button>
                    @endforeach
                </div>

                {{-- Switches --}}
                <div class="micro mb-2.5 mt-6">Only show</div>
                <div class="space-y-0.5">
                    @foreach ([
                        ['freeOnly', $freeOnly, 'Free tracks', 'gift'],
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

        {{-- ══════ RESULTS ══════ --}}
        <div class="min-w-0 flex-1">

            <div class="mb-3 flex flex-wrap items-center gap-3">
                <button x-show="! filters" style="display: none" x-on:click="toggle()"
                        class="hidden items-center gap-2 rounded-full bg-surface px-4 py-2 text-[0.83rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 lg:flex dark:bg-surface-dark">
                    <x-icon name="sliders" style="solid" class="text-[0.75rem]" />
                    Filters
                </button>

                <span class="micro">{{ number_format($this->sounds->total()) }} {{ Str::plural('track', $this->sounds->total()) }}</span>

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
                    <x-sound-row :sound="$sound" wire:key="track-{{ $sound->id }}" />

                    @if ($loop->iteration === \App\Support\Ads::catalogAfter())
                        <x-ad-slot name="catalog" />
                    @endif
                @empty
                    <div class="py-20 text-center">
                        <x-icon name="music" style="regular" class="text-[26px] text-ink/15 dark:text-paper/15" />
                        <p class="mt-3 text-lg">No tracks found</p>
                        <p class="mt-1 text-sm text-ink/50 dark:text-paper/50">
                            @if (filled($search))
                                Nothing matches “{{ $search }}”. Try a broader word.
                            @elseif ($this->hasFilters)
                                Try removing some filters.
                            @else
                                No music has been published yet.
                            @endif
                        </p>

                        {{-- The bridge back to the effects catalogue. The
                             direction that will fire most, because this is
                             the smaller half: a word like "thunder" typed
                             here misses, with thirty hits one click away. --}}
                        @if ($this->elsewhere)
                            <a href="{{ route('sounds.index', ['q' => $search]) }}" wire:navigate
                               class="mt-5 inline-flex items-center gap-2.5 rounded-full bg-brand px-5 py-2.5 text-[0.85rem] font-medium text-white shadow-brand transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-brand-lg">
                                <x-icon name="waveform-lines" style="solid" class="text-[0.8rem]" />
                                {{ $this->elsewhere }} {{ Str::plural('sound effect', $this->elsewhere) }}
                                <x-icon name="arrow-right" style="solid" class="text-[0.7rem]" />
                            </a>
                        @endif

                        @if ($this->hasFilters)
                            <div>
                                <button wire:click="clearFilters"
                                        class="mt-4 text-[0.85rem] text-ink/50 underline transition hover:text-brand dark:text-paper/50">
                                    Clear filters
                                </button>
                            </div>
                        @endif
                    </div>
                @endforelse
            </div>

            <div class="mt-8">{{ $this->sounds->links() }}</div>
        </div>
    </div>
</div>
