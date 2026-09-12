@props([
    'bars' => 160,
    'height' => 'h-16',
])

@php
    /*
     * A waveform that is not data.
     *
     * The heights come from a sine curve rather than random(): random bars
     * produce clumps and gaps that read as noise, while a curve reads as a
     * shape — which is what a waveform is. Two sines of different periods
     * added together keep it from looking mechanical.
     *
     * Seeded by index, so the same page renders the same shape on every
     * request and the markup stays cacheable.
     */
    $shape = [];

    for ($i = 0; $i < $bars; $i++) {
        $t = $i / max(1, $bars - 1);

        $envelope = sin($t * M_PI);                    // quiet at both ends
        $detail = 0.55 + 0.45 * sin($t * M_PI * 9.0);  // the ripple along it

        $shape[] = [
            'height' => max(6, (int) round($envelope * $detail * 100)),
            'duration' => round(2.4 + 1.9 * sin($t * M_PI * 3.7), 2),
            'delay' => round($t * 2.2, 2),
        ];
    }
@endphp

{{--
    Decorative only: aria-hidden, and no text alternative, because there is
    nothing here a screen reader could usefully say.
--}}
{{--
    THE BARS NO LONGER STRETCH, and that was the real problem here.

    They were `w-[3px] flex-1`, and flex-1 wins: the 3px was a floor, not a
    width, so every bar grew to share whatever space was going. Sixty-four
    of them across a full-width panel are not hairlines, they are slabs —
    and the gap-[2px] between them was the only thing keeping them apart.

    Now the width is fixed and justify-between does the spacing, which is
    how the real waveform beside it already worked. Same visual language for
    the decorative wave and the data one, instead of two different textures
    that happen to sit on the same page.
--}}
<div {{ $attributes->merge(['class' => "wave-ambient pointer-events-none flex items-center justify-between {$height}"]) }}
     aria-hidden="true">
    @foreach ($shape as $bar)
        <span class="w-px shrink-0 rounded-full bg-brand/25"
              style="height: {{ $bar['height'] }}%;
                     --dur: {{ max(1.2, $bar['duration']) }}s;
                     animation-delay: -{{ $bar['delay'] }}s;"></span>
    @endforeach
</div>
