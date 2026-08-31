@props([
    'href',
    'title',
    'subtitle' => null,
    'icon' => 'waveform-lines',
    'meta' => null,
    'raw' => false,           // render the title as markup — hand-written titles only
    'tone' => 'brand',        // brand · action
    'filled' => false,        // already coloured, rather than on hover
    'size' => 'default',      // default · tall · wide
    'delay' => 0,
])

@php
    /*
     * `raw` is opt-in and never used for a title that came out of the
     * database. A pack name is typed by a person into a form; running it
     * through {!! !!} because one hand-written card wanted an italic word
     * is how a stored-XSS hole gets opened for a typographic flourish.
     */

    /*
     * hover:, NOT group-hover:, for the card's own fill.
     *
     * `group-hover:` compiles to `.group:hover .group-hover\:bg-brand` — a
     * DESCENDANT selector. An element carrying both `group` and
     * `group-hover:bg-brand` can never match itself, so the card stayed
     * white while its children obediently turned their text white too.
     * Invisible text on hover, and the kind of bug that looks like a colour
     * problem when it is a selector problem.
     *
     * `group` stays because the children genuinely are descendants.
     */
    $colour = $tone === 'action' ? 'action' : 'brand';

    $skin = $filled
        ? "bg-{$colour} text-white shadow-soft-md hover:shadow-soft-lg"
        : "bg-surface shadow-soft-sm hover:bg-{$colour} hover:shadow-soft-lg dark:bg-surface-dark";

    $shape = match ($size) {
        'tall' => 'lg:row-span-2 min-h-[300px]',
        'wide' => 'sm:col-span-2',
        default => 'min-h-[170px]',
    };

    /*
     * The watermark is sized and pushed out per tile size.
     *
     * A single 125px glyph was fine on the tall card and far too big on a
     * 170px one: it reached up under the subtitle and out under the arrow
     * button, so on those cards the "texture" was reading as an icon
     * colliding with the text. Smaller, and further into the corner.
     */
    $mark = match ($size) {
        'tall' => 'text-[132px] -bottom-8 -right-7',
        'wide' => 'text-[118px] -bottom-7 -right-7',
        default => 'text-[96px] -bottom-6 -right-5',
    };
@endphp

<a href="{{ $href }}" wire:navigate
   style="animation-delay: {{ $delay }}ms"
   {{ $attributes->merge(['class' =>
        "rise group relative flex flex-col overflow-hidden rounded-card p-6 transition duration-400 ease-dbelo hover:-translate-y-1 {$skin} {$shape}"
   ]) }}>

    {{-- The watermark. Oversized and clipped by the card so it reads as
         texture rather than as an icon somebody forgot to size. It
         brightens rather than moves: a shape this large sliding around is
         distracting at the edge of vision while you read the next card.

         The mask fades it out toward the top-left, which is exactly where
         the title and subtitle are. Corner-anchored texture that dissolves
         before it reaches the text cannot collide with it, whatever length
         a category description turns out to be. --}}
    {{-- The mask lives on a wrapper, not on the icon.

         <x-icon> takes `style` as a PROP — it is the Font Awesome weight —
         so a second style attribute for CSS would collide with it and one
         of the two would be silently dropped. --}}
    <span aria-hidden="true"
          style="mask-image: linear-gradient(to top left, #000 15%, transparent 78%);
                 -webkit-mask-image: linear-gradient(to top left, #000 15%, transparent 78%);"
          @class([
              'pointer-events-none absolute leading-none transition duration-500 ease-dbelo group-hover:scale-105',
              $mark,
              'text-white/15 group-hover:text-white/22' => $filled,
              'text-ink/[0.05] group-hover:text-white/18 dark:text-paper/[0.06]' => ! $filled,
          ])>
        <x-icon :name="$icon" style="solid" class="leading-none" />
    </span>

    <div class="relative flex h-full flex-col">
        <h3 @class([
                'text-[1.35rem] font-semibold tracking-[-0.02em] transition duration-300 ease-dbelo',
                'group-hover:text-white' => ! $filled,
            ])>{!! $raw ? $title : e($title) !!}</h3>

        @if ($subtitle)
            <p @class([
                    'mt-2 max-w-[38ch] text-[0.88rem] leading-relaxed transition duration-300 ease-dbelo',
                    'text-white/80' => $filled,
                    'text-ink/55 group-hover:text-white/85 dark:text-paper/55' => ! $filled,
                ])>{{ $subtitle }}</p>
        @endif

        <div class="mt-auto flex items-end justify-between gap-4 pt-6">
            @if ($meta)
                <span @class([
                        'text-[10px] font-medium uppercase tracking-[0.15em] transition duration-300 ease-dbelo',
                        'text-white/70' => $filled,
                        'text-ink/45 group-hover:text-white/70 dark:text-paper/45' => ! $filled,
                    ])>{{ $meta }}</span>
            @else
                <span></span>
            @endif

            {{-- The arrow is the only thing that moves. One moving element
                 reads as an invitation; three read as a fidget. --}}
            <span @class([
                    'grid size-9 shrink-0 place-items-center rounded-full transition duration-400 ease-dbelo group-hover:translate-x-1',
                    'bg-white/20 text-white' => $filled,
                    'bg-ink/[0.06] text-ink/40 group-hover:bg-white/20 group-hover:text-white dark:bg-paper/10 dark:text-paper/40' => ! $filled,
                ])>
                <x-icon name="arrow-right" style="solid" class="text-[0.75rem]" />
            </span>
        </div>
    </div>
</a>
