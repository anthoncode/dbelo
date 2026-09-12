<?php

namespace App\Livewire;

use App\Models\Sound;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Small standalone component so a heart can sit in any listing without the
 * parent page having to know anything about favourites.
 */
class FavoriteButton extends Component
{
    public Sound $sound;

    public bool $favourited = false;

    public bool $showLabel = false;

    public function mount(Sound $sound, bool $showLabel = false): void
    {
        $this->sound = $sound;
        $this->showLabel = $showLabel;
        $this->favourited = $this->isFavourited();
    }

    public function toggle(): void
    {
        if (! auth()->check()) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        if ($this->favourited) {
            $this->sound->favouritedBy()->detach(auth()->id());
            $this->sound->decrement('favorites_count');
            $this->favourited = false;
        } else {
            // attach with the timestamp, since the pivot has created_at
            // but no updated_at and therefore no automatic timestamps.
            $this->sound->favouritedBy()->attach(auth()->id(), ['created_at' => now()]);
            $this->sound->increment('favorites_count');
            $this->favourited = true;
        }

        $this->dispatch('favourites-changed');
    }

    /**
     * Re-read after somebody else changed the same thing.
     *
     * The collection picker can now favourite a sound too, and the heart is
     * usually on screen at the same time. Without this it would sit there
     * looking empty next to a panel that says the sound is saved — the exact
     * kind of disagreement that makes people press a control twice and end
     * up back where they started.
     *
     * Re-reading is all this does. It dispatches nothing, so the event that
     * woke it cannot bounce back.
     */
    #[On('favourites-changed')]
    public function sync(): void
    {
        $this->favourited = $this->isFavourited();
    }

    private function isFavourited(): bool
    {
        return auth()->check()
            && $this->sound->favouritedBy()->whereKey(auth()->id())->exists();
    }

    public function render()
    {
        return view('livewire.favorite-button');
    }
}
