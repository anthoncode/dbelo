@props([
    'name',
    'style' => 'regular',
])

{{--
    Wrapper around Font Awesome Pro 7.3.
    Install: copy css/ and webfonts/ into public/vendor/fontawesome/

    Usage: <x-icon name="waveform-lines" style="solid" class="size-4" />

    Going through a component means a future icon-set change touches one
    file instead of every <i> tag in the project.
--}}
<i {{ $attributes->merge(['class' => "fa-{$style} fa-{$name}"]) }} aria-hidden="true"></i>
