<?php

use App\Models\Category;
use App\Models\Collection as Pack;
use App\Models\Sound;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.admin')] class extends Component {

    public Pack $pack;

    public string $search = '';
    public string $categoryId = '';

    public function mount(Pack $pack): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        abort_unless($pack->is_featured, 404);

        $this->pack = $pack;
    }

    public function title(): string
    {
        return $this->pack->name;
    }

    /** Ids already in the pack — used to grey out the picker results. */
    #[Computed]
    public function memberIds(): array
    {
        return $this->pack->sounds()->pluck('sounds.id')->all();
    }

    #[Computed]
    public function members()
    {
        return $this->pack->sounds()->with(['files', 'category'])->get();
    }

    /**
     * Plain SQL rather than Meilisearch: the admin picker needs exact,
     * predictable matching, and it must keep working when the search
     * engine is down.
     */
    #[Computed]
    public function results()
    {
        if (! filled($this->search) && ! filled($this->categoryId)) {
            return collect();
        }

        return Sound::published()
            ->with(['files', 'category'])
            ->when($this->search, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->when($this->categoryId, fn ($q, $c) => $q->where('category_id', $c))
            ->orderByDesc('downloads_count')
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function categories()
    {
        return Category::orderBy('parent_id')->orderBy('sort_order')->get();
    }

    public function add(int $soundId): void
    {
        if (in_array($soundId, $this->memberIds, true)) {
            return;
        }

        $this->pack->sounds()->attach($soundId, [
            'created_at' => now(),
            'sort_order' => (int) $this->pack->sounds()->max('sort_order') + 1,
        ]);

        $this->after();
    }

    public function remove(int $soundId): void
    {
        $this->pack->sounds()->detach($soundId);
        $this->after();
    }

    public function move(int $soundId, string $direction): void
    {
        $ids = $this->members->pluck('id')->all();
        $index = array_search($soundId, $ids, true);

        if ($index === false) {
            return;
        }

        $swapWith = $direction === 'up' ? $index - 1 : $index + 1;

        if ($swapWith < 0 || $swapWith >= count($ids)) {
            return;
        }

        [$ids[$index], $ids[$swapWith]] = [$ids[$swapWith], $ids[$index]];

        // Rewriting every position keeps the sequence dense: gaps would
        // accumulate after enough moves and the order would drift.
        foreach ($ids as $position => $id) {
            $this->pack->sounds()->updateExistingPivot($id, ['sort_order' => $position]);
        }

        $this->after();
    }

    public function togglePublic(): void
    {
        if (! $this->pack->is_public && $this->pack->sounds()->count() === 0) {
            session()->flash('error', 'Add some sounds before publishing.');

            return;
        }

        $this->pack->update(['is_public' => ! $this->pack->is_public]);
    }

    protected function after(): void
    {
        $this->pack->refreshCount();
        $this->pack->touch();

        unset($this->members, $this->memberIds, $this->results);
    }
}; ?>

<div>
    <div class="mb-5 flex flex-wrap items-center gap-3">
        <a href="{{ route('admin.packs') }}" wire:navigate>
            <x-admin.icon-button icon="arrow-left" variant="muted" label="Back to packs" />
        </a>

        <div class="min-w-0">
            <h2 class="truncate text-[1.05rem] font-medium">{{ $pack->name }}</h2>
            <p class="text-[0.78rem] text-paper/35">
                {{ $pack->sounds_count }} {{ Str::plural('sound', $pack->sounds_count) }} · /{{ $pack->slug }}
            </p>
        </div>

        <div class="ml-auto flex items-center gap-2">
            <a href="{{ route('collections.show', $pack) }}" target="_blank">
                <x-admin.icon-button icon="arrow-up-right-from-square" variant="muted" label="Preview" />
            </a>

            <button wire:click="togglePublic"
                    @class([
                        'flex items-center gap-2 rounded-lg px-4 py-2 text-[0.83rem] transition duration-200 ease-dbelo',
                        'bg-brand text-white hover:brightness-115' => $pack->is_public,
                        'bg-raised text-paper/60 hover:bg-paper/[0.10] hover:text-paper' => ! $pack->is_public,
                    ])>
                <x-icon :name="$pack->is_public ? 'globe' : 'lock'" style="solid" class="text-[11px]" />
                {{ $pack->is_public ? 'Live' : 'Hidden' }}
            </button>
        </div>
    </div>

    @if (session('error'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-hairline bg-raised px-4 py-3">
            <x-icon name="triangle-exclamation" style="solid" class="text-paper/60" />
            <span class="text-[0.88rem] text-paper/80">{{ session('error') }}</span>
        </div>
    @endif

    <div class="grid gap-5 xl:grid-cols-[380px_1fr]">

        {{-- ══════ COLUMN 1 — PICKER ══════ --}}
        <div class="h-fit rounded-2xl border border-hairline bg-panel">
            <div class="border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">Add sounds</h2>
            </div>

            <div class="space-y-3 p-5">
                <div class="flex items-center gap-2.5 rounded-lg bg-raised px-3.5 py-2.5">
                    <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search the catalogue…"
                           class="min-w-0 flex-1 border-0 bg-transparent p-0 text-[0.88rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-0" />
                </div>

                <select wire:model.live="categoryId"
                        class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.85rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                    <option value="">Any category</option>
                    @foreach ($this->categories as $category)
                        <option value="{{ $category->id }}">{{ $category->parent_id ? '— ' : '' }}{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="max-h-[520px] divide-y divide-hairline overflow-y-auto border-t border-hairline">
                @forelse ($this->results as $sound)
                    @php $inPack = in_array($sound->id, $this->memberIds, true); @endphp

                    <div wire:key="res-{{ $sound->id }}"
                         class="flex items-center gap-3 px-5 py-2.5 transition hover:bg-paper/[0.03]">
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-[0.86rem] {{ $inPack ? 'text-paper/35' : '' }}">{{ $sound->title }}</div>
                            <div class="truncate text-[0.72rem] text-paper/25">
                                {{ $sound->category?->name }} · {{ $sound->durationForHumans() }}
                            </div>
                        </div>

                        @if ($inPack)
                            <span class="grid size-8 shrink-0 place-items-center rounded-full text-brand" title="Already in this pack">
                                <x-icon name="check" style="solid" class="text-[11px]" />
                            </span>
                        @else
                            <x-admin.icon-button icon="plus" variant="brand" label="Add to pack"
                                                 wire:click="add({{ $sound->id }})" />
                        @endif
                    </div>
                @empty
                    <p class="px-5 py-10 text-center text-[0.85rem] text-paper/30">
                        {{ filled($search) || filled($categoryId)
                            ? 'Nothing matches'
                            : 'Search or pick a category to start' }}
                    </p>
                @endforelse
            </div>
        </div>

        {{-- ══════ COLUMN 2 — IN THIS PACK ══════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="flex items-center justify-between border-b border-hairline px-5 py-3.5">
                <h2 class="text-[0.95rem] font-medium">
                    In this pack <span class="ml-1.5 text-paper/35">{{ $this->members->count() }}</span>
                </h2>
                <span class="text-[0.75rem] text-paper/25">Order matters — it is what visitors see</span>
            </div>

            <div class="divide-y divide-hairline">
                @forelse ($this->members as $index => $sound)
                    <div wire:key="mem-{{ $sound->id }}"
                         class="flex flex-col gap-3 px-5 py-3 transition hover:bg-paper/[0.03] lg:flex-row lg:items-center">

                        <span class="w-5 shrink-0 text-[0.78rem] tabular-nums text-paper/25">{{ $index + 1 }}</span>

                        <div class="min-w-0 lg:w-44">
                            <div class="truncate text-[0.88rem]">{{ $sound->title }}</div>
                            <div class="truncate text-[0.72rem] text-paper/25">
                                {{ $sound->category?->name }} · {{ $sound->durationForHumans() }}
                            </div>
                        </div>

                        <x-waveform-player :sound="$sound" :bars="70" class="flex-1 text-paper" />

                        <div class="flex shrink-0 items-center gap-1">
                            <x-admin.icon-button icon="chevron-up" variant="muted" label="Move up"
                                                 wire:click="move({{ $sound->id }}, 'up')" />
                            <x-admin.icon-button icon="chevron-down" variant="muted" label="Move down"
                                                 wire:click="move({{ $sound->id }}, 'down')" />
                            <x-admin.icon-button icon="xmark" label="Remove from pack"
                                                 wire:click="remove({{ $sound->id }})" />
                        </div>
                    </div>
                @empty
                    <div class="px-5 py-20 text-center">
                        <x-icon name="list-music" style="regular" class="text-[24px] text-paper/20" />
                        <p class="mt-3 text-[0.9rem] text-paper/45">This pack is empty</p>
                        <p class="mt-2 text-[0.8rem] text-paper/25">Search on the left and add sounds with the + button</p>
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
