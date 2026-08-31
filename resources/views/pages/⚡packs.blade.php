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
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($this->packs as $i => $pack)
                <x-tile :href="route('packs.show', $pack)"
                        :title="$pack->name"
                        :subtitle="Str::limit($pack->description, 110)"
                        :icon="$this->iconFor($pack)"
                        :meta="$pack->sounds_count.' '.Str::plural('sound', $pack->sounds_count)"
                        :delay="50 * $i"
                        wire:key="pack-{{ $pack->id }}" />
            @endforeach
        </div>
    @endif
</div>
