@props(['title', 'subtitle' => null])

<x-layouts::site>
    <div class="mx-auto max-w-3xl py-6">

        <div class="mb-9">
            <div class="micro">Legal</div>
            <h1 class="mt-2 text-3xl font-semibold">{{ $title }}</h1>
            @if ($subtitle)
                <p class="mt-2 text-ink/60 dark:text-paper/60">{{ $subtitle }}</p>
            @endif
            <p class="micro mt-3">Effective {{ \Illuminate\Support\Carbon::parse(config('dbelo.legal.effective_date'))->format('F j, Y') }}</p>
        </div>

        <div class="rounded-card bg-surface p-8 shadow-soft-md dark:bg-surface-dark
                    [&_h2]:mb-3 [&_h2]:mt-9 [&_h2]:text-xl [&_h2]:font-semibold first:[&_h2]:mt-0
                    [&_h3]:mb-2 [&_h3]:mt-6 [&_h3]:font-medium
                    [&_p]:mb-4 [&_p]:leading-relaxed [&_p]:text-ink/70 dark:[&_p]:text-paper/70
                    [&_li]:mb-2 [&_li]:leading-relaxed [&_li]:text-ink/70 dark:[&_li]:text-paper/70
                    [&_ul]:mb-4 [&_ul]:ml-5 [&_ul]:list-disc
                    [&_strong]:font-medium [&_strong]:text-ink dark:[&_strong]:text-paper
                    [&_a]:text-brand [&_a]:underline">
            {{ $slot }}
        </div>

        <div class="mt-8 flex flex-wrap gap-3">
            <a href="{{ route('legal.terms') }}" wire:navigate class="rounded-full bg-surface px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-surface-dark">Terms</a>
            <a href="{{ route('legal.privacy') }}" wire:navigate class="rounded-full bg-surface px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-surface-dark">Privacy</a>
            <a href="{{ route('legal.licenses') }}" wire:navigate class="rounded-full bg-surface px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-surface-dark">Licenses</a>
            <a href="{{ route('legal.contributor') }}" wire:navigate class="rounded-full bg-surface px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-surface-dark">Contributors</a>
            <a href="{{ route('claims.create') }}" wire:navigate class="rounded-full bg-surface px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-surface-dark">Copyright</a>
        </div>
    </div>
</x-layouts::site>
