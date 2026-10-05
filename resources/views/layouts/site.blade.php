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
            // Music, by the same rule as Packs and Collections below: the
            // entry appears only when the page has something on it. dbelo is
            // a sound-effects library that also carries music, so this link
            // has to earn its place in a bar that cannot grow for ever.
            //
            // 'sounds.*' above does NOT match this, because /sounds is now
            // sound effects only — the two are siblings, not a page and its
            // filter, and highlighting Browse while the visitor is on Music
            // would say they are the same place.
            \App\Support\FooterLinks::hasMusic() ? ['Music', route('music.index'), 'music.*'] : null,
            // Through FooterLinks rather than straight at the model: the
            // footer below asks these same two questions on this same page,
            // and the answer is cached there. Two live queries per page view
            // for one fact is the smaller half of it — the bigger half is
            // that two callers can disagree, and "Packs in the header, no
            // Packs in the footer" is a bug nobody would think to look for.
            // The cache is dropped whenever a collection is saved.
            \App\Support\FooterLinks::hasPacks() ? ['Packs', route('packs.index'), 'packs.*'] : null,
            // Same rule as Packs: a nav entry leading to an empty page is
            // worse than no entry, and nothing is listed until somebody
            // chooses to list it.
            \App\Support\FooterLinks::hasListedCollections() ? ['Collections', route('collections.index'), 'collections.index'] : null,
            \App\Models\Post::blogHasPosts() ? ['Blog', route('blog'), 'blog*'] : null,
            // The converter was reachable only by typing its URL. It is a
            // page built to bring strangers in, and nothing on the site
            // pointed at it — which made the twenty pages behind it close to
            // invisible.
            ['Converter', route('converter'), 'converter*'],
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
    <nav class="sticky top-0 z-50 px-4 pt-4 sm:px-6">
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

                {{-- ══════════════════════════════════════════════════
                     THE SEARCH
                     ══════════════════════════════════════════════════
                     A plain GET form to the catalogue. No JavaScript, no
                     endpoint of its own, no request per keystroke — Enter
                     goes to /sounds?q= and the catalogue does what it
                     already does well.

                     ON MOBILE IT IS THE NAVIGATION. There is no hamburger:
                     in a library of sound effects nobody browses categories,
                     everybody types. So the links collapse away below md and
                     the field takes the room they leave, which is the shape
                     an app has rather than the shape a website has.

                     Hidden on the catalogue itself, where it would sit two
                     inches above a second search box that does the same
                     thing and is the one that actually filters the page. --}}
                {{--
                    Hidden on BOTH catalogue pages, not just on /sounds.

                    The condition used to name sounds.index alone, which was
                    right while there was one catalogue. With /music there
                    were two search boxes stacked on that page — and the top
                    one posted to /sounds, so searching from it took the
                    visitor out of the music catalogue they were standing in.
                    A field that silently moves you somewhere else is worse
                    than a duplicated field.

                    Everywhere else it goes to the sound effects, and the
                    placeholder SAYS sound effects rather than the vague
                    "sounds" it said before. The honest label is what makes
                    the default defensible: somebody who wanted music can see
                    they are about to search the other half, and if they
                    search anyway the empty state on the far side offers them
                    the music results by name. That bridge is the real answer
                    to "which half does my word live in?" — not a scope
                    dropdown in here that nobody opens.
                --}}
                @unless (request()->routeIs('sounds.index', 'music.index'))
                    <form action="{{ route('sounds.index') }}" method="GET"
                          class="mx-2 flex min-w-0 flex-1 items-center gap-2 rounded-full bg-ink/[0.05] px-3.5 py-2 transition duration-300 ease-dbelo focus-within:bg-ink/[0.08] md:mx-3 md:max-w-[22rem] dark:bg-paper/[0.07] dark:focus-within:bg-paper/[0.12]">
                        <x-icon name="magnifying-glass" style="regular" class="shrink-0 text-[0.8rem] text-ink/35 dark:text-paper/35" />
                        <input type="search" name="q" value="{{ request('q') }}"
                               placeholder="Search sound effects…"
                               aria-label="Search sound effects"
                               class="w-full min-w-0 border-0 bg-transparent p-0 text-[0.86rem] text-ink placeholder:text-ink/35 focus:outline-none focus:ring-0 dark:text-paper dark:placeholder:text-paper/35" />
                    </form>
                @endunless

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
                                <x-user-avatar class="size-8 text-[0.8rem]" />
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
                                    <x-user-avatar class="size-10 text-[0.95rem]" />

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
                        {{-- Two words each, and on a phone there is room for
                             neither. Below sm they become one icon that goes
                             to sign-in — the door is still there, it is just
                             the size a door is in an app. --}}
                        <a href="{{ route('login') }}" wire:navigate
                           class="hidden rounded-full px-4 py-2 text-sm text-ink/60 transition duration-300 ease-dbelo hover:bg-ink/[0.06] hover:text-ink sm:block dark:text-paper/60 dark:hover:bg-paper/10 dark:hover:text-paper">
                            Log in
                        </a>

                        <a href="{{ route('register') }}" wire:navigate
                           class="hidden rounded-full bg-brand px-5 py-2.5 text-sm font-medium text-white shadow-brand transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-brand-lg sm:block">
                            Sign up
                        </a>

                        <a href="{{ route('login') }}" wire:navigate aria-label="Log in"
                           class="grid size-9 shrink-0 place-items-center rounded-full bg-brand text-white transition duration-300 ease-dbelo sm:hidden">
                            <x-icon name="user" style="solid" class="text-[0.82rem]" />
                        </a>
                    @endauth

                    {{--
                        SUN or MOON. Two states, and the third is gone.

                        It used to cycle light -> dark -> system, and the
                        third stop was defended here as the only way back to
                        "follow my Mac" once you had pinned a choice. That
                        reasoning was right and the control was still wrong:
                        on a Mac already in dark mode, "system" and "dark"
                        render IDENTICALLY - same background, a 13px icon the
                        only difference - so one click in three appeared to
                        do nothing at all.

                        A switch that has to be explained is a broken switch,
                        and this one got asked about twice. Following the
                        system now lives in account settings, where things
                        are configured; the bar gets a light switch.

                        THE ICON IS THE DESTINATION, not the current state.
                        In daylight it shows the moon, meaning "press for
                        night". Both conventions exist and neither is
                        obviously right - this is the one somebody expects
                        when they ask "why is it not showing the night icon
                        during the day".

                        Everything lives in x-data methods rather than in the
                        click expression, and that is not style. Alpine
                        compiles an x-on expression into `__self.result =
                        <expr>`, so a `const` or a `try` at that level is a
                        syntax error and Alpine then discards the handler
                        silently - the button just stops responding, with
                        nothing in the console pointing at why. Inside a
                        method body those are ordinary statements again.
                    --}}
                    <button type="button"
                            x-data="{
                                mode: 'light',
                                flash: false,

                                init() {
                                    this.sync();

                                    /* Somebody still on `system` should see the
                                       icon follow their Mac at sunset rather
                                       than go stale until the next reload. */
                                    window.matchMedia('(prefers-color-scheme: dark)')
                                        .addEventListener('change', () => {
                                            if (!this.$flux || (this.$flux.appearance || 'system') === 'system') this.sync();
                                        });
                                },

                                /* What the page is ACTUALLY showing right now,
                                   which for an untouched visitor is whatever
                                   their system says. */
                                sync() {
                                    const pref = (this.$flux && this.$flux.appearance) || 'system';

                                    this.mode = pref === 'system'
                                        ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                                        : pref;
                                },

                                toggle() {
                                    const next = this.mode === 'dark' ? 'light' : 'dark';
                                    const root = document.documentElement;
                                    const release = () => root.classList.remove('theme-switching');

                                    /* Colours must snap, not cross-fade. See
                                       .theme-switching in app.css. Released by
                                       two routes because requestAnimationFrame
                                       is frozen in a background tab, and while
                                       that class is stuck every transition on
                                       the site is dead. */
                                    root.classList.add('theme-switching');
                                    requestAnimationFrame(() => requestAnimationFrame(release));
                                    setTimeout(release, 300);

                                    this.mode = next;
                                    if (this.$flux) this.$flux.appearance = next;

                                    /* Retrigger the wash: an animation only
                                       replays if the class actually leaves. */
                                    this.flash = false;
                                    this.$nextTick(() => this.flash = true);
                                    setTimeout(() => this.flash = false, 700);
                                },
                            }"
                            x-on:click="toggle()"
                            :title="mode === 'dark' ? 'Switch to light' : 'Switch to dark'"
                            :aria-label="mode === 'dark' ? 'Switch to light' : 'Switch to dark'"
                            class="relative grid size-9 shrink-0 place-items-center overflow-hidden rounded-full bg-ink/[0.05] text-ink/55 transition duration-300 ease-dbelo hover:bg-ink/[0.10] hover:text-ink dark:bg-paper/10 dark:text-paper/60 dark:hover:bg-paper/[0.16] dark:hover:text-paper">

                        {{-- Nightfall.

                             A wash of colour expands from the middle of the
                             button and fades: cold blue on the way to dark,
                             warm amber on the way to light. 700ms, leaving
                             nothing behind - it makes the switch feel like
                             something happened rather than like an icon being
                             swapped out. --}}
                        <span class="pointer-events-none absolute inset-0 rounded-full opacity-0"
                              :class="flash && ('nightfall ' + (mode === 'dark' ? 'nightfall-night' : 'nightfall-day'))"></span>

                        {{-- The moon comes down from above and the sun rises
                             from below, which is the direction the real ones
                             move. 200ms: long enough to read as motion, short
                             enough that the button has already answered. --}}
                        <span class="absolute transition-all duration-200 ease-dbelo"
                              :class="mode === 'light' ? 'translate-y-0 rotate-0 opacity-100' : '-translate-y-5 rotate-90 opacity-0'">
                            <x-icon name="moon" style="solid" class="text-[0.85rem]" />
                        </span>

                        <span class="absolute transition-all duration-200 ease-dbelo"
                              :class="mode === 'dark' ? 'translate-y-0 rotate-0 opacity-100' : 'translate-y-5 -rotate-90 opacity-0'">
                            <x-icon name="sun" style="solid" class="text-[0.85rem]" />
                        </span>
                    </button>

                    {{-- THE HAMBURGER IS GONE, and so is the panel it
                         opened. Below md the bar is the favicon, the search,
                         one account icon and the theme — the four things a
                         phone app puts in a header.

                         What it costs: Browse, Packs, Blog and Converter are
                         not in the bar on a phone. They are in the footer,
                         which is a real demotion and worth having said out
                         loud. The trade is that the search — the thing
                         everybody actually uses on a sound library — gets the
                         width instead of a menu button that hides four links
                         behind a tap. --}}
                </div>
            </div>
        </div>
    </nav>

    <main class="mx-auto max-w-[1160px] px-6 py-8">
        {{ $slot }}
    </main>

    {{--
        ══════════════════════════════════════════════════════════════════
        THE FOOTER
        ══════════════════════════════════════════════════════════════════

        WHAT IT WAS: one paragraph, a copyright, and a single row of links —
        four nav items, a middle dot, five legal pages, and every page the
        operator had published tacked on the end. Twelve links in a line, in
        which "Privacy" and "About" and "Converter" were typographically the
        same thing, and the only thing that could ever happen to it was a
        thirteenth.

        WHAT IT IS: four columns with names on them, built by
        App\Support\FooterLinks. The grouping is the point. Nobody looks for
        the terms of use in the same moment they look for the converter, and
        a list that mixes the two teaches the eye to skip the whole block.

        WHY THE LINKS ARE NOT LISTED HERE: because deciding what to show is
        five conditionals and three queries — is there a blog yet, is
        anything in the directory, which categories have sounds — and this is
        a Blade file where a raw PHP block cannot safely meet an inline one.
        The layout draws; FooterLinks decides.

        WHY IT MATTERS MORE THAN A FOOTER USUALLY DOES: it is on every page,
        which makes it the only place from which every page links to the
        category hubs and to the converter pair pages. Those were written to
        bring strangers in and had almost nothing pointing at them from
        inside the site.

        pb-32 stays: the player bar is fixed to the bottom on a phone and
        would otherwise sit on top of the last column.
    --}}
    <footer class="mx-auto mt-16 max-w-[1160px] border-t border-ink/[0.07] px-6 py-12 pb-32 dark:border-paper/10">

        <div class="flex flex-col gap-12 lg:flex-row lg:gap-20">

            {{-- ══════════════════════════════════════════════════════════
                 THE MARK AND THE SENTENCE

                 Same component as the header, not a copy of it: one logo,
                 one place it is defined, and the footer cannot drift from
                 the bar the day a new file is uploaded.
                 ══════════════════════════════════════════════════════════ --}}
            <div class="lg:w-[19rem] lg:shrink-0">
                <div class="-ml-1 inline-flex">
                    <x-site-logo />
                </div>

                {{-- The paragraph from Admin → Settings → General.
                     Hidden entirely when empty rather than left as a blank
                     gap: the footer has to look finished with the setting
                     untouched. --}}
                @if (filled(config('dbelo.site.footer')))
                    <p class="mt-5 max-w-[42ch] text-[0.86rem] leading-relaxed text-ink/45 dark:text-paper/45">
                        {{ config('dbelo.site.footer') }}
                    </p>
                @endif
            </div>

            {{-- ══════════════════════════════════════════════════════════
                 THE COLUMNS

                 Two across on a phone, four from sm up. The count is not
                 hard-coded anywhere: FooterLinks drops a column that has
                 nothing in it — Resources is empty until the first post or
                 page exists — and the grid simply has fewer cells.

                 Each column is its own <nav> with a label, so a screen
                 reader announces "Legal navigation" instead of reading
                 twenty links as one undifferentiated list.
                 ══════════════════════════════════════════════════════════ --}}
            <div class="grid flex-1 grid-cols-2 gap-x-8 gap-y-10 sm:grid-cols-4">
                @foreach (\App\Support\FooterLinks::columns() as $column)
                    <nav aria-label="{{ $column['title'] }}" wire:key="footer-col-{{ $loop->index }}">
                        <h2 class="text-[0.7rem] font-semibold uppercase tracking-[0.14em] text-ink/35 dark:text-paper/35">
                            {{ $column['title'] }}
                        </h2>

                        <ul class="mt-4 space-y-2.5">
                            @foreach ($column['links'] as $link)
                                <li>
                                    {{-- wire:navigate on everything except
                                         the RSS feed, which answers with XML
                                         — see the note on FooterLinks. --}}
                                    <a href="{{ $link['href'] }}"
                                       @if ($link['navigate'] ?? true) wire:navigate @endif
                                       class="text-[0.87rem] text-ink/55 transition duration-300 ease-dbelo hover:text-brand dark:text-paper/55">
                                        {{ $link['label'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </nav>
                @endforeach
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             THE BOTTOM BAR

             flex-col-reverse below sm so the social row sits ABOVE the
             copyright on a phone: the icons are the thing somebody might
             tap, and the legal sentence is the thing nobody reads. Source
             order stays copyright-first, which is the order that makes sense
             to a screen reader.
             ══════════════════════════════════════════════════════════════ --}}
        <div class="mt-12 flex flex-col-reverse items-center gap-6 border-t border-ink/[0.07] pt-7 sm:flex-row sm:justify-between dark:border-paper/10">

            @if (filled($footerCopyright = \App\Support\FooterLinks::copyright()))
                <p class="text-center text-[0.8rem] text-ink/40 sm:text-left dark:text-paper/40">
                    {{ $footerCopyright }}
                </p>
            @endif

            {{-- ── THE PROFILES ─────────────────────────────────────────
                 Admin → Settings → General → Social profiles. Nothing is
                 drawn when the list is empty: five grey circles linking to
                 an account that does not exist yet is worse than no row.

                 rel="me" is the one part worth explaining. It is how a
                 profile on the other end can verify that this site claims
                 it back — Mastodon reads it, and it costs an attribute.
                 noopener is not optional on target="_blank". --}}
            @if (\App\Support\Social::any())
                <div class="flex items-center gap-1">
                    @foreach (\App\Support\Social::links() as $profile)
                        <a href="{{ $profile['url'] }}"
                           target="_blank" rel="noopener noreferrer me"
                           title="{{ $profile['label'] }}" aria-label="{{ $profile['label'] }}"
                           class="grid size-9 place-items-center rounded-full text-ink/45 transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:bg-ink/[0.06] hover:text-brand dark:text-paper/45 dark:hover:bg-paper/10">
                            <x-icon :name="$profile['icon']" :style="$profile['brand'] ? 'brands' : 'solid'"
                                    class="text-[0.95rem]" />
                        </a>
                    @endforeach
                </div>
            @endif
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
