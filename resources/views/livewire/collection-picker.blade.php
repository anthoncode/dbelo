<div class="relative" @click.outside="$wire.open = false">
    <button type="button" wire:click="toggleOpen"
            class="flex shrink-0 items-center gap-2 rounded-full bg-paper px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md dark:bg-paper/10">
        <x-icon name="folder-music" :style="count($this->memberOf) ? 'solid' : 'regular'" class="text-sm" />
        {{ count($this->memberOf) ? 'In '.count($this->memberOf).' '.Str::plural('collection', count($this->memberOf)) : 'Add to collection' }}
    </button>

    @if ($open)
        <div class="absolute right-0 top-full z-40 mt-3 w-72 rounded-card bg-surface p-2 shadow-soft-lg dark:bg-surface-dark">

            @forelse ($this->collections as $collection)
                <button type="button" wire:click="toggleCollection({{ $collection->id }})" wire:key="col-{{ $collection->id }}"
                        class="flex w-full items-center gap-3 rounded-control px-4 py-2.5 text-left transition hover:bg-ink/[0.05] dark:hover:bg-paper/10">
                    <span @class([
                        'grid size-5 shrink-0 place-items-center rounded-md text-[0.6rem]',
                        'bg-brand text-white' => in_array($collection->id, $this->memberOf, true),
                        'bg-ink/10 dark:bg-paper/15' => ! in_array($collection->id, $this->memberOf, true),
                    ])>
                        @if (in_array($collection->id, $this->memberOf, true)) ✓ @endif
                    </span>
                    <span class="min-w-0 flex-1 truncate text-[0.9rem]">{{ $collection->name }}</span>
                    <span class="micro shrink-0">{{ $collection->sounds_count }}</span>
                </button>
            @empty
                <p class="micro px-4 py-3">No collections yet</p>
            @endforelse

            <form wire:submit="create" class="mt-1 flex gap-2 border-t border-ink/[0.07] p-2 pt-3 dark:border-paper/10">
                <input type="text" wire:model="newName" placeholder="New collection"
                       class="min-w-0 flex-1 rounded-control border-0 bg-paper px-3 py-2 text-[0.85rem] shadow-soft-sm placeholder:text-ink/30 focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-paper/10 dark:placeholder:text-paper/30" />
                <button type="submit" class="shrink-0 rounded-control bg-brand px-3 py-2 text-white shadow-brand">
                    <x-icon name="plus" style="solid" class="text-xs" />
                </button>
            </form>
            @error('newName') <p class="px-4 pb-2 text-sm text-brand">{{ $message }}</p> @enderror
        </div>
    @endif
</div>
