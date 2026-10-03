{{--
    ⌘K.

    The panel has more than forty screens. "Where is the setting for X" is
    the question somebody actually asks all day, and the honest answer used
    to be "expand three accordions and read". So screens are the FIRST thing
    this searches, not an afterthought under the records.

    THE SCREEN LIST IS SHIPPED WITH THE PAGE, not fetched. It comes from
    App\Support\AdminNav — the same map that draws the sidebar — so it is a
    few kilobytes of literal data and filtering it is a string compare in
    the browser. Typing "ads" answers before the word is finished, and it
    keeps answering when the network is slow or the database is the thing
    you opened the panel to investigate.

    Records (sounds, users, posts) need a query, so those arrive late and
    separately. A failed fetch loses the records and never the navigation.
--}}
<div
    x-data="palette({{ \Illuminate\Support\Js::from(\App\Support\AdminNav::searchable()) }}, '{{ route('admin.palette') }}')"
    x-on:keydown.window.prevent.cmd.k="show()"
    x-on:keydown.window.prevent.ctrl.k="show()"
    class="relative"
>
    {{-- The bar in the header. It is a button, not an input: the real input
         lives in the overlay, and two focusable search fields on one page
         is a way to type into the wrong one. --}}
    <button type="button" @click="show()"
            class="hidden items-center gap-2.5 rounded-lg bg-raised px-3 py-2 text-left transition hover:bg-paper/[0.09] lg:flex">
        <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
        <span class="w-40 text-[0.83rem] text-paper/30">Search…</span>
        <kbd class="rounded border border-hairline px-1.5 py-0.5 font-mono text-[0.66rem] text-paper/30">⌘K</kbd>
    </button>

    {{-- Narrow screens get the icon alone; the label is the first thing to
         go when there is no room, and a magnifier needs no label. --}}
    <button type="button" @click="show()"
            aria-label="Search"
            class="grid size-9 place-items-center rounded-lg text-paper/45 transition hover:bg-paper/[0.07] hover:text-paper lg:hidden">
        <x-icon name="magnifying-glass" style="regular" class="text-[15px]" />
    </button>

    {{-- ══════════════════════════ OVERLAY ══════════════════════════ --}}
    <div x-show="open" x-cloak
         x-transition.opacity.duration.150ms
         class="fixed inset-0 z-[100]"
         style="position: fixed; inset: 0; z-index: 100;">

        <div class="absolute inset-0 bg-black/60" @click="hide()"></div>

        {{-- Geometry inline, colour in classes.

             Same rule the save bar learned the hard way: an arbitrary value
             that has never been used before does not exist in a stale
             build, and a dialog with no width is a strip against the left
             edge. Losing the shadow until the next build is survivable;
             losing the shape is not. --}}
        <div class="relative overflow-hidden rounded-2xl border border-hairline bg-panel"
             style="margin: 12vh auto 0; width: 40rem; max-width: calc(100vw - 2rem); box-shadow: 0 30px 80px rgba(0,0,0,.6);">

            <div class="flex items-center gap-3 border-b border-hairline px-4">
                <x-icon name="magnifying-glass" style="regular" class="shrink-0 text-[14px] text-paper/30" />

                <input x-ref="input" type="text" x-model="q"
                       @input="run()"
                       @keydown.down.prevent="move(1)"
                       @keydown.up.prevent="move(-1)"
                       @keydown.enter.prevent="go()"
                       @keydown.escape.prevent="hide()"
                       placeholder="Go to a screen, or find a sound, a person, a post…"
                       autocomplete="off" spellcheck="false"
                       class="w-full border-0 bg-transparent py-4 text-[0.95rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-0" />

                <span x-show="loading" x-cloak class="shrink-0 text-[0.7rem] text-paper/25">searching…</span>

                <kbd class="shrink-0 rounded border border-hairline px-1.5 py-0.5 font-mono text-[0.66rem] text-paper/25">esc</kbd>
            </div>

            <div class="max-h-[52vh] overflow-y-auto py-2" x-ref="list">

                <template x-if="flat.length === 0">
                    <p class="px-4 py-8 text-center text-[0.84rem] text-paper/35"
                       x-text="q.length < 2
                           ? 'Type to search screens, sounds, people and posts.'
                           : 'Nothing matches “' + q + '”.'"></p>
                </template>

                <template x-for="(section, si) in sections" :key="section.key">
                    <div x-show="section.rows.length">
                        <div class="px-4 pb-1 pt-3 text-[0.64rem] font-semibold uppercase tracking-[0.16em] text-paper/25"
                             x-text="section.label"></div>

                        <template x-for="row in section.rows" :key="row.key">
                            <a :href="row.url"
                               @mouseenter="cursor = row.key"
                               @click="hide()"
                               class="flex items-center gap-3 px-4 py-2.5 transition"
                               :class="cursor === row.key ? 'bg-brand/15 text-paper' : 'text-paper/60 hover:bg-paper/[0.04]'">

                                <i class="w-4 shrink-0 text-center text-[13px] text-paper/35"
                                   :class="'fa-regular fa-' + row.icon" aria-hidden="true"></i>

                                <span class="min-w-0 flex-1 truncate text-[0.88rem]" x-text="row.label"></span>

                                <span class="shrink-0 truncate text-[0.74rem] text-paper/25" x-text="row.meta"></span>
                            </a>
                        </template>
                    </div>
                </template>
            </div>

            <div class="flex items-center gap-4 border-t border-hairline px-4 py-2.5 text-[0.72rem] text-paper/25">
                <span><kbd class="font-mono">↑↓</kbd> move</span>
                <span><kbd class="font-mono">↵</kbd> open</span>
                <span class="ml-auto">Screens answer instantly; records need a moment.</span>
            </div>
        </div>
    </div>
</div>

@once
    {{-- Inline rather than pushed to a stack: this layout has no @stack, and
         a push with no stack disappears without an error. It sits in the
         header, which is before Livewire's bundle at the foot of the body,
         so the alpine:init listener is registered before Alpine boots. --}}

{{-- The Alpine component that drives this lives in resources/js/app.js.

     It used to be a <script> right here, registering itself on
     `alpine:init`. That works on a full page load and fails on every
     wire:navigate: by then Alpine has long since started, the event never
     fires again, and the component is never registered. The markup still
     renders — so `x-show="open"` never applies, and this overlay, which is
     `position: fixed; inset: 0`, sits open across the whole panel.

     x-cloak hid it the first time and not the second: Livewire restores the
     cached DOM with the attribute already stripped by the earlier init.

     In app.js the registration happens once, in the head, before Alpine
     starts, and survives every navigation. --}}
@endonce
