@props(['collection'])

@php
    $states = \App\Models\Collection::visibilities();
    $current = $collection->visibility();
    $face = $states[$current];

    /*
     * The caller's class REPLACES the default, it does not join it.
     *
     * $attributes->merge() would concatenate the two, and both would carry a
     * background: Tailwind emits both rules and the stylesheet's order picks
     * the winner, not the order they were written here. Same reasoning as
     * x-copy-button — whoever placed the button owns how it looks.
     */
    $trigger = $attributes->get('class') ?: 'flex items-center gap-2 rounded-full px-4 py-2 text-[0.8rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md '
        .($collection->isListed() ? 'bg-brand text-white' : 'bg-paper dark:bg-paper/10');
@endphp

{{--
    Who can see this collection: private, unlisted, or listed.

    ── WHY A MENU AND NOT A SWITCH ──────────────────────────────────────────

    There were two states and a switch, and a switch is honest about two
    things and nothing else. With three, the cycling version — press to go
    private → unlisted → listed → private — is a control that cannot tell you
    where the next press lands, so people press it to find out and publish
    something by accident. The menu shows all three at once, says what each
    one means, and takes one press to reach any of them.

    ── WHY THE WORDING IS NOT IN HERE ───────────────────────────────────────

    The labels and the sentences come from Collection::visibilities(). This
    component draws them; it does not decide them. The alternative is the
    same three descriptions written out in two templates, which stay in step
    until the first time one of them is edited.

    ── WHAT IT CALLS ────────────────────────────────────────────────────────

    setVisibility(id, state) on whichever Livewire component is around it.
    Both callers implement that exact signature, the id included, even the
    page that is only ever showing one collection: a method that takes the id
    is a method that can check the id belongs where it was pressed.

    ── NOT A LIVEWIRE ROOT ──────────────────────────────────────────────────

    x-data here is safe because this is a plain Blade component nested inside
    a Livewire one, not the element Livewire hangs its component on. On a
    Livewire root element it would not be — see the note in collection-picker.
--}}
<div x-data="{ open: false }"
     x-on:keydown.escape.window="open = false"
     x-on:click.outside="open = false"
     class="relative">

    <button type="button" x-on:click="open = ! open"
            title="{{ $face['blurb'] }}"
            class="{{ $trigger }}"
            {{ $attributes->except('class') }}>
        <x-icon :name="$face['icon']" style="solid" class="text-[0.8em]" />
        {{ $face['label'] }}
        <x-icon name="chevron-down" style="solid" class="text-[0.62em] opacity-50" />
    </button>

    <div x-show="open" style="display: none" x-transition.opacity.duration.150ms
         class="absolute right-0 top-full z-50 mt-2 w-72 rounded-card bg-surface p-2 text-ink shadow-soft-lg dark:bg-surface-dark dark:text-paper">

        @foreach ($states as $key => $state)
            <button type="button"
                    wire:click="setVisibility({{ $collection->id }}, '{{ $key }}')"
                    x-on:click="open = false"
                    wire:key="vis-{{ $collection->id }}-{{ $key }}"
                    @class([
                        'flex w-full items-start gap-2.5 rounded-control px-2.5 py-2.5 text-left transition hover:bg-ink/[0.05] dark:hover:bg-paper/[0.07]',
                        'bg-ink/[0.05] dark:bg-paper/[0.07]' => $key === $current,
                    ])>
                <x-icon :name="$state['icon']" style="solid"
                        class="mt-[0.2rem] shrink-0 text-[0.78rem] {{ $key === $current ? 'text-brand' : 'text-ink/35 dark:text-paper/35' }}" />

                <span class="min-w-0 flex-1">
                    <span class="flex items-center gap-2 text-[0.88rem]">
                        {{ $state['label'] }}

                        @if ($key === $current)
                            <span class="text-[0.68rem] uppercase tracking-[0.14em] text-ink/30 dark:text-paper/30">Now</span>
                        @endif
                    </span>

                    <span class="mt-0.5 block text-[0.76rem] leading-relaxed text-ink/45 dark:text-paper/45">
                        {{ $state['blurb'] }}
                    </span>
                </span>
            </button>
        @endforeach
    </div>
</div>
