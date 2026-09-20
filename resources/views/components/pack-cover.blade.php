@props([
    'pack',
    /** Font Awesome name drawn when the pack has no cover image. */
    'icon' => 'box-open',
    /**
     * Size of the glyph, as a CSS length. It is set as the font-size of the
     * wrapper and both the icon and its reflection are drawn at 1em, so one
     * number scales the whole treatment — a 24px thumbnail and a 4:3 card
     * ask for very different glyphs and should not need different markup.
     */
    'glyph' => '2.9rem',
    /** The card's hover choreography. Off anywhere there is no group to hover. */
    'interactive' => false,
])

@php($cover = $pack->coverUrl())

{{--
    A pack's cover, or the treatment that stands in for one.

    ── WHY THIS IS ITS OWN COMPONENT ────────────────────────────────────────

    It was written inside x-pack-card, which was fine while the listing was
    the only place a pack had a face. The pack's own page now shows one next
    to the title, and a second copy of a gradient, a masked reflection and a
    fallback rule is a second copy that drifts: change the wash on one and
    the two pages stop looking like the same product.

    ── THE REFLECTION ───────────────────────────────────────────────────────

    The glyph drawn a second time, flipped, and faded downwards with a mask.
    It is the cheapest way to make a flat icon read as an object sitting on a
    surface — no image, no extra request — and it follows whatever icon the
    caller asked for, so a pack with a different glyph needs nothing else.

    The mask goes on a WRAPPER span rather than on the icon, because x-icon
    already takes `style` as a prop — the Font Awesome family — so a second
    style attribute meant for CSS collides with it and one of the two is
    silently dropped.

    ── SIZE AND SHAPE BELONG TO THE CALLER ──────────────────────────────────

    This component brings `relative overflow-hidden` and nothing else about
    its own dimensions: the card wants a 4:3 box that fills its column, the
    page wants a small rounded square. Both are the caller's business.
--}}
<div {{ $attributes->merge(['class' => 'relative overflow-hidden']) }}>
    @if ($cover)
        {{-- loading="lazy" because a listing is a dozen of these and none
             below the fold is worth a request before it is scrolled to. The
             scale is on the IMAGE, not the box, so the corners stay put
             while the picture drifts. --}}
        <img src="{{ $cover }}" alt="" loading="lazy" decoding="async"
             @class([
                 'absolute inset-0 size-full object-cover',
                 'transition duration-700 ease-dbelo group-hover:scale-[1.06]' => $interactive,
             ]) />
    @else
        <div class="absolute inset-0"
             style="background:
                 radial-gradient(120% 80% at 50% 0%, color-mix(in srgb, var(--color-brand) 38%, transparent), transparent 70%),
                 linear-gradient(160deg, color-mix(in srgb, var(--color-brand) 16%, transparent), transparent 55%);"></div>

        <div class="absolute inset-0 flex flex-col items-center justify-center"
             style="font-size: {{ $glyph }};">
            <x-icon :name="$icon" style="solid"
                    @class([
                        'text-[1em] leading-none text-paper/85 drop-shadow-[0_10px_30px_rgba(0,0,0,0.45)]',
                        'transition duration-700 ease-dbelo group-hover:-translate-y-1' => $interactive,
                    ]) />

            <span @class([
                      'mt-[0.06em] block',
                      'transition duration-700 ease-dbelo group-hover:-translate-y-1' => $interactive,
                  ])
                  style="mask-image: linear-gradient(to top, transparent 15%, #000 95%);
                         -webkit-mask-image: linear-gradient(to top, transparent 15%, #000 95%);">
                <x-icon :name="$icon" style="solid"
                        class="block scale-y-[-1] text-[1em] leading-none text-paper/20" />
            </span>
        </div>
    @endif
</div>
