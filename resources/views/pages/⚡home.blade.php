<?php

use App\Models\Category;
use App\Models\Collection as Pack;
use App\Models\Plan;
use App\Models\Sound;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * No #[Title] attribute, deliberately.
 *
 * It said 'Sound effects library', and partials/head.blade.php appends
 * ' — '.config('app.name') to whatever title a page gives it. So the tab
 * read "Sound effects library — dbelo", which spends the first and most
 * weighted words of the most valuable line on the site saying "library"
 * instead of what people actually search for.
 *
 * The real title is in shareSeo() below, where it sits next to the
 * description it has to agree with. One page, one place.
 */
new #[Layout('layouts.site')] class extends Component {

    public string $q = '';

    /**
     * Fallback icons, by slug.
     *
     * categories.icon exists in the schema and is meant to win. This is what
     * the site looks like until somebody fills it in — which, for a landing
     * page, is the difference between shipping and waiting on data entry.
     */
    protected const ICONS = [
        'ambience' => 'wind',
        'animals' => 'paw',
        'cartoon' => 'face-laugh',
        'cinematic' => 'film',
        'foley' => 'shoe-prints',
        'horror' => 'ghost',
        'human' => 'person',
        'interface' => 'computer-mouse',
        'machines' => 'gears',
        'nature' => 'leaf',
        'sci-fi' => 'rocket',
        'transportation' => 'car',
        'weapons' => 'burst',
        // Packs
        'podcast' => 'microphone',
        'youtube' => 'clapperboard',
        'game-ui' => 'gamepad',
        'trailer' => 'film',
        'notifications' => 'bell',
    ];

    public function mount(): void
    {
        view()->share('seo', [
            /*
             * ── THE TITLE, AND WHY THE BRAND IS AT THE END ───────────────
             *
             * head.blade.php appends ' — dbelo' on its own, so this is only
             * the first part. Writing the brand here would print it twice.
             *
             * It leads with "Free sound effects" because that is the phrase
             * people type; nobody searches "dbelo" yet. The first words of
             * a title are the ones Google weights and the ones a human scans
             * in a list of ten blue links, and spending them on an unknown
             * brand is giving away the best position on the page.
             *
             * The brand is not defended here anyway: the WebSite JSON-LD
             * below is what Google uses for the site name it prints above
             * the result, and the domain matches a brand search by itself.
             *
             * Measured with App\Support\Serp: 56 characters once the suffix
             * is added, 82% of the width Google gives a title.
             */
            'title' => 'Free sound effects for video, games and podcasts',

            /*
             * NO QUANTITY CLAIM, on purpose.
             *
             * This said "Thousands of studio-grade sound effects" while the
             * catalogue held two. A description that oversells sends people
             * to a near-empty library, they leave, and the bounce is a
             * ranking signal — and it is the kind of sentence nobody
             * remembers to correct in either direction.
             *
             * What it promises instead is true today and still true at
             * fifty thousand, and every clause is verifiable on the site:
             * listening needs no account, downloads come in MP3 or WAV, and
             * the licence is stated on each sound.
             */
            'description' => 'Free sound effects for video, games and podcasts. Listen to anything without an account, '
                .'download in MP3 or WAV, and see the exact licence on every sound.',
            'canonical' => route('home'),
            /*
             * ── TWO GRAPHS, AND ONE THAT WAS REMOVED ─────────────────────
             *
             * WebSite is what Google's own site-names documentation calls
             * the most important signal for the name it prints above a
             * result. Without it the name is guessed, and the guess is
             * usually the domain.
             *
             * Organization is the entity behind the site rather than the
             * site itself — the pair is what a knowledge panel is built
             * from. Nothing invented here: a name, an address and a logo
             * when there is one.
             *
             * sameAs is the social profiles, and it is present only when
             * there ARE any — array_filter below drops the key rather than
             * emitting an empty list. It means "this organisation and that
             * account are the same entity", which is exactly what the admin
             * panel asks for when it says official accounts only. One list,
             * entered once: the footer draws it and this claims it.
             *
             * WHAT CAME OUT: potentialAction / SearchAction, the sitelinks
             * search box. Google retired that feature in October 2024 — the
             * announcement is literally titled "Farewell, Sitelinks Search
             * Box" — and the markup has done nothing since. Harmless, but
             * markup that does nothing is markup somebody will one day try
             * to debug.
             */
            'jsonld' => array_values(array_filter([
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'WebSite',
                    'name' => config('app.name', 'dbelo'),
                    'url' => route('home'),
                ],
                array_filter([
                    '@context' => 'https://schema.org',
                    '@type' => 'Organization',
                    'name' => config('app.name', 'dbelo'),
                    'url' => route('home'),
                    'logo' => \App\Support\Appearance::url('logo_light') ?: asset('og-default.png'),
                    'sameAs' => \App\Support\Social::sameAs(),
                ]),
            ])),
        ]);
    }

    public function search(): void
    {
        $this->redirectRoute('sounds.index', ['q' => $this->q], navigate: true);
    }

    /**
     * Live suggestions while typing. Two characters is the floor: below that
     * every sound in the catalogue matches and the dropdown is noise.
     */
    #[Computed]
    public function suggestions()
    {
        if (mb_strlen(trim($this->q)) < 2) {
            return collect();
        }

        try {
            return Sound::search($this->q)
                ->query(fn ($builder) => $builder->with('category'))
                ->take(6)
                ->get();
        } catch (\Throwable $e) {
            // The engine lives in its own process. If it is not answering,
            // the home page still has to render — a dropdown of suggestions
            // is a nicety, and no nicety is worth a 500 on the front door.
            report($e);

            return Sound::published()
                ->with('category')
                ->where('title', 'like', '%'.trim($this->q).'%')
                ->latest('published_at')
                ->limit(6)
                ->get();
        }
    }

    /**
     * The six categories people actually download from.
     *
     * Ordered by real downloads, falling back to how much is published.
     * That second clause is not defensive padding — on a new catalogue every
     * category has zero downloads, and without it the front page would order
     * itself by database id and look broken for exactly as long as the site
     * has no traffic. It corrects itself the moment traffic arrives.
     */
    #[Computed]
    public function categories()
    {
        return Category::roots()
            ->withCount(['sounds' => fn ($q) => $q->published()])
            ->withSum(['sounds as downloads_total' => fn ($q) => $q->published()], 'downloads_count')
            ->whereHas('sounds', fn ($q) => $q->published())
            ->orderByDesc('downloads_total')
            ->orderByDesc('sounds_count')
            ->limit(6)
            ->get();
    }

    /** Curated sets. Public and flagged as featured — see Collection::scopeFeatured. */
    #[Computed]
    public function packs()
    {
        return Pack::featured()
            ->withCount(['sounds' => fn ($q) => $q->published()])
            ->orderByDesc('sounds_count')
            ->limit(4)
            ->get();
    }

    public function iconFor($model): string
    {
        return $model->icon
            ?? self::ICONS[$model->slug]
            ?? 'waveform-lines';
    }

    #[Computed]
    public function latest()
    {
        return Sound::published()
            ->with(['files', 'category', 'user:id,name', 'tags:id,name'])
            ->latest('published_at')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function plans()
    {
        return Plan::where('is_active', true)->orderBy('sort_order')->get();
    }

    /**
     * The ladder: free and everything that bills again.
     *
     * A one-off purchase is not a cheaper rung of the same ladder, and a
     * grid is a comparison — four cards in a row invite the eye to read
     * "$3 once" against "$9 / month" on one axis, where the cheapest wins
     * and the recurring plans lose to something that is not competing with
     * them. So the passes come out, and get a shape of their own below.
     *
     * Decided by Plan::isOneOff(), which is "costs money and never bills
     * again" — not by position and not by slug. A week pass added next year
     * lands in the right place without anybody remembering this rule.
     */
    #[Computed]
    public function tiers()
    {
        return $this->plans->reject(fn (Plan $plan) => $plan->isOneOff())->values();
    }

    /**
     * The plan this visitor is already on, if any.
     *
     * Used to stop the page offering somebody the thing they are already
     * paying for — "Choose Pro" on the account that has Pro is the clearest
     * possible sign that a page is not looking at who is reading it.
     */
    #[Computed]
    public function currentPlanId(): ?int
    {
        return auth()->user()?->currentPlan()?->id;
    }

    /** Day pass and anything else bought once. */
    #[Computed]
    public function passes()
    {
        return $this->plans->filter(fn (Plan $plan) => $plan->isOneOff())->values();
    }

    /**
     * The grid, sized by what is in it.
     *
     * Written out in full in every arm: Tailwind emits a class only if it
     * saw the whole string in a source file at build time, so a computed
     * "lg:grid-cols-{$n}" would exist at runtime and never in the CSS.
     */
    public function gridClass(): string
    {
        return match ($this->tiers->count()) {
            1 => 'max-w-md mx-auto',
            2 => 'sm:grid-cols-2 max-w-3xl mx-auto',
            3 => 'sm:grid-cols-2 lg:grid-cols-3',
            default => 'sm:grid-cols-2 lg:grid-cols-4',
        };
    }

    #[Computed]
    public function totals(): array
    {
        return [
            'sounds' => Sound::published()->count(),
            'categories' => Category::count(),
        ];
    }
}; ?>

<div>
    {{-- ══════════════════════════════════════════════════════════════
         HERO
         ══════════════════════════════════════════════════════════════ --}}
    <section class="relative py-14 text-center">

        {{-- The waveform breathing behind the title. Absolutely positioned
             and pointer-events-none so it cannot touch the layout or steal
             a click meant for the search box. The mask fades both ends into
             the page — a hard edge would give away that it is a strip of
             bars rather than something the page is doing. --}}
        <div class="pointer-events-none absolute inset-x-0 top-24 -z-10 mx-auto max-w-[820px] px-6 opacity-60 dark:opacity-45"
             style="mask-image: linear-gradient(to right, transparent, #000 22%, #000 78%, transparent);
                    -webkit-mask-image: linear-gradient(to right, transparent, #000 22%, #000 78%, transparent);">
            <x-ambient-wave :bars="190" height="h-32" />
        </div>

        <span class="rise mb-7 inline-flex items-center gap-2 rounded-full bg-surface px-4 py-2 text-sm text-ink/60 shadow-soft-sm dark:bg-surface-dark dark:text-paper/60">
            <span class="size-2 rounded-full bg-brand"></span>
            {{ number_format($this->totals['sounds']) }} {{ Str::plural('sound', $this->totals['sounds']) }} online
        </span>

        <h1 class="rise text-[clamp(2.6rem,6vw,4.4rem)] font-bold" style="animation-delay: 60ms">
            Every sound your<br><span class="key">story</span> needs
        </h1>

        <p class="rise mx-auto mt-6 max-w-[54ch] text-[1.08rem] text-ink/60 dark:text-paper/60" style="animation-delay: 120ms">
            Studio-grade sound effects, cleared for commercial use.
            Listen to everything <span class="key">free</span>.
        </p>

        <div x-data="{ open: false }" @click.outside="open = false"
             class="rise relative mx-auto mt-9 max-w-[660px]" style="animation-delay: 180ms">
            <form wire:submit="search"
                  class="flex items-center gap-3 rounded-full bg-surface py-2.5 pl-6 pr-2.5 shadow-soft-md transition duration-400 ease-dbelo focus-within:shadow-soft-lg dark:bg-surface-dark">
                <x-icon name="magnifying-glass" style="solid" class="shrink-0 text-ink/35 dark:text-paper/35" />

                <input type="search" wire:model.live.debounce.250ms="q" @focus="open = true"
                       autocomplete="off"
                       placeholder="door creak, thunder, laser, footsteps on gravel…"
                       class="min-w-0 flex-1 border-0 bg-transparent p-0 text-base font-light placeholder:text-ink/35 focus:outline-none focus:ring-0 dark:placeholder:text-paper/35" />

                <button type="submit"
                        class="shrink-0 rounded-full bg-action px-6 py-2.5 text-sm font-medium text-white transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:brightness-110">
                    Search
                </button>
            </form>

            @if ($this->suggestions->isNotEmpty())
                {{-- style="display:none", not x-cloak: this panel lives inside
                     a Livewire component that re-renders on every keystroke,
                     and a morph restores the SERVER's attributes. If the
                     closed state is not in the server HTML, the morph wipes
                     the inline style x-show wrote and the panel is stuck
                     open. --}}
                <div x-show="open" style="display: none" x-transition.opacity.duration.200ms
                     class="absolute inset-x-0 top-full z-40 mt-2 overflow-hidden rounded-card bg-surface p-2 text-left shadow-soft-lg dark:bg-surface-dark">
                    @foreach ($this->suggestions as $hit)
                        <a href="{{ route('sounds.show', $hit) }}" wire:navigate wire:key="sug-{{ $hit->id }}"
                           class="flex items-center gap-3 rounded-control px-4 py-2.5 transition duration-200 ease-dbelo hover:bg-ink/[0.05] dark:hover:bg-paper/[0.08]">
                            <x-icon name="waveform-lines" style="solid" class="text-[0.8rem] text-brand" />
                            <span class="min-w-0 flex-1 truncate text-[0.9rem]">{{ $hit->title }}</span>
                            <span class="micro shrink-0">{{ $hit->category?->name }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Entry points by intent, the way the trending chips work on the
             sites people are coming from. Cheaper to click than to type. --}}
        @if ($this->categories->isNotEmpty())
            <div class="rise mt-6 flex flex-wrap justify-center gap-2" style="animation-delay: 240ms">
                @foreach ($this->categories as $cat)
                    <a href="{{ route('sounds.index', ['category' => $cat->slug]) }}" wire:navigate
                       class="rounded-full bg-surface px-4 py-2 text-[0.82rem] text-ink/60 shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:text-brand hover:shadow-soft-md dark:bg-surface-dark dark:text-paper/60">
                        {{ $cat->name }}
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    {{-- ══════════════════════════════════════════════════════════════
         HOW IT WORKS
         ══════════════════════════════════════════════════════════════ --}}
    <section class="py-6">
        {{--
            Three cells that have to read as ONE object.

            What made this look untidy was that nothing was pinned: a bare
            30px glyph, then a heading, then a paragraph — and because the
            three paragraphs are different lengths, nothing below the icon
            lined up across the columns. The icon now sits in a fixed 56px
            chip and the paragraph is pushed to the bottom with mt-auto, so
            all three headings share a baseline and all three cells end
            together no matter how the copy is edited later.

            `items-stretch` is the default for grid, so the cells are already
            equal height — the alignment problem was inside them.
        --}}
        <div class="grid gap-px overflow-hidden rounded-card bg-ink/[0.06] shadow-soft-sm sm:grid-cols-3 dark:bg-paper/10">
            @foreach ([
                ['magnifying-glass', 'Browse', 'Search by what you hear, not by what it is called. Typos are fine — “thundr” finds thunder.'],
                ['headphones', 'Listen', 'Every sound plays in full, free, with no account. The player follows you while you keep looking.'],
                ['arrow-down-to-line', 'Download', 'MP3 with a free account. WAV masters and no daily limit on Pro.'],
            ] as $i => [$icon, $label, $copy])
                <div class="rise group relative flex flex-col items-center overflow-hidden bg-surface px-7 pb-8 pt-9 text-center transition duration-400 ease-dbelo hover:bg-brand dark:bg-surface-dark"
                     style="animation-delay: {{ 60 * $i }}ms">

                    {{-- Top-right corner, clear of the centred column below it. --}}
                    <span class="absolute right-5 top-4 text-[0.8rem] font-semibold tabular-nums text-ink/15 transition duration-300 group-hover:text-white/40 dark:text-paper/15">
                        {{ $i + 1 }}
                    </span>

                    {{-- Fixed-size chip: the icons have different natural
                         widths, and centring three different widths is what
                         made the row look crooked. --}}
                    <span class="grid size-14 shrink-0 place-items-center rounded-full bg-brand/10 transition duration-400 ease-dbelo group-hover:scale-105 group-hover:bg-white/20">
                        <x-icon :name="$icon" style="solid"
                                class="text-[1.35rem] leading-none text-brand transition duration-400 ease-dbelo group-hover:text-white" />
                    </span>

                    <h3 class="mt-5 text-[1.1rem] font-medium transition duration-300 group-hover:text-white">{{ $label }}</h3>

                    <p class="mt-2 max-w-[32ch] text-[0.86rem] leading-relaxed text-ink/55 transition duration-300 group-hover:text-white/80 dark:text-paper/55">
                        {{ $copy }}
                    </p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ══════════════════════════════════════════════════════════════
         SOMETHING TO HEAR

         Placed this high on purpose. A sound library whose front page makes
         no sound is asking to be believed instead of demonstrating. One
         click here and the persistent bar appears — which explains the whole
         product better than any paragraph on this page.
         ══════════════════════════════════════════════════════════════ --}}
    @if ($this->latest->isNotEmpty())
        <section class="py-10">
            <x-section-head eyebrow="Newest"
                            title="Press play. That is the whole demo."
                            lead="Nothing here is a preview clip. Every sound plays end to end, for free, without an account.">
                <x-slot:action>
                    <a href="{{ route('sounds.index', ['sort' => 'newest']) }}" wire:navigate
                       class="flex items-center gap-2 rounded-full bg-surface px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:text-brand hover:shadow-soft-md dark:bg-surface-dark">
                        Browse all sounds
                        <x-icon name="arrow-right" style="solid" class="text-[0.7rem]" />
                    </a>
                </x-slot:action>
            </x-section-head>

            <div class="rise rounded-card bg-surface p-3 shadow-soft-md dark:bg-surface-dark">
                @foreach ($this->latest as $sound)
                    <x-sound-row :sound="$sound" wire:key="new-{{ $sound->id }}" />
                @endforeach
            </div>
        </section>
    @endif

    {{-- ══════════════════════════════════════════════════════════════
         CATEGORIES — by what the sound IS
         ══════════════════════════════════════════════════════════════ --}}
    @if ($this->categories->isNotEmpty())
        <section class="py-10">
            <x-section-head eyebrow="Categories"
                            title='Browse by what the sound <span class="key">is</span>'
                            lead="The six our visitors reach for most. Everything else is one click further in." />

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->categories as $i => $cat)
                    <x-tile :href="route('sounds.index', ['category' => $cat->slug])"
                            :title="$cat->name"
                            :subtitle="$cat->description"
                            :icon="$this->iconFor($cat)"
                            :meta="$cat->sounds_count.' '.Str::plural('sound', $cat->sounds_count)"
                            :delay="60 * $i"
                            wire:key="cat-{{ $cat->id }}" />
                @endforeach
            </div>

            <div class="mt-6 text-center">
                <a href="{{ route('sounds.index') }}" wire:navigate
                   class="inline-flex items-center gap-2 rounded-full bg-surface px-6 py-3 text-[0.88rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md dark:bg-surface-dark">
                    Browse all {{ number_format($this->totals['categories']) }} categories
                    <x-icon name="arrow-right" style="solid" class="text-[0.7rem]" />
                </a>
            </div>
        </section>
    @endif

    {{-- ══════════════════════════════════════════════════════════════
         PACKS — by what you are MAKING

         The pairing with the section above is the point, and the two
         headings are written to teach it: categories answer "what is this
         sound", packs answer "what am I building". A pack that repeats a
         category is a category with a different name.
         ══════════════════════════════════════════════════════════════ --}}
    {{-- Shown to an admin even when empty, the same way an unpublished
         post shows with a banner: you cannot design a section you cannot
         see, and hiding it until the data exists is how it stays unbuilt. --}}
    @if ($this->packs->isNotEmpty() || auth()->user()?->isAdmin())
        <section class="py-10">
            <x-section-head eyebrow="Packs"
                            title='Or by what you are <span class="key">making</span>'
                            lead="Sets pulled from across the catalogue for one job. A podcast intro needs a sting, a room tone and a click — three categories, one afternoon of work." />

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 lg:grid-rows-2">

                {{-- The tall card: the way into all of them.

                     The accent word uses .key-plain, not .key. This card
                     fills with brand on hover and .key would pin the same
                     orange on top of it — the word would vanish exactly
                     when the visitor is looking at it. --}}
                <x-tile :href="route('packs.index')"
                        title='All in <span class="key-plain">one</span>'
                        raw
                        subtitle="Take a whole pack in a single download — every sound cleared for commercial use and ready to drop straight into a video, a podcast, an ad or a game."
                        icon="layer-group"
                        :meta="$this->packs->count() ? $this->packs->count().' sets' : 'Coming soon'"
                        size="tall" />

                {{-- The first one arrives already coloured rather than
                     waiting for a hover. One filled card in a grid is where
                     the eye lands, and that is worth spending on the pack
                     you most want opened. --}}
                @foreach ($this->packs as $i => $pack)
                    <x-tile :href="route('packs.show', $pack)"
                            :title="$pack->name"
                            :subtitle="Str::limit($pack->description, 90)"
                            :icon="$this->iconFor($pack)"
                            :meta="$pack->sounds_count.' '.Str::plural('sound', $pack->sounds_count)"
                            :tone="$i === 0 ? 'action' : 'brand'"
                            :filled="$i === 0"
                            :delay="60 * ($i + 1)"
                            wire:key="pack-{{ $pack->id }}" />
                @endforeach

                @if ($this->packs->isEmpty())
                    <div class="rounded-card border border-dashed border-ink/15 p-6 sm:col-span-2 dark:border-paper/15">
                        <div class="flex items-center gap-2.5 text-[0.88rem] text-warning">
                            <x-icon name="eye" style="solid" class="text-[0.8rem]" />
                            Only you can see this section
                        </div>
                        <p class="mt-2 text-[0.85rem] leading-relaxed text-ink/50 dark:text-paper/50">
                            There are no packs yet, so visitors get nothing here. Create one in
                            <span class="text-ink/70 dark:text-paper/70">Catalog &rarr; Packs</span>, mark it public,
                            and it appears for everyone.
                        </p>
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- ══════════════════════════════════════════════════════════════
         PLANS
         ══════════════════════════════════════════════════════════════ --}}
    @if ($this->plans->isNotEmpty())
        {{-- id="plans" so /settings/plan can link straight here. Without it
             "See the plans" lands at the top of the home page and the person
             who came to compare has to go looking. --}}
        <section id="plans" class="scroll-mt-24 py-14">
            <div class="rise text-center">
                <div class="micro">Plans</div>
                <h2 class="mt-2 text-[clamp(1.6rem,3.4vw,2.3rem)] font-semibold">
                    Listen free. <span class="key">Download</span> without limits.
                </h2>
            </div>

            <div class="mt-8 grid gap-4 {{ $this->gridClass() }}">
                @foreach ($this->tiers as $i => $plan)
                    @php($isPopular = $plan->is_popular ?? $loop->index === 1)

                    <div @class([
                            'rise relative flex flex-col rounded-card p-7 transition duration-400 ease-dbelo hover:-translate-y-1',
                            'bg-surface shadow-soft-md hover:shadow-soft-lg dark:bg-surface-dark' => ! $isPopular,
                            'bg-surface shadow-soft-lg ring-2 ring-brand dark:bg-surface-dark' => $isPopular,
                        ])
                         style="animation-delay: {{ 60 * $i }}ms"
                         wire:key="plan-{{ $plan->id }}">

                        @if ($isPopular)
                            <span class="absolute right-6 top-6 rounded-full bg-brand px-3 py-1 text-[9px] font-semibold uppercase tracking-[0.12em] text-white">
                                Popular
                            </span>
                        @endif

                        <div class="micro">{{ $plan->name }}</div>

                        <div class="mt-3 flex items-baseline gap-1.5">
                            {{-- priceForHumans() rather than the column: the
                                 price is stored in CENTS, and formatting it
                                 in the view is how a $9.00 plan ends up
                                 advertised at $900. --}}
                            <span class="text-[2.1rem] font-semibold tracking-[-0.03em]">{{ $plan->priceForHumans() }}</span>

                            @unless ($plan->isFree())
                                <span class="text-[0.85rem] text-ink/45 dark:text-paper/45">
                                    /{{ ($plan->interval ?? 'month') === 'year' ? 'yr' : 'mo' }}
                                </span>
                            @endunless
                        </div>

                        <ul class="mt-6 flex-1 space-y-2.5">
                            @foreach (($plan->features ?? []) as $feature)
                                <li class="flex items-start gap-2.5 text-[0.86rem] text-ink/65 dark:text-paper/65">
                                    <x-icon name="check" style="solid" class="mt-1 shrink-0 text-[0.7rem] text-success" />
                                    {{ $feature }}
                                </li>
                            @endforeach
                        </ul>

                        {{-- ── WHERE THE BUTTON GOES ────────────────────
                             It went to /register for everybody, and Fortify
                             puts `guest` on that route — so a signed-in
                             visitor clicking "Choose Pro" was bounced
                             straight back and the page appeared to do
                             nothing at all.

                             There is no checkout yet: PayPal is not
                             connected and no purchase route exists. Rather
                             than a button that pretends, each case gets the
                             truest destination available today. --}}
                        @if ($this->currentPlanId === $plan->id)
                            {{-- Not a link. Offering somebody the plan they
                                 are already on is the clearest sign a page
                                 is not looking at who is reading it. --}}
                            <div class="mt-7 flex items-center justify-center gap-2 rounded-full bg-success/12 py-3 text-center text-[0.88rem] font-medium text-success">
                                <x-icon name="circle-check" style="solid" class="text-[0.78rem]" />
                                Your plan
                            </div>
                        @else
                            <a href="{{ auth()->check() ? route('plan.show') : route('register') }}" wire:navigate
                               @class([
                                   'mt-7 block rounded-full py-3 text-center text-[0.88rem] font-medium transition duration-300 ease-dbelo hover:-translate-y-0.5',
                                   'bg-brand text-white shadow-brand hover:shadow-brand-lg' => $isPopular,
                                   'bg-ink/[0.05] text-ink/70 hover:bg-ink/[0.09] dark:bg-paper/[0.08] dark:text-paper/70' => ! $isPopular,
                               ])>
                                @auth
                                    Change plan
                                @else
                                    {{ $plan->isFree() ? 'Start free' : 'Choose '.$plan->name }}
                                @endauth
                            </a>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- ══════════════════════════════════════════════════════════
                 BOUGHT ONCE, NOT SUBSCRIBED

                 A bar, not a fourth card, and visible rather than behind a
                 button. Two reasons, and neither is the layout:

                 · This is the cheapest way somebody pays you for the first
                   time — the person who will not subscribe today. A product
                   one click away is a product most visitors never see, and
                   the one you least want hidden is the low-commitment one.

                 · It is a different kind of purchase, not a cheaper tier.
                   The shape says so before the words do: nobody compares a
                   wide dark bar against three light cards on the same axis,
                   which is exactly the comparison a fourth column invites
                   and the one that makes "$3 once" beat "$9 a month".

                 Full width on purpose. It reads as the floor under the
                 ladder rather than a rung of it.
                 ══════════════════════════════════════════════════════════ --}}
            @foreach ($this->passes as $pass)
                <div class="rise mt-4 overflow-hidden rounded-card bg-surface p-7 shadow-soft-lg ring-1 ring-brand/25 sm:p-8 dark:bg-surface-dark"
                     style="animation-delay: {{ 60 * ($this->tiers->count() + $loop->index) }}ms"
                     wire:key="pass-{{ $pass->id }}">

                    <div class="relative flex flex-wrap items-center gap-x-8 gap-y-5">

                        <div class="min-w-[14rem] flex-1">
                            <div class="flex items-center gap-2.5">
                                <x-icon name="ticket" style="solid" class="text-[0.85rem] text-brand" />
                                <span class="micro">{{ $pass->name }}</span>
                            </div>

                            <p class="mt-2.5 max-w-[46ch] text-[0.95rem] leading-relaxed text-ink/65 dark:text-paper/65">
                                {{ $pass->description ?: 'Everything a plan gives you, for a day. No account to cancel and nothing renews.' }}
                            </p>

                            @if (filled($pass->features ?? []))
                                <ul class="mt-4 flex flex-wrap gap-x-5 gap-y-2">
                                    @foreach ($pass->features as $feature)
                                        <li class="flex items-center gap-2 text-[0.84rem] text-ink/60 dark:text-paper/60">
                                            <x-icon name="check" style="solid" class="shrink-0 text-[0.68rem] text-success" />
                                            {{ $feature }}
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-6">
                            <div class="flex items-baseline gap-1.5">
                                <span class="text-[2.1rem] font-semibold tracking-[-0.03em]">{{ $pass->priceForHumans() }}</span>
                                {{-- "once" rather than an interval. The whole
                                     point of this box is that there is no
                                     next payment, and the word that says so
                                     belongs next to the number. --}}
                                <span class="text-[0.85rem] text-ink/45 dark:text-paper/45">once</span>
                            </div>

                            @if ($this->currentPlanId === $pass->id)
                                <div class="flex items-center gap-2 rounded-full bg-success/12 px-7 py-3 text-[0.88rem] font-medium text-success">
                                    <x-icon name="circle-check" style="solid" class="text-[0.78rem]" />
                                    Active now
                                </div>
                            @else
                                <a href="{{ auth()->check() ? route('plan.show') : route('register') }}" wire:navigate
                                   class="rounded-full bg-action px-7 py-3 text-[0.88rem] font-medium text-white transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:brightness-110">
                                    Get the {{ $pass->name }}
                                </a>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </section>
    @endif

    {{-- ══════════════════════════════════════════════════════════════
         LAST WORD
         ══════════════════════════════════════════════════════════════ --}}
    {{-- Guests are the audience, but an admin sees it too — with a note
         saying so. Same rule as the packs section above: a block that only
         renders for logged-out visitors is a block you can never look at
         while you are building the page, so it silently rots. --}}
    @if (auth()->guest() || auth()->user()?->isAdmin())
        <section class="py-10">
            @auth
                <div class="rise mb-3 flex items-center gap-2.5 text-[0.85rem] text-warning">
                    <x-icon name="eye" style="solid" class="text-[0.8rem]" />
                    Only signed-out visitors see this panel
                </div>
            @endauth

            <div class="rise relative overflow-hidden rounded-panel bg-ink px-8 py-14 text-center text-paper shadow-soft-lg dark:bg-surface-dark">

                <div class="pointer-events-none absolute inset-x-0 bottom-0 opacity-30"
                     style="mask-image: linear-gradient(to top, #000, transparent);
                            -webkit-mask-image: linear-gradient(to top, #000, transparent);">
                    <x-ambient-wave :bars="150" height="h-24" />
                </div>

                <div class="relative">
                    <h2 class="text-[clamp(1.6rem,3.4vw,2.3rem)] font-semibold">
                        Start with a <span class="key">free</span> account
                    </h2>
                    <p class="mx-auto mt-3 max-w-[46ch] text-[0.95rem] text-paper/60">
                        Five downloads a day, no card, no trial clock. Upgrade the day you need more, not before.
                    </p>

                    <a href="{{ route('register') }}" wire:navigate
                       class="mt-7 inline-flex items-center gap-2.5 rounded-full bg-action px-8 py-3.5 font-medium text-white transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:brightness-110">
                        Create account
                        <x-icon name="arrow-right" style="solid" class="text-[0.8rem]" />
                    </a>
                </div>
            </div>
        </section>
    @endif
</div>
