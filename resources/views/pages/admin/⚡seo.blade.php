<?php

use App\Services\SeoAudit;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('SEO')] class extends Component {
    public bool $scannedLinks = false;

    /** @var array<int, array<string, mixed>> */
    public array $brokenLinks = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    #[Computed]
    public function findings(): array
    {
        return app(SeoAudit::class)->findings();
    }

    #[Computed]
    public function sitemap(): array
    {
        return app(SeoAudit::class)->sitemap();
    }

    #[Computed]
    public function robots(): array
    {
        return app(SeoAudit::class)->robots();
    }

    /**
     * On demand, never on load.
     *
     * Every internal link in every published page gets matched against the
     * route table and, where the route takes a model, looked up. That is
     * cheap per link and not cheap per hundred — and a screen that does it
     * on every render is a screen that gets slower as the blog grows, for
     * an answer that changes once a week.
     */
    public function scanLinks(): void
    {
        $this->brokenLinks = app(SeoAudit::class)->brokenLinks();
        $this->scannedLinks = true;
    }
}; ?>

<div class="space-y-5">

    {{-- ══════════════════════════════════════════════════════════════
         THE AUDIT

         Not a checklist of SEO features — one question: which pages are
         wasting their chance to rank. Every row is computed from the
         database, with no crawler and no external service, because the
         things that actually keep a catalogue out of the results are all
         visible from the inside.
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel">
        <div class="border-b border-hairline px-5 py-4">
            <h2 class="text-[0.95rem] font-medium">What is holding pages back</h2>
            <p class="mt-0.5 max-w-[74ch] text-[0.78rem] leading-relaxed text-paper/40">
                Worst first, then by how many pages it affects — so you can work down and stop when you run out of
                evening. Each one links to the screen where it gets fixed; none of them is editable here, because the
                meta title of a sound belongs on the form next to the sound.
            </p>
        </div>

        <div class="divide-y divide-hairline">
            @forelse ($this->findings as $finding)
                @php
                    [$dot, $chip] = match ($finding['severity']) {
                        'high' => ['bg-danger', 'bg-danger/15 text-danger'],
                        'medium' => ['bg-warning', 'bg-warning/15 text-warning'],
                        default => ['bg-info', 'bg-info/15 text-info'],
                    };
                @endphp

                <div class="flex items-start gap-3.5 px-5 py-4" wire:key="f-{{ $finding['key'] }}">
                    <span class="mt-2 size-2 shrink-0 rounded-full {{ $dot }}"></span>

                    <span class="grid size-11 shrink-0 place-items-center rounded-full {{ $chip }} text-[0.95rem] font-semibold tabular-nums">
                        {{ number_format($finding['count']) }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="text-[0.9rem] text-paper/85">{{ $finding['label'] }}</div>
                        <p class="mt-1.5 max-w-[76ch] text-[0.82rem] leading-relaxed text-paper/45">{{ $finding['why'] }}</p>
                    </div>

                    @if ($finding['route'])
                        <a href="{{ route($finding['route']) }}" wire:navigate
                           class="shrink-0 rounded-lg bg-raised px-3 py-1.5 text-[0.78rem] transition hover:bg-brand hover:text-white">
                            Fix
                        </a>
                    @endif
                </div>
            @empty
                <div class="px-5 py-14 text-center">
                    <x-icon name="circle-check" style="solid" class="text-[24px] text-success" />
                    <p class="mt-3 text-[0.9rem] text-paper/45">Nothing to fix</p>
                    <p class="mx-auto mt-1 max-w-[54ch] text-[0.8rem] leading-relaxed text-paper/30">
                        On an empty catalogue this is also what "nothing published yet" looks like.
                    </p>
                </div>
            @endforelse
        </div>
    </div>

    <div class="grid gap-5 xl:grid-cols-2">

        {{-- ═══════════════════ SITEMAP ═══════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="flex items-center justify-between gap-3 border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">Sitemap</h2>
                <a href="{{ $this->sitemap['url'] }}" target="_blank"
                   class="shrink-0 rounded-lg bg-raised px-3 py-1.5 text-[0.78rem] transition hover:bg-brand hover:text-white">
                    Open
                </a>
            </div>

            <div class="px-5 py-5">
                <div class="text-[2rem] font-semibold leading-none tracking-[-0.03em]">
                    {{ number_format($this->sitemap['total']) }}
                    <span class="text-[0.85rem] font-normal text-paper/40">URLs</span>
                </div>

                <div class="mt-4 space-y-1.5 text-[0.82rem] text-paper/50">
                    @foreach ($this->sitemap['counts'] as $kind => $count)
                        <div class="flex items-center justify-between" wire:key="sm-{{ $kind }}">
                            <span class="capitalize">{{ $kind }}</span>
                            <span class="tabular-nums">{{ number_format($count) }}</span>
                        </div>
                    @endforeach
                </div>

                <p class="mt-4 text-[0.78rem] leading-relaxed text-paper/35">
                    Regenerated on request and cached. Packs were missing from it until today — it was written before
                    they existed, and nothing noticed, which is the argument for this screen in one sentence.
                </p>
            </div>
        </div>

        {{-- ═══════════════════ ROBOTS ═══════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">robots.txt</h2>
            </div>

            <div class="px-5 py-5">
                @if (! $this->robots['exists'])
                    <p class="text-[0.85rem] text-danger">There is no robots.txt in public/.</p>
                @else
                    @if ($this->robots['blocksEverything'])
                        <div class="mb-4 rounded-xl bg-danger/10 px-4 py-3 text-[0.83rem] leading-relaxed text-paper/70">
                            <x-icon name="triangle-exclamation" style="solid" class="mr-1.5 text-[0.78rem] text-danger" />
                            This file contains <code class="font-mono">Disallow: /</code> — the whole site is closed to
                            search engines.
                        </div>
                    @endif

                    {{-- The Sitemap line is an absolute URL typed by hand. It
                         is right in production and quietly wrong everywhere
                         else, and "quietly" is the problem: nothing fails, the
                         file simply points at another site. --}}
                    <div @class([
                        'rounded-xl px-4 py-3 text-[0.83rem] leading-relaxed',
                        'bg-success/10 text-paper/70' => $this->robots['matches'],
                        'bg-warning/10 text-paper/70' => ! $this->robots['matches'],
                    ])>
                        <x-icon :name="$this->robots['matches'] ? 'circle-check' : 'triangle-exclamation'" style="solid"
                                class="mr-1.5 text-[0.78rem] {{ $this->robots['matches'] ? 'text-success' : 'text-warning' }}" />

                        @if ($this->robots['matches'])
                            The Sitemap line matches this site.
                        @else
                            The Sitemap line says
                            <code class="break-all font-mono text-[0.76rem]">{{ $this->robots['declared'] ?? 'nothing' }}</code>,
                            but this site is at
                            <code class="break-all font-mono text-[0.76rem]">{{ $this->robots['expected'] }}</code>.
                            Correct in production, wrong here — worth knowing which one you are looking at.
                        @endif
                    </div>

                    <pre class="mt-4 max-h-56 overflow-auto rounded-lg bg-rail px-4 py-3 font-mono text-[0.72rem] leading-relaxed text-paper/55">{{ $this->robots['contents'] }}</pre>

                    <p class="mt-3 text-[0.78rem] leading-relaxed text-paper/35">
                        A static file, edited in the repository rather than here. It changes twice a year and a mistake
                        in it can delist the whole site — that is a change worth a commit and a diff, not a textarea.
                    </p>
                @endif
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         INTERNAL BROKEN LINKS
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-4">
            <div>
                <h2 class="text-[0.95rem] font-medium">Broken links inside your own pages</h2>
                <p class="mt-0.5 max-w-[76ch] text-[0.78rem] leading-relaxed text-paper/40">
                    Not the 404s on the Redirects screen — those are the ones a <em>visitor</em> hit. These are the
                    ones nobody has hit yet, sitting in a published post, waiting for a crawler to follow them and
                    conclude the site is poorly kept.
                </p>
            </div>

            <button type="button" wire:click="scanLinks" wire:loading.attr="disabled" wire:target="scanLinks"
                    class="flex shrink-0 items-center gap-2 rounded-lg bg-raised px-3.5 py-2 text-[0.82rem] transition hover:bg-brand hover:text-white disabled:opacity-50">
                <x-icon name="link-slash" style="solid" class="text-[0.75rem]" />
                <span wire:loading.remove wire:target="scanLinks">Scan</span>
                <span wire:loading wire:target="scanLinks">Scanning…</span>
            </button>
        </div>

        @if ($scannedLinks && $brokenLinks)
            <div class="divide-y divide-hairline">
                @foreach ($brokenLinks as $i => $link)
                    <div class="flex items-center gap-3.5 px-5 py-3" wire:key="bl-{{ $i }}">
                        <x-admin.icon-chip icon="link-slash" tone="muted" />

                        <div class="min-w-0 flex-1">
                            <div class="truncate text-[0.86rem] text-paper/75">{{ $link['post'] }}</div>
                            <div class="truncate font-mono text-[0.74rem] text-danger">{{ $link['href'] }}</div>
                        </div>

                        <a href="{{ route($link['type'] === 'page' ? 'admin.pages.edit' : 'admin.blog.edit', $link['id']) }}"
                           wire:navigate
                           class="shrink-0 rounded-lg bg-raised px-3 py-1.5 text-[0.78rem] transition hover:bg-brand hover:text-white">
                            Edit
                        </a>
                    </div>
                @endforeach
            </div>
        @elseif ($scannedLinks)
            <div class="px-5 py-12 text-center">
                <x-icon name="circle-check" style="solid" class="text-[22px] text-success" />
                <p class="mt-2.5 text-[0.88rem] text-paper/45">Every internal link resolves.</p>
            </div>
        @else
            <p class="px-5 py-12 text-center text-[0.85rem] text-paper/35">Not scanned yet.</p>
        @endif
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         WHAT THIS SCREEN DOES NOT DO
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel px-5 py-4">
        <p class="text-[0.78rem] leading-relaxed text-paper/40">
            <x-icon name="circle-info" style="solid" class="mr-1 text-[0.72rem] text-info" />
            <strong class="text-paper/60">No Google data here, and none until the site is public.</strong>
            Impressions, positions and what Google has actually indexed all come from Search Console, which needs a
            verified domain and an authorised account — and until dbelo.com is live there would be nothing to read.
            Everything above is the half you control from the inside, which is also the half that has to be right
            first: a page with no description does not rank whatever Search Console says about it.
        </p>
    </div>
</div>
