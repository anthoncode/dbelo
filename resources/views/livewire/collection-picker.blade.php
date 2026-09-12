{{--
    The list picker.

    ── OPENING IS ALPINE, NOT LIVEWIRE ──────────────────────────────────
    The open/closed flag used to be a Livewire property, so every press of
    the trigger was a network round trip before anything moved — and the
    outside-click handler wrote to that same property, which is why closing
    lagged too, and sometimes did not happen at all when a re-render landed
    first. A dropdown is pure interface state; it has no business leaving the
    browser. The panel now opens on the same frame as the click.

    Livewire still owns everything INSIDE it. Alpine state survives a
    Livewire morph, so ticking a collection re-renders the rows without
    closing the panel.

    ── COLOUR IS SET EXPLICITLY, NOT INHERITED ──────────────────────────
    This whole thing lives inside the sound page's hero card, which is
    `bg-ink text-paper` — dark with light text, in BOTH themes. Anything in
    here that does not name its own colour inherits light text. That is what
    made the trigger and the rows unreadable in light mode: a pale button and
    a pale panel, both painting their text the colour of the card they came
    from rather than the colour of the surface they landed on.

    Same rule share-menu already documents: a dropdown is a menu, not part of
    the card that opened it, so it sets its own ink.
--}}

@guest
    {{-- Nothing to pick from until there is an account. A link rather than a
         button that has to ask the server what to do about it. --}}
    <a href="{{ route('login') }}" wire:navigate
       class="flex shrink-0 items-center gap-2 rounded-full bg-surface px-4 py-2 text-[0.82rem] text-ink/70 shadow-soft-sm dark:bg-surface-dark dark:text-paper/70 transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md">
        <x-icon name="folder-music" style="regular" class="text-[0.82rem]" />
        Add to collection
    </a>
@else
    <div x-data="{ open: false }"
         @keydown.escape.window="open = false"
         @click.outside="open = false"
         class="relative">

        <button type="button" x-on:click="open = ! open"
                class="flex shrink-0 items-center gap-2 rounded-full bg-surface px-4 py-2 text-[0.82rem] text-ink/70 shadow-soft-sm dark:bg-surface-dark dark:text-paper/70 transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md">
            <x-icon name="folder-music" :style="count($this->memberOf) ? 'solid' : 'regular'"
                    class="text-[0.82rem] {{ count($this->memberOf) ? 'text-brand' : '' }}" />

            {{ count($this->memberOf)
                ? 'In '.count($this->memberOf).' '.Str::plural('list', count($this->memberOf))
                : 'Add to collection' }}
        </button>

        <div x-show="open" style="display: none" x-transition.opacity.duration.150ms
             class="absolute right-0 top-full z-50 mt-2 w-64 rounded-card bg-surface p-3 text-ink shadow-soft-lg dark:bg-surface-dark dark:text-paper">

            {{-- Only once there is enough to search through. --}}
            @if ($this->showSearch)
                <div class="relative mb-2.5">
                    <input type="text" wire:model.live.debounce.200ms="search"
                           placeholder="Search collections" autocomplete="off"
                           class="w-full rounded-full border-0 bg-ink/[0.06] py-2 pl-4 pr-9 text-[0.84rem] text-ink placeholder:text-ink/40 focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-paper/[0.08] dark:text-paper dark:placeholder:text-paper/40" />

                    <x-icon name="magnifying-glass" style="regular"
                            class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-[0.78rem] text-ink/40 dark:text-paper/40" />
                </div>
            @endif

            <div class="px-1 pb-1 text-[0.75rem] uppercase tracking-[0.1em] text-ink/35 dark:text-paper/35">
                Add to collection
            </div>

            <div class="max-h-56 overflow-y-auto">

                {{-- ═══ Favourites, always first ═══ --}}
                <button type="button" wire:click="toggleFavourite"
                        class="flex w-full items-center gap-2.5 rounded-control px-1.5 py-2 text-left transition hover:bg-ink/[0.05] dark:hover:bg-paper/[0.07]">
                    <x-icon name="heart" :style="$this->favourited ? 'solid' : 'regular'"
                            class="shrink-0 text-[0.82rem] {{ $this->favourited ? 'text-action' : 'text-ink/35 dark:text-paper/35' }}" />

                    <span class="min-w-0 flex-1 truncate text-[0.88rem]">Favourites</span>

                    <span class="shrink-0 text-[0.8rem] {{ $this->favourited ? 'text-ink/45 dark:text-paper/45' : 'text-action' }}">
                        {{ $this->favourited ? 'Remove' : 'Add' }}
                    </span>
                </button>

                @if ($this->allCollections->isNotEmpty())
                    <div class="my-1 border-t border-ink/[0.07] dark:border-paper/10"></div>
                @endif

                {{-- ═══ The person's own lists ═══ --}}
                @forelse ($this->collections as $collection)
                    @php $in = in_array($collection->id, $this->memberOf, true); @endphp

                    <button type="button" wire:click="toggleCollection({{ $collection->id }})" wire:key="col-{{ $collection->id }}"
                            class="flex w-full items-center gap-2.5 rounded-control px-1.5 py-2 text-left transition hover:bg-ink/[0.05] dark:hover:bg-paper/[0.07]">
                        <x-icon name="folder-music" :style="$in ? 'solid' : 'regular'"
                                class="shrink-0 text-[0.82rem] {{ $in ? 'text-brand' : 'text-ink/35 dark:text-paper/35' }}" />

                        <span class="min-w-0 flex-1 truncate text-[0.88rem]">{{ $collection->name }}</span>

                        <span class="shrink-0 text-[0.8rem] {{ $in ? 'text-ink/45 dark:text-paper/45' : 'text-action' }}">
                            {{ $in ? 'Remove' : 'Add' }}
                        </span>
                    </button>
                @empty
                    @if ($this->allCollections->isNotEmpty())
                        {{-- Filtered to nothing is not the same as having
                             none, and "no collections" under a search box
                             would be a lie. --}}
                        <p class="px-1.5 py-2.5 text-[0.82rem] text-ink/40 dark:text-paper/40">
                            Nothing matches “{{ $search }}”.
                        </p>
                    @endif
                @endforelse
            </div>

            {{-- ═══ Make a new one ═══
                 Outlined rather than filled, so it reads as somewhere to
                 type instead of as one more row to press. --}}
            <form wire:submit="create" class="mt-2.5">
                <div class="flex items-center gap-2 rounded-control border border-ink/15 px-3 py-1.5 focus-within:border-brand/50 dark:border-paper/15">
                    <input type="text" wire:model="newName" placeholder="Collection name" maxlength="80"
                           class="min-w-0 flex-1 border-0 bg-transparent p-0 text-[0.88rem] text-ink placeholder:text-ink/40 focus:outline-none focus:ring-0 dark:text-paper dark:placeholder:text-paper/40" />

                    <button type="submit" aria-label="Create collection"
                            class="grid size-6 shrink-0 place-items-center rounded-full bg-action text-white transition hover:brightness-110">
                        <x-icon name="plus" style="solid" class="text-[0.62rem]" />
                    </button>
                </div>

                @error('newName')
                    <p class="mt-1.5 px-1 text-[0.78rem] text-danger">{{ $message }}</p>
                @enderror
            </form>
        </div>
    </div>
@endguest
