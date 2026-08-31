<?php

use App\Models\PostCategory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Blog categories')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')] public string $search = '';

    // ── Create ──
    public string $name = '';
    public string $description = '';

    // ── Inline edit, in the same row ──
    public ?int $editing = null;
    public string $editName = '';
    public string $editDescription = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function categories()
    {
        return PostCategory::query()
            ->withCount(['posts', 'posts as live_count' => fn ($q) => $q->live()])
            ->when($this->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(10);
    }

    #[Computed]
    public function totals(): array
    {
        return [
            'all' => PostCategory::count(),
            'empty' => PostCategory::doesntHave('posts')->count(),
        ];
    }

    public function create(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'description' => ['nullable', 'string', 'max:300'],
        ]);

        PostCategory::create([
            'name' => trim($this->name),
            'slug' => PostCategory::uniqueSlug($this->name),
            'description' => $this->description ?: null,
            'sort_order' => (PostCategory::max('sort_order') ?? 0) + 1,
        ]);

        $this->reset(['name', 'description']);
        $this->refresh();

        session()->flash('ok', 'Category added.');
    }

    public function edit(int $id): void
    {
        $category = PostCategory::findOrFail($id);

        $this->editing = $id;
        $this->editName = $category->name;
        $this->editDescription = (string) $category->description;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset(['editing', 'editName', 'editDescription']);
        $this->resetErrorBag();
    }

    public function update(): void
    {
        $this->validate([
            'editName' => ['required', 'string', 'min:2', 'max:80'],
            'editDescription' => ['nullable', 'string', 'max:300'],
        ]);

        // The slug is deliberately not regenerated on rename:
        // /blog/category/tutorials is a URL somebody may already have linked.
        PostCategory::whereKey($this->editing)->update([
            'name' => trim($this->editName),
            'description' => $this->editDescription ?: null,
        ]);

        $this->cancel();
        $this->refresh();
    }

    public function move(int $id, int $direction): void
    {
        $category = PostCategory::findOrFail($id);

        $neighbour = PostCategory::query()
            ->when($direction < 0,
                fn ($q) => $q->where('sort_order', '<', $category->sort_order)->orderByDesc('sort_order'),
                fn ($q) => $q->where('sort_order', '>', $category->sort_order)->orderBy('sort_order'))
            ->first();

        if (! $neighbour) {
            return;
        }

        [$a, $b] = [$category->sort_order, $neighbour->sort_order];

        $category->update(['sort_order' => $b]);
        $neighbour->update(['sort_order' => $a]);

        $this->refresh();
    }

    public function delete(int $id): void
    {
        $category = PostCategory::withCount('posts')->findOrFail($id);

        if ($category->posts_count) {
            session()->flash('error', "“{$category->name}” still has {$category->posts_count} post(s). Move them somewhere else first.");

            return;
        }

        $category->delete();
        $this->refresh();

        session()->flash('ok', 'Category deleted.');
    }

    protected function refresh(): void
    {
        unset($this->categories, $this->totals);
    }
}; ?>

<div>
    @if (session('ok'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-success/30 bg-success/10 px-4 py-3">
            <x-icon name="circle-check" style="solid" class="text-success" />
            <span class="text-[0.88rem]">{{ session('ok') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-danger/30 bg-danger/10 px-4 py-3">
            <x-icon name="triangle-exclamation" style="solid" class="text-danger" />
            <span class="text-[0.88rem]">{{ session('error') }}</span>
        </div>
    @endif

    <div class="grid gap-5 xl:grid-cols-[340px_1fr]">

        {{-- ══════ COLUMN 1 — ADD ══════ --}}
        <div class="h-fit space-y-5">
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                    <x-admin.icon-chip icon="folder-plus" tone="brand" />
                    <h2 class="text-[0.95rem] font-medium">Add category</h2>
                </div>

                <form wire:submit="create" class="space-y-4 p-5">
                    <div>
                        <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Name</label>
                        <input type="text" wire:model="name" placeholder="Tutorials"
                               class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.9rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                        @error('name') <p class="mt-1.5 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Description</label>
                        <textarea wire:model="description" rows="3" placeholder="Shown at the top of the category page."
                                  class="w-full resize-none rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.88rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40"></textarea>
                        @error('description') <p class="mt-1.5 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-[0.75rem] leading-relaxed text-paper/30">
                            A category page with its own text ranks; one that is only a list of links does not.
                        </p>
                    </div>

                    <button type="submit"
                            class="flex w-full items-center justify-center gap-2 rounded-lg bg-action py-2.5 text-[0.88rem] font-medium text-white transition duration-200 ease-dbelo hover:brightness-110">
                        <x-icon name="plus" style="solid" class="text-[12px]" />
                        Add category
                    </button>
                </form>
            </div>

            <div class="rounded-2xl border border-hairline bg-panel p-5">
                <div class="flex items-center gap-3">
                    <x-admin.icon-chip icon="circle-info" tone="muted" />
                    <div class="min-w-0 flex-1">
                        <div class="text-[0.88rem]">{{ $this->totals['all'] }} categories</div>
                        <div class="text-[0.75rem] text-paper/30">{{ $this->totals['empty'] }} with no posts</div>
                    </div>
                </div>

                <p class="mt-4 text-[0.75rem] leading-relaxed text-paper/30">
                    These belong to the blog only. They never appear in the sound catalogue, and their URLs live
                    under <span class="text-paper/50">/blog/category/</span> so they cannot collide with it.
                </p>
            </div>
        </div>

        {{-- ══════ COLUMN 2 — LIST ══════ --}}
        <div class="min-w-0 rounded-2xl border border-hairline bg-panel">

            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
                <h2 class="text-[0.95rem] font-medium">
                    All categories <span class="ml-1.5 text-paper/35">{{ $this->categories->total() }}</span>
                </h2>

                <div class="flex items-center gap-2.5 rounded-lg bg-raised px-3 py-2">
                    <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Filter…"
                           class="w-32 border-0 bg-transparent p-0 text-[0.83rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-0" />
                </div>
            </div>

            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                        <th class="w-12 px-5 py-2.5 font-medium">#</th>
                        <th class="px-3 py-2.5 font-medium">Name</th>
                        <th class="w-40 px-3 py-2.5 font-medium">URL</th>
                        <th class="w-20 px-3 py-2.5 font-medium">Posts</th>
                        <th class="w-[150px] px-5 py-2.5 text-right font-medium">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-hairline">
                    @php $row = ($this->categories->currentPage() - 1) * $this->categories->perPage(); @endphp

                    @forelse ($this->categories as $category)
                        @php $row++; @endphp

                        @if ($editing === $category->id)
                            {{-- Editing happens in the row itself, not on another screen --}}
                            <tr wire:key="edit-{{ $category->id }}" class="bg-raised">
                                <td class="px-5 py-4 text-[0.8rem] tabular-nums text-paper/25">{{ $row }}</td>
                                <td colspan="4" class="px-3 py-4 pr-5">
                                    <div class="space-y-3">
                                        <input type="text" wire:model="editName" autofocus wire:keydown.enter="update"
                                               class="w-full rounded-lg border-0 bg-panel px-3.5 py-2.5 text-[0.9rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                                        @error('editName') <p class="text-[0.8rem] text-danger">{{ $message }}</p> @enderror

                                        <textarea wire:model="editDescription" rows="2" placeholder="Description"
                                                  class="w-full resize-none rounded-lg border-0 bg-panel px-3.5 py-2.5 text-[0.86rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40"></textarea>
                                        @error('editDescription') <p class="text-[0.8rem] text-danger">{{ $message }}</p> @enderror

                                        <div class="flex items-center gap-2">
                                            <button wire:click="update"
                                                    class="flex items-center gap-2 rounded-lg bg-action px-5 py-2.5 text-[0.85rem] font-medium text-white transition hover:brightness-110">
                                                <x-icon name="check" style="solid" class="text-[11px]" />
                                                Save
                                            </button>
                                            <button wire:click="cancel"
                                                    class="rounded-lg bg-panel px-4 py-2.5 text-[0.85rem] text-paper/55 transition hover:text-paper">
                                                Cancel
                                            </button>

                                            <span class="ml-auto flex items-center gap-2 text-[0.75rem] text-paper/25">
                                                <x-icon name="lock" style="solid" class="text-[10px]" />
                                                /blog/category/{{ $category->slug }}
                                            </span>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @else
                            <tr wire:key="cat-{{ $category->id }}" class="transition hover:bg-paper/[0.03]">
                                <td class="px-5 py-3 text-[0.8rem] tabular-nums text-paper/25">{{ $row }}</td>

                                <td class="px-3 py-3">
                                    <div class="text-[0.89rem]">{{ $category->name }}</div>
                                    @if ($category->description)
                                        <div class="truncate text-[0.73rem] text-paper/25">{{ $category->description }}</div>
                                    @else
                                        <div class="text-[0.73rem] text-warning/60">No description</div>
                                    @endif
                                </td>

                                <td class="px-3 py-3 font-mono text-[0.73rem] text-paper/40">/{{ $category->slug }}</td>

                                <td class="px-3 py-3">
                                    <span class="text-[0.82rem] tabular-nums text-paper/55">{{ $category->live_count }}</span>
                                    @if ($category->posts_count > $category->live_count)
                                        <span class="group/tip relative ml-1 text-[0.73rem] text-paper/25">
                                            +{{ $category->posts_count - $category->live_count }}
                                            <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">
                                                drafts or scheduled
                                            </span>
                                        </span>
                                    @endif
                                </td>

                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-admin.icon-button icon="arrow-up" variant="muted" label="Move up"
                                                             wire:click="move({{ $category->id }}, -1)" />
                                        <x-admin.icon-button icon="arrow-down" variant="muted" label="Move down"
                                                             wire:click="move({{ $category->id }}, 1)" />

                                        @if ($category->live_count)
                                            <a href="{{ route('blog.category', $category->slug) }}" target="_blank">
                                                <x-admin.icon-button icon="arrow-up-right-from-square" label="Open on the site" />
                                            </a>
                                        @endif

                                        <x-admin.icon-button icon="pen" label="Edit" wire:click="edit({{ $category->id }})" />

                                        <x-admin.icon-button icon="trash" label="Delete"
                                                             class="hover:!bg-danger/15 hover:!text-danger"
                                                             wire:click="delete({{ $category->id }})" />
                                    </div>
                                </td>
                            </tr>
                        @endif
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
                        {{ $this->categories->firstItem() }}–{{ $this->categories->lastItem() }} of {{ $this->categories->total() }}
                    </span>

                    <div class="flex items-center gap-1.5">
                        <x-admin.icon-button icon="chevron-left" variant="muted" label="Previous"
                                             wire:click="previousPage" @disabled($this->categories->onFirstPage()) />

                        @foreach ($this->categories->getUrlRange(max(1, $this->categories->currentPage() - 2), min($this->categories->lastPage(), $this->categories->currentPage() + 2)) as $page => $url)
                            <button wire:click="gotoPage({{ $page }})" wire:key="pcpg-{{ $page }}"
                                    @class([
                                        'grid size-8 place-items-center rounded-full text-[0.78rem] tabular-nums transition duration-200 ease-dbelo',
                                        'bg-brand text-white' => $page === $this->categories->currentPage(),
                                        'text-paper/40 hover:bg-paper/[0.10] hover:text-paper' => $page !== $this->categories->currentPage(),
                                    ])>{{ $page }}</button>
                        @endforeach

                        <x-admin.icon-button icon="chevron-right" variant="muted" label="Next"
                                             wire:click="nextPage" @disabled(! $this->categories->hasMorePages()) />
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
