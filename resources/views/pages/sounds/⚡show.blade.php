<?php

use App\Models\Sound;
use App\Support\Serp;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.site')] #[Title('Sound effect')] class extends Component {
    public Sound $sound;

    #[Url(except: 'similar')] public string $tab = 'similar';

    public function mount(Sound $sound): void
    {
        abort_unless($sound->isPublished(), 404);

        $this->sound = $sound->load(['files', 'category.parent', 'license', 'tags', 'user']);

        $sound->incrementQuietly('plays_count');

        // Opened from a recent search: that search found what it was for.
        // Separates "I have nothing" from "I have it and showed the wrong
        // thing" — two failures with very different fixes.
        app(\App\Services\SearchLogger::class)->attributeClick();

        $this->shareSeo();
    }

    /**
     * Meta tags and structured data for this sound.
     *
     * The AudioObject markup is what lets Google render a play button next
     * to the result instead of a plain blue link — the single highest
     * impact SEO detail for an audio library.
     */
    protected function shareSeo(): void
    {
        $sound = $this->sound;
        $preview = $sound->files->firstWhere('purpose', 'preview');

        /*
         * THE SNIPPET IS DERIVED, NOT STORED.
         *
         * The description is now a paragraph written for the page — two or
         * three sentences, 200 to 320 characters. Serp::snippet() takes the
         * sentences of it that fit the width of a result line, so Google
         * gets a finished sentence instead of the first 155 characters with
         * an ellipsis stuck on the end.
         *
         * Derived at render rather than written into meta_description when
         * a suggestion is accepted, and that is deliberate: a stored copy
         * goes stale the first time somebody edits the description and
         * forgets the other field. meta_description stays what it has always
         * been — the MANUAL OVERRIDE, for the handful of pages where the
         * paragraph and the snippet really should say different things.
         *
         * The generated sentence underneath is unchanged: a sound with no
         * description at all still gets a true, specific snippet rather than
         * the site-wide default.
         */
        $description = $sound->meta_description
            ?: Serp::snippet(
                $sound->description
                    ?: sprintf(
                        '%s — free %s sound effect, %s. Download in MP3 or WAV.',
                        $sound->title,
                        strtolower($sound->category?->name ?? 'audio'),
                        $sound->durationForHumans()
                    )
            );

        $jsonld = [
            '@context' => 'https://schema.org',
            '@type' => 'AudioObject',
            'name' => $sound->title,
            'description' => $description,
            'url' => route('sounds.show', $sound),
            'duration' => 'PT'.max(1, (int) round($sound->duration_ms / 1000)).'S',
            'encodingFormat' => 'audio/mpeg',
            'uploadDate' => $sound->published_at?->toDateString(),
            'genre' => $sound->category?->name,
            'keywords' => $sound->tags->pluck('name')->join(', '),
            'isAccessibleForFree' => ! $sound->is_premium,
            'creator' => [
                '@type' => 'Person',
                'name' => $sound->user->name,
            ],
        ];

        if ($preview) {
            $jsonld['contentUrl'] = Storage::disk($preview->disk)->url($preview->path);
        }

        if ($sound->license) {
            $jsonld['license'] = $sound->license->url ?: route('sounds.show', $sound);
        }

        /*
         * thumbnailUrl is what a result needs before Google will consider
         * showing an image beside it at all. It is the site image today
         * rather than one drawn per sound — a per-sound waveform would be
         * better and costs disk, which is a trade to make later.
         */
        $jsonld['thumbnailUrl'] = \App\Support\Appearance::url('social_image') ?? asset('og-default.png');

        if ($download = $sound->files->firstWhere('purpose', 'download')) {
            $jsonld['contentSize'] = $this->megabytes((int) $download->size_bytes);
        }

        /*
         * ── THE BREADCRUMB ───────────────────────────────────────────────
         *
         * This is the line under the title in a Google result. Without it
         * the result shows the raw URL — dbelo.com/sounds/thunder-rumble-03
         * — and with it, "dbelo › Ambience › Thunder Rumble". The second one
         * tells somebody scanning ten results what kind of site this is
         * before they click, which is most of what a breadcrumb is for.
         *
         * Sent as a second top-level object rather than nested: a page may
         * describe more than one thing, and ld+json takes an array.
         */
        $crumbs = [
            ['name' => 'Sound effects', 'url' => route('sounds.index')],
        ];

        if ($sound->category) {
            if ($sound->category->parent) {
                $crumbs[] = [
                    'name' => $sound->category->parent->name,
                    'url' => route('sounds.index', ['category' => $sound->category->parent->slug]),
                ];
            }

            $crumbs[] = [
                'name' => $sound->category->name,
                'url' => route('sounds.index', ['category' => $sound->category->slug]),
            ];
        }

        $crumbs[] = ['name' => $sound->title, 'url' => route('sounds.show', $sound)];

        $breadcrumb = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($crumbs)->map(fn ($crumb, $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            ])->all(),
        ];

        view()->share('seo', [
            /*
             * "Thunder Rumble — free sound effect, 0:04" rather than
             * "Thunder Rumble".
             *
             * Nobody searches for the name of a file they have never seen.
             * They search for "free thunder sound effect", and the words
             * that match that query have to be IN the title, not implied by
             * the site it sits on. The duration goes in because it is the
             * next thing anybody wants to know and it costs four characters.
             *
             * A meta_title typed by hand always wins: the moment a person
             * has written one, a pattern is a worse guess than their words.
             */
            'title' => $sound->meta_title ?: sprintf(
                '%s — free %s, %s',
                $sound->title,
                $sound->type === 'music' ? 'music track' : 'sound effect',
                $sound->durationForHumans(),
            ),
            'description' => $description,
            'canonical' => route('sounds.show', $sound),
            'type' => 'music.song',
            'image_alt' => $sound->title.' — waveform',
            // Lets a platform that supports it play the preview in the feed.
            'audio' => $preview ? Storage::disk($preview->disk)->url($preview->path) : null,
            'jsonld' => [$jsonld, $breadcrumb],
        ]);
    }

    // ---------------------------------------------------------------
    // What sits under the player
    // ---------------------------------------------------------------

    /**
     * How much description is shown before "See more".
     *
     * ── WHY A CHARACTER COUNT AND NOT line-clamp ─────────────────────────
     *
     * This used to be line-clamp-2, which is a CSS rule and therefore only
     * the browser knows whether anything was actually cut. The button had no
     * way to find out, so it was rendered always — including under a
     * one-sentence description where "See more" opened nothing. A control
     * that lies is worse than a missing control: press it once, watch nothing
     * happen, and every other control on the page loses a little credibility.
     *
     * Counting characters on the server means the button can be rendered only
     * when there is genuinely something behind it.
     *
     * ── WHY THIS DROPPED FROM 445 TO 150 ─────────────────────────────────
     *
     * 445 was four lines, chosen when a description was one sentence of about
     * 135 characters — so it never fired, and "See more" was a button that
     * had never once appeared. The descriptions are 200 to 320 now, written
     * as three sentences with a job each, and the whole paragraph was landing
     * between the waveform and the Download button and pushing it off screen.
     *
     * 150 shows the first sentence and a little of the second: what the sound
     * IS, which is the question somebody has while deciding whether to press
     * play. Where it gets used and how it was recorded are worth reading and
     * are not worth the button moving down for, so they go behind the fold.
     */
    public const DETAILS_CLAMP = 150;

    /**
     * Never fold when only a few words would be hidden.
     *
     * A "See more" that reveals six words is a control that cost a click and
     * returned nothing — the same broken promise as the one that opened
     * nothing, just quieter. Below this much hidden text the paragraph is
     * simply shown whole.
     *
     * 30 and not 60: at 60 the fold only started at 210 characters, which is
     * above where most written descriptions land, so the common case went on
     * showing the whole paragraph and the change did nothing where it was
     * asked for. 30 folds anything past 180 — effectively every description
     * the model writes — and still shows short hand-written ones whole.
     */
    private const DETAILS_WORTH_FOLDING = 30;

    /**
     * Which tabs this sound actually has, in order.
     *
     * ── WHY THIS IS COMPUTED AND NOT WRITTEN IN THE TEMPLATE ─────────────
     *
     * The tab strip used to skip empty tabs with @continue while $tab stayed
     * on its default of 'similar'. A sound with no related sounds therefore
     * rendered no button for the tab that was selected, and the panel below
     * drew an empty list — a card with nothing in it and no way to tell why.
     *
     * Moving the licence in makes that worse rather than better: the licence
     * is the one tab that is almost always there, so it would be the thing
     * you could not reach. The list is built here, and `activeTab()` below falls
     * back to the first entry, so the selected tab is always one that exists.
     *
     * @return array<string, array{0: string, 1: string, 2: ?int}>
     */
    #[Computed]
    public function tabs(): array
    {
        $tabs = [];

        if ($this->related->isNotEmpty()) {
            $tabs['similar'] = ['Similar sounds', 'waveform-lines', $this->related->count()];
        }

        if ($this->packSounds->isNotEmpty()) {
            $tabs['pack'] = ['In this pack', 'box-open', $this->packSounds->count()];
        }

        /*
         * Last, and with no count.
         *
         * Last because the other two are what somebody came for — another
         * sound — and this is the small print they check before leaving. No
         * count because "License 1" is a number that answers no question;
         * every other tab's number says how much is behind it.
         */
        if ($this->sound->license) {
            $tabs['license'] = ['License', 'file-contract', null];
        }

        return $tabs;
    }

    /**
     * The selected tab, guaranteed to be one that exists.
     *
     * $tab comes from the URL, so it can name a tab this sound does not have
     * — an old link, a pack that was emptied, or simply the default landing
     * on a sound with nothing similar. Resolving it here rather than trusting
     * the property is what stops the page rendering an empty panel.
     */
    public function activeTab(): string
    {
        $tabs = $this->tabs;

        return isset($tabs[$this->tab]) ? $this->tab : (string) array_key_first($tabs);
    }

    /**
     * The description, in the two lengths the page needs.
     *
     * @return array{full: string, short: string, truncated: bool}|null
     */
    #[Computed]
    public function details(): ?array
    {
        $full = trim((string) $this->sound->description);

        if ($full === '') {
            return null;
        }

        if (mb_strlen($full) <= self::DETAILS_CLAMP + self::DETAILS_WORTH_FOLDING) {
            return ['full' => $full, 'short' => $full, 'truncated' => false];
        }

        return [
            'full' => $full,
            'short' => $this->lead($full),
            'truncated' => true,
        ];
    }

    /**
     * The part shown before the fold.
     *
     * Prefers to end on a full stop. The descriptions are written as three
     * sentences, so a fold that lands on one of those joins reads as a
     * paragraph that paused; a fold three words into the second sentence
     * reads as a paragraph that was interrupted, and the ellipsis is the
     * only thing distinguishing the two.
     *
     * The sentence has to be worth showing, hence the lower bound: a
     * description opening with "Short, dry, close." would otherwise fold
     * after three words and show almost nothing.
     */
    private function lead(string $full): string
    {
        $window = mb_substr($full, 0, self::DETAILS_CLAMP);
        $stop = -1;

        foreach (['. ', '! ', '? '] as $ending) {
            $at = mb_strrpos($window, $ending);

            if ($at !== false && $at > $stop) {
                $stop = $at;
            }
        }

        if ($stop + 1 >= self::DETAILS_CLAMP / 2) {
            return rtrim(mb_substr($window, 0, $stop + 1));
        }

        // preserveWords, because a cut landing mid-word reads as a bug
        // rather than as a fold — and the ellipsis has to look deliberate
        // for "See more" to look like the answer to it.
        return Str::limit($full, self::DETAILS_CLAMP, '…', preserveWords: true);
    }

    /**
     * The technical chips: what you get and how big it is.
     *
     * Read from sound_files rather than assumed, because which formats a
     * sound actually has depends on what came out of processing — and
     * promising a WAV that is not there is worse than not offering one.
     */
    #[Computed]
    public function specs(): array
    {
        $master = $this->sound->files->firstWhere('purpose', 'original');
        $download = $this->sound->files->firstWhere('purpose', 'download');

        $chips = [];

        if ($master) {
            $chips[] = ['music', strtoupper($master->format)];
        }

        if ($this->sound->sample_rate) {
            $chips[] = ['wave-sine', number_format($this->sound->sample_rate / 1000, 1).' kHz'];
        }

        if ($this->sound->bit_depth) {
            $chips[] = ['layer-group', $this->sound->bit_depth.'-bit'];
        }

        $chips[] = ['diagram-project', $this->sound->channels === 1 ? 'Mono' : 'Stereo'];

        if ($size = ($download?->size_bytes ?: $master?->size_bytes)) {
            $chips[] = ['hard-drive', $this->megabytes($size)];
        }

        return $chips;
    }

    /** How many tags the page shows. The index keeps all of them. */
    public const TAGS_SHOWN = 5;

    /**
     * The tags worth putting on the page.
     *
     * Three filters, in this order:
     *
     *   unique by slug   the tags table keys on slug, so duplicates should
     *                    not exist — but a pivot row written before that rule
     *                    existed still can, and the reader sees the same word
     *                    twice and assumes the catalogue is careless.
     *   not the category the sound is already filed under it, and the
     *                    breadcrumb above says so. Repeating it as a pill
     *                    spends one of five slots saying nothing new.
     *   first five       see the note in the template.
     */
    #[Computed]
    public function visibleTags()
    {
        $category = Str::slug((string) $this->sound->category?->name);

        return $this->sound->tags
            ->unique(fn ($tag) => $tag->slug ?: Str::slug($tag->name))
            ->reject(fn ($tag) => $category !== '' && ($tag->slug ?: Str::slug($tag->name)) === $category)
            ->take(self::TAGS_SHOWN)
            ->values();
    }

    protected function megabytes(int $bytes): string
    {
        return $bytes >= 1024 ** 2
            ? round($bytes / 1024 ** 2, 2).' MB'
            : round($bytes / 1024).' KB';
    }

    /**
     * More like this.
     *
     * Same category first, then anything else to fill the gap. A list of
     * three because a category is thin is a worse page than a list of eight
     * where the last few are looser matches — the visitor is browsing, not
     * being served a ranking.
     */
    #[Computed]
    public function related()
    {
        $limit = 8;

        $sameCategory = Sound::published()
            ->with(['files', 'user:id,name', 'tags:id,name', 'category:id,name'])
            ->where('id', '!=', $this->sound->id)
            ->when($this->sound->category_id, fn ($q) => $q->where('category_id', $this->sound->category_id))
            ->orderByDesc('downloads_count')
            ->limit($limit)
            ->get();

        if ($sameCategory->count() >= $limit) {
            return $sameCategory;
        }

        $filler = Sound::published()
            ->with(['files', 'user:id,name', 'tags:id,name', 'category:id,name'])
            ->whereNotIn('id', $sameCategory->pluck('id')->push($this->sound->id))
            ->orderByDesc('downloads_count')
            ->limit($limit - $sameCategory->count())
            ->get();

        return $sameCategory->concat($filler);
    }

    /** The pack this sound belongs to, if it is in a published one. */
    #[Computed]
    public function pack()
    {
        return $this->sound->collections()
            ->where('is_featured', true)
            ->where('is_public', true)
            ->first();
    }

    #[Computed]
    public function packSounds()
    {
        return $this->pack
            ? $this->pack->sounds()->published()
                ->with(['files', 'user:id,name', 'tags:id,name'])
                ->limit(20)->get()
            : collect();
    }

    public function title(): string
    {
        return $this->sound->title;
    }
}; ?>

<div class="mx-auto max-w-4xl">

    <nav class="mb-6 flex items-center gap-2 text-sm text-ink/50 dark:text-paper/50">
        <a href="{{ route('sounds.index') }}" wire:navigate class="transition hover:text-brand">Sound effects</a>
        @if ($sound->category)
            <x-icon name="chevron-right" style="solid" class="text-[0.6rem] opacity-50" />
            <a href="{{ route('sounds.index', ['category' => $sound->category->slug]) }}" wire:navigate class="transition hover:text-brand">
                {{ $sound->category->name }}
            </a>
        @endif
    </nav>

    {{-- ══════════════════════════════════════════════════════════════
         THE CARD

         Dark on a light page, which is the signature of the system: the
         thing that matters most is the darkest thing on screen. It is also
         where the waveform belongs — a light background washes it out.
         ══════════════════════════════════════════════════════════════ --}}
    {{-- NO overflow-hidden here, and that is deliberate.

         It used to be, and it silently broke every dropdown inside this
         card. The collection picker and the share menu both open as
         absolutely positioned panels, and an ancestor with overflow-hidden
         clips them at the card's edge — so the panel opened, rendered, and
         was cut off or invisible depending on how much room was left below.
         Nothing errors; the control simply does not appear to work.

         Nothing in this card needs the clip. There is no edge-to-edge image
         or child with a negative margin — everything sits inside p-6/p-8 —
         so rounded-panel already gives the rounded corners on its own. --}}
    <div class="rounded-panel bg-ink text-paper shadow-soft-lg dark:bg-surface-dark">

        <div class="p-6 sm:p-8">

            {{-- Player: big play button, the full waveform, the duration --}}
            <div class="flex items-center gap-5">
                <div class="min-w-0 flex-1">
                    <span class="micro !text-paper/40">
                        {{ $sound->type === 'music' ? 'Music' : 'Sound effect' }}
                    </span>

                    <h1 class="mt-1 text-[clamp(1.5rem,3.4vw,2.1rem)] font-semibold">{{ $sound->title }}</h1>

                    <div class="mt-1.5 text-[0.88rem] text-paper/45">
                        By <span class="text-brand">{{ $sound->user->name }}</span>
                    </div>
                </div>

                @if ($sound->is_premium)
                    <span class="shrink-0 self-start rounded-full bg-brand px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.09em] text-white">Pro</span>
                @endif
            </div>

            <x-waveform-player :sound="$sound" :bars="200" height="h-16" button="size-14" class="mt-7" />

            {{-- ── ITEM DETAILS ───────────────────────────────────────
                 Above the tags, and rendered ONLY when there is a
                 description. No placeholder, no empty heading: a sound
                 without one simply goes straight from the waveform to its
                 spec line, which is a complete page rather than a page with
                 a hole labelled in it.

                 x-data on ONE LINE with no comments inside it. A `//` comment
                 in an Alpine attribute swallows the rest of the object
                 literal, the component is discarded, and every name in it
                 falls through to `window` — which is how `open` once became
                 `window.open` here and cost three turns to find. --}}
            @if ($this->details)
                <div x-data="{ open: false }" class="mt-7">
                    <div class="micro !text-paper/40">Item details</div>

                    {{-- Two paragraphs rather than one that grows: the short
                         one is what the server rendered, so nothing is
                         measured in the browser and nothing reflows on load.
                         The long one starts hidden with an inline style and
                         NOT x-cloak — a Livewire morph can only restore what
                         was rendered, and x-cloak would flash the whole
                         description open for a frame on any re-render. --}}
                    {{-- The toggle sits INSIDE the paragraph, right after the
                         ellipsis it answers, rather than on its own line
                         below.

                         On its own line it read as a separate control that
                         happened to be nearby — the eye reached the "…",
                         found nothing, and moved on to the spec row. Next to
                         the dots it is the end of the sentence: the text
                         stops, and the thing that continues it is the next
                         word. Same for "See less", which belongs at the end
                         of the full text for the same reason.

                         One button per paragraph, not one shared button
                         moving between them: each lives in the flow of the
                         text it closes, and only one of the two paragraphs
                         is ever on screen. --}}
                    <p class="mt-2 text-[0.95rem] leading-relaxed text-paper/70"
                       x-show="! open">{{ $this->details['short'] }}@if ($this->details['truncated'])<button
                            type="button" x-on:click="open = true"
                            class="ml-1.5 text-[0.85rem] text-brand underline underline-offset-4 transition hover:text-paper">See more</button>@endif</p>

                    <p class="mt-2 text-[0.95rem] leading-relaxed text-paper/70"
                       x-show="open" style="display: none">{{ $this->details['full'] }}@if ($this->details['truncated'])<button
                            type="button" x-on:click="open = false"
                            class="ml-1.5 text-[0.85rem] text-brand underline underline-offset-4 transition hover:text-paper">See less</button>@endif</p>
                </div>
            @endif

            {{-- The spec line: format, sample rate, bit depth, channels,
                 size — beside the duration, where it was and where it
                 belongs. It answers "what exactly am I downloading", which is
                 the question asked while looking at the Download button, not
                 one asked further down the page. --}}
            <div class="mt-7 flex flex-wrap items-center gap-x-5 gap-y-3">
                @foreach ($this->specs as [$icon, $label])
                    <span class="flex items-center gap-2.5 text-[0.86rem] text-paper/65">
                        <x-icon :name="$icon" style="solid" class="text-[0.8rem] text-paper/30" />
                        {{ $label }}
                    </span>
                @endforeach

                <span class="flex items-center gap-2.5 text-[0.86rem] text-paper/65">
                    <x-icon name="clock" style="solid" class="text-[0.8rem] text-paper/30" />
                    {{ $sound->durationForHumans() }}
                </span>

                @if ($sound->is_loopable)
                    <span class="flex items-center gap-2.5 text-[0.86rem] text-success">
                        <x-icon name="repeat" style="solid" class="text-[0.8rem]" />
                        Seamless loop
                    </span>
                @endif
            </div>

            {{-- Tags and the actions, on one line where there is room.

                 FIVE AT MOST, and that is a display rule rather than a
                 storage one. The model returns eight to fourteen and the
                 search index keeps every one of them — that breadth is what
                 makes a sound findable. What it does not do is help a person
                 reading the page: past five, the row wraps, pushes the
                 Download button down, and turns into a wall of pills nobody
                 reads. Narrow for the eye, wide for the engine.

                 The category is dropped when it repeats as a tag, and the
                 list is de-duplicated by name — "Crowd" and "crowd" are one
                 word to a reader even where they are two rows. --}}
            <div class="mt-7 flex flex-wrap items-center gap-3">
                @foreach ($this->visibleTags as $tag)
                    <a href="{{ route('sounds.index', ['q' => $tag->name]) }}" wire:navigate
                       class="rounded-full bg-paper/[0.07] px-4 py-2 text-[0.83rem] text-paper/70 transition duration-300 ease-dbelo hover:bg-paper/[0.14] hover:text-paper">
                        {{ $tag->name }}
                    </a>
                @endforeach

                <div class="ml-auto flex items-center gap-2.5">
                    <livewire:favorite-button :sound="$sound" :key="'fav-'.$sound->id" />
                    <livewire:collection-picker :sound="$sound" :key="'pick-'.$sound->id" />

                    <x-share-menu :url="route('sounds.show', $sound)" :title="$sound->title" tone="dark" />

                    <a href="{{ route('sounds.download', $sound) }}"
                       class="flex items-center gap-2.5 rounded-full bg-action px-7 py-3 font-medium text-white transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:brightness-110">
                        <x-icon name="arrow-down-to-line" style="solid" class="text-[0.85rem]" />
                        Download
                    </a>
                </div>
            </div>

            {{-- ══════════════════════════════════════════════════════════
                 THE LINE UNDER THE BUTTON
                 ══════════════════════════════════════════════════════════
                 It used to say "Free account needed to download" to
                 everybody who was not signed in. That is now only true some
                 of the time, and a button that says one thing while the
                 line under it says another is how a visitor learns not to
                 read either.

                 A guest with files left is told the number BEFORE pressing,
                 which is the whole mechanism: the count is what makes the
                 sign-up page later feel like an expected step rather than a
                 refusal. Fully qualified because a ⚡ component's template
                 does not see the `use` imports in its PHP head. --}}
            <div class="micro !text-paper/35 mt-3 text-right">
                @auth
                    @php($remaining = auth()->user()->remainingDownloadsToday())
                    {{ $remaining === null ? 'Unlimited downloads' : $remaining.' left today' }}
                @else
                    @if ($sound->is_premium)
                        Part of a plan — sign in to download
                    @elseif (! \App\Support\Downloads::guestsAllowed())
                        Free account needed to download
                    @elseif (\App\Support\Downloads::guestHasTaken(request(), $sound->id))
                        You already have this one — re-downloading is free
                    @else
                        @php($left = \App\Support\Downloads::guestRemaining(request()))
                        {{ $left > 0
                            ? $left.($left === 1 ? ' free download left' : ' free downloads left').' — no account needed'
                            : 'Free account needed to keep downloading' }}
                    @endif
                @endauth
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         ADVERTISING
         ══════════════════════════════════════════════════════════════
         Here, and not under the player, and the reason is worth writing
         down because the obvious placement is the dangerous one.

         Above the fold it earns more per impression — almost nobody
         scrolls — but it sat two elements from the Download button and its
         280px pushed that button off the screen. Accidental clicks beside a
         download do not cost you a placement; they cost the AdSense account.
         And it was doing to Download exactly what we removed the description
         from that spot for doing.

         By this point the visitor has listened, seen the spec sheet, read
         the licence and made their decision. There is nothing left to
         interrupt and nothing clickable within half a page — which also
         happens to be when "what do I do next" is a real question, so the
         block is more relevant here than it was up there.
         ══════════════════════════════════════════════════════════════ --}}
    <x-ad-slot name="sound" />

    {{-- ══════════════════════════════════════════════════════════════
         MORE
         ══════════════════════════════════════════════════════════════ --}}
    {{-- The licence is a TAB here now, not a panel of its own above.

         It was a full-width card carrying three pills and one sentence, and
         it sat between the player and the related sounds — prime space for
         something a visitor glances at once. As a tab it costs a tab stop
         and nothing else, and it is next to the two other things somebody
         looks at after deciding they want the sound.

         The guard now includes the licence. Without that, a sound with no
         pack and nothing similar would render no section at all and the
         licence would simply be gone from the page — which is how moving a
         legal term into a tab turns from tidying into hiding. --}}
    @if ($this->tabs !== [])
        @php($current = $this->activeTab())

        <section class="mt-12">

            <div class="mb-5 flex flex-wrap items-center gap-1.5 border-b border-ink/[0.08] dark:border-paper/10">
                @foreach ($this->tabs as $key => [$label, $icon, $count])
                    <button wire:click="$set('tab', '{{ $key }}')"
                            @class([
                                'flex items-center gap-2 border-b-2 px-4 py-3 text-[0.9rem] transition duration-300 ease-dbelo -mb-px',
                                'border-brand text-brand' => $current === $key,
                                'border-transparent text-ink/50 hover:text-ink dark:text-paper/50 dark:hover:text-paper' => $current !== $key,
                            ])>
                        <x-icon :name="$icon" style="solid" class="text-[0.8rem]" />
                        {{ $label }}

                        {{-- Only where a number answers something. "License 1"
                             is a count of nothing anybody asked about. --}}
                        @if ($count !== null)
                            <span class="text-[0.75rem] opacity-50">{{ $count }}</span>
                        @endif
                    </button>
                @endforeach

                @if ($this->pack)
                    <a href="{{ route('collections.show', $this->pack) }}" wire:navigate
                       class="ml-auto flex items-center gap-2 py-3 text-[0.83rem] text-ink/45 transition hover:text-brand dark:text-paper/45">
                        {{ $this->pack->name }}
                        <x-icon name="arrow-right" style="solid" class="text-[0.7rem]" />
                    </a>
                @endif
            </div>

            @if ($current === 'license')
                {{-- p-7 and not p-3: the sound lists are rows that bring their
                     own padding, this is prose and needs its own. --}}
                <div class="rounded-card bg-surface p-7 shadow-soft-md dark:bg-surface-dark">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-3">
                        <span class="grid size-11 shrink-0 place-items-center rounded-full bg-brand/10 text-brand">
                            <x-icon name="file-contract" style="solid" class="text-[0.95rem]" />
                        </span>

                        <div class="min-w-0">
                            <h2 class="text-lg font-medium">{{ $sound->license->name }}</h2>
                            @if ($sound->license->version)
                                <div class="micro mt-0.5">Version {{ $sound->license->version }}</div>
                            @endif
                        </div>

                        {{-- Icon AND text, never colour alone: a permission a
                             colour-blind visitor reads backwards is a legal
                             problem, not a design one.

                             No longer folded behind anything. The tab is
                             already the fold — hiding them a second time
                             inside it would mean two clicks to learn whether
                             you may sell the thing you are downloading. --}}
                        <div class="flex flex-wrap gap-1.5 sm:ml-auto">
                            @foreach ([
                                ['Commercial use', $sound->license->allows_commercial],
                                ['Credit required', $sound->license->requires_attribution],
                                ['Modifications allowed', $sound->license->allows_derivatives],
                            ] as [$label, $allowed])
                                <span @class([
                                    'flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[0.72rem] shadow-soft-sm',
                                    'bg-success/10 text-success' => $allowed,
                                    'bg-ink/[0.04] text-ink/40 dark:bg-paper/[0.07] dark:text-paper/40' => ! $allowed,
                                ])>
                                    <x-icon :name="$allowed ? 'circle-check' : 'circle-xmark'" style="solid" class="text-[0.65rem]" />
                                    {{ $label }}
                                </span>
                            @endforeach
                        </div>
                    </div>

                    @if (filled($sound->license->summary))
                        <p class="mt-5 max-w-[80ch] text-[0.92rem] leading-relaxed text-ink/60 dark:text-paper/60">
                            {{ $sound->license->summary }}
                        </p>
                    @endif

                    <a href="{{ $sound->license->url ?: route('legal.licenses') }}"
                       @if ($sound->license->url) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif
                       class="mt-4 inline-flex items-center gap-2 text-[0.85rem] text-brand underline underline-offset-4 transition hover:text-ink dark:hover:text-paper">
                        Read the full licence
                        <x-icon name="arrow-up-right" style="solid" class="text-[0.7rem]" />
                    </a>
                </div>
            @else
                <div class="rounded-card bg-surface p-3 shadow-soft-md dark:bg-surface-dark">
                    @foreach (($current === 'pack' ? $this->packSounds : $this->related) as $other)
                        <x-sound-row :sound="$other" wire:key="{{ $current }}-{{ $other->id }}" />
                    @endforeach
                </div>
            @endif
        </section>
    @endif
</div>
