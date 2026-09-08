@php
    use App\Support\AdminNav;

    $menuGroups = AdminNav::section('menu');
    $otherGroups = AdminNav::section('other');
    $activeGroup = AdminNav::activeGroup();
@endphp

<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    @include('partials.head')
</head>
<body class="min-h-screen bg-canvas text-paper antialiased">

{{-- Before everything, including the sidebar: if this is showing, nothing
     below it can be trusted to look the way it was written. --}}
<x-admin.stale-build />

<div
    x-data="{
        collapsed: false,
        open: { '{{ $activeGroup }}': true },

        init() {
            this.collapsed = localStorage.getItem('dbelo.sidebar') === '1';

            const saved = localStorage.getItem('dbelo.groups');
            if (saved) {
                try { this.open = { ...JSON.parse(saved), '{{ $activeGroup }}': true } } catch (e) {}
            }

            this.$watch('collapsed', v => localStorage.setItem('dbelo.sidebar', v ? '1' : '0'));
            this.$watch('open', v => localStorage.setItem('dbelo.groups', JSON.stringify(v)));
        },

        toggle(key) {
            // Clicking a group while collapsed expands the sidebar first:
            // opening an accordion nobody can read would do nothing.
            if (this.collapsed) {
                this.collapsed = false;
                this.open[key] = true;
                return;
            }

            this.open[key] = ! this.open[key];
        },
    }"
    class="flex min-h-screen"
>

    {{-- ══════════════ SIDEBAR ══════════════ --}}
    <aside
        class="flex shrink-0 flex-col border-r border-hairline bg-panel transition-[width] duration-300 ease-dbelo"
        :class="collapsed ? 'w-[76px]' : 'w-[252px]'"
    >

        {{-- Brand, and the collapse control.

             THE ACCOUNT USED TO BE HERE. It moved to the top right, where
             every panel puts it and where it now sits beside the bell and
             the search. What is left is what this block was actually for:
             collapsing the rail. The avatar and the name were decoration on
             a chevron, and keeping a second copy of them here would have
             been two controls opening one profile — the same duplication
             the email screen was corrected for. --}}
        @php
            /*
             * THE DARK LOGO, not the light one. This panel is pinned to dark
             * by class="dark" on <html>, so logo_light would be whatever mark
             * was drawn for a white page sitting on a near-black rail.
             * logoDark() falls back to the light file by itself, which is the
             * right answer for a mark that reads on both.
             */
            $adminLogo = \App\Support\Appearance::logoDark();
            $adminFavicon = \App\Support\Appearance::url('favicon');
        @endphp

        {{-- THE MARK AND THE COLLAPSE CONTROL, and nothing else.

             The name in text is gone. It said "dbelo" next to a logo that
             also says "dbelo", which is the word twice — and once there is a
             real uploaded mark, the wordmark is usually already inside it.

             Full logo expanded, favicon collapsed. Those are two different
             images for two different widths, not one image scaled: a
             wordmark squeezed into 28px is unreadable, and a favicon
             stretched across 200px is a smudge.

             The padding tightens when collapsed because the rail is 76px and
             the two controls have to fit inside it. px-3 leaves 52px for a
             28px mark and a 24px button; px-2 leaves 60px, which is the
             same two controls with four pixels to breathe. --}}
        <div class="flex h-[60px] shrink-0 items-center border-b border-hairline"
             :class="collapsed ? 'gap-1 px-2' : 'gap-2 px-3'">

            {{-- ── Expanded: the full mark ── --}}
            <a href="{{ route('admin.dashboard') }}" wire:navigate
               x-show="! collapsed" x-cloak
               aria-label="{{ config('app.name', 'dbelo') }}"
               class="flex min-w-0 flex-1 items-center rounded-lg px-2 py-1.5 transition hover:bg-paper/[0.05]">
                @if ($adminLogo)
                    <img src="{{ $adminLogo }}" alt="{{ config('app.name', 'dbelo') }}"
                         class="h-7 w-auto max-w-[160px] object-contain object-left" />
                @else
                    {{-- No logo uploaded. The same waveform tile x-site-logo
                         falls back to, so the panel and the site cannot show
                         two different marks for the same missing file. --}}
                    <span class="grid size-8 shrink-0 place-items-center rounded-[9px] bg-brand">
                        <x-icon name="waveform-lines" style="solid" class="text-[13px] text-white" />
                    </span>
                @endif
            </a>

            <button type="button" x-show="! collapsed" x-cloak @click="collapsed = true"
                    class="grid size-7 shrink-0 place-items-center rounded-lg text-paper/30 transition hover:bg-paper/10 hover:text-paper/70"
                    title="Collapse">
                <x-icon name="chevrons-left" style="regular" class="text-[12px]" />
            </button>

            {{-- ── Collapsed: the favicon ──

                 Still a link to the dashboard, and the chevron beside it is
                 still only the collapse toggle. Making the mark itself expand
                 the rail would be cheaper in pixels and would give one
                 control two meanings depending on a state you cannot see
                 from the control. --}}
            <a href="{{ route('admin.dashboard') }}" wire:navigate
               x-show="collapsed" x-cloak
               aria-label="{{ config('app.name', 'dbelo') }}"
               class="grid size-7 shrink-0 place-items-center overflow-hidden rounded-[8px] transition hover:opacity-80">
                @if ($adminFavicon)
                    <img src="{{ $adminFavicon }}" alt="{{ config('app.name', 'dbelo') }}"
                         class="size-7 object-contain" />
                @else
                    <span class="grid size-7 place-items-center rounded-[8px] bg-brand">
                        <x-icon name="waveform-lines" style="solid" class="text-[12px] text-white" />
                    </span>
                @endif
            </a>

            <button type="button" x-show="collapsed" x-cloak @click="collapsed = false"
                    class="grid size-6 shrink-0 place-items-center rounded-lg text-paper/30 transition hover:bg-paper/10 hover:text-paper/70"
                    title="Expand">
                <x-icon name="chevrons-right" style="regular" class="text-[11px]" />
            </button>
        </div>

        {{-- Main navigation

             NOT flex-1.

             It used to be, which pinned the "Other" block to the bottom of
             the window and left a tall empty stripe between the last Tools
             item and Settings whenever the open accordion was short. Pinning
             a block to the bottom and having no gap are the same requirement
             pulling in opposite directions: if it stays at the bottom while
             the menu is short, the gap IS the pinning. So the two blocks
             simply stack, and the divider above "Other" does the separating
             that the empty space was doing badly.

             The overflow classes went with it: without a height limit they
             could never scroll anything. A long menu now makes the page
             taller, which is what a long menu should do. --}}
        <nav class="px-3 py-4">
            <p x-show="! collapsed" x-cloak
               class="mb-2 px-3.5 text-[0.65rem] font-semibold uppercase tracking-[0.18em] text-paper/25">Menu</p>
            <p x-show="collapsed" x-cloak
               class="mb-2 text-center text-[0.6rem] font-semibold uppercase tracking-[0.14em] text-paper/20">Menu</p>

            <div class="space-y-0.5">
                @foreach ($menuGroups as $key => $group)
                    <x-admin.nav-group :group-key="$key" :group="$group" :key="'nav-'.$key" />
                @endforeach
            </div>
        </nav>

        {{-- Settings, Help and the way out. Directly under the menu, not at
             the foot of the window — see the note above. --}}
        <div class="border-t border-hairline px-3 py-4">
            <p x-show="! collapsed" x-cloak
               class="mb-2 px-3.5 text-[0.65rem] font-semibold uppercase tracking-[0.18em] text-paper/25">Other</p>

            <div class="space-y-0.5">
                @foreach ($otherGroups as $key => $group)
                    <x-admin.nav-group :group-key="$key" :group="$group" :key="'nav-'.$key" />
                @endforeach

                {{-- "View site" was here, at the foot of a nav list, which
                     is the one place it does not belong: it is not a screen
                     in this panel, it is the way out of it. It now sits in
                     the top bar with the rest of the global chrome. --}}
            </div>
        </div>
    </aside>

    {{-- ══════════════ MAIN ══════════════ --}}
    <div class="flex min-w-0 flex-1 flex-col">

        {{-- ══════════════════════════════════════════════════════════
             TOP BAR
             ══════════════════════════════════════════════════════════
             Left to right: where you are, then what a page offers, then the
             four things that are true on every page — search, the bell, the
             way out to the site, and who you are signed in as.

             The order is deliberate. Search is the one used most and sits
             furthest from the destructive one; the account menu, which holds
             the only irreversible item in the bar, is last and smallest. --}}
        <header class="flex h-[60px] shrink-0 items-center gap-4 border-b border-hairline px-6">
            <h1 class="min-w-0 truncate text-[0.98rem] font-medium">{{ $title ?? 'Dashboard' }}</h1>

            {{-- Beside the title, not among the icons on the right. It is
                 not a control you operate, it is a fact about what you are
                 looking at — the same reason it sits next to the page name
                 rather than next to the bell. --}}
            <x-admin.site-status-pill />

            <div class="ml-auto flex items-center gap-2">
                @isset($actions)
                    <div class="mr-1 flex items-center gap-3">{{ $actions }}</div>
                @endisset

                {{-- First in the cluster: it is the only one here that
                     makes something, and the rest are ways of looking. --}}
                <x-admin.quick-create />

                <x-admin.command-palette />

                <x-admin.notice-bell />

                {{-- Opens in a new tab, and that is the whole point of the
                     button. Checking the live site is something you do
                     WHILE editing it; replacing the panel you are working in
                     with the page you wanted to glance at means finding your
                     way back every time. --}}
                <a href="{{ route('home') }}" target="_blank" rel="noopener"
                   title="Open the site in a new tab"
                   class="grid size-9 shrink-0 place-items-center rounded-lg text-paper/45 transition hover:bg-paper/[0.07] hover:text-paper">
                    <x-icon name="arrow-up-right-from-square" style="regular" class="text-[14px]" />
                </a>

                <div class="mx-1 h-6 w-px shrink-0 bg-hairline"></div>

                <x-admin.account-menu />
            </div>
        </header>

        <main class="min-w-0 flex-1 p-6">
            {{ $slot }}
        </main>
    </div>
</div>

@fluxScripts
</body>
</html>
