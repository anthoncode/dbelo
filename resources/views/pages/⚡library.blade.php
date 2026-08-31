<?php

use App\Models\Collection;
use App\Models\Download;
use App\Models\Sound;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.site')] #[Title('Your library')] class extends Component {

    #[Url(except: 'favorites')]
    public string $tab = 'favorites';

    public string $newCollection = '';

    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
    }

    #[Computed]
    public function favorites()
    {
        return auth()->user()->favorites()
            ->with(['files', 'category'])
            ->orderByPivot('created_at', 'desc')
            ->get();
    }

    #[Computed]
    public function collections()
    {
        return auth()->user()->collections()
            ->withCount('sounds')
            ->latest('updated_at')
            ->get();
    }

    #[Computed]
    public function downloads()
    {
        return Download::where('user_id', auth()->id())
            ->with(['sound.files', 'license'])
            ->latest('created_at')
            ->limit(100)
            ->get();
    }

    #[Computed]
    public function counts(): array
    {
        return [
            'favorites' => auth()->user()->favorites()->count(),
            'collections' => auth()->user()->collections()->count(),
            'downloads' => Download::where('user_id', auth()->id())->count(),
        ];
    }

    public function createCollection(): void
    {
        $this->validate(['newCollection' => ['required', 'string', 'max:80']]);

        auth()->user()->collections()->create([
            'name' => $this->newCollection,
            'slug' => Collection::uniqueSlug($this->newCollection),
        ]);

        $this->newCollection = '';
        unset($this->collections, $this->counts);
    }

    public function togglePublic(int $id): void
    {
        $collection = auth()->user()->collections()->findOrFail($id);
        $collection->update(['is_public' => ! $collection->is_public]);

        unset($this->collections);
    }

    public function deleteCollection(int $id): void
    {
        auth()->user()->collections()->findOrFail($id)->delete();

        unset($this->collections, $this->counts);
    }
}; ?>

<div>
    <div class="mx-auto max-w-4xl">

        <div class="mb-7">
            <div class="micro">Your account</div>
            <h1 class="mt-2 text-3xl font-semibold">Library</h1>
        </div>

        {{-- Tabs --}}
        <div class="mb-6 flex flex-wrap gap-2">
            @foreach (['favorites' => 'Favourites', 'collections' => 'Collections', 'downloads' => 'Downloads'] as $key => $label)
                <button wire:click="$set('tab', '{{ $key }}')"
                        class="flex items-center gap-2 rounded-full px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5
                               {{ $tab === $key ? 'bg-ink text-paper dark:bg-brand' : 'bg-surface text-ink/60 dark:bg-surface-dark dark:text-paper/60' }}">
                    {{ $label }}
                    <span class="rounded-full px-2 py-0.5 text-[0.72rem] {{ $tab === $key ? 'bg-paper/20' : 'bg-ink/[0.06] dark:bg-paper/10' }}">
                        {{ $this->counts[$key] }}
                    </span>
                </button>
            @endforeach
        </div>

        {{-- FAVOURITES --}}
        @if ($tab === 'favorites')
            <div class="rounded-card bg-surface p-3 shadow-soft-md dark:bg-surface-dark">
                @forelse ($this->favorites as $sound)
                    <div wire:key="fav-{{ $sound->id }}"
                         class="flex flex-col gap-4 rounded-control px-4 py-3 transition duration-350 ease-dbelo hover:bg-ink/[0.04] lg:flex-row lg:items-center dark:hover:bg-paper/[0.06]">
                        <div class="min-w-0 lg:w-48">
                            <a href="{{ route('sounds.show', $sound) }}" wire:navigate class="block truncate text-[0.95rem] hover:text-brand">{{ $sound->title }}</a>
                            <div class="micro mt-1">{{ $sound->category?->name }} · {{ $sound->durationForHumans() }}</div>
                        </div>

                        <x-waveform-player :sound="$sound" :bars="80" class="flex-1" />

                        <livewire:favorite-button :sound="$sound" :key="'favbtn-'.$sound->id" />

                        <a href="{{ route('sounds.download', $sound) }}"
                           class="shrink-0 rounded-full bg-paper px-5 py-2.5 text-[0.83rem] font-medium shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-paper/10">
                            Download
                        </a>
                    </div>
                @empty
                    <div class="py-16 text-center">
                        <p class="text-lg">Nothing saved yet</p>
                        <p class="micro mt-2">Tap the heart on any sound to keep it here</p>
                    </div>
                @endforelse
            </div>
        @endif

        {{-- COLLECTIONS --}}
        @if ($tab === 'collections')
            <form wire:submit="createCollection" class="mb-5 flex gap-3">
                <input type="text" wire:model="newCollection" placeholder="New collection — “Documentary”, “Podcast ep. 4”…"
                       class="min-w-0 flex-1 rounded-full border-0 bg-surface px-6 py-3 text-[0.95rem] shadow-soft-sm placeholder:text-ink/30 focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-surface-dark dark:placeholder:text-paper/30" />
                <button type="submit" class="shrink-0 rounded-full bg-brand px-6 py-3 font-medium text-white shadow-brand transition hover:-translate-y-0.5 hover:shadow-brand-lg">
                    Create
                </button>
            </form>
            @error('newCollection') <p class="mb-4 text-sm text-brand">{{ $message }}</p> @enderror

            <div class="grid gap-4 sm:grid-cols-2">
                @forelse ($this->collections as $collection)
                    <div wire:key="col-{{ $collection->id }}" class="rounded-card bg-surface p-6 shadow-soft-md transition duration-450 ease-dbelo hover:-translate-y-1 hover:shadow-soft-lg dark:bg-surface-dark">
                        <div class="flex items-start justify-between gap-3">
                            <a href="{{ route('collections.show', $collection) }}" wire:navigate class="min-w-0">
                                <div class="truncate text-[1.05rem] hover:text-brand">{{ $collection->name }}</div>
                                <div class="micro mt-1">{{ $collection->sounds_count }} {{ Str::plural('sound', $collection->sounds_count) }}</div>
                            </a>

                            <span class="grid size-10 shrink-0 place-items-center rounded-[14px] bg-ink/[0.05] text-brand dark:bg-paper/10">
                                <x-icon name="folder-music" style="solid" />
                            </span>
                        </div>

                        <div class="mt-5 flex flex-wrap gap-2">
                            <button wire:click="togglePublic({{ $collection->id }})"
                                    class="flex items-center gap-2 rounded-full px-4 py-2 text-[0.8rem] shadow-soft-sm transition hover:-translate-y-0.5
                                           {{ $collection->is_public ? 'bg-brand text-white' : 'bg-paper dark:bg-paper/10' }}">
                                <x-icon :name="$collection->is_public ? 'globe' : 'lock'" style="solid" class="text-[0.7rem]" />
                                {{ $collection->is_public ? 'Shared' : 'Private' }}
                            </button>

                            <button wire:click="deleteCollection({{ $collection->id }})"
                                    wire:confirm="Delete “{{ $collection->name }}”? The sounds stay in the catalogue."
                                    class="rounded-full bg-paper px-4 py-2 text-[0.8rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-paper/10">
                                Delete
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full rounded-card bg-surface py-16 text-center shadow-soft-md dark:bg-surface-dark">
                        <p class="text-lg">No collections yet</p>
                        <p class="micro mt-2">Group sounds by project so you can find them again</p>
                    </div>
                @endforelse
            </div>
        @endif

        {{-- DOWNLOADS --}}
        @if ($tab === 'downloads')
            <div class="rounded-card bg-surface p-3 shadow-soft-md dark:bg-surface-dark">
                @forelse ($this->downloads as $download)
                    <div wire:key="dl-{{ $download->id }}"
                         class="flex flex-wrap items-center gap-4 rounded-control px-4 py-3.5 transition duration-350 ease-dbelo hover:bg-ink/[0.04] dark:hover:bg-paper/[0.06]">
                        <div class="min-w-0 flex-1">
                            @if ($download->sound)
                                <a href="{{ route('sounds.show', $download->sound) }}" wire:navigate class="block truncate text-[0.95rem] hover:text-brand">
                                    {{ $download->sound->title }}
                                </a>
                            @else
                                <span class="text-[0.95rem] opacity-60">Sound removed from catalogue</span>
                            @endif

                            <div class="micro mt-1">
                                {{ $download->created_at?->format('M j, Y') }}
                                @if ($download->license_snapshot)
                                    · {{ $download->license_snapshot['name'] ?? '' }} v{{ $download->license_snapshot['version'] ?? '1.0' }}
                                @endif
                            </div>
                        </div>

                        {{-- The frozen licence is the point of this screen: it is
                             the user's proof of what they were allowed to do. --}}
                        @if ($download->license_snapshot)
                            <details class="w-full">
                                <summary class="micro cursor-pointer">Licence granted that day</summary>
                                <p class="mt-2 text-[0.88rem] leading-relaxed text-ink/65 dark:text-paper/65">
                                    {{ $download->license_snapshot['summary'] ?? '' }}
                                </p>
                            </details>
                        @endif
                    </div>
                @empty
                    <div class="py-16 text-center">
                        <p class="text-lg">No downloads yet</p>
                        <p class="micro mt-2">Everything you download is listed here with its licence</p>
                    </div>
                @endforelse
            </div>
        @endif
    </div>
</div>
