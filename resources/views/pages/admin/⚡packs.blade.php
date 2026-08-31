<?php

use App\Models\Collection as Pack;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Packs')] class extends Component {
    use WithPagination;

    // ── New pack ──
    public string $name = '';
    public string $description = '';

    // ── Inline editing ──
    public ?int $editingId = null;
    public string $editName = '';
    public string $editDescription = '';

    public string $search = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    #[Computed]
    public function packs()
    {
        return Pack::query()
            ->packs()
            ->withCount('sounds')
            ->with('user')
            ->when($this->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->latest('updated_at')
            ->paginate(10);
    }

    #[Computed]
    public function totals(): array
    {
        return [
            'all' => Pack::packs()->count(),
            'live' => Pack::packs()->where('is_public', true)->count(),
            'empty' => Pack::packs()->where('sounds_count', 0)->count(),
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $pack = auth()->user()->collections()->create([
            'name' => $this->name,
            'slug' => Pack::uniqueSlug($this->name),
            'description' => $this->description ?: null,
            'is_featured' => true,
            // Created hidden on purpose: an empty pack should never be
            // reachable from the site while it is being filled.
            'is_public' => false,
        ]);

        $this->reset(['name', 'description']);
        $this->refreshLists();
        $this->resetPage();

        $this->redirectRoute('admin.packs.edit', $pack, navigate: true);
    }

    public function startEdit(int $id): void
    {
        $pack = Pack::packs()->findOrFail($id);

        $this->editingId = $id;
        $this->editName = $pack->name;
        $this->editDescription = (string) $pack->description;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editName', 'editDescription']);
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        $this->validate([
            'editName' => ['required', 'string', 'max:80'],
            'editDescription' => ['nullable', 'string', 'max:500'],
        ]);

        Pack::packs()->findOrFail($this->editingId)->update([
            'name' => $this->editName,
            'description' => $this->editDescription ?: null,
        ]);

        $this->cancelEdit();
        $this->refreshLists();

        session()->flash('ok', 'Pack updated.');
    }

    public function togglePublic(int $id): void
    {
        $pack = Pack::packs()->withCount('sounds')->findOrFail($id);

        if (! $pack->is_public && $pack->sounds_count === 0) {
            session()->flash('error', 'Add some sounds before publishing this pack.');

            return;
        }

        $pack->update(['is_public' => ! $pack->is_public]);
        $this->refreshLists();
    }

    public function delete(int $id): void
    {
        $pack = Pack::packs()->findOrFail($id);

        // Only the grouping goes: the sounds themselves stay in the catalogue.
        $pack->sounds()->detach();
        $pack->delete();

        $this->refreshLists();

        session()->flash('ok', 'Pack deleted. The sounds are untouched.');
    }

    protected function refreshLists(): void
    {
        unset($this->packs, $this->totals);
    }
}; ?>

<div>
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
        <div class="h-fit space-y-5">
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="border-b border-hairline px-5 py-4">
                    <h2 class="text-[0.95rem] font-medium">Add pack</h2>
                </div>

                <form wire:submit="create" class="space-y-4 p-5">
                    <div>
                        <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Name</label>
                        <input type="text" wire:model="name" placeholder="Horror Essentials"
                               class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.9rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                        @error('name') <p class="mt-1.5 text-[0.8rem] text-brand">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Description</label>
                        <textarea wire:model="description" rows="3"
                                  placeholder="Everything you need to score a haunted house scene."
                                  class="w-full resize-none rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.9rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40"></textarea>
                        <p class="mt-1.5 text-[0.75rem] text-paper/30">Shown on the pack page and used for SEO.</p>
                    </div>

                    <button type="submit"
                            class="flex w-full items-center justify-center gap-2 rounded-lg bg-brand py-2.5 text-[0.88rem] font-medium text-white transition duration-200 hover:brightness-115">
                        <x-icon name="plus" style="solid" class="text-[12px]" />
                        Create and add sounds
                    </button>
                    <p class="text-center text-[0.75rem] text-paper/30">Takes you straight to the sound picker.</p>
                </form>
            </div>

            <div class="rounded-2xl border border-hairline bg-panel p-5">
                <div class="flex items-center gap-3">
                    <x-admin.icon-chip icon="box-open" :tone="$this->totals['live'] ? 'brand' : 'muted'" />
                    <div class="min-w-0 flex-1">
                        <div class="text-[0.88rem]">{{ $this->totals['live'] }} live</div>
                        <div class="text-[0.75rem] text-paper/30">of {{ $this->totals['all'] }} packs</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ══════ COLUMN 2 — LIST ══════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">

            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
                <h2 class="text-[0.95rem] font-medium">
                    All packs <span class="ml-1.5 text-paper/35">{{ $this->totals['all'] }}</span>
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
                        <th class="px-3 py-2.5 font-medium">Pack</th>
                        <th class="w-24 px-3 py-2.5 text-right font-medium">Sounds</th>
                        <th class="w-28 px-3 py-2.5 font-medium">Status</th>
                        <th class="w-[132px] px-5 py-2.5 text-right font-medium">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-hairline">
                    @php $row = ($this->packs->currentPage() - 1) * $this->packs->perPage(); @endphp

                    @forelse ($this->packs as $pack)
                        @php
                            $row++;
                            $isEditing = $editingId === $pack->id;
                        @endphp

                        <tr wire:key="pack-{{ $pack->id }}"
                            class="transition {{ $isEditing ? 'bg-raised' : 'hover:bg-paper/[0.03]' }}">

                            <td class="px-5 py-2.5 text-[0.8rem] tabular-nums text-paper/25">{{ $row }}</td>

                            @if ($isEditing)
                                <td class="px-3 py-2" colspan="2">
                                    <input type="text" wire:model="editName" autofocus
                                           wire:keydown.enter="saveEdit" wire:keydown.escape="cancelEdit"
                                           class="w-full rounded-lg border-0 bg-panel px-3 py-2 text-[0.88rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                                    @error('editName') <p class="mt-1 text-[0.78rem] text-brand">{{ $message }}</p> @enderror

                                    <textarea wire:model="editDescription" rows="2" placeholder="Description"
                                              class="mt-2 w-full resize-none rounded-lg border-0 bg-panel px-3 py-2 text-[0.82rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40"></textarea>
                                </td>

                                <td class="px-3 py-2 text-[0.8rem] text-paper/35">—</td>

                                <td class="px-5 py-2">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <x-admin.icon-button icon="check" variant="brand" label="Save" wire:click="saveEdit" />
                                        <x-admin.icon-button icon="xmark" label="Cancel" wire:click="cancelEdit" />
                                    </div>
                                </td>

                            @else
                                <td class="px-3 py-2.5">
                                    <div class="flex items-center gap-3">
                                        <x-admin.icon-chip icon="box-open" :tone="$pack->is_public ? 'brand' : 'muted'" />
                                        <div class="min-w-0">
                                            <a href="{{ route('admin.packs.edit', $pack) }}" wire:navigate
                                               class="block truncate text-[0.89rem] transition hover:text-brand">{{ $pack->name }}</a>
                                            <div class="truncate text-[0.72rem] text-paper/25">
                                                {{ $pack->description ? Str::limit($pack->description, 60) : '/'.$pack->slug }}
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <td class="px-3 py-2.5 text-right">
                                    <span @class([
                                        'rounded-full px-2.5 py-1 text-[0.75rem] tabular-nums',
                                        'bg-raised text-paper/70' => $pack->sounds_count > 0,
                                        'text-paper/20' => $pack->sounds_count === 0,
                                    ])>{{ $pack->sounds_count }}</span>
                                </td>

                                <td class="px-3 py-2.5">
                                    <button wire:click="togglePublic({{ $pack->id }})"
                                            @class([
                                                'flex items-center gap-1.5 rounded-full px-3 py-1 text-[0.75rem] transition duration-200 ease-dbelo',
                                                'bg-brand text-white' => $pack->is_public,
                                                'bg-raised text-paper/50 hover:bg-paper/[0.10] hover:text-paper' => ! $pack->is_public,
                                            ])>
                                        <x-icon :name="$pack->is_public ? 'globe' : 'lock'" style="solid" class="text-[9px]" />
                                        {{ $pack->is_public ? 'Live' : 'Hidden' }}
                                    </button>
                                </td>

                                <td class="px-5 py-2.5">
                                    <div class="flex items-center justify-end gap-1">
                                        <a href="{{ route('collections.show', $pack) }}" target="_blank">
                                            <x-admin.icon-button icon="arrow-up-right-from-square" variant="muted" label="Preview" />
                                        </a>
                                        <a href="{{ route('admin.packs.edit', $pack) }}" wire:navigate>
                                            <x-admin.icon-button icon="list-music" label="Manage sounds" />
                                        </a>
                                        <x-admin.icon-button icon="pen" label="Rename" wire:click="startEdit({{ $pack->id }})" />
                                        <x-admin.icon-button icon="trash" label="Delete"
                                                             wire:click="delete({{ $pack->id }})"
                                                             wire:confirm="Delete “{{ $pack->name }}”? The sounds stay in the catalogue." />
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-16 text-center">
                                <x-icon name="box-open" style="regular" class="text-[24px] text-paper/20" />
                                <p class="mt-3 text-[0.9rem] text-paper/45">
                                    {{ $search ? 'Nothing matches that filter' : 'No packs yet' }}
                                </p>
                                @unless ($search)
                                    <p class="mx-auto mt-2 max-w-sm text-[0.8rem] text-paper/25">
                                        A pack groups sounds that solve one problem together — scoring a horror scene,
                                        starting a podcast — even when they share no category.
                                    </p>
                                @endunless
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            @if ($this->packs->hasPages())
                <div class="flex flex-wrap items-center justify-between gap-3 border-t border-hairline px-5 py-3.5">
                    <span class="text-[0.78rem] text-paper/35">
                        {{ $this->packs->firstItem() }}–{{ $this->packs->lastItem() }} of {{ $this->packs->total() }}
                    </span>

                    <div class="flex items-center gap-1.5">
                        <x-admin.icon-button icon="chevron-left" variant="muted" label="Previous"
                                             wire:click="previousPage" @disabled($this->packs->onFirstPage()) />

                        @foreach ($this->packs->getUrlRange(max(1, $this->packs->currentPage() - 2), min($this->packs->lastPage(), $this->packs->currentPage() + 2)) as $page => $url)
                            <button wire:click="gotoPage({{ $page }})" wire:key="ppg-{{ $page }}"
                                    @class([
                                        'grid size-8 place-items-center rounded-full text-[0.78rem] tabular-nums transition duration-200 ease-dbelo',
                                        'bg-brand text-white' => $page === $this->packs->currentPage(),
                                        'text-paper/40 hover:bg-paper/[0.10] hover:text-paper' => $page !== $this->packs->currentPage(),
                                    ])>{{ $page }}</button>
                        @endforeach

                        <x-admin.icon-button icon="chevron-right" variant="muted" label="Next"
                                             wire:click="nextPage" @disabled(! $this->packs->hasMorePages()) />
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
