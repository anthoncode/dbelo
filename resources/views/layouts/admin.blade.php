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

        {{-- Account --}}
        <div class="border-b border-hairline p-3">
            <div class="group/item relative flex items-center gap-3 rounded-xl p-2"
                 :class="collapsed ? 'justify-center' : ''">

                <a href="{{ route('profile.edit') }}"
                   class="grid size-9 shrink-0 place-items-center rounded-xl bg-brand text-[0.75rem] font-semibold text-white transition hover:opacity-90">
                    {{ auth()->user()?->initials() }}
                </a>

                <div x-show="! collapsed" x-cloak class="min-w-0 flex-1">
                    <div class="truncate text-[0.87rem] font-medium leading-tight">{{ auth()->user()?->name }}</div>
                    <div class="truncate text-[0.72rem] capitalize text-paper/35">{{ auth()->user()?->role }}</div>
                </div>

                <button type="button" x-show="! collapsed" x-cloak @click="collapsed = true"
                        class="grid size-7 shrink-0 place-items-center rounded-lg text-paper/30 transition hover:bg-paper/10 hover:text-paper/70"
                        title="Collapse">
                    <x-icon name="chevrons-left" style="regular" class="text-[12px]" />
                </button>

                <x-admin.tooltip :label="auth()->user()?->name ?? 'Account'" />
            </div>

            <button type="button" x-show="collapsed" x-cloak @click="collapsed = false"
                    class="mt-2 grid h-8 w-full place-items-center rounded-lg text-paper/30 transition hover:bg-paper/10 hover:text-paper/70"
                    title="Expand">
                <x-icon name="chevrons-right" style="regular" class="text-[12px]" />
            </button>
        </div>

        {{-- Main navigation --}}
        <nav class="flex-1 overflow-y-auto overflow-x-hidden px-3 py-4">
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

        {{-- Pinned at the bottom --}}
        <div class="border-t border-hairline px-3 py-4">
            <p x-show="! collapsed" x-cloak
               class="mb-2 px-3.5 text-[0.65rem] font-semibold uppercase tracking-[0.18em] text-paper/25">Other</p>

            <div class="space-y-0.5">
                @foreach ($otherGroups as $key => $group)
                    <x-admin.nav-group :group-key="$key" :group="$group" :key="'nav-'.$key" />
                @endforeach

                <div class="group/item relative">
                    <a href="{{ route('sounds.index') }}"
                       class="flex items-center rounded-xl text-paper/55 transition duration-200 ease-dbelo hover:bg-paper/[0.05] hover:text-paper"
                       :class="collapsed ? 'justify-center px-0 py-3' : 'gap-3.5 px-3.5 py-3'">
                        <x-icon name="arrow-up-right-from-square" style="regular" class="w-4 shrink-0 text-center text-[15px]" />
                        <span x-show="! collapsed" x-cloak class="min-w-0 flex-1 truncate text-[0.9rem]">View site</span>
                    </a>
                    <x-admin.tooltip label="View site" />
                </div>
            </div>
        </div>
    </aside>

    {{-- ══════════════ MAIN ══════════════ --}}
    <div class="flex min-w-0 flex-1 flex-col">

        <header class="flex h-[60px] shrink-0 items-center gap-4 border-b border-hairline px-6">
            <h1 class="min-w-0 truncate text-[0.98rem] font-medium">{{ $title ?? 'Dashboard' }}</h1>

            <div class="ml-auto flex items-center gap-3">
                @isset($actions)
                    {{ $actions }}
                @endisset

                <div class="hidden items-center gap-2.5 rounded-lg bg-raised px-3 py-2 lg:flex">
                    <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
                    <input type="search" placeholder="Search…"
                           class="w-40 border-0 bg-transparent p-0 text-[0.83rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-0" />
                </div>
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
