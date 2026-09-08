{{--
    The mark in the header.

    TWO SIZES, ONE COMPONENT. Below md the bar is a phone-app header — a
    mark, a search field, an account icon and the theme — and a wordmark
    eats the width the search needs. So the favicon stands alone there and
    the full logo appears from md up.

    Falls back to the waveform tile and the wordmark when nothing has been
    uploaded. A site with no logo file should look finished, not look like a
    missing image.

    Both light and dark variants are rendered and CSS picks one. Swapping the
    src with JavaScript on theme change would flash the wrong logo on every
    page load for as long as it took the script to run, and the theme class
    is applied before first paint precisely so that nothing flashes.

    An uploaded SVG is served through <img>, never inlined. SVG in an <img>
    cannot execute script; the same file inlined into the page can. Only
    admins can upload one, and the upload already rejects files containing
    script — but the cheap structural defence is worth having as well as the
    check, because one of them will still be here in two years.
--}}
@php
    $light = \App\Support\Appearance::url('logo_light');
    $dark = \App\Support\Appearance::logoDark();
    $favicon = \App\Support\Appearance::url('favicon');
    $name = config('app.name', 'dbelo');
@endphp

<a href="{{ route('home') }}" wire:navigate
   aria-label="{{ $name }}"
   class="flex shrink-0 items-center gap-2.5 pl-1 text-[1.25rem] font-bold tracking-[-0.04em] md:pl-2">

    {{-- ── Phone and tablet: the mark alone ── --}}
    <span class="md:hidden">
        @if ($favicon)
            <img src="{{ $favicon }}" alt="{{ $name }}" class="size-9 rounded-[10px] object-contain" />
        @elseif ($light)
            {{-- No favicon uploaded, but there is a logo: show it small
                 rather than falling all the way back to the placeholder
                 tile, which would be a worse mark than the one they have. --}}
            <img src="{{ $light }}" alt="{{ $name }}" class="h-8 w-auto max-w-[7rem] dark:hidden" />
            <img src="{{ $dark }}" alt="{{ $name }}" class="hidden h-8 w-auto max-w-[7rem] dark:block" />
        @else
            <span class="grid size-9 place-items-center rounded-[10px] bg-brand">
                <x-icon name="waveform-lines" style="solid" class="text-[14px] text-white" />
            </span>
        @endif
    </span>

    {{-- ── From md up: the full mark ── --}}
    <span class="hidden items-center gap-2.5 md:flex">
        @if ($light)
            <img src="{{ $light }}" alt="{{ $name }}" class="h-8 w-auto max-w-[190px] dark:hidden" />
            <img src="{{ $dark }}" alt="{{ $name }}" class="hidden h-8 w-auto max-w-[190px] dark:block" />
        @else
            <span class="grid size-8 place-items-center rounded-[10px] bg-brand">
                <x-icon name="waveform-lines" style="solid" class="text-[13px] text-white" />
            </span>
            {{ $name }}
        @endif
    </span>
</a>
