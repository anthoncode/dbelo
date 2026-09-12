{{--
    Save / unsave a sound.

    ── SIZE ─────────────────────────────────────────────────────────────
    The icon-only form is size-9, NOT size-10. It sits in a row of three
    round buttons — favourite, share, download — and it was the only one a
    notch larger, which put it on a different centre line and made the group
    look thrown together rather than aligned. Same skin as the other two as
    well: bg-surface, not bg-paper, so all three circles are the same white
    on a white card instead of one of them being faintly grey.

    ── COLOUR ───────────────────────────────────────────────────────────
    Saved is `action`, not `brand`. Brand is identity — the logo, the
    playhead, the PRO badge, whatever is currently active — and a hearted
    sound is none of those: it is the result of something the visitor did.
    It also has to stand apart from the collection rows in the picker, which
    stay brand. One glance should say which of the two lists it is in.

    ── ARIA ─────────────────────────────────────────────────────────────
    aria-label is interpolated, not bound with a leading colon. The colon
    shorthand is a Blade COMPONENT feature; on a plain HTML tag it is left
    alone, so this element used to ship a literal `:aria-label` attribute
    holding the unevaluated PHP. Every screen reader read the button as
    unlabelled.
--}}
<button
    type="button"
    wire:click="toggle"
    @class([
        'flex shrink-0 items-center justify-center gap-2 rounded-full shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md',
        'px-5 py-2.5 text-[0.85rem]' => $showLabel,
        'size-9' => ! $showLabel,
        'bg-action text-white' => $favourited,
        'bg-surface text-ink/55 hover:text-action dark:bg-surface-dark dark:text-paper/55' => ! $favourited,
    ])
    aria-label="{{ $favourited ? 'Remove from favourites' : 'Save to favourites' }}"
    title="{{ $favourited ? 'Saved to favourites' : 'Save to favourites' }}"
>
    <x-icon name="heart" :style="$favourited ? 'solid' : 'regular'" class="text-[0.85rem] leading-none" />

    @if ($showLabel)
        {{ $favourited ? 'Saved' : 'Save' }}
    @endif
</button>
