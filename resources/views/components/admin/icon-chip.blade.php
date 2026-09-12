@props([
    'icon',
    'style' => 'solid',
    'size' => 'size-8',
    'tone' => 'brand',   // brand · muted · success · warning · danger
])

{{-- Non-interactive round holder: marks what a row or a card is about.
     Round everywhere in the admin, by convention. --}}
<span @class([
    "grid {$size} shrink-0 place-items-center rounded-full",
    'bg-raised text-brand' => $tone === 'brand',
    'bg-raised/50 text-paper/40' => $tone === 'muted',

    // The semantic three. Tinted rather than solid: this holder marks what
    // a card is ABOUT, and a solid red circle reads as an alarm going off
    // rather than as a heading for a number that happens to be a bad one.
    'bg-success/12 text-success' => $tone === 'success',
    'bg-warning/12 text-warning' => $tone === 'warning',
    'bg-danger/12 text-danger' => $tone === 'danger',
])>
    <x-icon :name="$icon" :style="$style" {{ $attributes->merge(['class' => 'text-[12px]']) }} />
</span>
