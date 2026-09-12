<?php

use App\Models\Category;
use App\Models\License;
use App\Models\Sound;
use App\Models\Tag;
use App\Services\SoundImporter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.site')] #[Title('Edit sound')] class extends Component {

    public Sound $sound;

    public string $titleField = '';
    public string $description = '';
    public string $categoryId = '';
    public string $licenseId = '';
    public string $tags = '';
    public bool $isPremium = false;
    public bool $isLoopable = false;
    public bool $isFeatured = false;
    public string $metaTitle = '';
    public string $metaDescription = '';

    public bool $confirmingDelete = false;

    public function mount(Sound $sound): void
    {
        // The owner can edit their own work; an admin can edit anything.
        abort_unless(
            auth()->check() && (auth()->user()->isAdmin() || $sound->user_id === auth()->id()),
            403
        );

        $this->sound = $sound->load('tags');

        $this->titleField = $sound->title;
        $this->description = (string) $sound->description;
        $this->categoryId = (string) ($sound->category_id ?? '');
        $this->licenseId = (string) ($sound->license_id ?? '');
        $this->tags = $sound->tags->pluck('name')->join(', ');
        $this->isPremium = (bool) $sound->is_premium;
        $this->isLoopable = (bool) $sound->is_loopable;
        $this->isFeatured = (bool) $sound->is_featured;
        $this->metaTitle = (string) $sound->meta_title;
        $this->metaDescription = (string) $sound->meta_description;
    }

    #[Computed]
    public function categories()
    {
        return Category::orderBy('parent_id')->orderBy('sort_order')->get();
    }

    #[Computed]
    public function licenses()
    {
        return License::orderBy('id')->get();
    }

    public function save(SoundImporter $importer): void
    {
        $this->validate([
            'titleField' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:2000'],
            'categoryId' => ['nullable', 'exists:categories,id'],
            'licenseId' => ['nullable', 'exists:licenses,id'],
            'tags' => ['nullable', 'string', 'max:255'],
            'metaTitle' => ['nullable', 'string', 'max:180'],
            'metaDescription' => ['nullable', 'string', 'max:300'],
        ]);

        // The slug only follows the title while the sound has never been
        // public. Once published, the URL is frozen so bookmarks and search
        // rankings survive an edit.
        if ($this->titleField !== $this->sound->title && ! $this->sound->published_at) {
            $this->sound->slug = $importer->uniqueSlug($this->titleField);
        }

        $this->sound->fill([
            'title' => $this->titleField,
            'description' => $this->description ?: null,
            'category_id' => $this->categoryId ?: null,
            'license_id' => $this->licenseId ?: null,
            'is_premium' => $this->isPremium,
            'is_loopable' => $this->isLoopable,
            'is_featured' => auth()->user()->isAdmin() ? $this->isFeatured : $this->sound->is_featured,
            'meta_title' => $this->metaTitle ?: null,
            'meta_description' => $this->metaDescription ?: null,
        ])->save();

        $this->syncTags();

        session()->flash('saved', 'Changes saved.');
    }

    /**
     * The parsing moved to Tag::idsFromList().
     *
     * Admin → Sounds now edits tags from its own inline row, and a rule
     * written in two screens is a rule that disagrees the first time one of
     * them is fixed. One definition; both callers.
     */
    protected function syncTags(): void
    {
        $this->sound->tags()->sync(Tag::idsFromList($this->tags));
    }

    public function delete(): void
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        // forceDelete so the model's cleanup hook removes the audio files.
        // Leaving 60 MB masters behind on every deletion fills a disk fast.
        $this->sound->forceDelete();

        $this->redirectRoute('moderate', navigate: true);
    }
}; ?>

<div>
    <div class="mx-auto max-w-3xl">

        <a href="{{ url()->previous() }}" class="micro mb-6 inline-flex items-center gap-2 hover:text-brand">
            <x-icon name="arrow-left" style="solid" class="text-xs" /> Back
        </a>

        <div class="mb-7">
            <div class="micro">Editing</div>
            <h1 class="mt-2 text-3xl font-semibold">{{ $sound->title }}</h1>
            <p class="micro mt-2">
                {{ ucfirst($sound->status) }}
                @if ($sound->published_at) · published {{ $sound->published_at->format('M j, Y') }} @endif
                · {{ $sound->downloads_count }} {{ Str::plural('download', $sound->downloads_count) }}
            </p>
        </div>

        @if (session('saved'))
            <div class="mb-6 flex items-center gap-3 rounded-card bg-surface p-4 shadow-soft-md dark:bg-surface-dark">
                <span class="grid size-9 shrink-0 place-items-center rounded-[12px] bg-brand text-white">
                    <x-icon name="check" style="solid" class="text-sm" />
                </span>
                <span class="text-[0.95rem]">{{ session('saved') }}</span>
            </div>
        @endif

        <div class="mb-5 rounded-card bg-surface p-6 shadow-soft-md dark:bg-surface-dark">
            <x-waveform-player :sound="$sound" :bars="140" height="h-14" />
        </div>

        <div class="rounded-card bg-surface p-7 shadow-soft-md dark:bg-surface-dark">
            <div class="grid gap-5">

                <label class="block">
                    <span class="micro mb-2 block">Title</span>
                    <input type="text" wire:model="titleField"
                           class="w-full rounded-control border-0 bg-paper px-4 py-3 text-[0.95rem] shadow-soft-sm focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-paper/10" />
                    @error('titleField') <span class="mt-1 block text-sm text-brand">{{ $message }}</span> @enderror
                    @if ($sound->published_at)
                        <span class="micro mt-2 block">URL frozen at /sounds/{{ $sound->slug }} — editing the title will not break existing links</span>
                    @endif
                </label>

                <label class="block">
                    <span class="micro mb-2 block">Description</span>
                    <textarea wire:model="description" rows="3"
                              placeholder="How it was recorded, with what gear, in what conditions."
                              class="w-full rounded-control border-0 bg-paper px-4 py-3 text-[0.95rem] shadow-soft-sm placeholder:text-ink/30 focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-paper/10 dark:placeholder:text-paper/30"></textarea>
                </label>

                <div class="grid gap-5 md:grid-cols-2">
                    <label class="block">
                        <span class="micro mb-2 block">Category</span>
                        <select wire:model="categoryId"
                                class="w-full rounded-control border-0 bg-paper px-4 py-3 text-[0.95rem] shadow-soft-sm focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-paper/10">
                            <option value="">Uncategorised</option>
                            @foreach ($this->categories as $cat)
                                <option value="{{ $cat->id }}">{{ $cat->parent_id ? '— ' : '' }}{{ $cat->name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="block">
                        <span class="micro mb-2 block">License</span>
                        <select wire:model="licenseId"
                                class="w-full rounded-control border-0 bg-paper px-4 py-3 text-[0.95rem] shadow-soft-sm focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-paper/10">
                            <option value="">None</option>
                            @foreach ($this->licenses as $license)
                                <option value="{{ $license->id }}">{{ $license->name }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <label class="block">
                    <span class="micro mb-2 block">Tags</span>
                    <input type="text" wire:model="tags" placeholder="thunder, storm, rumble, distant"
                           class="w-full rounded-control border-0 bg-paper px-4 py-3 text-[0.95rem] shadow-soft-sm placeholder:text-ink/30 focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-paper/10 dark:placeholder:text-paper/30" />
                    <span class="micro mt-2 block">Comma separated. These drive the search.</span>
                </label>

                <div class="flex flex-wrap gap-3">
                    <button type="button" wire:click="$toggle('isPremium')"
                            class="flex items-center gap-2 rounded-full px-4 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5
                                   {{ $isPremium ? 'bg-brand text-white' : 'bg-paper dark:bg-paper/10' }}">
                        <x-icon name="crown" :style="$isPremium ? 'solid' : 'regular'" class="text-xs" /> Premium
                    </button>

                    <button type="button" wire:click="$toggle('isLoopable')"
                            class="flex items-center gap-2 rounded-full px-4 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5
                                   {{ $isLoopable ? 'bg-brand text-white' : 'bg-paper dark:bg-paper/10' }}">
                        <x-icon name="repeat" :style="$isLoopable ? 'solid' : 'regular'" class="text-xs" /> Seamless loop
                    </button>

                    @if (auth()->user()->isAdmin())
                        <button type="button" wire:click="$toggle('isFeatured')"
                                class="flex items-center gap-2 rounded-full px-4 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5
                                       {{ $isFeatured ? 'bg-brand text-white' : 'bg-paper dark:bg-paper/10' }}">
                            <x-icon name="star" :style="$isFeatured ? 'solid' : 'regular'" class="text-xs" /> Featured
                        </button>
                    @endif
                </div>

                {{-- SEO --}}
                <details class="rounded-control bg-paper p-5 dark:bg-paper/10">
                    <summary class="micro cursor-pointer">Search engine listing</summary>

                    <div class="mt-5 grid gap-5">
                        <label class="block">
                            <span class="micro mb-2 block">Meta title</span>
                            <input type="text" wire:model="metaTitle" placeholder="{{ $sound->title }} — free sound effect"
                                   class="w-full rounded-control border-0 bg-surface px-4 py-3 text-[0.95rem] shadow-soft-sm placeholder:text-ink/30 focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-surface-dark dark:placeholder:text-paper/30" />
                        </label>

                        <label class="block">
                            <span class="micro mb-2 block">Meta description</span>
                            <textarea wire:model="metaDescription" rows="2" placeholder="Leave empty to generate it from the description."
                                      class="w-full rounded-control border-0 bg-surface px-4 py-3 text-[0.95rem] shadow-soft-sm placeholder:text-ink/30 focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-surface-dark dark:placeholder:text-paper/30"></textarea>
                        </label>
                    </div>
                </details>
            </div>

            <div class="mt-7 flex flex-wrap items-center gap-4">
                <button wire:click="save" wire:loading.attr="disabled"
                        class="flex items-center gap-2 rounded-full bg-brand px-7 py-3 font-medium text-white shadow-brand transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-brand-lg">
                    <x-icon name="check" style="solid" class="text-sm" />
                    <span wire:loading.remove wire:target="save">Save changes</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>

                @if (auth()->user()->isAdmin())
                    <button wire:click="$toggle('confirmingDelete')"
                            class="ml-auto px-3 text-sm text-ink/45 underline transition hover:text-brand dark:text-paper/45">
                        Delete permanently
                    </button>
                @endif
            </div>

            @if ($confirmingDelete)
                <div class="mt-5 rounded-control bg-paper p-5 dark:bg-paper/10">
                    <div class="text-[0.95rem]">Delete “{{ $sound->title }}” permanently?</div>
                    <p class="micro mt-2">
                        The master, the preview and the download will be erased from disk.
                        {{ $sound->downloads_count }} {{ Str::plural('download', $sound->downloads_count) }}
                        already recorded will keep their frozen license. This cannot be undone.
                    </p>
                    <div class="mt-4 flex gap-3">
                        <button wire:click="delete"
                                class="rounded-full bg-ink px-5 py-2.5 text-[0.85rem] text-paper shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-brand">
                            Yes, delete it
                        </button>
                        <button wire:click="$toggle('confirmingDelete')" class="px-3 text-sm text-ink/50 underline dark:text-paper/50">
                            Cancel
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
