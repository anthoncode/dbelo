<?php

use App\Models\Collection as Pack;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.site')] #[Title('Sound effect packs')] class extends Component {

    protected const ICONS = [
        'podcast' => 'microphone',
        'youtube' => 'clapperboard',
        'game-ui' => 'gamepad',
        'trailer' => 'film',
        'cinematic' => 'film',
        'notifications' => 'bell',
        'horror' => 'ghost',
        'nature' => 'leaf',
        'foley' => 'shoe-prints',
    ];

    public function mount(): void
    {
        view()->share('seo', [
            'title' => 'Sound effect packs',
            'description' => 'Curated sound effect packs for podcasts, video, games and trailers. Every sound free to preview, cleared for commercial use.',
            'canonical' => route('packs.index'),
        ]);
    }

    #[Computed]
    public function packs()
    {
        return Pack::featured()
            ->withCount(['sounds' => fn ($q) => $q->published()])
            ->orderByDesc('sounds_count')
            ->get();
    }

    /**
     * Packs that exist but are not on the page.
     *
     * ── WHY AN ADMIN SEES A LINE NOBODY ELSE DOES ────────────────────────
     *
     * featured() means is_featured AND is_public, and a pack is created
     * featured-but-hidden on purpose so an empty one is never reachable. The
     * failure that follows is a quiet one: you build a pack, you forget the
     * second switch, and the page says "No packs yet" — which reads as "none
     * exist", not as "yours are hidden".
     *
     * The count is only ever computed for an admin, and the notice only ever
     * rendered for one. A visitor must not learn that there are pages they
     * cannot see.
     */
    #[Computed]
    public function hiddenCount(): int
    {
        if (! auth()->user()?->isAdmin()) {
            return 0;
        }

        return Pack::packs()->where('is_public', false)->count();
    }

    public function iconFor($pack): string
    {
        return self::ICONS[$pack->slug] ?? 'box-open';
    }
}; ?>

<div>
    <section class="relative py-12 text-center">
        <div class="pointer-events-none absolute inset-x-0 top-6 -z-10 mx-auto max-w-[640px] px-6 opacity-45"
             style="mask-image: linear-gradient(to right, transparent, #000 25%, #000 75%, transparent);
                    -webkit-mask-image: linear-gradient(to right, transparent, #000 25%, #000 75%, transparent);">
            <x-ambient-wave :bars="52" height="h-20" />
        </div>

        <h1 class="rise text-[clamp(2.1rem,5vw,3.2rem)] font-bold">
            Packs for what you are <span class="key">making</span>
        </h1>

        <p class="rise mx-auto mt-5 max-w-[58ch] text-[1.02rem] leading-relaxed text-ink/60 dark:text-paper/60"
           style="animation-delay: 80ms">
            Categories tell you what a sound <em>is</em>. A pack is pulled from across all of them for one job —
            everything a podcast opener, a game menu or a trailer cut actually needs, in one place.
        </p>
    </section>

    @if ($this->hiddenCount > 0)
        <div class="mb-6 flex flex-wrap items-center gap-3 rounded-card bg-warning/[0.08] px-5 py-4 text-[0.88rem]">
            <x-icon name="eye-slash" style="solid" class="text-[0.8rem] text-warning" />
            <span>
                {{ $this->hiddenCount }} {{ Str::plural('pack', $this->hiddenCount) }}
                {{ $this->hiddenCount === 1 ? 'is' : 'are' }} not public yet, so
                {{ $this->hiddenCount === 1 ? 'it does' : 'they do' }} not appear here.
            </span>
            <a href="{{ route('admin.packs') }}" wire:navigate
               class="ml-auto inline-flex items-center gap-2 rounded-full bg-ink px-4 py-2 text-[0.8rem] text-paper transition hover:-translate-y-0.5 dark:bg-paper/10">
                Manage packs
                <x-icon name="arrow-right" style="solid" class="text-[0.66rem]" />
            </a>
        </div>
    @endif

    @if ($this->packs->isEmpty())
        <div class="rounded-card bg-surface py-20 text-center shadow-soft-md dark:bg-surface-dark">
            <x-icon name="box-open" style="regular" class="text-[28px] text-ink/15 dark:text-paper/15" />
            <p class="mt-3 text-lg">No packs yet</p>
            <p class="mx-auto mt-1.5 max-w-[40ch] text-sm text-ink/50 dark:text-paper/50">
                They are being put together. In the meantime the whole catalogue is open.
            </p>
            <a href="{{ route('sounds.index') }}" wire:navigate
               class="mt-6 inline-flex items-center gap-2 rounded-full bg-action px-6 py-3 text-[0.88rem] font-medium text-white transition duration-300 ease-dbelo hover:-translate-y-0.5">
                Browse all sounds
                <x-icon name="arrow-right" style="solid" class="text-[0.7rem]" />
            </a>
        </div>
    @else
        {{-- Covers rather than tiles. A pack is a thing you choose by the
             look of it; a text row is a thing you read, and twelve text rows
             is a list of names nobody picks from. --}}
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->packs as $i => $pack)
                <x-pack-card :pack="$pack"
                             :icon="$this->iconFor($pack)"
                             :delay="50 * $i"
                             wire:key="pack-{{ $pack->id }}" />
            @endforeach
        </div>
    @endif
</div>
