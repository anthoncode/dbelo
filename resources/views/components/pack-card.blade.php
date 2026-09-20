@props([
    'pack',
    /** Font Awesome name used when the pack has no cover image. */
    'icon' => 'box-open',
    /** Milliseconds, for the staggered entrance. */
    'delay' => 0,
])

@php($badge = $pack->badge())

{{--
    A PACK COVER.

    ── THE DESCRIPTION IS NOT HIDDEN BEHIND HOVER ───────────────────────────

    It looks like it is, and on a desktop it behaves like it: the panel sits
    low, showing the name and the count, and rises on hover to reveal what the
    pack is for.

    But hover does not exist on a phone, and a touch that would trigger it is
    the same touch that follows the link. Building the description as
    hover-only would mean half the audience never sees it and nobody on that
    half knows there was anything to see.

    So the reveal is `lg:` only. Below that breakpoint the panel is simply
    open — the description is always there, clamped to two lines. Same markup,
    one rule, no second component to keep in step.

    ── WHY THE WHOLE THING IS ONE <a> ───────────────────────────────────────

    A card with a link inside it has two targets: the picture, and the title.
    Clicking the gap between them does nothing, which is the kind of dead zone
    people blame on the site being slow rather than on themselves having
    missed. One anchor, one hit area, one focus ring.
--}}
<a href="{{ route('packs.show', $pack) }}" wire:navigate
   {{ $attributes->merge(['class' => 'rise group relative block overflow-hidden rounded-card bg-ink shadow-soft-md transition duration-500 ease-dbelo hover:-translate-y-1 hover:shadow-soft-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-paper dark:focus-visible:ring-offset-surface-dark']) }}
   style="animation-delay: {{ $delay }}ms">

    {{-- The 4:3 box. aspect-ratio rather than a fixed height so a row of
         cards stays a grid at every width without a media query per size. --}}
    <div class="relative aspect-[4/3] w-full overflow-hidden">

        {{-- The cover, or the icon treatment that stands in for one.

             Both used to be written out here. They moved to x-pack-cover the
             day the pack's own page needed the same face beside its title:
             one gradient, one reflection, one fallback rule, drawn in two
             places from a single definition. --}}
        <x-pack-cover :pack="$pack" :icon="$icon" glyph="2.9rem" interactive
                      class="absolute inset-0 size-full" />

        {{-- The scrim. Always present, and it deepens on hover so the text
             that arrives with the hover has something to sit on. Without it
             a pale cover turns the name into white-on-white. --}}
        <div class="absolute inset-0 bg-gradient-to-t from-ink via-ink/35 to-transparent opacity-85 transition duration-500 group-hover:opacity-95"></div>

        {{-- ── THE BADGE ─────────────────────────────────────────────────
             title= carries the long version: "3 of 40 sounds need a
             subscription. The rest are free." The pill has room for the
             short answer, and the honest one is a sentence. --}}
        <span title="{{ $badge['title'] }}"
              @class([
                  'absolute right-3 top-3 rounded-full px-2.5 py-1 text-[0.68rem] font-medium uppercase tracking-[0.1em] backdrop-blur-sm',
                  'bg-success/90 text-ink' => $badge['tone'] === 'success',
                  'bg-brand/90 text-white' => $badge['tone'] === 'brand',
              ])>{{ $badge['label'] }}</span>

        {{-- ── THE PANEL ─────────────────────────────────────────────────
             Open below lg, revealed on hover above it. translate-y on a
             container whose overflow is hidden: the description is laid out
             from the first paint, so nothing reflows when it appears and the
             animation is one transform rather than a height change. --}}
        <div class="absolute inset-x-0 bottom-0 p-4 transition duration-500 ease-dbelo lg:translate-y-[calc(100%-4.6rem)] lg:group-hover:translate-y-0">

            <h3 class="text-[1.02rem] font-medium leading-snug text-paper">{{ $pack->name }}</h3>

            <div class="mt-1 flex items-center gap-2 text-[0.78rem] text-paper/55">
                <x-icon name="waveform-lines" style="solid" class="text-[0.68rem]" />
                {{ $pack->sounds_count }} {{ Str::plural('sound', $pack->sounds_count) }}
            </div>

            @if (filled($pack->description))
                <p class="mt-2.5 line-clamp-2 text-[0.83rem] leading-relaxed text-paper/65 lg:opacity-0 lg:transition lg:delay-100 lg:duration-500 lg:group-hover:opacity-100">
                    {{ $pack->description }}
                </p>
            @endif

            <span class="mt-3 hidden items-center gap-1.5 text-[0.8rem] font-medium text-brand lg:inline-flex lg:opacity-0 lg:transition lg:delay-150 lg:duration-500 lg:group-hover:opacity-100">
                Open the pack
                <x-icon name="arrow-right" style="solid" class="text-[0.66rem] transition duration-300 group-hover:translate-x-0.5" />
            </span>
        </div>
    </div>
</a>
