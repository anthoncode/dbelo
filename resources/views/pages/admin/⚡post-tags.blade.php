<?php

use App\Models\PostTag;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Blog tags')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')] public string $search = '';
    #[Url(except: false)] public bool $unusedOnly = false;

    public string $name = '';

    // ── Inline edit, in the same row ──
    public ?int $editing = null;
    public string $editName = '';

    /** Merge duplicates: the same job the catalogue tag screen does. */
    public array $selected = [];
    public string $mergeTarget = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    #[Computed]
    public function tags()
    {
        return PostTag::query()
            ->withCount(['posts', 'posts as live_count' => fn ($q) => $q->live()])
            ->when($this->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when($this->unusedOnly, fn ($q) => $q->doesntHave('posts'))
            ->orderByDesc('posts_count')
            ->orderBy('name')
            ->paginate(10);
    }

    #[Computed]
    public function selectedTags()
    {
        return PostTag::whereIn('id', $this->selected)->withCount('posts')->orderByDesc('posts_count')->get();
    }

    #[Computed]
    public function totals(): array
    {
        return [
            'all' => PostTag::count(),
            'unused' => PostTag::doesntHave('posts')->count(),
        ];
    }

    public function create(): void
    {
        $this->validate(['name' => ['required', 'string', 'min:2', 'max:60']]);

        $slug = Str::slug($this->name);

        if (PostTag::where('slug', $slug)->exists()) {
            $this->addError('name', 'That tag already exists.');

            return;
        }

        PostTag::create(['name' => trim($this->name), 'slug' => $slug]);

        $this->name = '';
        $this->refresh();

        session()->flash('ok', 'Tag added.');
    }

    public function edit(int $id): void
    {
        $this->editing = $id;
        $this->editName = PostTag::findOrFail($id)->name;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset(['editing', 'editName']);
        $this->resetErrorBag();
    }

    public function update(): void
    {
        $this->validate(['editName' => ['required', 'string', 'min:2', 'max:60']]);

        // Renaming keeps the slug: /blog/tag/foley may already be linked.
        PostTag::whereKey($this->editing)->update(['name' => trim($this->editName)]);

        $this->cancel();
        $this->refresh();
    }

    /**
     * Tags are typed while writing, so near-duplicates are inevitable:
     * "foley", "Foley", "foley recording". Merging keeps one and moves every
     * post onto it.
     */
    public function merge(): void
    {
        if (! $this->mergeTarget || count($this->selected) < 2) {
            return;
        }

        $target = PostTag::findOrFail($this->mergeTarget);
        $others = PostTag::whereIn('id', $this->selected)->whereKeyNot($target->id)->get();

        foreach ($others as $tag) {
            foreach ($tag->posts()->pluck('posts.id') as $postId) {
                $target->posts()->syncWithoutDetaching([$postId]);
            }

            $tag->delete();
        }

        $count = $others->count();

        $this->clearSelection();
        $this->refresh();

        session()->flash('ok', "{$count} tag(s) merged into “{$target->name}”.");
    }

    public function delete(int $id): void
    {
        PostTag::findOrFail($id)->delete();

        $this->refresh();
        session()->flash('ok', 'Tag deleted.');
    }

    public function deleteSelected(): void
    {
        $count = PostTag::whereIn('id', $this->selected)->count();

        PostTag::whereIn('id', $this->selected)->get()->each->delete();

        $this->clearSelection();
        $this->refresh();

        session()->flash('ok', "{$count} tag(s) deleted.");
    }

    public function clearSelection(): void
    {
        $this->reset(['selected', 'mergeTarget']);
    }

    protected function refresh(): void
    {
        unset($this->tags, $this->totals, $this->selectedTags);
    }
}; ?>

<div>
    @if (session('ok'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-success/30 bg-success/10 px-4 py-3">
            <x-icon name="circle-check" style="solid" class="text-success" />
            <span class="text-[0.88rem]">{{ session('ok') }}</span>
        </div>
    @endif

    <div class="grid gap-5 xl:grid-cols-[340px_1fr]">

        {{-- ══════ COLUMN 1 — ADD ══════ --}}
        <div class="h-fit space-y-5">
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                    <x-admin.icon-chip icon="tag" tone="brand" />
                    <h2 class="text-[0.95rem] font-medium">Add tag</h2>
                </div>

                <form wire:submit="create" class="space-y-4 p-5">
                    <div>
                        <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Name</label>
                        <input type="text" wire:model="name" placeholder="field recording"
                               class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.9rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                        @error('name') <p class="mt-1.5 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-[0.75rem] leading-relaxed text-paper/30">
                            Tags are also created on the fly while writing a post. This screen is for tidying up.
                        </p>
                    </div>

                    <button type="submit"
                            class="flex w-full items-center justify-center gap-2 rounded-lg bg-action py-2.5 text-[0.88rem] font-medium text-white transition duration-200 ease-dbelo hover:brightness-110">
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

                <p class="mt-4 text-[0.75rem] leading-relaxed text-paper/30">
                    Select two or more to merge duplicates. Every post moves onto the one you keep.
                </p>
            </div>
        </div>

        {{-- ══════ COLUMN 2 — LIST ══════ --}}
        <div class="min-w-0 rounded-2xl border border-hairline bg-panel">

            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
                <h2 class="text-[0.95rem] font-medium">
                    All tags <span class="ml-1.5 text-paper/35">{{ $this->tags->total() }}</span>
                </h2>

                <div class="flex items-center gap-2">
                    <button wire:click="$toggle('unusedOnly')"
                            @class([
                                'rounded-full px-3.5 py-2 text-[0.78rem] transition duration-200 ease-dbelo',
                                'bg-action text-white' => $unusedOnly,
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

            {{-- Bulk bar: only appears when it can do something --}}
            @if (count($selected) > 0)
                <div class="flex flex-wrap items-center gap-3 border-b border-hairline bg-action/[0.10] px-5 py-3">
                    <span class="text-[0.85rem]">{{ count($selected) }} selected</span>

                    @if (count($selected) > 1)
                        <div class="flex items-center gap-2">
                            <span class="text-[0.8rem] text-paper/45">Merge into</span>
                            <select wire:model="mergeTarget"
                                    class="rounded-lg border-0 bg-raised px-3 py-1.5 text-[0.82rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                                <option value="">Choose…</option>
                                @foreach ($this->selectedTags as $tag)
                                    <option value="{{ $tag->id }}">{{ $tag->name }} ({{ $tag->posts_count }})</option>
                                @endforeach
                            </select>
                            <button wire:click="merge" @disabled(! $mergeTarget)
                                    class="rounded-lg bg-action px-4 py-1.5 text-[0.82rem] font-medium text-white transition hover:brightness-110 disabled:opacity-40">
                                Merge
                            </button>
                        </div>
                    @endif

                    <div class="ml-auto flex items-center gap-2">
                        <button wire:click="deleteSelected"
                                wire:confirm="Delete {{ count($selected) }} tag(s)? They will be removed from every post."
                                class="rounded-lg bg-raised px-4 py-1.5 text-[0.82rem] text-paper/70 transition hover:bg-danger/15 hover:text-danger">
                            Delete
                        </button>
                        <x-admin.icon-button icon="xmark" variant="muted" label="Clear selection" wire:click="clearSelection" />
                    </div>
                </div>
            @endif

            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                        <th class="w-10 px-5 py-2.5"></th>
                        <th class="w-12 px-2 py-2.5 font-medium">#</th>
                        <th class="px-3 py-2.5 font-medium">Name</th>
                        <th class="w-40 px-3 py-2.5 font-medium">URL</th>
                        <th class="w-20 px-3 py-2.5 font-medium">Posts</th>
                        <th class="w-[120px] px-5 py-2.5 text-right font-medium">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-hairline">
                    @php $row = ($this->tags->currentPage() - 1) * $this->tags->perPage(); @endphp

                    @forelse ($this->tags as $tag)
                        @php $row++; @endphp

                        <tr wire:key="tag-{{ $tag->id }}" class="transition hover:bg-paper/[0.03]">
                            <td class="px-5 py-3">
                                <input type="checkbox" wire:model.live="selected" value="{{ $tag->id }}"
                                       class="size-4 rounded border-hairline bg-raised text-brand focus:ring-brand" />
                            </td>

                            <td class="px-2 py-3 text-[0.8rem] tabular-nums text-paper/25">{{ $row }}</td>

                            <td class="px-3 py-3">
                                @if ($editing === $tag->id)
                                    <div class="flex items-center gap-2">
                                        <input type="text" wire:model="editName" autofocus wire:keydown.enter="update"
                                               class="min-w-0 flex-1 rounded-lg border-0 bg-raised px-3 py-2 text-[0.86rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                                        <x-admin.icon-button icon="check" label="Save" class="!text-success hover:!bg-success/15" wire:click="update" />
                                        <x-admin.icon-button icon="xmark" variant="muted" label="Cancel" wire:click="cancel" />
                                    </div>
                                    @error('editName') <p class="mt-1.5 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                                @else
                                    <span class="text-[0.89rem]">{{ $tag->name }}</span>
                                @endif
                            </td>

                            <td class="px-3 py-3 font-mono text-[0.73rem] text-paper/40">/{{ $tag->slug }}</td>

                            <td class="px-3 py-3">
                                <span @class([
                                    'text-[0.82rem] tabular-nums',
                                    'text-paper/55' => $tag->posts_count,
                                    'text-paper/20' => ! $tag->posts_count,
                                ])>{{ $tag->live_count }}</span>
                            </td>

                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    @if ($tag->live_count)
                                        <a href="{{ route('blog.tag', $tag->slug) }}" target="_blank">
                                            <x-admin.icon-button icon="arrow-up-right-from-square" label="Open on the site" />
                                        </a>
                                    @endif

                                    <x-admin.icon-button icon="pen" label="Rename" wire:click="edit({{ $tag->id }})" />

                                    <x-admin.icon-button icon="trash" label="Delete"
                                                         class="hover:!bg-danger/15 hover:!text-danger"
                                                         wire:click="delete({{ $tag->id }})"
                                                         wire:confirm="Delete “{{ $tag->name }}”? It will be removed from every post." />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-16 text-center">
                                <x-icon name="tags" style="regular" class="text-[24px] text-paper/20" />
                                <p class="mt-3 text-[0.9rem] text-paper/45">
                                    {{ $search || $unusedOnly ? 'Nothing matches these filters' : 'No tags yet' }}
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
                            <button wire:click="gotoPage({{ $page }})" wire:key="ptpg-{{ $page }}"
                                    @class([
                                        'grid size-8 place-items-center rounded-full text-[0.78rem] tabular-nums transition duration-200 ease-dbelo',
                                        'bg-brand text-white' => $page === $this->tags->currentPage(),
                                        'text-paper/40 hover:bg-paper/[0.10] hover:text-paper' => $page !== $this->tags->currentPage(),
                                    ])>{{ $page }}</button>
                        @endforeach

                        <x-admin.icon-button icon="chevron-right" variant="muted" label="Next"
                                             wire:click="nextPage" @disabled(! $this->tags->hasMorePages()) />
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
