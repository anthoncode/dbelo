<?php

use App\Models\Category;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Categories')] class extends Component {
    use WithPagination;

    // ── New category ──
    public string $name = '';
    public string $parentId = '';
    public string $icon = '';
    public string $description = '';

    // ── Inline editing ──
    public ?int $editingId = null;
    public string $editName = '';
    public string $editParentId = '';
    public string $editIcon = '';

    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    /**
     * Paginated by PARENT, not by row: splitting a parent from its children
     * across two pages would break the tree the table is meant to show.
     */
    #[Computed]
    public function categories()
    {
        return Category::query()
            ->whereNull('parent_id')
            ->with(['children' => fn ($q) => $q->withCount('sounds')->orderBy('sort_order')])
            ->withCount('sounds')
            ->when($this->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhereHas('children', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->orderBy('sort_order')
            // Two parents per page, each with its full set of children.
            ->paginate(2);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function parents()
    {
        return Category::whereNull('parent_id')->orderBy('sort_order')->get();
    }

    #[Computed]
    public function totals(): array
    {
        return [
            'all' => Category::count(),
            'roots' => Category::whereNull('parent_id')->count(),
        ];
    }

    public function create(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'parentId' => ['nullable', 'exists:categories,id'],
            'icon' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        // A child slug carries its parent so "Wood" under Foley and "Wood"
        // under Machines can coexist.
        $parent = $this->parentId ? Category::find($this->parentId) : null;
        $slugBase = $parent ? $parent->name.' '.$this->name : $this->name;

        Category::create([
            'name' => $this->name,
            'slug' => $this->uniqueSlug($slugBase),
            'parent_id' => $this->parentId ?: null,
            'icon' => $this->icon ?: null,
            'description' => $this->description ?: null,
            'sort_order' => Category::where('parent_id', $this->parentId ?: null)->max('sort_order') + 1,
        ]);

        $this->reset(['name', 'parentId', 'icon', 'description']);
        $this->refreshLists();
        $this->resetPage();

        session()->flash('ok', 'Category created.');
    }

    public function startEdit(int $id): void
    {
        $category = Category::findOrFail($id);

        $this->editingId = $id;
        $this->editName = $category->name;
        $this->editParentId = (string) ($category->parent_id ?? '');
        $this->editIcon = (string) $category->icon;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editName', 'editParentId', 'editIcon']);
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        $this->validate([
            'editName' => ['required', 'string', 'max:80'],
            'editParentId' => ['nullable', 'exists:categories,id'],
            'editIcon' => ['nullable', 'string', 'max:40'],
        ]);

        $category = Category::findOrFail($this->editingId);

        // A category cannot be its own parent, nor the child of one of its
        // own children: that would orphan a whole branch of the tree.
        if ((int) $this->editParentId === $category->id) {
            $this->addError('editParentId', 'A category cannot be its own parent.');

            return;
        }

        if ($category->children()->count() && $this->editParentId) {
            $this->addError('editParentId', 'This category has children, so it must stay at the top level.');

            return;
        }

        $category->update([
            'name' => $this->editName,
            'parent_id' => $this->editParentId ?: null,
            'icon' => $this->editIcon ?: null,
        ]);

        // The slug is deliberately left alone: it is already indexed by
        // Google and used in shared URLs.
        $this->cancelEdit();
        $this->refreshLists();

        session()->flash('ok', 'Category updated.');
    }

    public function delete(int $id): void
    {
        $category = Category::withCount(['sounds', 'children'])->findOrFail($id);

        if ($category->children_count > 0) {
            session()->flash('error', "“{$category->name}” has subcategories. Move or delete them first.");

            return;
        }

        if ($category->sounds_count > 0) {
            session()->flash('error', "“{$category->name}” still holds {$category->sounds_count} sounds. Reassign them first.");

            return;
        }

        $category->delete();
        $this->refreshLists();

        session()->flash('ok', 'Category deleted.');
    }

    public function move(int $id, string $direction): void
    {
        $category = Category::findOrFail($id);

        $neighbour = Category::where('parent_id', $category->parent_id)
            ->when($direction === 'up',
                fn ($q) => $q->where('sort_order', '<', $category->sort_order)->orderByDesc('sort_order'),
                fn ($q) => $q->where('sort_order', '>', $category->sort_order)->orderBy('sort_order'))
            ->first();

        if (! $neighbour) {
            return;
        }

        [$a, $b] = [$category->sort_order, $neighbour->sort_order];
        $category->update(['sort_order' => $b]);
        $neighbour->update(['sort_order' => $a]);

        $this->refreshLists();
    }

    protected function refreshLists(): void
    {
        unset($this->categories, $this->parents, $this->totals);
    }

    protected function uniqueSlug(string $value): string
    {
        $base = Str::slug($value) ?: 'category';
        $slug = $base;
        $i = 2;

        while (Category::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}; ?>

<div>
    {{-- Flash --}}
    @if (session('ok'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-brand/30 bg-brand/10 px-4 py-3">
            <x-icon name="circle-check" style="solid" class="text-brand" />
            <span class="text-[0.88rem]">{{ session('ok') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-hairline bg-raised px-4 py-3">
            <x-icon name="triangle-exclamation" style="solid" class="text-paper/60" />
            <span class="text-[0.88rem] text-paper/80">{{ session('error') }}</span>
        </div>
    @endif

    <div class="grid gap-5 xl:grid-cols-[340px_1fr]">

        {{-- ══════ COLUMN 1 — ADD ══════ --}}
        <div class="h-fit rounded-2xl border border-hairline bg-panel">
            <div class="border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">Add category</h2>
            </div>

            <form wire:submit="create" class="space-y-4 p-5">

                <div>
                    <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Name</label>
                    <input type="text" wire:model="name" placeholder="Thunder"
                           class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.9rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                    @error('name') <p class="mt-1.5 text-[0.8rem] text-brand">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Parent</label>
                    <select wire:model="parentId"
                            class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.9rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                        <option value="">— Top level —</option>
                        @foreach ($this->parents as $parent)
                            <option value="{{ $parent->id }}">{{ $parent->name }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1.5 text-[0.75rem] text-paper/30">Leave empty to create a main category.</p>
                </div>

                <div>
                    <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Icon</label>
                    <div class="flex gap-2">
                        <x-admin.icon-chip :icon="$icon ?: 'waveform-lines'" size="size-[42px]" class="text-[15px]" />
                        <input type="text" wire:model.live.debounce.400ms="icon" placeholder="cloud-bolt"
                               class="min-w-0 flex-1 rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.9rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                    </div>
                    <p class="mt-1.5 text-[0.75rem] text-paper/30">Font Awesome name, without the <code class="text-paper/45">fa-</code> prefix.</p>
                </div>

                <div>
                    <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Description</label>
                    <textarea wire:model="description" rows="3" placeholder="Shown on the category page and used for SEO."
                              class="w-full resize-none rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.9rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40"></textarea>
                </div>

                <button type="submit"
                        class="flex w-full items-center justify-center gap-2 rounded-lg bg-brand py-2.5 text-[0.88rem] font-medium text-white transition duration-200 hover:opacity-90">
                    <x-icon name="plus" style="solid" class="text-[12px]" />
                    Add category
                </button>
            </form>
        </div>

        {{-- ══════ COLUMN 2 — TABLE ══════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">

            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
                <h2 class="text-[0.95rem] font-medium">
                    All categories
                    <span class="ml-1.5 text-paper/35">{{ $this->totals['all'] }}</span>
                </h2>

                <div class="flex items-center gap-2.5 rounded-lg bg-raised px-3 py-2">
                    <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Filter…"
                           class="w-36 border-0 bg-transparent p-0 text-[0.83rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-0" />
                </div>
            </div>

            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                        <th class="w-12 px-5 py-2.5 font-medium">#</th>
                        <th class="px-3 py-2.5 font-medium">Name</th>
                        <th class="w-40 px-3 py-2.5 font-medium">Parent</th>
                        <th class="w-24 px-3 py-2.5 text-right font-medium">Sounds</th>
                        <th class="w-[132px] px-5 py-2.5 text-right font-medium">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-hairline">
                    @php $row = ($this->categories->currentPage() - 1) * $this->categories->perPage(); @endphp

                    @forelse ($this->categories as $parent)
                        @foreach (collect([$parent])->concat($parent->children) as $category)
                            @php
                                $row++;
                                $isChild = $category->parent_id !== null;
                                $isEditing = $editingId === $category->id;
                            @endphp

                            <tr wire:key="cat-{{ $category->id }}"
                                class="transition {{ $isEditing ? 'bg-raised' : 'hover:bg-paper/[0.03]' }}">

                                <td class="px-5 py-2.5 text-[0.8rem] tabular-nums text-paper/25">{{ $row }}</td>

                                @if ($isEditing)
                                    {{-- ── Editing in place: the row turns into
                                         its own form, so nothing moves and the
                                         context around it stays visible ── --}}
                                    <td class="px-3 py-2">
                                        <div class="flex gap-2">
                                            <x-admin.icon-chip :icon="$editIcon ?: 'waveform-lines'" size="size-9" class="text-[13px]" />
                                            <input type="text" wire:model="editName" autofocus
                                                   wire:keydown.enter="saveEdit" wire:keydown.escape="cancelEdit"
                                                   class="min-w-0 flex-1 rounded-lg border-0 bg-panel px-3 py-2 text-[0.88rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                                            <input type="text" wire:model.live.debounce.400ms="editIcon" placeholder="icon"
                                                   class="w-24 shrink-0 rounded-lg border-0 bg-panel px-3 py-2 text-[0.82rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                                        </div>
                                        @error('editName') <p class="mt-1 text-[0.78rem] text-brand">{{ $message }}</p> @enderror
                                    </td>

                                    <td class="px-3 py-2">
                                        <select wire:model="editParentId"
                                                class="w-full rounded-lg border-0 bg-panel px-3 py-2 text-[0.82rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                                            <option value="">— Top level —</option>
                                            @foreach ($this->parents as $option)
                                                @continue($option->id === $category->id)
                                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('editParentId') <p class="mt-1 text-[0.78rem] text-brand">{{ $message }}</p> @enderror
                                    </td>

                                    <td class="px-3 py-2 text-right text-[0.85rem] tabular-nums text-paper/35">
                                        {{ $category->sounds_count }}
                                    </td>

                                    <td class="px-5 py-2">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <x-admin.icon-button icon="check" variant="brand" label="Save changes" wire:click="saveEdit" />
                                            <x-admin.icon-button icon="xmark" label="Cancel" wire:click="cancelEdit" />
                                        </div>
                                    </td>

                                @else
                                    <td class="px-3 py-2.5">
                                        <div class="flex items-center gap-3 {{ $isChild ? 'pl-6' : '' }}">
                                            @if ($isChild)
                                                <span class="-ml-4 size-[5px] shrink-0 rounded-full bg-paper/20"></span>
                                            @endif

                                            <x-admin.icon-chip :icon="$category->icon ?: 'waveform-lines'"
                                                               :tone="$isChild ? 'muted' : 'brand'" />

                                            <div class="min-w-0">
                                                <div class="truncate text-[0.89rem] {{ $isChild ? 'text-paper/75' : '' }}">{{ $category->name }}</div>
                                                <div class="truncate text-[0.72rem] text-paper/25">/{{ $category->slug }}</div>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="px-3 py-2.5 text-[0.83rem] text-paper/40">
                                        {{ $isChild ? $parent->name : '—' }}
                                    </td>

                                    <td class="px-3 py-2.5 text-right">
                                        <span @class([
                                            'rounded-full px-2.5 py-1 text-[0.75rem] tabular-nums',
                                            'bg-raised text-paper/70' => $category->sounds_count > 0,
                                            'text-paper/20' => $category->sounds_count === 0,
                                        ])>{{ $category->sounds_count }}</span>
                                    </td>

                                    <td class="px-5 py-2.5">
                                        <div class="flex items-center justify-end gap-1">
                                            <x-admin.icon-button icon="chevron-up" variant="muted" label="Move up"
                                                                 wire:click="move({{ $category->id }}, 'up')" />
                                            <x-admin.icon-button icon="chevron-down" variant="muted" label="Move down"
                                                                 wire:click="move({{ $category->id }}, 'down')" />
                                            <x-admin.icon-button icon="pen" label="Edit"
                                                                 wire:click="startEdit({{ $category->id }})" />
                                            <x-admin.icon-button icon="trash" label="Delete"
                                                                 wire:click="delete({{ $category->id }})"
                                                                 wire:confirm="Delete “{{ $category->name }}”?" />
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-16 text-center">
                                <x-icon name="folder-tree" style="regular" class="text-[24px] text-paper/20" />
                                <p class="mt-3 text-[0.9rem] text-paper/45">
                                    {{ $search ? 'Nothing matches that filter' : 'No categories yet' }}
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if ($this->categories->hasPages())
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-hairline px-5 py-3.5">
                    <span class="text-[0.78rem] text-paper/35">
                        {{ $this->categories->firstItem() }}–{{ $this->categories->lastItem() }}
                        of {{ $this->categories->total() }} main categories
                    </span>

                    <div class="flex items-center gap-1.5">
                        <x-admin.icon-button icon="chevron-left" variant="muted" label="Previous"
                                             wire:click="previousPage"
                                             @disabled($this->categories->onFirstPage()) />

                        @foreach ($this->categories->getUrlRange(1, $this->categories->lastPage()) as $page => $url)
                            <button wire:click="gotoPage({{ $page }})" wire:key="pg-{{ $page }}"
                                    @class([
                                        'grid size-8 place-items-center rounded-full text-[0.78rem] tabular-nums transition duration-200 ease-dbelo',
                                        'bg-brand text-white' => $page === $this->categories->currentPage(),
                                        'text-paper/40 hover:bg-paper/[0.10] hover:text-paper' => $page !== $this->categories->currentPage(),
                                    ])>
                                {{ $page }}
                            </button>
                        @endforeach

                        <x-admin.icon-button icon="chevron-right" variant="muted" label="Next"
                                             wire:click="nextPage"
                                             @disabled(! $this->categories->hasMorePages()) />
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
