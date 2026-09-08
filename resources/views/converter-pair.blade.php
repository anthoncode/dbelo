{{--
    /convert/{pair} — one page per conversion people actually search for.

    THE TOOL IS THE SAME TOOL. What differs is the heading, the preselected
    format, and several hundred words that are true only of this pair. That
    writing is not decoration around the converter — it IS the reason the
    page exists. Twenty pages carrying the same widget and two swapped words
    are thin content, and Google penalises thin content rather than ignoring
    it.
--}}
@php
    $cfg = \App\Support\Converter::config();
    $per = \App\Support\ConverterPairs::class;
@endphp

<style>
    @keyframes dbelo-done {
        0%   { background-color: rgba(137, 210, 6, 0);    }
        18%  { background-color: rgba(137, 210, 6, 0.16); }
        100% { background-color: rgba(137, 210, 6, 0);    }
    }
    .dbelo-flash { animation: dbelo-done 1.4s ease-out 1; }
    @media (prefers-reduced-motion: reduce) {
        .dbelo-flash { animation: dbelo-done 0.6s linear 1; }
    }
</style>

<x-layouts::site>

    <div class="mx-auto max-w-[64rem] px-5 py-12 sm:py-16">

        {{-- ══════════════════════════════════════════════════════════════
             THE HEADING IS THE SEARCH
             ══════════════════════════════════════════════════════════════ --}}
        <nav class="text-[0.8rem] text-ink/35 dark:text-paper/35">
            <a href="{{ route('converter') }}" wire:navigate class="transition hover:text-brand">Audio converter</a>
            <span class="mx-1.5">/</span>
            <span>{{ strtoupper($pair['from']) }} to {{ strtoupper($pair['to']) }}</span>
        </nav>

        <div class="mt-5">
            <span class="inline-flex items-center gap-2 rounded-full bg-success/10 px-4 py-1.5 text-[0.78rem] font-medium text-success">
                <x-icon name="lock" style="solid" class="text-[0.7rem]" />
                Your file never leaves your browser
            </span>

            <h1 class="mt-5 text-[2rem] leading-tight font-medium sm:text-[2.5rem]">{{ $pair['title'] }}</h1>

            <p class="mt-4 max-w-[62ch] text-[1.02rem] leading-relaxed text-ink/60 dark:text-paper/60">
                {{ $pair['lead'] }}
            </p>
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             THE TOOL, WITH THIS PAIR ALREADY CHOSEN
             ══════════════════════════════════════════════════════════════
             Somebody who searched "wav to mp3" has already told us both
             halves. Making them pick MP3 from a dropdown is asking a
             question they answered before they arrived. --}}
        <div class="mt-9"
             x-data="converter({{ \Illuminate\Support\Js::from($cfg) }})"
             x-init="lockTo('{{ $pair['to'] }}')">
            @include('partials.converter-tool', ['cfg' => $cfg, 'pair' => $pair])
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             WHAT IS ACTUALLY TRUE OF THIS CONVERSION
             ══════════════════════════════════════════════════════════════
             The honest content and the content that ranks are the same
             content, which is the happy part of this whole plan. --}}
        <div class="mt-12 grid gap-4 lg:grid-cols-2">

            <div class="rounded-card bg-surface p-7 shadow-soft-sm dark:bg-surface-dark">
                <span class="grid size-10 place-items-center rounded-full bg-warning/12 text-warning">
                    <x-icon name="arrow-down-short-wide" style="solid" class="text-[0.9rem]" />
                </span>
                <h2 class="mt-4 text-[1.05rem] font-medium">What you lose</h2>
                <p class="mt-2 text-[0.9rem] leading-relaxed text-ink/60 dark:text-paper/60">{{ $pair['loses'] }}</p>
            </div>

            <div class="rounded-card bg-surface p-7 shadow-soft-sm dark:bg-surface-dark">
                <span class="grid size-10 place-items-center rounded-full bg-info/12 text-info">
                    <x-icon name="circle-info" style="solid" class="text-[0.9rem]" />
                </span>
                <h2 class="mt-4 text-[1.05rem] font-medium">What you do <em>not</em> gain</h2>
                <p class="mt-2 text-[0.9rem] leading-relaxed text-ink/60 dark:text-paper/60">{{ $pair['gains'] }}</p>
            </div>
        </div>

        {{-- Sizes, so the decision is a number rather than a feeling. --}}
        <div class="mt-4 rounded-card bg-surface p-7 shadow-soft-sm dark:bg-surface-dark">
            <h2 class="text-[1.05rem] font-medium">What it does to the size</h2>

            <div class="mt-5 flex flex-wrap items-center gap-5">
                <div>
                    <div class="text-[0.72rem] uppercase tracking-[0.14em] text-ink/35 dark:text-paper/35">
                        {{ strtoupper($pair['from']) }} · one minute
                    </div>
                    <div class="mt-1 text-[1.5rem] font-medium tabular-nums">{{ $per::perMinute($pair['from']) }}</div>
                </div>

                <x-icon name="arrow-right" style="solid" class="text-[0.9rem] text-ink/25 dark:text-paper/25" />

                <div>
                    <div class="text-[0.72rem] uppercase tracking-[0.14em] text-ink/35 dark:text-paper/35">
                        {{ strtoupper($pair['to']) }} · one minute
                    </div>
                    <div class="mt-1 text-[1.5rem] font-medium tabular-nums text-brand">{{ $per::perMinute($pair['to']) }}</div>
                </div>
            </div>

            {{-- Said plainly rather than buried in a footnote: these are
                 approximations, and a page printing two decimal places
                 would be claiming a precision it does not have. --}}
            <p class="mt-4 text-[0.8rem] leading-relaxed text-ink/35 dark:text-paper/35">
                Approximate, for stereo audio at the settings this tool uses by default. The real figure moves
                with the material — silence and speech compress far better than a full mix.
            </p>
        </div>

        <div class="mt-4 rounded-card bg-surface p-7 shadow-soft-sm dark:bg-surface-dark">
            <h2 class="text-[1.05rem] font-medium">When this is the right conversion</h2>
            <p class="mt-2 max-w-[70ch] text-[0.9rem] leading-relaxed text-ink/60 dark:text-paper/60">{{ $pair['when'] }}</p>
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             THE OTHER PAIRS
             ══════════════════════════════════════════════════════════════
             The reverse first, then anything sharing a format. Links
             between pages that genuinely belong together are most of what
             makes twenty pages read as a section rather than twenty
             strangers. --}}
        @php $related = $per::related($pair['slug']); @endphp

        @if ($related)
            <div class="mt-10">
                <h2 class="text-[0.72rem] font-semibold uppercase tracking-[0.16em] text-ink/35 dark:text-paper/35">
                    Other conversions
                </h2>

                <div class="mt-4 flex flex-wrap gap-2.5">
                    @foreach ($related as $other)
                        <a href="{{ route('converter.pair', $other['slug']) }}" wire:navigate
                           class="rounded-full bg-surface px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:text-brand dark:bg-surface-dark">
                            {{ strtoupper($other['from']) }} → {{ strtoupper($other['to']) }}
                        </a>
                    @endforeach

                    <a href="{{ route('converter') }}" wire:navigate
                       class="rounded-full bg-ink/[0.05] px-5 py-2.5 text-[0.85rem] text-ink/60 transition duration-300 ease-dbelo hover:-translate-y-0.5 dark:bg-paper/[0.08] dark:text-paper/60">
                        All formats
                    </a>
                </div>
            </div>
        @endif

        <div class="mt-8 rounded-card bg-surface p-7 text-center shadow-soft-sm dark:bg-surface-dark">
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
