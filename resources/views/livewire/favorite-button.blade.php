{{--
    Save / unsave a sound.

    The icon-only form is size-9, NOT size-10. It sits in a row of three
    round buttons — favourite, share, download — and it was the only one a
    notch larger, which put it on a different centre line and made the group
    look thrown together rather than aligned. Same skin as the other two as
    well: bg-surface, not bg-paper, so all three circles are the same white
    on a white card instead of one of them being faintly grey.
--}}
<button
    type="button"
    wire:click="toggle"
    @class([
        'flex shrink-0 items-center justify-center gap-2 rounded-full shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md',
        'px-5 py-2.5 text-[0.85rem]' => $showLabel,
        'size-9' => ! $showLabel,
        'bg-brand text-white' => $favourited,
        'bg-surface text-ink/55 hover:text-brand dark:bg-surface-dark dark:text-paper/55' => ! $favourited,
    ])
    :aria-label="$favourited ? 'Remove from favourites' : 'Save to favourites'"
    title="{{ $favourited ? 'Saved' : 'Save' }}"
>
    <x-icon name="heart" :style="$favourited ? 'solid' : 'regular'" class="text-[0.85rem] leading-none" />
    @if ($showLabel)
        {{ $favourited ? 'Saved' : 'Save' }}
    @endif
</button>
