{{--
    Two volumes, because there are two situations.

    The first version had one: amber, every time. During active work the
    build falls behind the moment a file is written, so it was on almost
    permanently — and it took about an hour to become wallpaper. That is the
    exact failure this project has argued against three times: a badge that
    never goes dark is a badge nobody reads, which is how the real one gets
    missed. It was doing it to itself.

    So:

      Under a day    ordinary drift between one build and the next. A quiet
                     grey line that says what to run. No colour, no alarm.
      A day or more  somebody has been looking at a stale site for a day
                     without realising, which is the thing worth shouting
                     about.

    Both lead with the root cause rather than the symptom. "Run npm run
    build" fixes it once and it comes back on the next change; Vite watching
    is what actually ends it.

    Inline colours and base classes only: a notice about missing classes must
    not be written in the classes that are missing.
--}}
@php
    $behind = app(\App\Services\Diagnostics::class)->assetsBehind();

    $loud = $behind >= 1440;

    $unit = fn (int $n, string $word) => $n.' '.\Illuminate\Support\Str::plural($word, $n);

    $age = match (true) {
        $behind === PHP_INT_MAX => 'never built',
        $behind >= 1440 => $unit((int) round($behind / 1440), 'day').' behind',
        $behind >= 60 => $unit((int) round($behind / 60), 'hour').' behind',
        default => $unit($behind, 'minute').' behind',
    };
@endphp

@if ($behind > 0)
    @if ($loud)
        <div style="background:#ffa314; color:#0d0b10;" class="px-4 py-2.5 text-center text-sm">
            <strong>The styles have not been rebuilt for a day.</strong>
            Anything written since then renders with no CSS at all — not broken, unpainted.
            Run
            <code style="background:rgba(0,0,0,.14); padding:1px 6px; border-radius:5px;">./dev.sh</code>
            and leave it running.
            <span style="opacity:.7;">— {{ $age }}</span>
            <a href="{{ route('admin.diagnostics') }}" wire:navigate style="text-decoration: underline;">Details</a>
        </div>
    @else
        <div style="background:rgba(244,241,246,.05); color:rgba(244,241,246,.45); border-bottom:1px solid rgba(244,241,246,.07);"
             class="px-4 py-1.5 text-center text-xs">
            Styles are {{ $age }} — Vite is not watching, so anything added since the last build is unstyled.
            Run <code style="opacity:.85;">./dev.sh</code> to stop seeing this.
        </div>
    @endif
@endif
