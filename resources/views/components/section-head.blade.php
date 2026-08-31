@props([
    'eyebrow',
    'title',
    'lead' => null,
    'delay' => 0,
])

<div class="rise mb-6" style="animation-delay: {{ $delay }}ms">
    <div class="micro">{{ $eyebrow }}</div>

    <div class="mt-2 flex flex-wrap items-end justify-between gap-4">
        <h2 class="text-[clamp(1.5rem,3vw,2rem)] font-semibold">{!! $title !!}</h2>

        {{ $action ?? '' }}
    </div>

    @if ($lead)
        <p class="mt-2 max-w-[62ch] text-[0.95rem] leading-relaxed text-ink/55 dark:text-paper/55">{{ $lead }}</p>
    @endif
</div>
