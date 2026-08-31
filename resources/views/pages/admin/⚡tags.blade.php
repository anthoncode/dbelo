<?php

use App\Models\Sound;
use App\Models\Tag;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Tags')] class extends Component {
    use WithPagination;

    // ── New tag ──
    public string $name = '';

    // ── Inline editing ──
    public ?int $editingId = null;
    public string $editName = '';

    // ── Filters ──
    public string $search = '';
    public bool $unusedOnly = false;

    // ── Bulk selection ──
    public array $selected = [];
    public string $mergeTarget = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    #[Computed]
    public function tags()
    {
        return Tag::query()
            ->withCount('sounds')
            ->when($this->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when($this->unusedOnly, fn ($q) => $q->having('sounds_count', '=', 0))
            ->orderByDesc('sounds_count')
            ->orderBy('name')
            ->paginate(10);
    }

    #[Computed]
    public function totals(): array
    {
        return [
            'all' => Tag::count(),
            'unused' => Tag::whereDoesntHave('sounds')->count(),
        ];
    }

    /** The tags currently ticked, used by the bulk action bar. */
    #[Computed]
    public function selectedTags()
    {
        return $this->selected
            ? Tag::withCount('sounds')->whereIn('id', $this->selected)->orderByDesc('sounds_count')->get()
            : collect();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedUnusedOnly(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:60']]);

        $slug = Str::slug($this->name);

        if (Tag::where('slug', $slug)->exists()) {
            $this->addError('name', 'That tag already exists.');

            return;
        }

        Tag::create(['name' => trim($this->name), 'slug' => $slug]);

        $this->reset('name');
        $this->refreshLists();
        $this->resetPage();

        session()->flash('ok', 'Tag created.');
    }

    public function startEdit(int $id): void
    {
        $tag = Tag::findOrFail($id);

        $this->editingId = $id;
        $this->editName = $tag->name;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editName']);
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        $this->validate(['editName' => ['required', 'string', 'max:60']]);

        $tag = Tag::findOrFail($this->editingId);
        $tag->update(['name' => trim($this->editName)]);

        // Renaming changes what the search matches on, so the sounds
        // carrying this tag have to be pushed back into the index.
        $this->reindex($tag);

        $this->cancelEdit();
        $this->refreshLists();

        session()->flash('ok', 'Tag renamed.');
    }

    public function delete(int $id): void
    {
        $tag = Tag::withCount('sounds')->findOrFail($id);
        $sounds = $tag->sounds()->pluck('sounds.id');

        $tag->sounds()->detach();
        $tag->delete();

        Sound::whereIn('id', $sounds)->searchable();

        $this->selected = array_values(array_diff($this->selected, [$id]));
        $this->refreshLists();

        session()->flash('ok', $tag->sounds_count > 0
            ? "Tag deleted and removed from {$tag->sounds_count} sounds."
            : 'Tag deleted.');
    }

    /**
     * Fold several tags into one.
     *
     * This is the reason tags need a screen of their own: as soon as more
     * than one person uploads, "footstep", "footsteps" and "foot steps"
     * start coexisting, and each variant silently splits the results a
     * search would otherwise return together.
     */
    public function merge(): void
    {
        if (count($this->selected) < 2 || ! $this->mergeTarget) {
            return;
        }

        $target = Tag::findOrFail($this->mergeTarget);
        $others = Tag::whereIn('id', $this->selected)->where('id', '!=', $target->id)->get();

        $touched = collect();

        foreach ($others as $tag) {
            $soundIds = $tag->sounds()->pluck('sounds.id');
            $touched = $touched->merge($soundIds);

            // syncWithoutDetaching, so a sound already carrying the target
            // tag does not blow up on the composite primary key.
            $target->sounds()->syncWithoutDetaching($soundIds->all());

            $tag->sounds()->detach();
            $tag->delete();
        }

        Sound::whereIn('id', $touched->unique())->searchable();

        $count = $others->count();
        $this->reset(['selected', 'mergeTarget']);
        $this->refreshLists();

        session()->flash('ok', "Merged {$count} ".str('tag')->plural($count)." into “{$target->name}”.");
    }

    public function deleteSelected(): void
    {
        $tags = Tag::whereIn('id', $this->selected)->get();
        $touched = collect();

        foreach ($tags as $tag) {
            $touched = $touched->merge($tag->sounds()->pluck('sounds.id'));
            $tag->sounds()->detach();
            $tag->delete();
        }

        Sound::whereIn('id', $touched->unique())->searchable();

        $count = $tags->count();
        $this->reset('selected');
        $this->refreshLists();

        session()->flash('ok', "{$count} ".str('tag')->plural($count).' deleted.');
    }

    public function clearSelection(): void
    {
        $this->reset(['selected', 'mergeTarget']);
    }

    protected function reindex(Tag $tag): void
    {
        Sound::whereIn('id', $tag->sounds()->pluck('sounds.id'))->searchable();
    }

    protected function refreshLists(): void
    {
        unset($this->tags, $this->totals, $this->selectedTags);
    }
}; ?>

<div>
    @if (session('ok'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-brand/30 bg-brand/10 px-4 py-3">
            <x-icon name="circle-check" style="solid" class="text-brand" />
            <span class="text-[0.88rem]">{{ session('ok') }}</span>
        </div>
    @endif

    <div class="grid gap-5 xl:grid-cols-[340px_1fr]">

        {{-- ══════ COLUMN 1 — ADD ══════ --}}
        <div class="h-fit space-y-5">
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="border-b border-hairline px-5 py-4">
                    <h2 class="text-[0.95rem] font-medium">Add tag</h2>
                </div>

                <form wire:submit="create" class="space-y-4 p-5">
                    <div>
                        <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Name</label>
                        <input type="text" wire:model="name" placeholder="thunder"
                               class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.9rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                        @error('name') <p class="mt-1.5 text-[0.8rem] text-brand">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-[0.75rem] text-paper/30">
                            Tags are also created automatically when a sound is uploaded.
                        </p>
                    </div>

                    <button type="submit"
                            class="flex w-full items-center justify-center gap-2 rounded-lg bg-brand py-2.5 text-[0.88rem] font-medium text-white transition duration-200 hover:brightness-115">
                        <x-icon name="plus" style="solid" class="text-[12px]" />
                        Add tag
                    </button>
                </form>
            </div>

            {{-- Housekeeping --}}
            <div class="rounded-2xl border border-hairline bg-panel p-5">
                <div class="flex items-center gap-3">
                    <x-admin.icon-chip icon="broom" :tone="$this->totals['unused'] ? 'brand' : 'muted'" />
                    <div class="min-w-0 flex-1">
                        <div class="text-[0.88rem]">{{ $this->totals['unused'] }} unused</div>
                        <div class="text-[0.75rem] text-paper/30">of {{ $this->totals['all'] }} tags</div>
                    </div>
                </div>

                @if ($this->totals['unused'] > 0)
                    <button wire:click="$set('unusedOnly', true)"
                            class="mt-4 w-full rounded-lg bg-raised py-2 text-[0.83rem] text-paper/70 transition hover:bg-paper/[0.10] hover:text-paper">
                        Show them
                    </button>
                @endif
            </div>
        </div>

        {{-- ══════ COLUMN 2 — LIST ══════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">

            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
                <h2 class="text-[0.95rem] font-medium">
                    All tags <span class="ml-1.5 text-paper/35">{{ $this->totals['all'] }}</span>
                </h2>

                <div class="flex items-center gap-2">
                    <button wire:click="$toggle('unusedOnly')"
                            @class([
                                'rounded-full px-3.5 py-2 text-[0.78rem] transition duration-200 ease-dbelo',
                                'bg-brand text-white' => $unusedOnly,
                                'bg-raised text-paper/50 hover:text-paper' => ! $unusedOnly,
                            ])>
                        Unused only
                    </button>

                    <div class="flex items-center gap-2.5 rounded-lg bg-raised px-3 py-2">
                        <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
                        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Filter…"
                               class="w-32 border-0 bg-transparent p-0 text-[0.83rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-0" />
                    </div>
                </div>
            </div>

            {{-- Bulk action bar: only appears when it can do something --}}
            @if (count($selected) > 0)
                <div class="flex flex-wrap items-center gap-3 border-b border-hairline bg-brand/[0.08] px-5 py-3">
                    <span class="text-[0.85rem]">{{ count($selected) }} selected</span>

                    @if (count($selected) > 1)
                        <div class="flex items-center gap-2">
                            <span class="text-[0.8rem] text-paper/45">Merge into</span>
                            <select wire:model="mergeTarget"
                                    class="rounded-lg border-0 bg-raised px-3 py-1.5 text-[0.82rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                                <option value="">Choose…</option>
                                @foreach ($this->selectedTags as $tag)
                                    <option value="{{ $tag->id }}">{{ $tag->name }} ({{ $tag->sounds_count }})</option>
                                @endforeach
                            </select>
                            <button wire:click="merge" @disabled(! $mergeTarget)
                                    class="rounded-lg bg-brand px-4 py-1.5 text-[0.82rem] font-medium text-white transition hover:brightness-115 disabled:opacity-40">
                                Merge
                            </button>
                        </div>
                    @endif

                    <div class="ml-auto flex items-center gap-2">
                        <button wire:click="deleteSelected"
                                wire:confirm="Delete {{ count($selected) }} tags? They will be removed from every sound."
                                class="rounded-lg bg-raised px-4 py-1.5 text-[0.82rem] text-paper/70 transition hover:bg-paper/[0.10] hover:text-paper">
                            Delete
                        </button>
                        <x-admin.icon-button icon="xmark" label="Clear selection" wire:click="clearSelection" />
                    </div>
                </div>
            @endif

            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                        <th class="w-10 px-5 py-2.5"></th>
                        <th class="w-12 px-2 py-2.5 font-medium">#</th>
                        <th class="px-3 py-2.5 font-medium">Name</th>
                        <th class="w-28 px-3 py-2.5 text-right font-medium">Sounds</th>
                        <th class="w-[104px] px-5 py-2.5 text-right font-medium">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-hairline">
                    @php $row = ($this->tags->currentPage() - 1) * $this->tags->perPage(); @endphp

                    @forelse ($this->tags as $tag)
                        @php
                            $row++;
                            $isEditing = $editingId === $tag->id;
                        @endphp

                        <tr wire:key="tag-{{ $tag->id }}"
                            class="transition {{ $isEditing ? 'bg-raised' : 'hover:bg-paper/[0.03]' }}">

                            <td class="px-5 py-2.5">
                                <input type="checkbox" wire:model.live="selected" value="{{ $tag->id }}"
                                       class="size-4 cursor-pointer rounded border-0 bg-raised text-brand focus:ring-2 focus:ring-brand/40 focus:ring-offset-0" />
                            </td>

                            <td class="px-2 py-2.5 text-[0.8rem] tabular-nums text-paper/25">{{ $row }}</td>

                            @if ($isEditing)
                                <td class="px-3 py-2">
                                    <input type="text" wire:model="editName" autofocus
                                           wire:keydown.enter="saveEdit" wire:keydown.escape="cancelEdit"
                                           class="w-full rounded-lg border-0 bg-panel px-3 py-2 text-[0.88rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                                    @error('editName') <p class="mt-1 text-[0.78rem] text-brand">{{ $message }}</p> @enderror
                                    <p class="mt-1 text-[0.72rem] text-paper/25">The URL stays /{{ $tag->slug }}</p>
                                </td>

                                <td class="px-3 py-2 text-right text-[0.85rem] tabular-nums text-paper/35">{{ $tag->sounds_count }}</td>

                                <td class="px-5 py-2">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <x-admin.icon-button icon="check" variant="brand" label="Save" wire:click="saveEdit" />
                                        <x-admin.icon-button icon="xmark" label="Cancel" wire:click="cancelEdit" />
                                    </div>
                                </td>

                            @else
                                <td class="px-3 py-2.5">
                                    <div class="flex items-center gap-3">
                                        <x-admin.icon-chip icon="tag" :tone="$tag->sounds_count > 0 ? 'brand' : 'muted'" />
                                        <div class="min-w-0">
                                            <div class="truncate text-[0.89rem]">{{ $tag->name }}</div>
                                            <div class="truncate text-[0.72rem] text-paper/25">/{{ $tag->slug }}</div>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-3 py-2.5 text-right">
                                    @if ($tag->sounds_count > 0)
                                        <a href="{{ route('sounds.index', ['q' => $tag->name]) }}" target="_blank"
                                           class="rounded-full bg-raised px-2.5 py-1 text-[0.75rem] tabular-nums text-paper/70 transition hover:bg-paper/[0.10] hover:text-paper">
                                            {{ $tag->sounds_count }}
                                        </a>
                                    @else
                                        <span class="px-2.5 py-1 text-[0.75rem] text-paper/20">0</span>
                                    @endif
                                </td>

                                <td class="px-5 py-2.5">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-admin.icon-button icon="pen" label="Rename" wire:click="startEdit({{ $tag->id }})" />
                                        <x-admin.icon-button icon="trash" label="Delete"
                                                             wire:click="delete({{ $tag->id }})"
                                                             wire:confirm="Delete “{{ $tag->name }}”?{{ $tag->sounds_count ? ' It will be removed from '.$tag->sounds_count.' sounds.' : '' }}" />
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-16 text-center">
                                <x-icon name="tags" style="regular" class="text-[24px] text-paper/20" />
                                <p class="mt-3 text-[0.9rem] text-paper/45">
                                    @if ($unusedOnly)
                                        No unused tags — the catalogue is tidy
                                    @elseif ($search)
                                        Nothing matches that filter
                                    @else
                                        No tags yet
                                    @endif
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if ($this->tags->hasPages())
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-hairline px-5 py-3.5">
                    <span class="text-[0.78rem] text-paper/35">
                        {{ $this->tags->firstItem() }}–{{ $this->tags->lastItem() }} of {{ $this->tags->total() }}
                    </span>

                    <div class="flex items-center gap-1.5">
                        <x-admin.icon-button icon="chevron-left" variant="muted" label="Previous"
                                             wire:click="previousPage" @disabled($this->tags->onFirstPage()) />

                        @foreach ($this->tags->getUrlRange(max(1, $this->tags->currentPage() - 2), min($this->tags->lastPage(), $this->tags->currentPage() + 2)) as $page => $url)
                            <button wire:click="gotoPage({{ $page }})" wire:key="tpg-{{ $page }}"
                                    @class([
                                        'grid size-8 place-items-center rounded-full text-[0.78rem] tabular-nums transition duration-200 ease-dbelo',
                                        'bg-brand text-white' => $page === $this->tags->currentPage(),
                                        'text-paper/40 hover:bg-paper/[0.10] hover:text-paper' => $page !== $this->tags->currentPage(),
                                    ])>
                                {{ $page }}
                            </button>
                        @endforeach

                        <x-admin.icon-button icon="chevron-right" variant="muted" label="Next"
                                             wire:click="nextPage" @disabled(! $this->tags->hasMorePages()) />
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
