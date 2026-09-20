@props([
    /** The author's half of the title. The site's half is added here. */
    'title' => '',
    'description' => '',
    /** The page's real address. The breadcrumb is built from it. */
    'url' => '',
])

@php
    $full = \App\Support\Serp::fullTitle($title);
    $parts = parse_url($url);
    $host = $parts['host'] ?? \App\Support\Serp::siteName();
    $crumbs = array_values(array_filter(explode('/', trim($parts['path'] ?? '', '/'))));

    $bars = [
        ['Title', $full, 'title'],
        ['Description', $description, 'description'],
    ];
@endphp

{{--
    A search result, drawn the way Google draws one.

    ── THE SITE NAME IS NOT DECORATION ──────────────────────────────────────

    partials/head.blade.php emits "<what the author wrote> — dbelo", and the
    preview used to show only the first half. Eight characters the author
    could not see were counted against them by the only judge that matters,
    and a preview that is wrong about the length is worse than no preview:
    it is a number somebody trusted.

    ── THE BARS MEASURE WIDTH, NOT CHARACTERS ───────────────────────────────

    Google cuts by width. Two titles of eighteen characters — one of them all
    lowercase l's and i's — are nowhere near the same size on screen, so a
    counter that reports 18/60 for both has told one of them something false.
    App\Support\Serp weighs the letters. See the note there about what the
    number is and, more importantly, what it is not.

    ── WHY SHORT IS ORANGE AND NOT RED ──────────────────────────────────────

    A short description is not a mistake, it is unused space — a third of the
    only line where this page argues for itself, left blank. Worth noticing,
    not worth stopping for. Over the line is the same colour for the mirror
    reason: sometimes a cut tail is a fair price for a strong opening.
--}}
<div {{ $attributes->merge(['class' => 'rounded-xl bg-raised p-4']) }}>

    {{-- ── The result ─────────────────────────────────────────────── --}}
    <div class="flex items-center gap-2.5">
        {{-- Where the favicon goes. The initial rather than the real file,
             because this is a shape to read the layout by, not a preview of
             the icon — and a broken image here would look like a fault in
             the page being edited. --}}
        <span class="grid size-6 shrink-0 place-items-center rounded-full bg-paper/10 text-[0.62rem] font-semibold uppercase text-paper/60">
            {{ mb_substr(\App\Support\Serp::siteName(), 0, 1) }}
        </span>

        <span class="min-w-0">
            <span class="block truncate text-[0.78rem] leading-tight text-paper/70">{{ \App\Support\Serp::siteName() }}</span>
            <span class="block truncate text-[0.72rem] leading-tight text-paper/35">
                {{ $host }}@foreach ($crumbs as $crumb) <span class="text-paper/20">›</span> {{ $crumb }}@endforeach
            </span>
        </span>
    </div>

    <div class="mt-2 truncate text-[1rem] text-info">{{ $full }}</div>

    <p class="mt-1 line-clamp-2 text-[0.82rem] leading-relaxed text-paper/45">
        {{ $description !== '' ? $description : 'No description yet — Google will pick two lines out of the article, and they will not be the two you would have chosen.' }}
    </p>

    {{-- ── How much of each line is used ───────────────────────────── --}}
    <div class="mt-4 space-y-2.5 border-t border-hairline pt-3.5">
        @foreach ($bars as [$label, $text, $kind])
            @php
                $fill = \App\Support\Serp::fill($text, $kind);
                $verdict = \App\Support\Serp::verdict($text, $kind);
                $percent = min(100, (int) round($fill * 100));
            @endphp

            <div class="flex items-center gap-3">
                <span class="w-20 shrink-0 text-[0.7rem] uppercase tracking-[0.12em] text-paper/30">{{ $label }}</span>

                <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-paper/[0.07]">
                    <span @class([
                        'block h-full rounded-full transition-[width] duration-300',
                        'bg-success' => $verdict === 'good',
                        'bg-warning' => $verdict === 'short',
                        'bg-danger' => $verdict === 'long',
                    ]) style="width: {{ $percent }}%"></span>
                </span>

                <span @class([
                    'w-24 shrink-0 text-right text-[0.7rem] tabular-nums',
                    'text-success' => $verdict === 'good',
                    'text-warning' => $verdict === 'short',
                    'text-danger' => $verdict === 'long',
                ])>
                    {{ match ($verdict) {
                        'good' => 'Fits',
                        'short' => 'Room left',
                        default => 'Will be cut',
                    } }}
                </span>
            </div>
        @endforeach
    </div>
</div>
