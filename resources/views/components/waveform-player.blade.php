@props([
    'sound',
    'bars' => 150,
    'height' => 'h-8',
    'thickness' => 'w-px',
    'button' => 'size-10',
    'showTime' => true,
])

@php
    use Illuminate\Support\Facades\Storage;

    $preview = $sound->files->firstWhere('purpose', 'preview');
    $src = $preview ? Storage::disk($preview->disk)->url($preview->path) : null;

    /*
     * Stored with 400 peaks; thinned to exactly $bars of them.
     *
     * EXACTLY, and that word is the fix. This used to chunk by
     * ceil(400 / $bars), which only lands on $bars when $bars divides 400.
     * Everywhere else it silently drew fewer: 90 gave 80, 130 gave 100, and
     * anything from 201 to 399 gave 200 flat. The bars are laid out with
     * justify-between, so "fewer bars" does not mean a shorter waveform — it
     * means the same width shared between fewer of them. Every gap widens.
     *
     * That is most of why the wide ones looked so spread out: the sound page
     * asked for 130 bars across a thousand pixels and got a hundred.
     *
     * Boundaries by integer division so the slices tile the array with no
     * overlap and nothing left over at the end.
     *
     * max() of each slice, not the average: a waveform is drawn to show
     * where the loud parts are, and averaging a sharp transient with the
     * silence around it is how a gunshot turns into a bump.
     */
    $peaks = $sound->waveform ?? [];

    if ($peaks && count($peaks) > $bars) {
        $total = count($peaks);
        $thinned = [];

        for ($i = 0; $i < $bars; $i++) {
            $start = intdiv($i * $total, $bars);
            $end = intdiv(($i + 1) * $total, $bars);

            $thinned[] = round(max(array_slice($peaks, $start, max(1, $end - $start))), 4);
        }

        $peaks = $thinned;
    }

    // A 7% floor keeps silence visible instead of collapsing to nothing.
    $heights = array_map(fn ($p) => max(7, (int) round($p * 100)), $peaks);

    /*
     * Everything the bar at the bottom of the page needs to play this.
     *
     * Passed as data rather than looked up, because by the time the visitor
     * has navigated twice the row that started the track is long gone from
     * the DOM and the bar still has to render its title.
     */
    $track = [
        'id' => $sound->id,
        'src' => $src,
        'title' => $sound->title,
        'author' => $sound->user?->name,
        'url' => route('sounds.show', $sound),
        'duration' => round($sound->duration_ms / 1000, 3),
    ];
@endphp

<div x-data="dbeloTrack({{ Js::from($track) }})"
     {{ $attributes->merge(['class' => 'flex items-center gap-4']) }}>

    {{--
        The play control.

        `relative` with both icons absolutely placed, rather than two grid
        children: as grid items they occupied two separate rows, so the
        glyph was never actually in the middle of the circle and at 0.7rem
        inside a 40px button there was little enough of it that the button
        read as empty. One cell, one centred glyph, sized to the button.

        Two icons swapped rather than one name computed, and the pause icon
        carries style="display:none" rather than x-cloak. x-cloak is removed
        by Alpine on init and never restored, so after a Livewire re-render
        morphs this row the pause glyph would sit on top of the play glyph
        with nothing left to hide it.
    --}}
    <button type="button"
            x-on:click="toggle()"
            @disabled(! $src)
            :aria-label="playing ? 'Pause' : 'Play'"
            @class([
                $button,
                'relative grid shrink-0 place-items-center rounded-full text-white shadow-brand transition duration-300 ease-dbelo hover:scale-105',
                'bg-brand' => $src,
                // No preview file: say so, instead of a dead orange circle.
                'cursor-not-allowed bg-ink/20 shadow-none dark:bg-paper/20' => ! $src,
            ])
            @if (! $src) title="No preview available yet" @endif>

        <span x-show="! playing" class="absolute inset-0 grid place-items-center">
            <x-icon name="play" style="solid" class="translate-x-[1px] text-[0.95rem] leading-none" />
        </span>

        <span x-show="playing" style="display: none" class="absolute inset-0 grid place-items-center">
            <x-icon name="pause" style="solid" class="text-[0.95rem] leading-none" />
        </span>
    </button>

    @if ($heights)
        <div x-on:click="seek($event)"
             class="relative flex-1 cursor-pointer {{ $height }}"
             role="slider"
             aria-label="Seek"
             :aria-valuenow="Math.round(progress * 100)"
             aria-valuemin="0" aria-valuemax="100">

            {{-- Unplayed bars: currentColor, so they adapt to light or dark cards --}}
            <div class="absolute inset-0 flex items-center justify-between">
                @foreach ($heights as $h)
                    <span class="{{ $thickness }} rounded-full bg-current opacity-25" style="height: {{ $h }}%"></span>
                @endforeach
            </div>

            {{-- Played bars revealed by clipping: ONE style write per frame
                 rather than one per bar. With 120 bars at 4 updates a second
                 that is the difference between 480 style writes and 4. --}}
            <div class="absolute inset-0 flex items-center justify-between"
                 :style="`clip-path: inset(0 ${100 - progress * 100}% 0 0)`">
                @foreach ($heights as $h)
                    <span class="{{ $thickness }} rounded-full bg-brand" style="height: {{ $h }}%"></span>
                @endforeach
            </div>
        </div>
    @else
        <div class="flex-1 text-sm opacity-40">No waveform</div>
    @endif

    @if ($showTime)
        <div class="micro w-9 shrink-0 text-right tabular-nums">{{ $sound->durationForHumans() }}</div>
    @endif
</div>
