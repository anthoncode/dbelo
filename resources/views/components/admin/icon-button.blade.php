@props([
    'icon',
    'style' => 'solid',
    'label' => null,
    'variant' => 'ghost',   // ghost · brand · muted
    'size' => 'size-8',
])

{{--
    Round action button for the admin.

    Every icon-only control needs a tooltip: an icon on its own is a guess
    until you have hovered it once, and in a table of actions that guess is
    the difference between editing and deleting.
--}}
<span class="group/tip relative inline-flex">
    <button
        {{ $attributes->merge([
            'type' => 'button',
            'class' => match ($variant) {
                'brand' => "grid {$size} place-items-center rounded-full bg-brand text-white transition duration-200 ease-dbelo hover:brightness-115",
                'muted' => "grid {$size} place-items-center rounded-full text-paper/25 transition duration-200 ease-dbelo hover:bg-paper/[0.10] hover:text-paper/70",
                default => "grid {$size} place-items-center rounded-full text-paper/40 transition duration-200 ease-dbelo hover:bg-paper/[0.10] hover:text-paper",
            },
        ]) }}
    >
        <x-icon :name="$icon" :style="$style" class="text-[11px]" />
    </button>

    @if ($label)
        <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2 translate-y-1 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.72rem] font-medium text-ink opacity-0 shadow-xl transition duration-200 ease-dbelo group-hover/tip:translate-y-0 group-hover/tip:opacity-100">
            {{ $label }}
        </span>
    @endif
</span>
