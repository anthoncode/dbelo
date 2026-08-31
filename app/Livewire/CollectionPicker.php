<?php

namespace App\Livewire;

use App\Models\Collection;
use App\Models\Sound;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Dropdown that puts a sound into one of the user's collections, or into a
 * brand new one without leaving the page.
 */
class CollectionPicker extends Component
{
    public Sound $sound;

    public bool $open = false;

    public string $newName = '';

    #[Computed]
    public function collections()
    {
        if (! auth()->check()) {
            return collect();
        }

        return auth()->user()->collections()
            ->withCount('sounds')
            ->latest('updated_at')
            ->get();
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

    public function toggleOpen(): void
    {
        if (! auth()->check()) {
            $this->redirectRoute('login', navigate: true);

            return;
        }

        $this->open = ! $this->open;
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

        unset($this->memberOf, $this->collections);
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

        unset($this->memberOf, $this->collections);
    }

    public function render()
    {
        return view('livewire.collection-picker');
    }
}
