<?php

namespace App\Livewire;

use App\Models\Collection;
use App\Models\Sound;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * "Which of my lists is this sound in?"
 *
 * ── WHY FAVOURITES IS INSIDE HERE ────────────────────────────────────────
 *
 * The heart and this dropdown used to sit next to each other as peers, and
 * they read as two ways to do the same thing. They are not — a heart is one
 * tap with no decision, a collection is a named list you might make public —
 * but nothing on screen said so, and the difference is invisible until you
 * have used both.
 *
 * Worse was the empty state. A new account opened this and found "No
 * collections yet" and a box asking for a name: nothing saved, and the first
 * thing asked of them was to invent a filing system. That is why people
 * pressed the heart and never opened this again.
 *
 * Favourites is now the first row, always present, never deletable. The
 * dropdown shows ONE idea — the lists this sound belongs to — and Favourites
 * is simply the list that always exists. The heart stays as the fast path
 * for the same row, so nothing was taken away.
 *
 * The storage did not change: favourites is still its own pivot, not a
 * collection row. Only the reading of it did.
 */
class CollectionPicker extends Component
{
    public Sound $sound;

    public string $newName = '';

    /** Filters the list below. Only shown once there is enough to search. */
    public string $search = '';

    /* ═══════════════════════════ Reading ═══════════════════════════ */

    /** Every collection this person owns, unfiltered. */
    #[Computed]
    public function allCollections()
    {
        if (! auth()->check()) {
            return collect();
        }

        return auth()->user()->collections()
            ->withCount('sounds')
            ->latest('updated_at')
            ->get();
    }

    /**
     * The rows to draw.
     *
     * Filtered in PHP rather than in a second query: the set is already in
     * memory, it is a person's own collections rather than a catalogue, and
     * a round trip per keystroke to re-sort a list of six is work nobody
     * asked for.
     */
    #[Computed]
    public function collections()
    {
        $search = trim($this->search);

        if ($search === '') {
            return $this->allCollections;
        }

        return $this->allCollections->filter(
            fn ($collection) => str_contains(mb_strtolower($collection->name), mb_strtolower($search))
        )->values();
    }

    /**
     * Show the search box only when it can do something.
     *
     * A search field above one row is a control that cannot help, and a
     * control that cannot help still has to be read and dismissed by every
     * person who opens this.
     */
    #[Computed]
    public function showSearch(): bool
    {
        return $this->allCollections->count() > 3;
    }

    #[Computed]
    public function memberOf(): array
    {
        if (! auth()->check()) {
            return [];
        }

        return $this->sound->collections()
            ->where('user_id', auth()->id())
            ->pluck('collections.id')
            ->all();
    }

    #[Computed]
    public function favourited(): bool
    {
        return auth()->check()
            && $this->sound->favouritedBy()->whereKey(auth()->id())->exists();
    }

    /* ═══════════════════════════ Acting ═══════════════════════════ */

    /*
     * There is no toggleOpen() and no $open property any more.
     *
     * Opening a dropdown was a round trip: press, wait for the server, then
     * the panel appears. The outside-click handler wrote to the same
     * property, so closing lagged as well and could be lost entirely if a
     * re-render landed first. Alpine owns it now — see the view. Whether a
     * menu is open is interface state and never needed to leave the browser.
     */

    /**
     * The same toggle the heart performs, so the two can never disagree.
     *
     * The event is what keeps them in step: every FavoriteButton on the page
     * listens for it and re-reads its own state. Without it, hearting from
     * in here would leave the heart outside still looking empty.
     */
    public function toggleFavourite(): void
    {
        if (! auth()->check()) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        if ($this->favourited) {
            $this->sound->favouritedBy()->detach(auth()->id());
            $this->sound->decrement('favorites_count');
        } else {
            // The pivot has created_at and no updated_at, so timestamps are
            // not automatic and the value has to be passed.
            $this->sound->favouritedBy()->attach(auth()->id(), ['created_at' => now()]);
            $this->sound->increment('favorites_count');
        }

        unset($this->favourited);

        $this->dispatch('favourites-changed');
    }

    public function toggleCollection(int $collectionId): void
    {
        $collection = Collection::where('user_id', auth()->id())->findOrFail($collectionId);

        if (in_array($collectionId, $this->memberOf, true)) {
            $collection->sounds()->detach($this->sound->id);
        } else {
            $collection->sounds()->attach($this->sound->id, ['created_at' => now()]);
        }

        $collection->refreshCount();
        $collection->touch();

        unset($this->memberOf, $this->collections, $this->allCollections);
    }

    public function create(): void
    {
        $this->validate([
            'newName' => ['required', 'string', 'max:80'],
        ]);

        $collection = auth()->user()->collections()->create([
            'name' => $this->newName,
            'slug' => Collection::uniqueSlug($this->newName),
        ]);

        $collection->sounds()->attach($this->sound->id, ['created_at' => now()]);
        $collection->refreshCount();

        $this->newName = '';

        // A new collection that the current filter hides would look like the
        // create had failed.
        $this->search = '';

        unset($this->memberOf, $this->collections, $this->allCollections);
    }

    public function render()
    {
        return view('livewire.collection-picker');
    }
}
