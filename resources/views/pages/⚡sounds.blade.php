<?php

use App\Models\Category;
use App\Models\Sound;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.site')] #[Title('Sound effects')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $duration = '';

    #[Url(except: false)]
    public bool $freeOnly = false;

    /**
     * Any filter change returns to page one. Without this the user can land
     * on page 7 of a result set that now has two pages.
     */
    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    public function setCategory(string $slug): void
    {
        $this->category = $this->category === $slug ? '' : $slug;
        $this->resetPage();
    }

    public function setDuration(string $bucket): void
    {
        $this->duration = $this->duration === $bucket ? '' : $bucket;
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'category', 'duration', 'freeOnly']);
        $this->resetPage();
    }

    #[Computed]
    public function categories()
    {
        return Category::roots()->withCount(['sounds' => fn ($q) => $q->published()])->get();
    }

    #[Computed]
    public function sounds()
    {
        return Sound::query()
            ->published()
            ->with(['files', 'category', 'license'])
            ->when($this->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('tags', fn ($t) => $t->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($this->category, fn ($q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
            ->when($this->duration, function ($query, $bucket) {
                match ($bucket) {
                    'short' => $query->where('duration_ms', '<', 1000),
                    'medium' => $query->whereBetween('duration_ms', [1000, 5000]),
                    'long' => $query->whereBetween('duration_ms', [5001, 30000]),
                    'xlong' => $query->where('duration_ms', '>', 30000),
                    default => null,
                };
            })
            ->when($this->freeOnly, fn ($q) => $q->where('is_premium', false))
            ->latest('published_at')
            ->paginate(20);
    }

    #[Computed]
    public function hasFilters(): bool
    {
        return filled($this->search) || filled($this->category) || filled($this->duration) || $this->freeOnly;
    }
}; ?>

<div>

    {{-- Hero --}}
    <div class="py-10 text-center">
        <span class="mb-6 inline-flex items-center gap-2 rounded-full bg-surface px-4 py-2 text-sm text-ink/60 shadow-soft-sm dark:bg-surface-dark dark:text-paper/60">
            <span class="size-2 rounded-full bg-brand"></span>
            {{ number_format($this->sounds->total()) }} sounds online
        </span>

        <h1 class="text-[clamp(2.4rem,5.5vw,4rem)] font-bold">
            Every sound your<br><span class="key">story</span> needs
        </h1>

        <p class="mx-auto mt-5 max-w-[52ch] text-[1.05rem] text-ink/60 dark:text-paper/60">
            Studio-grade sound effects, cleared for commercial use.
            Listen to everything <span class="key">free</span>.
        </p>

        {{-- Search --}}
        <div class="mx-auto mt-9 flex max-w-[660px] items-center gap-3 rounded-full bg-surface py-2.5 pl-6 pr-2.5 shadow-soft-md transition duration-400 ease-dbelo focus-within:shadow-soft-lg dark:bg-surface-dark">
            <svg class="size-5 shrink-0 text-ink/40 dark:text-paper/40" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" viewBox="0 0 24 24">
                <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>
            </svg>

            <input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="door creak, thunder, laser, footsteps on gravel…"
                class="min-w-0 flex-1 border-0 bg-transparent p-0 text-base font-light placeholder:text-ink/35 focus:outline-none focus:ring-0 dark:placeholder:text-paper/35"
            />

            <span wire:loading wire:target="search" class="micro shrink-0">Searching…</span>
        </div>

        {{-- Category chips --}}
        <div class="mt-5 flex flex-wrap justify-center gap-2">
            <button
                wire:click="$set('category', '')"
                class="rounded-full px-4 py-2 text-[0.83rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md
                       {{ $category === '' ? 'bg-ink text-paper dark:bg-brand' : 'bg-surface text-ink/60 dark:bg-surface-dark dark:text-paper/60' }}">
                All
            </button>

            @foreach ($this->categories as $cat)
                <button
                    wire:click="setCategory('{{ $cat->slug }}')"
                    wire:key="cat-{{ $cat->id }}"
                    class="rounded-full px-4 py-2 text-[0.83rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md
                           {{ $category === $cat->slug ? 'bg-ink text-paper dark:bg-brand' : 'bg-surface text-ink/60 dark:bg-surface-dark dark:text-paper/60' }}">
                    {{ $cat->name }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Filter bar --}}
    <div class="mb-5 mt-6 flex flex-wrap items-center gap-2">
        @foreach ([
            'short' => 'Under 1s',
            'medium' => '1 – 5s',
            'long' => '5 – 30s',
            'xlong' => 'Over 30s',
        ] as $bucket => $label)
            <button
                wire:click="setDuration('{{ $bucket }}')"
                class="rounded-full px-4 py-2 text-[0.83rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5
                       {{ $duration === $bucket ? 'bg-ink text-paper dark:bg-brand' : 'bg-surface text-ink/60 dark:bg-surface-dark dark:text-paper/60' }}">
                {{ $label }}
            </button>
        @endforeach

        <button
            wire:click="$toggle('freeOnly')"
            class="rounded-full px-4 py-2 text-[0.83rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5
                   {{ $freeOnly ? 'bg-ink text-paper dark:bg-brand' : 'bg-surface text-ink/60 dark:bg-surface-dark dark:text-paper/60' }}">
            Free only
        </button>

        @if ($this->hasFilters)
            <button wire:click="clearFilters" class="px-3 text-sm text-ink/45 underline transition hover:text-ink dark:text-paper/45 dark:hover:text-paper">
                Clear
            </button>
        @endif

        <span class="micro ml-auto">
            {{ number_format($this->sounds->total()) }} {{ Str::plural('result', $this->sounds->total()) }}
        </span>
    </div>

    {{-- Results --}}
    <div class="rounded-card bg-surface p-3 shadow-soft-md dark:bg-surface-dark">
        @forelse ($this->sounds as $sound)
            <div wire:key="sound-{{ $sound->id }}"
                 class="flex flex-col gap-4 rounded-control px-4 py-3 transition duration-350 ease-dbelo hover:bg-ink/[0.04] lg:flex-row lg:items-center dark:hover:bg-paper/[0.06]">

                <div class="min-w-0 lg:w-52">
                    <a href="{{ route('sounds.show', $sound) }}" wire:navigate class="block truncate text-[0.95rem] hover:text-brand">
                        {{ $sound->title }}
                    </a>
                    <div class="micro mt-1 flex items-center gap-1.5">
                        @if ($sound->category)<span>{{ $sound->category->name }}</span><span>·</span>@endif
                        <span>{{ $sound->durationForHumans() }}</span>
                        @if ($sound->is_premium)
                            <span class="rounded-full bg-brand px-2 py-0.5 text-[9px] font-semibold text-white">PRO</span>
                        @endif
                    </div>
                </div>

                <x-waveform-player :sound="$sound" :bars="72" class="flex-1" />

                <a href="{{ route('sounds.download', $sound) }}"
                   class="shrink-0 rounded-full bg-paper px-5 py-2.5 text-[0.83rem] font-medium shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md dark:bg-paper/10">
                    Download
                </a>
            </div>
        @empty
            <div class="py-20 text-center">
                <p class="text-lg">No sounds found</p>
                <p class="mt-1 text-sm text-ink/50 dark:text-paper/50">
                    {{ $this->hasFilters ? 'Try removing some filters.' : 'Nothing has been published yet.' }}
                </p>
            </div>
        @endforelse
    </div>

    <div class="mt-8">
        {{ $this->sounds->links() }}
    </div>
</div>
