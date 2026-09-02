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
        <div class="flex h-[60px] shrink-0 items-center border-b border-hairline px-3">
            <a href="{{ route('admin.dashboard') }}" wire:navigate
               x-show="! collapsed" x-cloak
               class="flex min-w-0 flex-1 items-center gap-2.5 rounded-lg px-2 py-1.5 transition hover:bg-paper/[0.05]">
                <span class="grid size-7 shrink-0 place-items-center rounded-lg bg-brand text-[0.7rem] font-bold text-white">d</span>
                <span class="min-w-0 flex-1 truncate text-[0.9rem] font-medium">{{ config('app.name', 'dbelo') }}</span>
            </a>

            <button type="button" x-show="! collapsed" x-cloak @click="collapsed = true"
                    class="grid size-7 shrink-0 place-items-center rounded-lg text-paper/30 transition hover:bg-paper/10 hover:text-paper/70"
                    title="Collapse">
                <x-icon name="chevrons-left" style="regular" class="text-[12px]" />
            </button>

            <button type="button" x-show="collapsed" x-cloak @click="collapsed = false"
                    class="grid h-8 w-full place-items-center rounded-lg text-paper/30 transition hover:bg-paper/10 hover:text-paper/70"
                    title="Expand">
                <x-icon name="chevrons-right" style="regular" class="text-[12px]" />
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

            <div class="ml-auto flex items-center gap-2">
                @isset($actions)
                    <div class="mr-1 flex items-center gap-3">{{ $actions }}</div>
                @endisset

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
