{{--
    The strip above the navigation.

    Every condition that decides whether this appears lives in
    App\Support\Homepage::bar(), not here — five conditions spread through a
    Blade file is a banner nobody can predict, and "why is it not showing?"
    then has five answers in five places instead of one.

    Colours are written out in full rather than assembled from the tone name.
    Tailwind only emits a class it saw in a source file at build time, so
    "bg-{$tone}" exists at runtime and has never existed in the stylesheet —
    the bar would render with no colour at all and nothing would report it.
--}}
@props([
    /**
     * Normally null: the component asks Homepage::bar() what to render and
     * gets null back when any of the nine conditions says no.
     *
     * The settings screen passes one in instead, built from the boxes as
     * they are being typed. Same markup either way — a preview drawn from a
     * second copy of this template is a preview that stops matching the
     * thing it previews, usually the day somebody restyles one of them.
     */
    'bar' => null,

    /** In the preview the close button must not set a real cookie. */
    'preview' => false,
])

@php
    $bar ??= \App\Support\Homepage::bar();
@endphp

@if ($bar)
    @php
        [$surface, $buttonClass] = match ($bar['tone']) {
            'action' => ['bg-action text-white', 'bg-white/20 hover:bg-white/30'],
            'info' => ['bg-info text-white', 'bg-white/20 hover:bg-white/30'],
            'success' => ['bg-success text-ink', 'bg-ink/15 hover:bg-ink/25'],
            'warning' => ['bg-warning text-ink', 'bg-ink/15 hover:bg-ink/25'],
            default => ['bg-brand text-white', 'bg-white/20 hover:bg-white/30'],
        };
    @endphp

    <div
        @if ($bar['dismissible'])
            x-data="{
                show: true,

                close() {
                    this.show = false;

                    @if ($preview) return; @endif

                    // A year, path-wide, SameSite=Lax. Written from here
                    // rather than through a route: a round trip to record
                    // that somebody closed a banner is a round trip nobody
                    // asked for, and the server reads it on the next request
                    // so the bar never flashes back.
                    document.cookie = '{{ \App\Support\Homepage::BAR_COOKIE }}={{ $bar['hash'] }}; path=/; max-age=31536000; SameSite=Lax';
                },
            }"
            x-show="show"
            x-transition.opacity.duration.200ms
        @endif
        class="{{ $surface }}"
    >
        <div class="mx-auto flex max-w-[1160px] flex-wrap items-center justify-center gap-x-4 gap-y-2 px-6 py-2.5 text-center">

            <span class="flex min-w-0 items-center gap-2.5">
                <x-icon :name="$bar['icon']" style="solid" class="shrink-0 text-[0.8rem] opacity-80" />
                <span class="text-[0.87rem]">{{ $bar['message'] }}</span>
            </span>

            @if ($bar['button'] && $bar['link'])
                @if ($bar['external'])
                    {{-- rel=noopener is not optional on a target=_blank link:
                         without it the page you opened can reach back through
                         window.opener and navigate the tab it came from. --}}
                    <a href="{{ $bar['link'] }}" target="_blank" rel="noopener noreferrer"
                       class="shrink-0 rounded-full {{ $buttonClass }} px-4 py-1 text-[0.8rem] font-medium transition">
                        {{ $bar['button'] }}
                    </a>
                @else
                    <a href="{{ $bar['link'] }}" wire:navigate
                       class="shrink-0 rounded-full {{ $buttonClass }} px-4 py-1 text-[0.8rem] font-medium transition">
                        {{ $bar['button'] }}
                    </a>
                @endif
            @endif

            @if ($bar['dismissible'])
                <button type="button" x-on:click="close()" aria-label="Close"
                        class="ml-1 grid size-6 shrink-0 place-items-center rounded-full opacity-60 transition hover:bg-black/10 hover:opacity-100">
                    <x-icon name="xmark" style="solid" class="text-[0.75rem]" />
                </button>
            @endif
        </div>
    </div>
@endif
