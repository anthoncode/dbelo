<?php

use App\Models\Category;
use App\Models\Plan;
use App\Models\Sound;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.site')] #[Title('Sound effects library')] class extends Component {

    public string $q = '';

    public function search(): void
    {
        $this->redirectRoute('sounds.index', ['q' => $this->q], navigate: true);
    }

    #[Computed]
    public function featured(): ?Sound
    {
        return Sound::published()
            ->with(['files', 'category', 'license'])
            ->orderByDesc('is_featured')
            ->orderByDesc('published_at')
            ->first();
    }

    #[Computed]
    public function categories()
    {
        return Category::roots()
            ->withCount(['sounds' => fn ($q) => $q->published()])
            ->orderByDesc('sounds_count')
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function latest()
    {
        return Sound::published()
            ->with(['files', 'category'])
            ->when($this->featured, fn ($q) => $q->where('id', '!=', $this->featured->id))
            ->latest('published_at')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function plans()
    {
        return Plan::where('is_active', true)->orderBy('sort_order')->get();
    }

    #[Computed]
    public function totals(): array
    {
        return [
            'sounds' => Sound::published()->count(),
            'categories' => Category::count(),
        ];
    }
}; ?>

<div>
    {{-- ============ HERO ============ --}}
    <section class="py-14 text-center">
        <span class="mb-7 inline-flex items-center gap-2 rounded-full bg-surface px-4 py-2 text-sm text-ink/60 shadow-soft-sm dark:bg-surface-dark dark:text-paper/60">
            <span class="size-2 rounded-full bg-brand"></span>
            {{ number_format($this->totals['sounds']) }} sounds online
        </span>

        <h1 class="text-[clamp(2.6rem,6vw,4.4rem)] font-bold">
            Every sound your<br><span class="key">story</span> needs
        </h1>

        <p class="mx-auto mt-6 max-w-[54ch] text-[1.08rem] text-ink/60 dark:text-paper/60">
            Studio-grade sound effects, cleared for commercial use.
            Listen to everything <span class="key">free</span>.
        </p>

        <form wire:submit="search"
              class="mx-auto mt-9 flex max-w-[660px] items-center gap-3 rounded-full bg-surface py-2.5 pl-6 pr-2.5 shadow-soft-md transition duration-400 ease-dbelo focus-within:shadow-soft-lg dark:bg-surface-dark">
            <x-icon name="magnifying-glass" style="solid" class="shrink-0 text-ink/35 dark:text-paper/35" />

            <input type="search" wire:model="q"
                   placeholder="door creak, thunder, laser, footsteps on gravel…"
                   class="min-w-0 flex-1 border-0 bg-transparent p-0 text-base font-light placeholder:text-ink/35 focus:outline-none focus:ring-0 dark:placeholder:text-paper/35" />

            <button type="submit"
                    class="shrink-0 rounded-full bg-ink px-6 py-2.5 text-sm font-medium text-paper shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md dark:bg-brand">
                Search
            </button>
        </form>

        @if ($this->categories->isNotEmpty())
            <div class="mt-5 flex flex-wrap justify-center gap-2">
                @foreach ($this->categories->take(6) as $cat)
                    <a href="{{ route('sounds.index', ['category' => $cat->slug]) }}" wire:navigate
                       class="rounded-full bg-surface px-4 py-2 text-[0.83rem] text-ink/60 shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md dark:bg-surface-dark dark:text-paper/60">
                        {{ $cat->name }}
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    {{-- ============ FEATURED ============ --}}
    @if ($this->featured)
        <section class="py-10">
            <div class="mb-6 flex items-end justify-between gap-4">
                <div>
                    <div class="micro">Featured</div>
                    <h2 class="mt-2 text-2xl font-semibold">Sound of the week</h2>
                </div>
                <a href="{{ route('sounds.index') }}" wire:navigate
                   class="rounded-full bg-surface px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition hover:-translate-y-0.5 hover:shadow-soft-md dark:bg-surface-dark">
                    Browse all
                </a>
            </div>

            {{-- The darkest thing on the page is the thing that matters most --}}
            <div class="overflow-hidden rounded-panel bg-ink text-paper shadow-soft-lg dark:bg-surface-dark">
                <div class="flex items-center justify-between border-b border-paper/10 px-7 py-4">
                    <span class="micro !text-paper/45 flex items-center gap-2">
                        <span class="size-2 rounded-full bg-brand"></span>
                        {{ $this->featured->sample_rate ? number_format($this->featured->sample_rate / 1000, 1).' kHz' : 'Ready' }}
                        @if ($this->featured->bit_depth) · {{ $this->featured->bit_depth }} bit @endif
                    </span>
                    @if ($this->featured->is_premium)
                        <span class="rounded-full bg-brand px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.09em] text-white">Pro</span>
                    @endif
                </div>

                <div class="p-7">
                    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <a href="{{ route('sounds.show', $this->featured) }}" wire:navigate class="text-2xl font-semibold hover:text-brand">
                                {{ $this->featured->title }}
                            </a>
                            <div class="micro !text-paper/45 mt-1.5">
                                {{ $this->featured->category?->name ?? 'Uncategorised' }} · {{ $this->featured->durationForHumans() }}
                            </div>
                        </div>

                        <a href="{{ route('sounds.download', $this->featured) }}"
                           class="flex items-center gap-2 rounded-full bg-brand px-6 py-3 font-medium text-white shadow-brand transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-brand-lg">
                            <x-icon name="arrow-down-to-line" style="solid" class="text-sm" />
                            Download
                        </a>
                    </div>

                    <x-waveform-player :sound="$this->featured" :bars="160" height="h-20" />
                </div>
            </div>
        </section>
    @endif

    {{-- ============ CATEGORIES ============ --}}
    @if ($this->categories->isNotEmpty())
        <section class="py-10">
            <div class="mb-6">
                <div class="micro">Explore</div>
                <h2 class="mt-2 text-2xl font-semibold">Browse by category</h2>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($this->categories as $cat)
                    <a href="{{ route('sounds.index', ['category' => $cat->slug]) }}" wire:navigate
                       class="group rounded-card bg-surface p-6 shadow-soft-md transition duration-450 ease-dbelo hover:-translate-y-1 hover:shadow-soft-lg dark:bg-surface-dark">
                        <span class="mb-4 grid size-12 place-items-center rounded-[15px] bg-ink/[0.05] text-brand transition duration-400 ease-dbelo group-hover:scale-107 group-hover:bg-brand group-hover:text-white dark:bg-paper/10">
                            <x-icon :name="$cat->icon ?? 'waveform-lines'" style="solid" class="text-lg" />
                        </span>
                        <div class="text-[1.02rem]">{{ $cat->name }}</div>
                        <div class="micro mt-1.5">{{ $cat->sounds_count }} {{ Str::plural('sound', $cat->sounds_count) }}</div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ============ LATEST ============ --}}
    @if ($this->latest->isNotEmpty())
        <section class="py-10">
            <div class="mb-6 flex items-end justify-between gap-4">
                <div>
                    <div class="micro">Fresh</div>
                    <h2 class="mt-2 text-2xl font-semibold">Latest uploads</h2>
                </div>
            </div>

            <div class="rounded-card bg-surface p-3 shadow-soft-md dark:bg-surface-dark">
                @foreach ($this->latest as $sound)
                    <div wire:key="home-{{ $sound->id }}"
                         class="flex flex-col gap-4 rounded-control px-4 py-3 transition duration-350 ease-dbelo hover:bg-ink/[0.04] lg:flex-row lg:items-center dark:hover:bg-paper/[0.06]">
                        <div class="min-w-0 lg:w-52">
                            <a href="{{ route('sounds.show', $sound) }}" wire:navigate class="block truncate text-[0.95rem] hover:text-brand">
                                {{ $sound->title }}
                            </a>
                            <div class="micro mt-1">
                                {{ $sound->category?->name ?? 'Uncategorised' }} · {{ $sound->durationForHumans() }}
                            </div>
                        </div>

                        <x-waveform-player :sound="$sound" :bars="90" class="flex-1" />

                        <a href="{{ route('sounds.download', $sound) }}"
                           class="shrink-0 rounded-full bg-paper px-5 py-2.5 text-[0.83rem] font-medium shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md dark:bg-paper/10">
                            Download
                        </a>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ============ PRICING ============ --}}
    @if ($this->plans->isNotEmpty())
        <section class="py-14">
            <div class="mb-8 text-center">
                <div class="micro">Plans</div>
                <h2 class="mt-2 text-[clamp(1.7rem,3vw,2.3rem)] font-semibold">
                    Listen free. <span class="key">Download</span> without limits.
                </h2>
            </div>

            <div class="grid gap-5 lg:grid-cols-3">
                @foreach ($this->plans as $plan)
                    @php($highlight = $plan->allows_premium && $plan->interval === 'month')

                    <div class="flex flex-col gap-5 rounded-card p-8 shadow-soft-md transition duration-450 ease-dbelo hover:-translate-y-1 hover:shadow-soft-lg
                                {{ $highlight ? 'bg-ink text-paper shadow-soft-lg dark:bg-surface-dark' : 'bg-surface dark:bg-surface-dark' }}">

                        <div>
                            <div class="flex items-center justify-between">
                                <div class="micro {{ $highlight ? '!text-paper/45' : '' }}">{{ $plan->name }}</div>
                                @if ($highlight)
                                    <span class="rounded-full bg-brand px-3 py-1 text-[10px] font-semibold uppercase tracking-[0.09em] text-white">Popular</span>
                                @endif
                            </div>

                            <div class="mt-2 text-[2.6rem] font-semibold tracking-[-0.035em]">
                                {{ $plan->priceForHumans() }}
                                @unless ($plan->isFree())
                                    <span class="text-base font-light {{ $highlight ? 'text-paper/60' : 'text-ink/50 dark:text-paper/50' }}">/{{ $plan->interval === 'year' ? 'yr' : 'mo' }}</span>
                                @endunless
                            </div>
                        </div>

                        <div class="flex-1">
                            @foreach (($plan->features ?? []) as $feature)
                                <div class="flex gap-3 py-1.5 text-[0.92rem] {{ $highlight ? 'text-paper/70' : 'text-ink/65 dark:text-paper/65' }}">
                                    <span class="font-semibold text-brand">✓</span> {{ $feature }}
                                </div>
                            @endforeach
                        </div>

                        <a href="{{ route('register') }}" wire:navigate
                           class="rounded-full py-3 text-center text-[0.9rem] font-medium transition duration-300 ease-dbelo hover:-translate-y-0.5
                                  {{ $highlight
                                      ? 'bg-brand text-white shadow-brand hover:shadow-brand-lg'
                                      : 'bg-paper shadow-soft-sm hover:shadow-soft-md dark:bg-paper/10' }}">
                            {{ $plan->isFree() ? 'Start free' : 'Choose '.$plan->name }}
                        </a>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    {{-- ============ CTA ============ --}}
    <section class="py-10">
        <div class="rounded-panel bg-ink px-8 py-14 text-center text-paper shadow-soft-lg dark:bg-surface-dark">
            <h2 class="text-[clamp(1.7rem,3vw,2.3rem)] font-semibold">
                Start with a <span class="key">free</span> account
            </h2>
            <p class="mx-auto mt-3 max-w-[46ch] text-paper/60">
                Five downloads a day, no card required. Upgrade whenever you need more.
            </p>
            <a href="{{ route('register') }}" wire:navigate
               class="mt-7 inline-flex items-center gap-2 rounded-full bg-brand px-8 py-3.5 font-medium text-white shadow-brand transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-brand-lg">
                Create account
                <x-icon name="arrow-right" style="solid" class="text-sm" />
            </a>
        </div>
    </section>
</div>
