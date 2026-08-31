@props([
    'icon',
    'style' => 'solid',
    'size' => 'size-8',
    'tone' => 'brand',   // brand · muted
])

{{-- Non-interactive round holder: marks what a row or a card is about.
     Round everywhere in the admin, by convention. --}}
<span @class([
    "grid {$size} shrink-0 place-items-center rounded-full",
    'bg-raised text-brand' => $tone === 'brand',
    'bg-raised/50 text-paper/40' => $tone === 'muted',
])>
    <x-icon :name="$icon" :style="$style" {{ $attributes->merge(['class' => 'text-[12px]']) }} />
</span>
