<?php

use App\Models\Category;
use App\Models\Collection as Pack;
use App\Models\Sound;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.admin')] class extends Component {
    use WithFileUploads;

    public Pack $pack;

    /** The cover being uploaded. Null except during an upload. */
    public $cover = null;

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

    /* ═══════════════════════════ The cover ═══════════════════════════ */

    /**
     * Saved the moment a file is chosen.
     *
     * An `updated` hook rather than a Save button, because there is no other
     * field on this screen to save alongside it: everything else here —
     * adding a sound, reordering, publishing — already applies on click. One
     * lonely Save button that governs one input is a button people forget to
     * press, and then the upload silently did nothing.
     *
     * 2 MB and a real image mime. The dimensions are not enforced: the card
     * crops with object-cover, so a wrong aspect ratio is a slightly odd
     * crop rather than a broken layout, and rejecting an upload over it
     * would be refusing work to prevent a cosmetic result.
     */
    public function updatedCover(): void
    {
        $this->validate([
            'cover' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ], [], ['cover' => 'cover image']);

        $disk = config('dbelo.storage.media', 'public');

        $old = $this->pack->cover_path;

        $path = $this->cover->store('packs', $disk);

        $this->pack->update(['cover_path' => $path]);

        // Delete the previous one AFTER the new one is stored and the row
        // points at it. The other order means a failed upload leaves a pack
        // whose image is gone and whose column still names it.
        if (filled($old)) {
            rescue(fn () => Storage::disk($disk)->delete($old), null, false);
        }

        $this->cover = null;
        $this->pack->refresh();

        session()->flash('ok', 'Cover updated.');
    }

    public function removeCover(): void
    {
        $path = $this->pack->cover_path;

        $this->pack->update(['cover_path' => null]);

        if (filled($path)) {
            rescue(fn () => Storage::disk(config('dbelo.storage.media', 'public'))->delete($path), null, false);
        }

        $this->pack->refresh();

        session()->flash('ok', 'Cover removed — the pack falls back to its icon.');
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

    @if (session('ok'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-success/25 bg-success/[0.06] px-4 py-3">
            <x-icon name="circle-check" style="solid" class="text-[0.8rem] text-success" />
            <span class="text-[0.88rem] text-paper/80">{{ session('ok') }}</span>
        </div>
    @endif

    {{-- ══════ COVER ══════
         The preview is the real thing at the real aspect ratio, not a
         thumbnail: the only question worth answering here is "does the crop
         work", and a square 80px box cannot answer it. --}}
    <div class="mb-5 rounded-2xl border border-hairline bg-panel p-5">
        <div class="flex flex-wrap items-start gap-5">

            <div class="relative aspect-[4/3] w-[220px] shrink-0 overflow-hidden rounded-xl bg-ink">
                @if ($pack->hasCover())
                    <img src="{{ $pack->coverUrl() }}" alt="" class="absolute inset-0 size-full object-cover" />
                @else
                    <div class="absolute inset-0"
                         style="background:
                             radial-gradient(120% 80% at 50% 0%, color-mix(in srgb, var(--color-brand) 38%, transparent), transparent 70%),
                             linear-gradient(160deg, color-mix(in srgb, var(--color-brand) 16%, transparent), transparent 55%);"></div>
                    <div class="absolute inset-0 grid place-items-center">
                        <x-icon name="box-open" style="solid" class="text-[2rem] text-paper/80" />
                    </div>
                @endif

                <div wire:loading wire:target="cover"
                     class="absolute inset-0 grid place-items-center bg-ink/70 text-paper">
                    <x-icon name="spinner-third" style="solid" class="animate-spin text-[1.2rem]" />
                </div>
            </div>

            <div class="min-w-0 flex-1">
                <h2 class="text-[0.95rem] font-medium">Cover</h2>
                <p class="mt-1 max-w-[70ch] text-[0.82rem] leading-relaxed text-paper/45">
                    Shown on the packs page. 4:3, at least 800&times;600, up to 2&nbsp;MB — it is cropped to fit,
                    so keep anything important away from the edges. Without one the pack draws its icon on a
                    brand gradient, which is a finished look rather than a placeholder: upload one when you
                    have it, not before.
                </p>

                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <label class="inline-flex cursor-pointer items-center gap-2 rounded-xl bg-raised px-4 py-2 text-[0.82rem] text-paper/80 transition hover:bg-rail">
                        <x-icon name="arrow-up-from-bracket" style="solid" class="text-[0.75rem] text-info" />
                        {{ $pack->hasCover() ? 'Replace' : 'Upload' }}
                        <input type="file" wire:model="cover" accept="image/jpeg,image/png,image/webp" class="hidden" />
                    </label>

                    @if ($pack->hasCover())
                        <button type="button" wire:click="removeCover"
                                class="text-[0.8rem] text-paper/35 underline-offset-2 transition hover:text-danger hover:underline">
                            Remove
                        </button>
                    @endif

                    @error('cover')
                        <span class="text-[0.8rem] text-danger">{{ $message }}</span>
                    @enderror
                </div>
            </div>
        </div>
    </div>

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
                    @php($inPack = in_array($sound->id, $this->memberIds, true))

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
