<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen">

    {{-- Immediately after <body>, which is where Google Tag Manager's
         <noscript> and several chat widgets are required to sit. Panels that
         offer only "header" and "footer" leave those with nowhere correct to
         go, so they get pasted into the head and quietly never run. --}}
    {!! \App\Support\CustomCode::bodyStart() !!}

    {{-- Order matters. The two admin banners say "what you are looking at is
         not what everybody else is looking at", and that has to be the first
         thing on the page. For a visitor neither renders, so the promotional
         bar is the top of the site — which is where it belongs. --}}
    <x-site-status-banner />

    @if (session()->has('impersonator_id'))
        {{-- Impossible to miss on purpose: acting as someone else without
             realising it is how mistakes get made. --}}
        <div class="flex flex-wrap items-center justify-center gap-3 bg-action px-4 py-2.5 text-center text-white">
            <x-icon name="user-secret" style="solid" class="text-sm" />
            <span class="text-[0.86rem]">
                You are viewing the site as <strong>{{ auth()->user()?->name }}</strong>
            </span>
            <a href="{{ route('impersonate.stop') }}"
               class="rounded-full bg-white/20 px-4 py-1 text-[0.8rem] font-medium transition hover:bg-white/30">
                Back to my account
            </a>
        </div>
    @endif

    <x-promo-bar />

    @php
        $navLinks = array_values(array_filter([
            ['Browse', route('sounds.index'), 'sounds.*'],
            \App\Models\Collection::featured()->exists() ? ['Packs', route('packs.index'), 'packs.*'] : null,
            \App\Models\Post::blogHasPosts() ? ['Blog', route('blog'), 'blog*'] : null,
        ]));
    @endphp

    {{--
        The bar floats rather than spanning the page.

        A full-width bar welded to the top edge is the default every site
        has; a pill with air around it reads as an object sitting on the
        page, which is the same idea as the cards below it.

        The pill is OPAQUE, not a translucent panel with a blur behind it.
        85% + backdrop-blur sounds like frosted glass and looks like it on a
        photograph, but this page scrolls high-contrast black text under the
        bar: at 15% transmission that text stays legible enough to read
        through the nav, and the result is two paragraphs fighting for the
        same pixels. Solid costs nothing and the shadow already separates
        the bar from the page.

        The 16px of air above the pill is padding on the sticky element, so
        the pill keeps its margin from the viewport edge while stuck.
    --}}
    <nav x-data="{ mobile: false }" class="sticky top-0 z-50 px-4 pt-4 sm:px-6">
        <div class="mx-auto max-w-[1160px] rounded-full bg-surface px-4 py-2.5 shadow-soft-lg transition duration-500 dark:bg-surface-dark">
            <div class="flex items-center gap-3">

                <x-site-logo />

                {{-- Public links. Everything an anonymous visitor might want,
                     and nothing else — the account items live behind the
                     avatar so the bar cannot grow with the product. --}}
                <div class="ml-3 hidden items-center gap-1 md:flex">
                    @foreach ($navLinks as [$label, $href, $pattern])
                        <a href="{{ $href }}" wire:navigate
                           @class([
                               'rounded-full px-4 py-2 text-sm transition duration-300 ease-dbelo',
                               'bg-ink text-paper dark:bg-brand' => request()->routeIs($pattern),
                               'text-ink/60 hover:bg-ink/[0.06] hover:text-ink dark:text-paper/60 dark:hover:bg-paper/10 dark:hover:text-paper' => ! request()->routeIs($pattern),
                           ])>{{ $label }}</a>
                    @endforeach
                </div>

                <div class="ml-auto flex items-center gap-2">

                    @auth
                        @if (auth()->user()->canUpload())
                            <a href="{{ route('upload') }}" wire:navigate
                               class="hidden items-center gap-2 rounded-full px-4 py-2 text-sm text-ink/60 transition duration-300 ease-dbelo hover:bg-ink/[0.06] hover:text-ink sm:flex dark:text-paper/60 dark:hover:bg-paper/10 dark:hover:text-paper">
                                <x-icon name="arrow-up-from-bracket" style="solid" class="text-xs" />
                                Upload
                            </a>
                        @endif

                        {{-- One dropdown instead of six links.
                             Admin, Review, Users, Library and Dashboard were
                             all sitting in the bar; on a wide screen that is
                             nine items competing with the logo, and on a
                             narrow one it wrapped. --}}
                        <div x-data="{ open: false }" x-on:click.outside="open = false"
                             x-on:keydown.escape.window="open = false" class="relative">

                            <button type="button" x-on:click="open = ! open" :aria-expanded="open"
                                    class="flex items-center gap-2 rounded-full py-1 pl-1 pr-2.5 transition duration-300 ease-dbelo hover:bg-ink/[0.06] dark:hover:bg-paper/10">
                                <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand text-[0.8rem] font-semibold text-white">
                                    {{ Str::upper(Str::substr(auth()->user()->name, 0, 1)) }}
                                </span>
                                <x-icon name="chevron-down" style="solid" class="text-[0.6rem] text-ink/40 dark:text-paper/40" />
                            </button>

                            {{--
                                w-72, not w-60.

                                240px had to hold an email address and
                                "Upload a sound" next to a 16px icon column
                                and 28px of padding — every one of those
                                lines was one character from the ellipsis.
                                A menu whose own labels truncate reads as
                                broken before anyone has clicked anything.

                                The header repeats the avatar at a larger
                                size: the trigger is a 32px letter, and when
                                the panel opens under it the eye wants
                                confirmation of WHICH account it just
                                opened, which is the one thing the old
                                header did not show.
                            --}}
                            <div x-show="open" style="display: none" x-transition.opacity.duration.200ms
                                 class="absolute right-0 z-50 mt-2.5 w-72 origin-top-right overflow-hidden rounded-card bg-surface p-2 shadow-soft-lg dark:bg-surface-dark">

                                <div class="flex items-center gap-3 px-2.5 pb-3 pt-2">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-brand text-[0.95rem] font-semibold text-white">
                                        {{ Str::upper(Str::substr(auth()->user()->name, 0, 1)) }}
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <div class="truncate text-[0.9rem] font-medium leading-tight">{{ auth()->user()->name }}</div>
                                        <div class="mt-0.5 truncate text-[0.76rem] leading-tight text-ink/45 dark:text-paper/45">{{ auth()->user()->email }}</div>
                                    </div>

                                    @if (auth()->user()->isAdmin())
                                        <span class="shrink-0 rounded-full bg-brand/12 px-2 py-0.5 text-[9px] font-semibold uppercase tracking-[0.1em] text-brand">
                                            Admin
                                        </span>
                                    @endif
                                </div>

                                <div class="mb-1.5 h-px bg-ink/[0.07] dark:bg-paper/10"></div>

                                {{-- No "Dashboard" entry. It pointed at the
                                     starter kit's placeholder page, sitting
                                     one line above "Admin panel" — two
                                     entries that read as the same thing,
                                     one of them empty. Account settings
                                     take its place. --}}
                                @foreach (array_filter([
                                    ['Library', 'heart', route('library')],
                                    ['Account settings', 'gear', route('profile.edit')],
                                    auth()->user()->canUpload() ? ['Upload a sound', 'arrow-up-from-bracket', route('upload')] : null,
                                ]) as [$label, $icon, $href])
                                    <a href="{{ $href }}" wire:navigate
                                       class="flex items-center gap-3 rounded-control px-2.5 py-2.5 text-[0.87rem] transition duration-200 ease-dbelo hover:bg-ink/[0.05] dark:hover:bg-paper/[0.08]">
                                        <x-icon :name="$icon" style="solid" class="w-4 shrink-0 text-center text-[0.8rem] text-ink/40 dark:text-paper/40" />
                                        <span class="min-w-0 flex-1 truncate">{{ $label }}</span>
                                    </a>
                                @endforeach

                                @if (auth()->user()->isAdmin())
                                    <div class="my-1.5 h-px bg-ink/[0.07] dark:bg-paper/10"></div>

                                    <div class="micro px-2.5 pb-1 pt-1.5 text-ink/35 dark:text-paper/35">Staff</div>

                                    @foreach ([
                                        ['Admin panel', 'gauge-high', route('admin.dashboard')],
                                        ['Review queue', 'shield-check', route('moderate')],
                                        ['Users', 'users', route('users')],
                                    ] as [$label, $icon, $href])
                                        <a href="{{ $href }}" wire:navigate
                                           class="flex items-center gap-3 rounded-control px-2.5 py-2.5 text-[0.87rem] transition duration-200 ease-dbelo hover:bg-ink/[0.05] dark:hover:bg-paper/[0.08]">
                                            <x-icon :name="$icon" style="solid" class="w-4 shrink-0 text-center text-[0.8rem] text-brand" />
                                            <span class="min-w-0 flex-1 truncate">{{ $label }}</span>
                                        </a>
                                    @endforeach
                                @endif

                                <div class="my-1.5 h-px bg-ink/[0.07] dark:bg-paper/10"></div>

                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit"
                                            class="flex w-full items-center gap-3 rounded-control px-2.5 py-2.5 text-left text-[0.87rem] text-danger transition duration-200 ease-dbelo hover:bg-danger/10">
                                        <x-icon name="arrow-right-from-bracket" style="solid" class="w-4 shrink-0 text-center text-[0.8rem]" />
                                        Log out
                                    </button>
                                </form>
                            </div>
                        </div>
                    @else
                        <a href="{{ route('login') }}" wire:navigate
                           class="rounded-full px-4 py-2 text-sm text-ink/60 transition duration-300 ease-dbelo hover:bg-ink/[0.06] hover:text-ink dark:text-paper/60 dark:hover:bg-paper/10 dark:hover:text-paper">
                            Log in
                        </a>

                        <a href="{{ route('register') }}" wire:navigate
                           class="rounded-full bg-brand px-5 py-2.5 text-sm font-medium text-white shadow-brand transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-brand-lg">
                            Sign up
                        </a>
                    @endauth

                    {{--
                        Light · dark · system — THREE states, not two.

                        Two was a bug, not a simplification. Flux starts every
                        visitor on "system", which is what makes a computer go
                        dark on its own at nightfall. A two-way toggle can only
                        write "light" or "dark", so the first click pinned the
                        site to one of them forever and that behaviour was
                        gone with no way back. The third stop is how you give
                        it back.

                        Driven through $flux.appearance because
                        @fluxAppearance in the head is what applies the class
                        BEFORE first paint — a second mechanism here would be
                        two things fighting over one class, and a white flash
                        on every load.

                        The state is mirrored locally rather than read from
                        $flux on every render: a local value is reactive for
                        certain, and this button is the only thing that writes
                        it besides the settings page.
                    --}}
                    <button type="button"
                            x-data="{ mode: 'system', flash: false }"
                            x-init="mode = ($flux.appearance || 'system')"
                            x-on:click="
                                mode = mode === 'light' ? 'dark' : (mode === 'dark' ? 'system' : 'light');
                                $flux.appearance = mode;

                                /* Retrigger the wash: an animation only replays
                                   if the class actually leaves the element. */
                                flash = false;
                                $nextTick(() => flash = true);
                                setTimeout(() => flash = false, 700);
                            "
                            :title="mode === 'light' ? 'Light — click for dark'
                                    : mode === 'dark' ? 'Dark — click to follow your system'
                                    : 'Following your system — click for light'"
                            :aria-label="'Theme: ' + mode"
                            class="relative grid size-9 shrink-0 place-items-center overflow-hidden rounded-full bg-ink/[0.05] text-ink/55 transition duration-300 ease-dbelo hover:bg-ink/[0.10] hover:text-ink dark:bg-paper/10 dark:text-paper/60 dark:hover:bg-paper/[0.16] dark:hover:text-paper">

                        {{-- Nightfall.

                             A wash of colour expands from the middle of the
                             button and fades: warm amber on the way to light,
                             cold blue on the way to dark. It lasts 700ms and
                             leaves nothing behind — the point is to make the
                             switch feel like something happened rather than
                             like an icon being swapped out. --}}
                        <span class="pointer-events-none absolute inset-0 rounded-full opacity-0"
                              :class="flash && ('nightfall ' + (
                                  mode === 'dark'  ? 'nightfall-night'
                                : mode === 'light' ? 'nightfall-day'
                                :                    'nightfall-auto'
                              ))"></span>

                        {{-- The sun SINKS and the night comes down from above,
                             which is the direction the real thing moves.
                             Three icons stacked and cross-faded rather than
                             one swapped: a swap is instant and reads as a
                             glitch, this reads as time passing. --}}
                        <span class="absolute transition-all duration-500 ease-dbelo"
                              :class="mode === 'light' ? 'translate-y-0 rotate-0 opacity-100' : 'translate-y-5 -rotate-90 opacity-0'">
                            <x-icon name="sun" style="solid" class="text-[0.85rem]" />
                        </span>

                        <span class="absolute transition-all duration-500 ease-dbelo"
                              :class="mode === 'dark' ? 'translate-y-0 rotate-0 opacity-100' : '-translate-y-5 rotate-90 opacity-0'">
                            <x-icon name="moon" style="solid" class="text-[0.85rem]" />
                        </span>

                        <span class="absolute transition-all duration-500 ease-dbelo"
                              :class="mode === 'system' ? 'scale-100 opacity-100' : 'scale-0 opacity-0'">
                            <x-icon name="circle-half-stroke" style="solid" class="text-[0.85rem]" />
                        </span>
                    </button>

                    <button type="button" x-on:click="mobile = ! mobile" aria-label="Menu"
                            class="grid size-9 shrink-0 place-items-center rounded-full bg-ink/[0.05] text-ink/55 transition duration-300 ease-dbelo md:hidden dark:bg-paper/10 dark:text-paper/60">
                        <x-icon name="bars" style="solid" class="text-[0.85rem]" />
                    </button>
                </div>
            </div>

            <div x-show="mobile" style="display: none" x-transition.opacity.duration.200ms class="mt-2 space-y-1 px-1 pb-2 md:hidden">
                @foreach ($navLinks as [$label, $href, $pattern])
                    <a href="{{ $href }}" wire:navigate x-on:click="mobile = false"
                       class="block rounded-control px-4 py-2.5 text-[0.9rem] transition duration-200 ease-dbelo hover:bg-ink/[0.05] dark:hover:bg-paper/[0.08]">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
    </nav>

    <main class="mx-auto max-w-[1160px] px-6 py-8">
        {{ $slot }}
    </main>

    <footer class="mx-auto mt-16 max-w-[1160px] border-t border-ink/[0.07] px-6 py-10 pb-32 dark:border-paper/10">
        {{-- The paragraph from Admin → Settings → General.
             Hidden entirely when empty rather than left as a blank gap: the
             footer has to look finished with the setting untouched. --}}
        @if (filled(config('dbelo.site.footer')))
            <p class="mb-6 max-w-[62ch] text-sm leading-relaxed text-ink/45 dark:text-paper/45">
                {{ config('dbelo.site.footer') }}
            </p>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-4 text-sm text-ink/45 dark:text-paper/45">
            {{-- The name comes from config, not from the word "dbelo" typed
                 here. That literal is exactly why the Site name field looked
                 like it did nothing: the title changed and the footer did
                 not, on the same page. --}}
            <span>&copy; {{ date('Y') }} {{ config('app.name', 'dbelo') }} — sound effects library</span>

            <nav class="flex flex-wrap gap-5">
                <a href="{{ route('legal.licenses') }}" wire:navigate class="transition hover:text-brand">Licenses</a>
                <a href="{{ route('legal.terms') }}" wire:navigate class="transition hover:text-brand">Terms</a>
                <a href="{{ route('legal.privacy') }}" wire:navigate class="transition hover:text-brand">Privacy</a>
                <a href="{{ route('legal.contributor') }}" wire:navigate class="transition hover:text-brand">Contribute</a>
                <a href="{{ route('claims.create') }}" wire:navigate class="transition hover:text-brand">Copyright</a>

                {{-- Pages the admin published, in the order chosen there.

                     The loop variable is NOT called $page: the route that
                     renders a page is `/{page}`, so on that one route the
                     slug is already in scope under that name and clobbers
                     the loop. --}}
                @foreach (\App\Models\Post::footerPages() as $footerPage)
                    <a href="{{ route('pages.show', $footerPage['slug']) }}" wire:navigate
                       class="transition hover:text-brand">{{ $footerPage['title'] }}</a>
                @endforeach
            </nav>
        </div>
    </footer>

    {{--
        The player.

        @persist keeps this exact DOM node across wire:navigate instead of
        rebuilding it, so the bar does not flicker between pages. The audio
        itself is not in here at all — it lives on window (see app.js), which
        is what actually keeps a sound playing while the visitor browses.

        Rendered on every page but invisible until something is playing:
        x-show on the track, not on a separate flag, so there is only one
        thing that can be wrong.
    --}}
    @persist('dbelo-player')
        <div x-data="dbeloBar" x-show="track" style="display: none"
             x-transition:enter="transition duration-400 ease-dbelo"
             x-transition:enter-start="translate-y-full opacity-0"
             x-transition:enter-end="translate-y-0 opacity-100"
             class="fixed inset-x-0 bottom-0 z-[60] border-t border-ink/[0.06] bg-surface/95 backdrop-blur-xl dark:border-paper/10 dark:bg-surface-dark/95">

            {{-- A hairline progress bar across the very top edge: readable
                 from the corner of the eye without looking down. --}}
            <div class="absolute inset-x-0 top-0 h-[2px] bg-brand/15">
                <div class="h-full bg-brand transition-[width] duration-150 ease-linear"
                     :style="`width: ${progress * 100}%`"></div>
            </div>

            <div class="mx-auto flex max-w-[1160px] items-center gap-4 px-6 py-3">

                <button type="button" x-on:click="toggle()"
                        :aria-label="playing ? 'Pause' : 'Play'"
                        class="grid size-11 shrink-0 place-items-center rounded-full bg-brand text-white shadow-brand transition duration-300 ease-dbelo hover:scale-108">
                    <span x-show="! playing"><x-icon name="play" style="solid" class="translate-x-px text-[0.8rem]" /></span>
                    <span x-show="playing" style="display: none"><x-icon name="pause" style="solid" class="text-[0.8rem]" /></span>
                </button>

                <div class="w-44 min-w-0 shrink-0">
                    <a :href="track?.url" class="block truncate text-[0.9rem] transition hover:text-brand" x-text="track?.title"></a>
                    <div class="micro mt-0.5 truncate" x-text="track?.author"></div>
                </div>

                {{-- The seek line. Deliberately plain here: the shaped
                     waveform belongs to the row and the sound page, and
                     repeating it in the bar competes with them. --}}
                <div x-on:click="seek($event)"
                     class="group/seek relative hidden h-8 flex-1 cursor-pointer items-center md:flex"
                     role="slider" aria-label="Seek"
                     :aria-valuenow="Math.round(progress * 100)" aria-valuemin="0" aria-valuemax="100">
                    <div class="h-1 w-full rounded-full bg-ink/10 dark:bg-paper/15">
                        <div class="relative h-full rounded-full bg-brand" :style="`width: ${progress * 100}%`">
                            <span class="absolute -right-1.5 top-1/2 size-3 -translate-y-1/2 rounded-full bg-brand opacity-0 shadow-brand transition duration-200 ease-dbelo group-hover/seek:opacity-100"></span>
                        </div>
                    </div>
                </div>

                <div class="micro hidden shrink-0 tabular-nums sm:block">
                    <span x-text="format(current)">0:00</span> / <span x-text="format(duration)"></span>
                </div>

                {{-- Volume. Hidden on touch, where the hardware buttons are
                     the real control and this is only in the way. --}}
                <div class="hidden shrink-0 items-center gap-2 lg:flex">
                    <x-icon name="volume-low" style="solid" class="text-[0.75rem] text-ink/35 dark:text-paper/35" />
                    <input type="range" min="0" max="1" step="0.05"
                           :value="volume"
                           x-on:input="setVolume(parseFloat($event.target.value))"
                           aria-label="Volume"
                           class="h-1 w-20 cursor-pointer appearance-none rounded-full bg-ink/10 accent-brand dark:bg-paper/15" />
                </div>

                <a :href="track?.url" wire:navigate
                   aria-label="Open sound"
                   class="grid size-9 shrink-0 place-items-center rounded-full text-ink/40 transition duration-300 ease-dbelo hover:bg-ink/[0.06] hover:text-brand dark:text-paper/40 dark:hover:bg-paper/10">
                    <x-icon name="up-right-from-square" style="solid" class="text-[0.75rem]" />
                </a>

                <button type="button" x-on:click="close()" aria-label="Close player"
                        class="grid size-9 shrink-0 place-items-center rounded-full text-ink/40 transition duration-300 ease-dbelo hover:bg-ink/[0.06] hover:text-ink dark:text-paper/40 dark:hover:bg-paper/10 dark:hover:text-paper">
                    <x-icon name="xmark" style="solid" class="text-[0.85rem]" />
                </button>
            </div>
        </div>
    @endpersist

    @fluxScripts

    {{-- Chat widgets and anything that draws on the page: after everything
         else, so it loads once the page is already readable. --}}
    {!! \App\Support\CustomCode::bodyEnd() !!}
</body>
</html>
