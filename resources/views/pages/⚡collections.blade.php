<?php

use App\Models\Collection;
use App\Models\Setting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The public collections directory.
 *
 * ── WHAT IS ON THIS PAGE, AND WHAT IS DELIBERATELY NOT ───────────────────
 *
 * Only collections whose owner chose "Listed". Sharing a link is a separate
 * decision and puts nothing here — the whole reason the third state exists
 * is that a person who sends one link to one client has not asked to be in a
 * directory. Collection::scopeListed is the single definition of that rule.
 *
 * Packs are excluded even though they live in the same table, because they
 * already have /packs. Two pages showing the same twelve boxes under two
 * names is how a visitor learns to trust neither.
 *
 * ── NO COVERS ────────────────────────────────────────────────────────────
 *
 * Packs get covers because a pack is chosen by how it looks — dbelo designed
 * one for each. A collection has no image and never will; inventing one
 * would be a picture of nothing, and twelve pictures of nothing make a page
 * slower to scan than twelve lines of text. So: rows, with the name, who
 * made it, and how much is in it.
 */
new #[Layout('layouts.site')] #[Title('Collections')] class extends Component {
    use WithPagination;

    public function mount(): void
    {
        view()->share('seo', [
            'title' => 'Collections',
            'description' => 'Sound effect collections put together by the dbelo community — grouped by project, mood and use.',
            /*
             * ── PAGE TWO IS NOT PAGE ONE ─────────────────────────────────
             *
             * This was route('collections.index') flat, written before
             * App\Support\Canonical existed — so the second page of the
             * directory told Google it was a copy of the first, and the
             * collections listed from there down were never reached.
             */
            'canonical' => \App\Support\Canonical::for(
                route('collections.index'),
                request()->query(),
            ),

            /*
             * The same switch that governs each collection page, asked once
             * for the directory: if the individual pages are not indexed,
             * a page that links to all of them should not be either. It is
             * off by default — see config/dbelo.php, `collections`.
             */
            'noindex' => ! filter_var(
                Setting::read('collections.indexable', false),
                FILTER_VALIDATE_BOOLEAN
            ),
        ]);
    }

    #[Computed]
    public function collections()
    {
        return Collection::listed()
            ->with('user')
            ->latest('updated_at')
            ->paginate(12);
    }
}; ?>

<div>
    <div class="mx-auto max-w-4xl">

        <div class="mb-7">
            <div class="micro">The community</div>
            <h1 class="mt-2 text-3xl font-semibold">Collections</h1>
            <p class="mt-3 max-w-[70ch] text-ink/60 dark:text-paper/60">
                Sets of sounds people have grouped for a project and chosen to share.
                Anyone with an account can make one; only the ones marked <em>Listed</em> appear here.
            </p>
        </div>

        <div class="overflow-hidden rounded-card bg-surface shadow-soft-md dark:bg-surface-dark">
            @forelse ($this->collections as $collection)
                <a href="{{ route('collections.show', $collection) }}" wire:navigate
                   wire:key="pub-col-{{ $collection->id }}"
                   class="flex flex-wrap items-center gap-4 border-b border-ink/[0.06] px-5 py-4 transition duration-300 ease-dbelo last:border-0 hover:bg-ink/[0.02] dark:border-paper/[0.07] dark:hover:bg-paper/[0.03]">

                    <span class="grid size-10 shrink-0 place-items-center rounded-[14px] bg-ink/[0.05] text-brand dark:bg-paper/10">
                        <x-icon name="folder-music" style="solid" class="text-[0.9rem]" />
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[1rem] transition hover:text-brand">{{ $collection->name }}</span>

                        <span class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[0.78rem] text-ink/45 dark:text-paper/45">
                            <span>{{ $collection->sounds_count }} {{ Str::plural('sound', $collection->sounds_count) }}</span>
                            <span>·</span>
                            <span>by {{ $collection->user?->name ?? 'Deleted user' }}</span>

                            @if (filled($collection->description))
                                <span>·</span>
                                <span class="truncate">{{ $collection->description }}</span>
                            @endif
                        </span>
                    </span>

                    <x-icon name="arrow-right" style="solid" class="shrink-0 text-[0.75rem] text-ink/25 dark:text-paper/25" />
                </a>
            @empty
                {{-- Nobody has listed one yet, and that is a normal state on a
                     young site rather than something to apologise for. The
                     line says what would put a collection here, because the
                     person reading it is the one who could. --}}
                <div class="py-16 text-center">
                    <p class="text-lg">No collections shared yet</p>
                    <p class="micro mt-2">Make one in your library and set it to Listed to see it here</p>
                </div>
            @endforelse
        </div>

        @if ($this->collections->hasPages())
            <div class="mt-5">{{ $this->collections->links() }}</div>
        @endif
    </div>
</div>
