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

    ── AND NOW THIS FILE DECIDES NOTHING ───────────────────────────────────

    It used to hold its own copy of the 1440-minute threshold, its own age
    formatter and its own hardcoded "./dev.sh". All three already existed in
    App\Services\Diagnostics.

    That cost a day of a live site shouting the wrong instruction. The
    remedy was corrected for a server with no Node, in Diagnostics — and
    this banner, which is the surface anybody actually reads, kept printing
    "Run ./dev.sh and leave it running" across the top of every admin page
    on dbelo.com, where there is no Vite to run and no dev server to leave
    running. One definition, two surfaces, and only one of them fixed: the
    failure this codebase has a name for, committed in the file that warns
    about it.

    So the sentence, the threshold and the command now come from
    Diagnostics::assetsReport() and this file renders them. There is nothing
    left here that can disagree with the Details page, because there is only
    one of each.

    Inline colours and base classes only: a notice about missing classes must
    not be written in the classes that are missing.
--}}
@php
    $assets = app(\App\Services\Diagnostics::class)->assetsReport();
@endphp

@if ($assets['behind'] > 0)
    @if ($assets['loud'])
        <div style="background:#ffa314; color:#0d0b10;" class="px-4 py-2.5 text-center text-sm">
            {{-- The headline bold, because on a wide screen the eye takes
                 that and nothing else. Two keys rather than one string split
                 on its first full stop: see the note in assetsReport(). --}}
            <strong>{{ $assets['headline'] }}</strong>
            {{ $assets['detail'] }}
            <span style="opacity:.7;">— {{ $assets['ageLabel'] }}</span>
            <a href="{{ route('admin.diagnostics') }}" wire:navigate style="text-decoration: underline;">Details</a>
        </div>
    @else
        {{-- The quiet one names the command and nothing else. Under a day
             this is drift, and drift does not need a paragraph. --}}
        <div style="background:rgba(244,241,246,.05); color:rgba(244,241,246,.45); border-bottom:1px solid rgba(244,241,246,.07);"
             class="px-4 py-1.5 text-center text-xs">
            Styles are {{ $assets['ageLabel'] }} — anything added since the last build is unstyled.
            Run <code style="opacity:.85;">{{ $assets['command'] }}</code> to stop seeing this.
        </div>
    @endif
@endif
