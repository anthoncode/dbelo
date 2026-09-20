@props([
    'sound',
    'bars' => 150,
    'showAuthor' => true,
])

{{--
    One sound in a list.

    The same row serves the catalogue, the related list on a sound page, a
    pack and the library — so a change to how a sound reads happens once.
    Everything that plays goes through x-waveform-player, which means the
    persistent bar at the bottom of the page is the only thing that ever
    holds audio.
--}}
<div {{ $attributes->merge(['class' => 'group/row flex flex-col gap-3 rounded-control px-4 py-3 transition duration-350 ease-dbelo hover:bg-ink/[0.04] lg:flex-row lg:items-center lg:gap-4 dark:hover:bg-paper/[0.06]']) }}>

    {{--
        Title and who made it.

        The column is a FIXED width on lg and every text node inside it
        truncates. A sound called "Heavy wooden door closing slowly in a
        stone corridor.wav" is completely normal in this catalogue, and
        letting one of them set the column width pushes the waveform and
        the three round buttons out of line with every other row —
        the list stops reading as a list.

        min-w-0 is on the column AND on each text node: a flex item's
        default min-width is its content, so `truncate` alone does nothing
        until the item is allowed to be narrower than the words in it.
    --}}
    <div class="min-w-0 lg:w-64 lg:shrink-0">
        <div class="flex min-w-0 items-center gap-2">
            {{-- title=… so the full name is still reachable once the
                 ellipsis has eaten it. --}}
            <a href="{{ route('sounds.show', $sound) }}" wire:navigate
               title="{{ $sound->title }}"
               class="min-w-0 flex-1 truncate text-[0.95rem] transition duration-200 ease-dbelo hover:text-brand">
                {{ $sound->title }}
            </a>

            @if ($sound->is_premium)
                <span class="shrink-0 rounded-full bg-brand px-2 py-0.5 text-[9px] font-semibold tracking-wide text-white">PRO</span>
            @endif
        </div>

        <div class="micro mt-1 flex min-w-0 items-center gap-1.5">
            @if ($showAuthor && $sound->user)
                <span class="min-w-0 truncate">{{ $sound->user->name }}</span>
            @elseif ($sound->category)
                <span class="min-w-0 truncate">{{ $sound->category->name }}</span>
            @endif

            @if ($sound->is_loopable)
                <x-icon name="repeat" style="solid" class="shrink-0 text-[0.65rem] text-brand" />
            @endif
        </div>
    </div>

    {{-- NO TAG PILL HERE, and this is the second thing removed from that
         spot rather than a gap nobody filled.

         It showed the first tag and a "+N" for the rest. That read fine when
         a sound had three tags; once the suggester started returning ten it
         became "crowd +9", where the number is bigger than the word and says
         nothing — and the muted +N sat at a third opacity beside a half
         opacity name, so one pill carried two greys and broke the line.

         The tags still exist, still drive the search, and are still on the
         sound's own page. A row in a list is for choosing WHICH sound to open;
         it needs a title, a shape and a play button, and every extra thing in
         it competes with those three.
         --}}

    <x-waveform-player :sound="$sound" :bars="$bars" class="min-w-0 flex-1" />

    {{--
        Actions. Muted until the row is hovered so a page of them reads as
        a list of sounds rather than a wall of buttons.

        All three circles are size-9. They were not: the favourite button
        rendered at size-10 while share and download were size-9, so the
        group had one circle a notch larger than its neighbours and sitting
        on a different centre line. `justify-end` pins the group to the
        right edge on narrow screens, where the row stacks and there is no
        waveform between the title and the buttons to hold them in place.
    --}}
    <div class="flex shrink-0 items-center justify-end gap-1.5 opacity-70 transition duration-300 ease-dbelo group-hover/row:opacity-100">
        <livewire:favorite-button :sound="$sound" :key="'fav-'.$sound->id" />

        <x-share-menu :url="route('sounds.show', $sound)" :title="$sound->title" size="size-9" />

        <a href="{{ route('sounds.download', $sound) }}"
           aria-label="Download {{ $sound->title }}"
           title="Download"
           class="grid size-9 shrink-0 place-items-center rounded-full bg-surface text-ink/55 shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:text-action hover:shadow-soft-md dark:bg-surface-dark dark:text-paper/55">
            <x-icon name="arrow-down-to-line" style="solid" class="text-[0.85rem]" />
        </a>
    </div>
</div>
