@props([
    'label' => 'Save changes',

    /** The Livewire method, for wire:loading. */
    'target' => 'save',
])

{{--
    The save button, kept in reach.

    A settings form long enough to need this is a form where the button sat
    below thirty fields — so changing one word at the top meant scrolling
    past everything else to commit it, and scrolling back. That is not a
    small annoyance on a screen somebody edits one line at a time; it is what
    makes them stop using the screen.

    Two things it must do beyond floating:

      It has to say whether there is anything to save. A permanently
      available button gives no signal, and on a form this size "did I
      already save that?" is a real question.

      It has to stay inside the <form>. position: fixed works perfectly well
      from in there, and a button outside the form would need a form=
      attribute and a matching id to submit at all.

    Expects `dirty` in an Alpine scope on an ancestor — normally the form
    itself, with x-on:input and x-on:change setting it, and the component
    clearing it on the `saved` event the screen dispatches.
--}}
{{--
    THE POSITION IS INLINE, NOT A CLASS, and that is not laziness.

    It was `fixed inset-x-4 bottom-4 z-40`. `fixed` happened to exist in the
    compiled stylesheet; the four offsets did not, because they had never
    been used before this component was written. A fixed element with no
    offsets falls back to its static position — so the button rendered at the
    bottom of a very long form, off-screen, and did not follow the scroll.

    It looked like the button had been removed. It also made the switches
    look broken, because a switch you cannot save is a switch that does
    nothing — one missing class, two bugs that appear unrelated.

    A control whose whole job is to commit the page must not become
    unreachable because a stylesheet is behind. Colour and shadow can wait
    for the next build; being on screen cannot.
--}}
<div style="position: fixed; right: 1.5rem; bottom: 1.5rem; z-index: 50;">
    <div class="flex items-center gap-3 rounded-2xl border border-hairline bg-panel px-3.5 py-3 shadow-2xl backdrop-blur"
         style="box-shadow: 0 10px 40px rgba(0,0,0,.45);">

        <span class="flex items-center gap-2 pl-1 text-[0.78rem]">
            <span class="size-2 shrink-0 rounded-full transition"
                  :class="dirty ? 'bg-warning' : 'bg-success/60'"></span>

            <span class="text-paper/40" x-text="dirty ? 'Unsaved changes' : 'All saved'">All saved</span>
        </span>

        {{ $slot }}

        <button type="submit" wire:loading.attr="disabled" wire:target="{{ $target }}"
                class="shrink-0 rounded-xl bg-brand px-5 py-2.5 text-[0.82rem] text-white transition hover:brightness-110 disabled:opacity-50">
            <span wire:loading.remove wire:target="{{ $target }}">{{ $label }}</span>
            <span wire:loading wire:target="{{ $target }}">Saving…</span>
        </button>
    </div>
</div>
