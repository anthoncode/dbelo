<?php

use App\Models\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.site')] class extends Component {

    public Collection $collection;

    public function mount(Collection $collection): void
    {
        /*
         * ── A PACK IS NOT A COLLECTION PAGE, EVEN THOUGH IT IS ONE ───────
         *
         * Packs live in this table, so a pack's slug resolves here as
         * happily as anybody's list — and /collections/efectos-prime and
         * /packs/efectos-prime were both serving the same pack under two
         * names, in two designs. Two URLs for one thing is a bug whoever
         * finds it has to reason about: the pack has a cover, a badge and a
         * Free/Pro count on one of them and none of that on the other.
         *
         * A redirect rather than a 404, because the content is real and this
         * is simply not where it lives. It costs one hop and the person ends
         * up on the page that was built for what they asked for.
         *
         * The mirror image is already handled: /packs/{slug} 404s for a
         * collection that is not featured, so a user's list can never leak
         * through the pack name either.
         */
        if ($collection->isPack()) {
            // Assigned before redirecting, not after. $collection is a typed
            // property with no default, and reading one that was never set is
            // a fatal error — so if anything ever renders this component on
            // the way out, it finds a value rather than a crash.
            $this->collection = $collection;

            $this->redirectRoute('packs.show', $collection, navigate: true);

            return;
        }

        // A private collection is only visible to its owner.
        abort_unless(
            $collection->isOpenByLink() || $collection->user_id === auth()->id(),
            404
        );

        $this->collection = $collection->load('user');

        view()->share('seo', [
            'title' => $collection->name,
            'description' => $collection->description
                ?: sprintf('%s — a collection of %d sound effects on dbelo.', $collection->name, $collection->sounds_count),
            'canonical' => route('collections.show', $collection),

            /*
             * ── INDEXED ONLY IF LISTED, AND ONLY IF THE ADMIN SAYS SO ────
             *
             * Two gates, and the owner only holds one of them.
             *
             * A private or unlisted collection is never indexed, whatever the
             * setting says: unlisted means a link somebody was handed, and a
             * page that arrives in search results was not handed to anybody.
             *
             * A LISTED collection is visible to people by its owner's choice,
             * but whether search engines keep it is a decision about the
             * whole domain, not about one list — so it is an admin setting,
             * off by default. The reasons it is off:
             *
             * THE NAMES COLLIDE. Collections are named by whoever made them,
             * and dozens of people name theirs "Podcast" or "Intro". Letting
             * Google index forty pages called Podcast on this domain is
             * asking it to decide which of them dbelo is about — and the
             * answer will not be the catalogue.
             *
             * THE NAMES ARE NOT OURS. A word filter runs when one is created
             * and it is a doormat, not a gate. Whatever gets past it ends up
             * in a search result attached to this domain, and a search result
             * outlives the page by months.
             *
             * AND THERE IS LITTLE TO RANK FOR. A collection is a list of
             * sounds that already have their own pages. Indexed, it competes
             * with them using the same words.
             *
             * The rule is Collection::isIndexable(), not written out here,
             * because the directory page asks the same question.
             */
            'noindex' => ! $collection->isIndexable(),
        ]);
    }

    #[Computed]
    public function sounds()
    {
        return $this->collection->sounds()
            ->published()
            ->with(['files', 'category'])
            ->get();
    }

    /**
     * Shared or private, from the collection's own page.
     *
     * ── WHY THIS IS HERE AND NOT ONLY IN THE LIBRARY ─────────────────────
     *
     * It was only in the library list, so sharing meant leaving the thing you
     * were looking at, going back to a tab, and finding the right row again.
     * Worse, the copy button only appears once a collection is shared, so
     * from in here a private collection offered no route to a link at all —
     * the page simply said "private" and stopped. People reasonably concluded
     * the feature did not exist.
     *
     * The rule itself is not repeated: Collection::toggleShared() owns the
     * name check and the sentence it refuses with, so both screens can only
     * ever say the same thing.
     */
    public function setVisibility(int $id, string $to): void
    {
        abort_unless($this->collection->user_id === auth()->id(), 403);

        // The menu passes the id even here, where there is only ever one
        // collection on screen, so that this line can exist: a request that
        // names a different collection is a request that did not come from
        // this page.
        abort_unless($id === $this->collection->id, 403);

        if ($refusal = $this->collection->setVisibility($to)) {
            session()->flash('error', $refusal);
        }
    }

    public function remove(int $soundId): void
    {
        abort_unless($this->collection->user_id === auth()->id(), 403);

        $this->collection->sounds()->detach($soundId);
        $this->collection->refreshCount();

        unset($this->sounds);
    }
}; ?>

<div>
    <div class="mx-auto max-w-4xl">

        <a href="{{ route('library') }}" wire:navigate class="micro mb-6 inline-flex items-center gap-2 hover:text-brand">
            <x-icon name="arrow-left" style="solid" class="text-xs" /> Library
        </a>

        <div class="mb-7 flex flex-wrap items-end justify-between gap-4">
            <div>
                <div class="micro">Collection</div>
                <h1 class="mt-2 text-3xl font-semibold">{{ $collection->name }}</h1>
                <p class="micro mt-2">
                    {{-- Shared or private is not repeated here. The control on
                         the right already says it, and saying it twice invites
                         the two to disagree after a toggle. --}}
                    {{ $collection->sounds_count }} {{ Str::plural('sound', $collection->sounds_count) }}
                    · by {{ $collection->user->name }}
                </p>
            </div>

            {{-- ══════════════════════════════════════════════════════════
                 WHO CAN SEE THIS, FOR THE OWNER, WHERE THEY ARE STANDING.

                 A visitor gets the same reassurance as before and nothing
                 else — the menu is not theirs to touch, and the server
                 agrees: setVisibility() refuses anyone but the owner.

                 The copy button appears only once the collection has left
                 private, because a link to a private one 404s for everybody
                 who is handed it. What stops that from being a dead end is
                 the menu sitting right next to it, which says in full what
                 each of the three states means.
                 ══════════════════════════════════════════════════════════ --}}
            @if ($collection->user_id === auth()->id())
                <div class="flex flex-wrap items-center gap-2">
                    @if ($collection->isOpenByLink())
                        <x-copy-button :text="$collection->shareUrl()"
                                       class="flex items-center gap-2 rounded-full bg-surface px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md dark:bg-surface-dark" />
                    @endif

                    <x-visibility-menu :collection="$collection"
                                       class="flex items-center gap-2 rounded-full px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-soft-md {{ $collection->isListed() ? 'bg-brand text-white' : 'bg-surface dark:bg-surface-dark' }}" />
                </div>
            @elseif ($collection->isOpenByLink())
                <div class="flex items-center gap-2 rounded-full bg-surface px-5 py-2.5 text-[0.85rem] shadow-soft-sm dark:bg-surface-dark">
                    <x-icon name="link" style="solid" class="text-xs text-brand" />
                    Anyone with the link can view
                </div>
            @endif
        </div>

        @if (session('error'))
            <div class="mb-6 flex items-start gap-3 rounded-card bg-warning/[0.1] px-5 py-4 text-[0.9rem]">
                <x-icon name="triangle-exclamation" style="solid" class="mt-[0.15rem] text-[0.8rem] text-warning" />
                <span>{{ session('error') }}</span>
            </div>
        @endif

        @if ($collection->description)
            <p class="mb-6 text-ink/60 dark:text-paper/60">{{ $collection->description }}</p>
        @endif

        <div class="rounded-card bg-surface p-3 shadow-soft-md dark:bg-surface-dark">
            @forelse ($this->sounds as $sound)
                <div wire:key="cs-{{ $sound->id }}"
                     class="flex flex-col gap-4 rounded-control px-4 py-3 transition duration-350 ease-dbelo hover:bg-ink/[0.04] lg:flex-row lg:items-center dark:hover:bg-paper/[0.06]">
                    <div class="min-w-0 lg:w-48">
                        <a href="{{ route('sounds.show', $sound) }}" wire:navigate class="block truncate text-[0.95rem] hover:text-brand">{{ $sound->title }}</a>
                        <div class="micro mt-1">{{ $sound->category?->name }} · {{ $sound->durationForHumans() }}</div>
                    </div>

                    <x-waveform-player :sound="$sound" :bars="80" class="flex-1" />

                    <a href="{{ route('sounds.download', $sound) }}"
                       class="shrink-0 rounded-full bg-paper px-5 py-2.5 text-[0.83rem] font-medium shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-paper/10">
                        Download
                    </a>

                    @if ($collection->user_id === auth()->id())
                        <button wire:click="remove({{ $sound->id }})"
                                class="grid size-9 shrink-0 place-items-center rounded-full bg-paper text-ink/40 shadow-soft-sm transition hover:-translate-y-0.5 hover:text-brand dark:bg-paper/10 dark:text-paper/40">
                            <x-icon name="xmark" style="solid" class="text-xs" />
                        </button>
                    @endif
                </div>
            @empty
                <div class="py-16 text-center">
                    <p class="text-lg">This collection is empty</p>
                    <p class="micro mt-2">Add sounds from any sound page</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
