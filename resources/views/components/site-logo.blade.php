{{--
    The mark in the header.

    Falls back to what has always been there — the waveform tile and the
    wordmark — when no logo has been uploaded. A site with no logo file
    should look finished, not look like a missing image.

    Both variants are rendered and CSS picks one. Swapping the src with
    JavaScript on theme change would flash the wrong logo on every page load
    for as long as it took the script to run, and the theme class is applied
    before first paint precisely so that nothing flashes.

    An uploaded SVG is served through <img>, never inlined. SVG in an <img>
    cannot execute script; the same file inlined into the page can. Only
    admins can upload one, and the upload already rejects files containing
    script — but the cheap structural defence is worth having as well as the
    check, because one of them will still be here in two years.
--}}
@php
    $light = \App\Support\Appearance::url('logo_light');
    $dark = \App\Support\Appearance::logoDark();
    $name = config('app.name', 'dbelo');
@endphp

<a href="{{ route('home') }}" wire:navigate
   class="flex shrink-0 items-center gap-2.5 pl-2 text-[1.25rem] font-bold tracking-[-0.04em]">

    @if ($light)
        <img src="{{ $light }}" alt="{{ $name }}" class="h-8 w-auto max-w-[190px] dark:hidden" />
        <img src="{{ $dark }}" alt="{{ $name }}" class="hidden h-8 w-auto max-w-[190px] dark:block" />
    @else
        <span class="grid size-8 place-items-center rounded-[10px] bg-brand">
            <x-icon name="waveform-lines" style="solid" class="text-[13px] text-white" />
        </span>
        {{ $name }}
    @endif
</a>
