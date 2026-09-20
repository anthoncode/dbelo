<?php

use App\Models\Collection;
use App\Models\Download;
use App\Models\Sound;
use App\Support\CleanWords;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.site')] #[Title('Your library')] class extends Component {
    use WithPagination;

    #[Url(except: 'favorites')]
    public string $tab = 'favorites';

    public string $newCollection = '';

    /** Which collection just had its link copied, for the confirmation. */
    public ?int $copied = null;

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

    /**
     * Paginated, twelve at a time.
     *
     * It used to be get() — every collection, always. That is fine for the
     * six somebody has in their first month and it is a page that never
     * finishes loading for the person who has been organising sounds for two
     * years, which is exactly the user worth keeping.
     *
     * ── notPack() IS NOT DECORATION ──────────────────────────────────────
     *
     * The admin screen makes a pack with auth()->user()->collections()
     * ->create(['is_featured' => true]), so every pack belongs to whoever
     * built it — and without this scope they appeared in that person's
     * library as ordinary collections, wearing a visibility menu that means
     * nothing to a pack and a Delete button that very much does.
     */
    #[Computed]
    public function collections()
    {
        return auth()->user()->collections()
            ->notPack()
            ->withCount('sounds')
            ->latest('updated_at')
            ->paginate(12, pageName: 'collections');
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
            // notPack() for the same reason the list has it, and it has to be
            // repeated here rather than inferred: a tab that counts twelve
            // and lists nine is a tab that makes people look for the three.
            'collections' => auth()->user()->collections()->notPack()->count(),
            'downloads' => Download::where('user_id', auth()->id())->count(),
        ];
    }

    public function createCollection(): void
    {
        $this->validate(['newCollection' => ['required', 'string', 'max:80']]);

        /*
         * ── THE NAME CHECK ───────────────────────────────────────────────
         *
         * A collection can be shared by link, and a link on this domain is
         * dbelo hosting whatever it is called. The check is a doormat rather
         * than moderation — App\Support\CleanWords is blunt about how easily
         * it is beaten — and it runs here, at the moment of typing, because
         * that is the only point where the person can simply pick another
         * name. Catching it later means taking something away.
         */
        if ($word = CleanWords::hit($this->newCollection)) {
            $this->addError('newCollection', "That name contains “{$word}”. Pick another one.");

            return;
        }

        auth()->user()->collections()->create([
            'name' => $this->newCollection,
            'slug' => Collection::uniqueSlug($this->newCollection),
        ]);

        $this->newCollection = '';
        $this->resetPage('collections');
        unset($this->collections, $this->counts);
    }

    /**
     * Private, unlisted or listed.
     *
     * The rule itself — the three states, the name check that runs before a
     * collection leaves private, and the sentence shown when it refuses —
     * lives on the model, because the collection's own page offers the same
     * menu and the two must not be able to drift apart.
     *
     * findOrFail runs against the signed-in user's own collections, so an id
     * belonging to somebody else is a 404 rather than a change.
     */
    public function setVisibility(int $id, string $to): void
    {
        $collection = auth()->user()->collections()->notPack()->findOrFail($id);

        if ($refusal = $collection->setVisibility($to)) {
            session()->flash('error', $refusal);

            return;
        }

        unset($this->collections);
    }

    public function deleteCollection(int $id): void
    {
        auth()->user()->collections()->notPack()->findOrFail($id)->delete();

        unset($this->collections, $this->counts);
    }

    /*
     * There is no shareUrl() here any more. It was a public method on a
     * Livewire component, which means it was also a method anybody could
     * call from the browser, and all it did was build a URL the view can
     * build itself. It lives on the model now — see Collection::shareUrl().
     */
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

            @if (session('error'))
                <div class="mb-4 flex items-start gap-3 rounded-card bg-warning/[0.1] px-5 py-4 text-[0.9rem]">
                    <x-icon name="triangle-exclamation" style="solid" class="mt-[0.15rem] text-[0.8rem] text-warning" />
                    <span>{{ session('error') }}</span>
                </div>
            @endif

            {{-- ══════════════════════════════════════════════════════════
                 A LIST, NOT A GRID OF COVERS.

                 Packs get covers because a pack is something you choose by
                 the look of it. A collection is somebody's own folder: they
                 already know what is in it, they are here to find one by
                 name, and a picture would be a picture of nothing — there is
                 no image to put on it and inventing one is decoration that
                 makes the list longer to scan.

                 So: one row each, name and count first, the rest to the
                 right. Twelve to a page.
                 ══════════════════════════════════════════════════════════ --}}
            <div class="overflow-hidden rounded-card bg-surface shadow-soft-md dark:bg-surface-dark">
                @forelse ($this->collections as $collection)
                    <div wire:key="col-{{ $collection->id }}"
                         class="flex flex-wrap items-center gap-4 border-b border-ink/[0.06] px-5 py-4 transition duration-300 ease-dbelo last:border-0 hover:bg-ink/[0.02] dark:border-paper/[0.07] dark:hover:bg-paper/[0.03]">

                        <span class="grid size-10 shrink-0 place-items-center rounded-[14px] bg-ink/[0.05] text-brand dark:bg-paper/10">
                            <x-icon name="folder-music" style="solid" class="text-[0.9rem]" />
                        </span>

                        <a href="{{ route('collections.show', $collection) }}" wire:navigate class="min-w-0 flex-1">
                            <div class="truncate text-[1rem] transition hover:text-brand">{{ $collection->name }}</div>

                            <div class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[0.78rem] text-ink/45 dark:text-paper/45">
                                <span>{{ $collection->sounds_count }} {{ Str::plural('sound', $collection->sounds_count) }}</span>
                                <span>·</span>
                                <span>Updated {{ $collection->updated_at?->diffForHumans() }}</span>

                                @if (filled($collection->description))
                                    <span>·</span>
                                    <span class="truncate">{{ $collection->description }}</span>
                                @endif
                            </div>
                        </a>

                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            {{-- The link is only worth copying once it works
                                 for somebody else. Offering it on a private
                                 collection would hand out a URL that 404s for
                                 everyone who is given it.

                                 Unlisted and listed both get the button:
                                 being in the directory does not stop a link
                                 from being the fastest way to send somebody
                                 to one particular collection. --}}
                            @if ($collection->isOpenByLink())
                                <x-copy-button :text="$collection->shareUrl()"
                                               class="flex items-center gap-2 rounded-full bg-paper px-4 py-2 text-[0.8rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-paper/10" />
                            @endif

                            <x-visibility-menu :collection="$collection" />

                            <button wire:click="deleteCollection({{ $collection->id }})"
                                    wire:confirm="Delete “{{ $collection->name }}”? The sounds stay in the catalogue."
                                    class="rounded-full bg-paper px-4 py-2 text-[0.8rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-paper/10">
                                Delete
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="py-16 text-center">
                        <p class="text-lg">No collections yet</p>
                        <p class="micro mt-2">Group sounds by project so you can find them again</p>
                    </div>
                @endforelse
            </div>

            @if ($this->collections->hasPages())
                <div class="mt-5">{{ $this->collections->links() }}</div>
            @endif
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
