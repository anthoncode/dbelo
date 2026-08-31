<?php

use App\Models\Collection as Pack;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.site')] #[Title('Sound effect pack')] class extends Component {
    public Pack $pack;

    /**
     * Looked up by hand rather than through route model binding.
     *
     * The binding would resolve any collection, and most collections are
     * somebody's private folder. Scoping the lookup here means a private
     * one is a 404 at this URL instead of a leak — the check cannot be
     * forgotten because it IS the lookup.
     */
    public function mount(string $pack): void
    {
        $this->pack = Pack::featured()->where('slug', $pack)->firstOrFail();

        view()->share('seo', [
            'title' => $this->pack->name.' sound effects',
            'description' => $this->pack->description
                ?: sprintf('%s — a curated sound effect pack. Free to preview, cleared for commercial use.', $this->pack->name),
            'canonical' => route('packs.show', $this->pack),
        ]);
    }

    #[Computed]
    public function sounds()
    {
        return $this->pack->sounds()
            ->published()
            ->with(['files', 'user:id,name', 'tags:id,name', 'category:id,name'])
            ->get();
    }

    /** Total running time, which is what a buyer actually wants to know. */
    #[Computed]
    public function totalDuration(): string
    {
        $seconds = (int) round($this->sounds->sum('duration_ms') / 1000);

        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);

        return $minutes >= 60
            ? intdiv($minutes, 60).'h '.($minutes % 60).'m'
            : $minutes.'m '.($seconds % 60).'s';
    }

    public function title(): string
    {
        return $this->pack->name;
    }
}; ?>

<div class="mx-auto max-w-4xl">

    <nav class="mb-6 flex items-center gap-2 text-sm text-ink/50 dark:text-paper/50">
        <a href="{{ route('packs.index') }}" wire:navigate class="transition hover:text-brand">Packs</a>
        <x-icon name="chevron-right" style="solid" class="text-[0.6rem] opacity-50" />
        <span>{{ $pack->name }}</span>
    </nav>

    <div class="rise relative overflow-hidden rounded-panel bg-ink p-8 text-paper shadow-soft-lg sm:p-10 dark:bg-surface-dark">
        {{-- Masked toward the top-left, where the title and description are.
             Same reason as on x-tile: corner texture that dissolves before
             it reaches the text cannot collide with a long pack name. --}}
        <span aria-hidden="true"
              style="mask-image: linear-gradient(to top left, #000 15%, transparent 78%);
                     -webkit-mask-image: linear-gradient(to top left, #000 15%, transparent 78%);"
              class="pointer-events-none absolute -bottom-8 -right-7 text-[150px] leading-none text-paper/[0.05]">
            <x-icon name="box-open" style="solid" class="leading-none" />
        </span>

        <div class="relative">
            <div class="micro !text-paper/40">Pack</div>
            <h1 class="mt-2 text-[clamp(1.8rem,4vw,2.6rem)] font-semibold">{{ $pack->name }}</h1>

            @if ($pack->description)
                <p class="mt-4 max-w-[58ch] text-[0.98rem] leading-relaxed text-paper/65">{{ $pack->description }}</p>
            @endif

            <div class="mt-7 flex flex-wrap items-center gap-x-6 gap-y-3">
                <span class="flex items-center gap-2 text-[0.88rem] text-paper/65">
                    <x-icon name="waveform-lines" style="solid" class="text-[0.8rem] text-paper/30" />
                    {{ $this->sounds->count() }} {{ Str::plural('sound', $this->sounds->count()) }}
                </span>

                <span class="flex items-center gap-2 text-[0.88rem] text-paper/65">
                    <x-icon name="clock" style="solid" class="text-[0.8rem] text-paper/30" />
                    {{ $this->totalDuration }} in total
                </span>

                <div class="ml-auto">
                    <x-share-menu :url="route('packs.show', $pack)" :title="$pack->name" tone="dark" />
                </div>
            </div>
        </div>
    </div>

    <div class="mt-8 rounded-card bg-surface p-3 shadow-soft-md dark:bg-surface-dark">
        @forelse ($this->sounds as $sound)
            <x-sound-row :sound="$sound" :bars="80" wire:key="pack-sound-{{ $sound->id }}" />
        @empty
            <div class="py-16 text-center">
                <p class="text-[0.95rem] text-ink/50 dark:text-paper/50">This pack is still being filled.</p>
            </div>
        @endforelse
    </div>
</div>
