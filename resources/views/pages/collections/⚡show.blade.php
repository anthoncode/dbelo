<?php

use App\Models\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.site')] class extends Component {

    public Collection $collection;

    public function mount(Collection $collection): void
    {
        // A private collection is only visible to its owner.
        abort_unless(
            $collection->is_public || $collection->user_id === auth()->id(),
            404
        );

        $this->collection = $collection->load('user');

        view()->share('seo', [
            'title' => $collection->name,
            'description' => $collection->description
                ?: sprintf('%s — a collection of %d sound effects on dbelo.', $collection->name, $collection->sounds_count),
            'canonical' => route('collections.show', $collection),
            'noindex' => ! $collection->is_public,
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
                    {{ $collection->sounds_count }} {{ Str::plural('sound', $collection->sounds_count) }}
                    · by {{ $collection->user->name }}
                    · {{ $collection->is_public ? 'shared' : 'private' }}
                </p>
            </div>

            @if ($collection->is_public)
                <div class="flex items-center gap-2 rounded-full bg-surface px-5 py-2.5 text-[0.85rem] shadow-soft-sm dark:bg-surface-dark">
                    <x-icon name="link" style="solid" class="text-xs text-brand" />
                    Anyone with the link can view
                </div>
            @endif
        </div>

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
