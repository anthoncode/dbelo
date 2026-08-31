<x-legal.shell title="Licenses" subtitle="What you can do with each sound, in plain language.">

    <p>Every sound in dbelo carries one of these licences. Which one applies is shown on the sound's page, and a copy of the exact text is attached to each download — so a change to a licence never affects a file you already have.</p>

    @foreach ($licenses as $license)
        <h2 id="{{ $license->slug }}">{{ $license->name }} <span class="text-base font-normal text-ink/45 dark:text-paper/45">v{{ $license->version }}</span></h2>

        <p>{{ $license->summary }}</p>

        <div class="my-5 flex flex-wrap gap-2.5">
            <span class="flex items-center gap-2 rounded-full bg-paper px-4 py-2 text-[0.83rem] shadow-soft-sm dark:bg-paper/10 {{ $license->allows_commercial ? '' : 'opacity-45' }}">
                <span class="{{ $license->allows_commercial ? 'text-brand' : '' }} font-semibold">{{ $license->allows_commercial ? '✓' : '✕' }}</span>
                Commercial use
            </span>
            <span class="flex items-center gap-2 rounded-full bg-paper px-4 py-2 text-[0.83rem] shadow-soft-sm dark:bg-paper/10">
                <span class="{{ $license->requires_attribution ? '' : 'text-brand' }} font-semibold">{{ $license->requires_attribution ? '!' : '✓' }}</span>
                {{ $license->requires_attribution ? 'Credit required' : 'No credit required' }}
            </span>
            <span class="flex items-center gap-2 rounded-full bg-paper px-4 py-2 text-[0.83rem] shadow-soft-sm dark:bg-paper/10 {{ $license->allows_derivatives ? '' : 'opacity-45' }}">
                <span class="{{ $license->allows_derivatives ? 'text-brand' : '' }} font-semibold">{{ $license->allows_derivatives ? '✓' : '✕' }}</span>
                Modifications allowed
            </span>
        </div>

        @if ($license->full_text)
            <div class="whitespace-pre-line text-[0.92rem] leading-relaxed text-ink/70 dark:text-paper/70">{{ $license->full_text }}</div>
        @endif

        @if ($license->url)
            <p><a href="{{ $license->url }}" target="_blank" rel="noopener">Read the official text</a></p>
        @endif
    @endforeach

</x-legal.shell>
