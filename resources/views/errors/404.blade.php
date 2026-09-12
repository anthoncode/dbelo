@php
    /*
     * ── THE 404 ──────────────────────────────────────────────────────────
     *
     * This page runs INSIDE the site layout, unlike 500. That is a
     * deliberate split: a 404 means the application is healthy and only the
     * address was wrong, so the header, the player and the footer all work
     * and there is no reason to drop the visitor out of the site. A 500
     * means the opposite, and its page is self-contained — see errors/500.
     *
     * Everything below degrades. ErrorSuggestions swallows a dead search
     * engine and a dead database and returns an empty collection, so the
     * worst case is this page with fewer things on it, never an error page
     * that errors.
     */
    $path = trim(request()->path(), '/');
    $suggestions = \App\Support\ErrorSuggestions::forPath($path);
@endphp

<x-layouts::site>
    <div class="mx-auto max-w-3xl py-10">

        {{-- ══════════════════════════════════════════════════════════════
             SILENCE
             A flat line, because this is a sound library and the joke is
             the right one: nothing here to hear. It is drawn in CSS rather
             than loaded as an image so it costs nothing and cannot 404 on
             a 404 page.
             ══════════════════════════════════════════════════════════════ --}}
        <div class="flex items-center justify-center gap-[3px]" aria-hidden="true">
            @for ($i = 0; $i < 48; $i++)
                <span class="h-px w-[3px] rounded-full bg-ink/25 dark:bg-paper/25"></span>
            @endfor
        </div>

        <div class="mt-8 text-center">
            <div class="micro">Error 404</div>

            <h1 class="mt-2 text-3xl font-semibold tracking-tight sm:text-4xl">Complete silence.</h1>

            <p class="mx-auto mt-3 max-w-[52ch] leading-relaxed text-ink/60 dark:text-paper/60">
                @if ($suggestions['guessed'])
                    There is nothing at that address — but going by what it was called, this may be what you came for.
                @else
                    There is nothing at that address. It may have been renamed, removed, or never existed at all.
                @endif
            </p>
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             SEARCH
             Pre-filled with whatever the dead URL was named, so the most
             common recovery — "that sound moved, find it again" — is one
             key press away rather than a retype.
             ══════════════════════════════════════════════════════════════ --}}
        <form action="{{ route('sounds.index') }}" method="GET" class="mx-auto mt-8 max-w-xl">
            <div class="flex items-center gap-2 rounded-full bg-surface px-5 py-2.5 shadow-soft-md dark:bg-surface-dark">
                <x-icon name="magnifying-glass" style="regular" class="shrink-0 text-[0.9rem] text-ink/35 dark:text-paper/35" />

                <label for="q-404" class="sr-only">Search the catalogue</label>

                <input id="q-404" type="search" name="q" value="{{ $suggestions['terms'] }}"
                       placeholder="Search the catalogue…" autocomplete="off"
                       class="min-w-0 flex-1 border-0 bg-transparent py-1.5 text-[0.95rem] placeholder:text-ink/30 focus:outline-none focus:ring-0 dark:placeholder:text-paper/30" />

                <button type="submit"
                        class="shrink-0 rounded-full bg-action px-5 py-2 text-[0.85rem] font-medium text-white transition duration-200 ease-dbelo hover:brightness-110">
                    Search
                </button>
            </div>
        </form>

        {{-- ══════════════════════════════════════════════════════════════
             SUGGESTIONS
             Two different lists with two different headings. Saying
             "you might like" above a generic popularity ranking is a small
             lie, and the visitor can tell — which costs more trust than the
             list gains.
             ══════════════════════════════════════════════════════════════ --}}
        @if ($suggestions['sounds']->isNotEmpty())
            <div class="mt-12">
                <div class="mb-3 flex items-baseline justify-between gap-4 px-1">
                    <h2 class="text-[1.05rem] font-medium">
                        {{ $suggestions['guessed'] ? 'Closest matches' : 'Most downloaded' }}
                    </h2>

                    @if ($suggestions['guessed'])
                        <span class="micro">for “{{ $suggestions['terms'] }}”</span>
                    @endif
                </div>

                <div class="rounded-card bg-surface p-2 shadow-soft-md dark:bg-surface-dark">
                    @foreach ($suggestions['sounds'] as $sound)
                        <x-sound-row :sound="$sound" :bars="90" />
                    @endforeach
                </div>

                @if ($suggestions['guessed'])
                    <div class="mt-3 px-1 text-center">
                        <a href="{{ route('sounds.index', ['q' => $suggestions['terms']]) }}"
                           class="text-[0.85rem] text-brand underline underline-offset-2 hover:opacity-80">
                            See everything for “{{ $suggestions['terms'] }}”
                        </a>
                    </div>
                @endif
            </div>
        @endif

        {{-- ══════════════════════════════════════════════════════════════
             WAYS OUT
             ══════════════════════════════════════════════════════════════ --}}
        <div class="mt-12 flex flex-wrap justify-center gap-3">
            <a href="{{ route('sounds.index') }}" wire:navigate
               class="rounded-full bg-surface px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-200 ease-dbelo hover:-translate-y-0.5 dark:bg-surface-dark">
                Browse sounds
            </a>

            @if (Route::has('packs.index'))
                <a href="{{ route('packs.index') }}" wire:navigate
                   class="rounded-full bg-surface px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-200 ease-dbelo hover:-translate-y-0.5 dark:bg-surface-dark">
                    Packs
                </a>
            @endif

            <a href="{{ route('home') }}" wire:navigate
               class="rounded-full bg-surface px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-200 ease-dbelo hover:-translate-y-0.5 dark:bg-surface-dark">
                Home
            </a>
        </div>

        {{-- The address that failed, quoted back.

             Worth showing: half of all 404s are a truncated copy-paste, and
             seeing the URL is how somebody notices their link lost its last
             three characters. Escaped by Blade — this string came from the
             address bar and is not to be trusted. --}}
        @if ($path !== '')
            <p class="mt-8 text-center text-[0.76rem] text-ink/25 dark:text-paper/25">
                Requested: <code class="font-mono">/{{ \Illuminate\Support\Str::limit($path, 90) }}</code>
            </p>
        @endif
    </div>
</x-layouts::site>
