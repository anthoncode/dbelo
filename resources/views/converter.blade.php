{{--
    /converter — the free audio converter.

    PLAIN BLADE, NOT A LIVEWIRE COMPONENT, and that is deliberate.

    This page has no server state: nothing to save, nothing to fetch, no
    endpoint behind it. Livewire would be actively harmful here — every
    render replaces the DOM, and this page holds a queue of in-progress
    conversions with blob URLs and live progress. One morph in the middle of
    a 90-second job and the visitor watches their work disappear.

    Every setting lives on the ROW, not the page: format, bitrate, trim,
    channels. Somebody with four files usually wants four different things.
--}}
@php
    $cfg = \App\Support\Converter::config();
@endphp

{{-- The success pulse.

     Inline rather than a Tailwind utility, for the reason the save bar
     learned the hard way: an animation that has never been compiled does
     not exist in a stale stylesheet, and this one has no fallback that
     reads as anything at all — it simply would not happen, silently.

     Short on purpose. A second of motion says "this just finished"; three
     seconds says "this row is special", which is not true. --}}
<style>
    @keyframes dbelo-done {
        0%   { background-color: rgba(137, 210, 6, 0);    }
        18%  { background-color: rgba(137, 210, 6, 0.16); }
        100% { background-color: rgba(137, 210, 6, 0);    }
    }
    .dbelo-flash { animation: dbelo-done 1.4s ease-out 1; }
    @media (prefers-reduced-motion: reduce) {
        /* Somebody who asked for less motion still needs to know it
           finished — so the colour appears and fades without the pulse. */
        .dbelo-flash { animation: dbelo-done 0.6s linear 1; }
    }
</style>

<x-layouts::site>

    <div class="mx-auto max-w-[64rem] px-5 py-12 sm:py-16"
         x-data="converter({{ \Illuminate\Support\Js::from($cfg) }})">

        {{-- ══════════════════════════════════════════════════════════════
             THE PROMISE, FIRST
             ══════════════════════════════════════════════════════════════
             The only thing separating this page from the two hundred
             converters that already rank — and it is true, which is why it
             goes above the tool rather than in a footer. --}}
        <div class="text-center">
            <span class="inline-flex items-center gap-2 rounded-full bg-success/10 px-4 py-1.5 text-[0.78rem] font-medium text-success">
                <x-icon name="lock" style="solid" class="text-[0.7rem]" />
                Your file never leaves your browser
            </span>

            <h1 class="mt-5 text-[2rem] leading-tight font-medium sm:text-[2.6rem]">Free audio converter</h1>

            <p class="mx-auto mt-4 max-w-[60ch] text-[1rem] leading-relaxed text-ink/55 dark:text-paper/55">
                MP3, WAV, OGG, FLAC, M4A and OPUS — each file to its own format, with its own settings.
                Everything runs on your machine: nothing is uploaded, nothing is stored, there is no account.
            </p>
        </div>

        @include('partials.converter-tool', ['cfg' => $cfg, 'pair' => null])

        {{-- ══════════════════════════════════════════════════════════════
             WHAT IT IS, AND WHAT IT IS NOT
             ══════════════════════════════════════════════════════════════ --}}
        <div class="mt-10 grid gap-4 sm:grid-cols-3">
            @foreach ([
                ['shield-check', 'Nothing is uploaded', 'The audio is read by your own browser and converted there. No server sees it, so there is nothing for us to store, leak or hand over.'],
                ['bolt', 'No account, no daily limit', 'There is nothing to sign up for. We are not counting you, because there is no request to count.'],
                ['circle-info', 'It will not add quality back', 'Converting a 128 kbps MP3 to FLAC recovers nothing — it only makes the file bigger. What was thrown away is gone.'],
            ] as [$icon, $heading, $detail])
                <div class="rounded-card bg-surface p-6 shadow-soft-sm dark:bg-surface-dark">
                    <span class="grid size-10 place-items-center rounded-full bg-brand/10 text-brand">
                        <x-icon :name="$icon" style="solid" class="text-[0.9rem]" />
                    </span>
                    <div class="mt-4 text-[0.95rem] font-medium">{{ $heading }}</div>
                    <p class="mt-1.5 text-[0.84rem] leading-relaxed text-ink/50 dark:text-paper/50">{{ $detail }}</p>
                </div>
            @endforeach
        </div>

        <div class="mt-5 rounded-card bg-surface p-6 text-center shadow-soft-sm sm:p-8 dark:bg-surface-dark">
            <p class="mx-auto max-w-[56ch] text-[0.92rem] leading-relaxed text-ink/60 dark:text-paper/60">
                This tool is free because {{ config('app.name', 'dbelo') }} is a library of sound effects, and
                we would rather you found us this way than not at all.
            </p>
            <a href="{{ route('sounds.index') }}" wire:navigate
               class="mt-5 inline-flex items-center gap-2.5 rounded-full bg-ink/[0.05] px-7 py-3 text-[0.88rem] text-ink/70 transition duration-300 ease-dbelo hover:bg-ink/[0.09] dark:bg-paper/[0.08] dark:text-paper/70 dark:hover:bg-paper/[0.14]">
                <x-icon name="waveform-lines" style="solid" class="text-[0.8rem]" />
                Browse the sound library
            </a>
        </div>
    </div>

    @vite('resources/js/converter.js')
</x-layouts::site>
