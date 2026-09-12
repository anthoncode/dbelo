<?php

use App\Jobs\ProcessSoundUpload;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Collection as Pack;
use App\Models\Sound;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Sounds')] class extends Component {
    use WithPagination;

    #[Url(except: 'all')] public string $status = 'all';
    #[Url(as: 'q', except: '')] public string $search = '';
    #[Url(except: '')] public string $category = '';

    /*
     * Which pack, by id.
     *
     * Here so a whole pack can be selected and then acted on in one go with
     * the bulk controls that already exist — marking forty sounds premium
     * one row at a time is what stops premium packs from existing at all.
     */
    #[Url(except: '')] public string $pack = '';
    #[Url(except: '')] public string $type = '';
    #[Url(except: 'recent')] public string $sort = 'recent';

    /** @var array<int, int> */
    public array $selected = [];

    // ── Inline edit, in the row itself ──
    public ?int $editing = null;
    public string $editTitle = '';
    public string $editCategory = '';

    /** Applied by the bulk bar when rows are ticked. */
    public string $bulkCategory = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    /**
     * Only the filters reset the pagination.
     *
     * Doing it for every property would throw you back to page one the
     * moment you opened an inline editor on page three.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search', 'category', 'pack', 'type', 'sort'], true)) {
            $this->resetPage();
            $this->selected = [];
        }
    }

    // ---------------------------------------------------------------
    // Reading
    // ---------------------------------------------------------------

    #[Computed]
    public function sounds()
    {
        return Sound::query()
            ->when($this->status === 'trashed', fn ($q) => $q->onlyTrashed())
            ->when(! in_array($this->status, ['all', 'trashed'], true),
                fn ($q) => $q->where('status', $this->status))
            /*
            | Packs are a many-to-many, so this is whereHas and not a column.
            | Qualified as collections.id because the pivot carries a bare
            | `id` too and MySQL will not guess which one you meant.
            */
            ->when($this->pack, fn ($q, $p) => $q->whereHas('collections',
                fn ($c) => $c->where('collections.id', $p)))
            /*
            | 'none' is a SENTINEL, not an id.
            |
            | Category ids are integers, so the string can never collide with
            | a real one — which is what makes "no category at all" express-
            | ible in a filter whose whole vocabulary was "this category".
            |
            | It needs its own branch because `where('category_id', null)`
            | does not do what it looks like: SQL compares NULL to nothing,
            | not even to NULL, so that clause matches zero rows instead of
            | the rows with nothing in them. whereNull() is the only spelling
            | that asks the question.
            */
            ->when($this->category === 'none', fn ($q) => $q->whereNull('category_id'))
            ->when($this->category !== '' && $this->category !== 'none',
                fn ($q) => $q->where('category_id', $this->category))
            ->when($this->type, fn ($q, $t) => $q->where('type', $t))
            ->when($this->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('title', 'like', "%{$s}%")
                ->orWhere('slug', 'like', "%{$s}%")))
            // `files` is eager loaded because every row draws a waveform and
            // offers a preview: without it this is 10 extra queries a page,
            // and 10 more the moment somebody changes the sort.
            ->with(['category:id,name', 'user:id,name', 'files'])
            ->when($this->sort === 'recent', fn ($q) => $q->latest('id'))
            ->when($this->sort === 'downloads', fn ($q) => $q->orderByDesc('downloads_count'))
            ->when($this->sort === 'plays', fn ($q) => $q->orderByDesc('plays_count'))
            ->when($this->sort === 'title', fn ($q) => $q->orderBy('title'))
            ->when($this->sort === 'duration', fn ($q) => $q->orderByDesc('duration_ms'))
            ->paginate(10);
    }

    /**
     * The number on each tab.
     *
     * One grouped query rather than six counts, because this runs on every
     * render of a screen that is meant to be filtered constantly.
     */
    #[Computed]
    public function counts(): array
    {
        $byStatus = Sound::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'all' => (int) $byStatus->sum(),
            'published' => (int) ($byStatus['published'] ?? 0),
            'pending' => (int) ($byStatus['pending'] ?? 0),
            'draft' => (int) ($byStatus['draft'] ?? 0),
            'rejected' => (int) ($byStatus['rejected'] ?? 0),
            'claimed' => (int) ($byStatus['claimed'] ?? 0),
            'trashed' => Sound::onlyTrashed()->count(),
        ];
    }

    #[Computed]
    public function categories()
    {
        return Category::orderBy('parent_id')->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Packs, with how many sounds each holds.
     *
     * The count is in the label because "Doors (0)" is the answer to a
     * question you would otherwise ask by selecting it and finding an empty
     * table.
     */
    #[Computed]
    public function packs()
    {
        return Pack::query()
            ->select(['id', 'name', 'sounds_count'])
            ->orderBy('name')
            ->get();
    }

    // ---------------------------------------------------------------
    // Drawing
    // ---------------------------------------------------------------

    /**
     * The stored waveform, thinned down to fit a table cell.
     *
     * ProcessSoundUpload saves 400 peaks, which is right for the full-width
     * player on the sound page and far too many for 110 pixels. Averaging
     * down rather than sampling every Nth value keeps a single loud transient
     * from disappearing between two picks.
     *
     * @return array<int, float>
     */
    public function peaks(Sound $sound, int $bars = 54): array
    {
        $waveform = $sound->waveform ?: [];

        if ($waveform === []) {
            return [];
        }

        $size = max(1, (int) floor(count($waveform) / $bars));
        $out = [];

        foreach (array_chunk($waveform, $size) as $chunk) {
            $out[] = max($chunk);
        }

        return array_slice($out, 0, $bars);
    }

    /** Public MP3 for the little play button. Null while it is still converting. */
    public function previewUrl(Sound $sound): ?string
    {
        $file = $sound->files->firstWhere('purpose', 'preview');

        return $file ? Storage::disk($file->disk)->url($file->path) : null;
    }

    // ---------------------------------------------------------------
    // Row actions
    // ---------------------------------------------------------------

    public function publish(int $id): void
    {
        $sound = Sound::findOrFail($id);

        // Publishing something with no preview and no waveform puts a broken
        // page on the site. The fix is not to refuse — it is to record the
        // intent and let the queue publish it when it finishes, which is the
        // same flag the bulk uploader sets.
        if (! $sound->processed_at) {
            $sound->update(['publish_when_ready' => true]);

            session()->flash('ok', "“{$sound->title}” is still converting. It goes live the moment it finishes.");

            return;
        }

        $sound->update([
            'status' => Sound::STATUS_PUBLISHED,
            'published_at' => $sound->published_at ?? now(),
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        ActivityLog::record('sound.published', $sound, $sound->title);

        $this->refresh();
    }

    public function unpublish(int $id): void
    {
        $sound = Sound::findOrFail($id);

        // published_at survives on purpose: it is the record of when the URL
        // first existed, and the slug logic elsewhere reads it to decide
        // whether renaming is safe.
        $sound->update([
            'status' => Sound::STATUS_PENDING,
            'publish_when_ready' => false,
        ]);

        ActivityLog::record('sound.unpublished', $sound, $sound->title);

        $this->refresh();
    }

    public function toggleFeatured(int $id): void
    {
        $sound = Sound::findOrFail($id);

        $sound->update(['is_featured' => ! $sound->is_featured]);

        $this->refresh();
    }

    public function togglePremium(int $id): void
    {
        $sound = Sound::findOrFail($id);

        $sound->update(['is_premium' => ! $sound->is_premium]);

        $this->refresh();
    }

    public function reprocess(int $id): void
    {
        $sound = Sound::findOrFail($id);

        $sound->update(['processing_error' => null]);

        ProcessSoundUpload::dispatch($sound);

        $this->refresh();

        session()->flash('ok', "“{$sound->title}” is back in the queue.");
    }

    // ── Inline edit ──

    public function edit(int $id): void
    {
        $sound = Sound::findOrFail($id);

        $this->editing = $id;
        $this->editTitle = $sound->title;
        $this->editCategory = (string) $sound->category_id;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset(['editing', 'editTitle', 'editCategory']);
        $this->resetErrorBag();
    }

    public function update(): void
    {
        $this->validate(['editTitle' => ['required', 'string', 'min:2', 'max:120']]);

        $sound = Sound::findOrFail($this->editing);

        // The slug is NOT regenerated. Once a sound has been published its
        // URL may be linked from anywhere, and quietly moving it creates a
        // 404 nobody knows about — that is what Tools → Redirects is for.
        $sound->update([
            'title' => trim($this->editTitle),
            'category_id' => $this->editCategory ?: null,
        ]);

        $this->cancel();
        $this->refresh();
    }

    // ── Removal ──

    public function delete(int $id): void
    {
        $sound = Sound::findOrFail($id);

        // Soft: the files stay, the download history still points at them,
        // and it can come back from the Trash tab.
        $sound->delete();

        ActivityLog::record('sound.deleted', $sound, $sound->title);

        $this->refresh();

        session()->flash('ok', "“{$sound->title}” moved to the trash.");
    }

    public function restore(int $id): void
    {
        $sound = Sound::withTrashed()->findOrFail($id);

        $sound->restore();
        $this->refresh();
    }

    /**
     * Gone for good, files and all.
     *
     * The Sound model's deleting hook wipes the stored audio only on a force
     * delete, so this is the one path that actually frees the disk.
     */
    public function destroy(int $id): void
    {
        $sound = Sound::withTrashed()->findOrFail($id);
        $title = $sound->title;

        $sound->forceDelete();

        ActivityLog::record('sound.destroyed', null, $title);

        $this->refresh();

        session()->flash('ok', "“{$title}” and its files are gone.");
    }

    // ---------------------------------------------------------------
    // Many at once
    // ---------------------------------------------------------------

    public function toggleAll(): void
    {
        $ids = $this->sounds->pluck('id')->all();

        $this->selected = count($this->selected) === count($ids) ? [] : $ids;
    }

    public function bulk(string $action): void
    {
        if ($this->selected === []) {
            return;
        }

        $sounds = Sound::withTrashed()->whereIn('id', $this->selected)->get();

        foreach ($sounds as $sound) {
            match ($action) {
                'publish' => $this->publish($sound->id),
                'unpublish' => $this->unpublish($sound->id),
                'premium' => $sound->update(['is_premium' => true]),
                'free' => $sound->update(['is_premium' => false]),
                'category' => $this->bulkCategory !== ''
                    ? $sound->update(['category_id' => $this->bulkCategory])
                    : null,
                'delete' => $sound->delete(),
                'restore' => $sound->restore(),
                default => null,
            };
        }

        $count = $sounds->count();

        ActivityLog::record("sounds.bulk_{$action}", null, "{$count} sound(s)");

        $this->selected = [];
        $this->refresh();

        session()->flash('ok', "{$count} sound(s) updated.");
    }

    protected function refresh(): void
    {
        unset($this->sounds, $this->counts);
        cache()->forget('admin.nav.counts');
    }
}; ?>

<div x-data="{
        playing: null,
        audio: null,

        /*
         * One <Audio> for the whole table, not one per row.
         *
         * Ten audio elements each holding a connection is how a catalogue
         * screen starts stuttering; and with a single element, starting one
         * preview stops the previous one for free.
         */
        toggle(id, url) {
            if (! url) return

            if (this.playing === id) {
                this.audio.pause()
                this.playing = null
                return
            }

            if (! this.audio) {
                this.audio = new Audio()
                this.audio.addEventListener('ended', () => this.playing = null)
            }

            this.audio.src = url
            this.audio.play()
            this.playing = id
        },
     }">

    @if (session('ok'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-success/30 bg-success/10 px-4 py-3">
            <x-icon name="circle-check" style="solid" class="text-success" />
            <span class="text-[0.88rem]">{{ session('ok') }}</span>
        </div>
    @endif

    {{-- ══════ HEADER ══════ --}}
    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-[1.3rem] font-semibold tracking-[-0.02em]">Sounds</h1>
            <p class="mt-1 text-[0.83rem] text-paper/35">The whole catalogue, in every state it can be in</p>
        </div>

        <a href="{{ route('admin.bulk-upload') }}"
           class="flex items-center gap-2 rounded-lg bg-action px-4 py-2.5 text-[0.86rem] font-medium text-white transition duration-200 ease-dbelo hover:brightness-110">
            <x-icon name="layer-plus" style="solid" class="text-[12px]" />
            Bulk upload
        </a>
    </div>

    {{-- ══════ TABS ══════ --}}
    <div class="mb-5 flex flex-wrap gap-1.5 rounded-2xl border border-hairline bg-panel p-1.5">
        @foreach ([
            'all' => ['All', 'waveform-lines'],
            'published' => ['Published', 'circle-check'],
            'pending' => ['In review', 'clipboard-check'],
            'draft' => ['Drafts', 'file'],
            'rejected' => ['Rejected', 'circle-xmark'],
            'claimed' => ['Claimed', 'shield-exclamation'],
            'trashed' => ['Trash', 'trash'],
        ] as $key => [$label, $icon])
            <button wire:click="$set('status', '{{ $key }}')"
                    @class([
                        'flex items-center gap-2 rounded-xl px-3.5 py-2.5 text-[0.83rem] transition duration-200 ease-dbelo',
                        'bg-raised text-paper' => $status === $key,
                        'text-paper/45 hover:text-paper' => $status !== $key,
                    ])>
                <x-icon :name="$icon" style="solid" class="text-[11px]" />
                {{ $label }}
                @if ($this->counts[$key])
                    <span class="text-[0.72rem] tabular-nums text-paper/30">{{ number_format($this->counts[$key]) }}</span>
                @endif
            </button>
        @endforeach
    </div>

    {{-- ══════ FILTERS ══════ --}}
    <div class="mb-5 flex flex-wrap items-center gap-3 rounded-2xl border border-hairline bg-panel px-5 py-3.5">
        <div class="flex w-64 flex-1 items-center gap-2.5 rounded-lg bg-raised px-3 py-2">
            <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Title or slug…"
                   class="w-full border-0 bg-transparent p-0 text-[0.85rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-0" />
        </div>

        <select wire:model.live="category"
                class="rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.83rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
            <option value="">All categories</option>

            {{-- Above the list, not buried at the bottom of forty names.
                 It is the option somebody comes here looking for after the
                 bell told them a number, and the one they would never find
                 by scrolling because it is not a category. --}}
            <option value="none">— No category —</option>

            @foreach ($this->categories as $cat)
                <option value="{{ $cat->id }}">{{ $cat->name }}</option>
            @endforeach
        </select>

        {{-- Packs.

             Next to the category filter because they answer the same kind of
             question — "show me this group" — and because the pair of them is
             how a whole pack gets marked premium: filter to it, tick the
             header checkbox, use the bulk bar. Forty rows, three clicks. --}}
        <select wire:model.live="pack"
                class="max-w-[14rem] rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.83rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
            <option value="">All packs</option>
            @foreach ($this->packs as $packOption)
                <option value="{{ $packOption->id }}">{{ $packOption->name }} ({{ $packOption->sounds_count }})</option>
            @endforeach
        </select>

        <select wire:model.live="type"
                class="rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.83rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
            <option value="">SFX & music</option>
            <option value="sfx">SFX</option>
            <option value="music">Music</option>
        </select>

        <select wire:model.live="sort"
                class="rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.83rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
            <option value="recent">Newest</option>
            <option value="downloads">Most downloaded</option>
            <option value="plays">Most played</option>
            <option value="duration">Longest</option>
            <option value="title">A–Z</option>
        </select>
    </div>

    {{-- ══════ BULK BAR ══════ --}}
    @if ($selected)
        <div class="mb-5 flex flex-wrap items-center gap-2.5 rounded-2xl border border-brand/30 bg-brand/10 px-5 py-3.5">
            <span class="mr-1 text-[0.85rem]">{{ count($selected) }} selected</span>

            @if ($status === 'trashed')
                <button wire:click="bulk('restore')"
                        class="flex items-center gap-2 rounded-lg bg-panel px-3.5 py-2 text-[0.82rem] text-paper/70 transition hover:text-paper">
                    <x-icon name="rotate-left" style="solid" class="text-[11px]" /> Restore
                </button>
            @else
                <button wire:click="bulk('publish')"
                        class="flex items-center gap-2 rounded-lg bg-panel px-3.5 py-2 text-[0.82rem] text-paper/70 transition hover:text-paper">
                    <x-icon name="circle-check" style="solid" class="text-[11px]" /> Publish
                </button>

                <button wire:click="bulk('unpublish')"
                        class="flex items-center gap-2 rounded-lg bg-panel px-3.5 py-2 text-[0.82rem] text-paper/70 transition hover:text-paper">
                    <x-icon name="eye-slash" style="solid" class="text-[11px]" /> Unpublish
                </button>

                <button wire:click="bulk('premium')"
                        class="flex items-center gap-2 rounded-lg bg-panel px-3.5 py-2 text-[0.82rem] text-paper/70 transition hover:text-paper">
                    <x-icon name="crown" style="solid" class="text-[11px]" /> Premium
                </button>

                <button wire:click="bulk('free')"
                        class="flex items-center gap-2 rounded-lg bg-panel px-3.5 py-2 text-[0.82rem] text-paper/70 transition hover:text-paper">
                    <x-icon name="unlock" style="solid" class="text-[11px]" /> Free
                </button>

                <div class="flex items-center gap-1.5">
                    <select wire:model="bulkCategory"
                            class="rounded-lg border-0 bg-panel px-3 py-2 text-[0.82rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                        <option value="">Move to…</option>
                        @foreach ($this->categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>

                    <x-admin.icon-button icon="check" variant="brand" label="Apply category"
                                         wire:click="bulk('category')" />
                </div>

                <button wire:click="bulk('delete')" wire:confirm="Move the selected sounds to the trash?"
                        class="ml-auto flex items-center gap-2 rounded-lg bg-danger/15 px-3.5 py-2 text-[0.82rem] text-danger transition hover:bg-danger/25">
                    <x-icon name="trash" style="solid" class="text-[11px]" /> Trash
                </button>
            @endif
        </div>
    @endif

    {{-- ══════ TABLE ══════ --}}
    <div class="min-w-0 rounded-2xl border border-hairline bg-panel">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
            <h2 class="text-[0.95rem] font-medium">
                {{ $this->sounds->total() }} {{ Str::plural('sound', $this->sounds->total()) }}
            </h2>

            @if ($this->sounds->total())
                <button wire:click="toggleAll" class="text-[0.8rem] text-paper/45 transition hover:text-paper">
                    {{ count($selected) === $this->sounds->count() ? 'Deselect page' : 'Select page' }}
                </button>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                        <th class="w-10 px-5 py-2.5"></th>
                        <th class="w-40 px-3 py-2.5 font-medium">Wave</th>
                        <th class="px-3 py-2.5 font-medium">Sound</th>
                        <th class="w-36 px-3 py-2.5 font-medium">Category</th>
                        <th class="w-20 px-3 py-2.5 font-medium">Length</th>
                        <th class="w-24 px-3 py-2.5 font-medium">Downloads</th>
                        <th class="w-[150px] px-5 py-2.5 text-right font-medium">Actions</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-hairline">
                    @forelse ($this->sounds as $sound)
                        @if ($editing === $sound->id)
                            {{-- Editing happens in the row, not on another screen --}}
                            <tr wire:key="edit-{{ $sound->id }}" class="bg-raised">
                                <td class="px-5 py-4"></td>
                                <td colspan="6" class="px-3 py-4 pr-5">
                                    <div class="space-y-3">
                                        <input type="text" wire:model="editTitle" autofocus wire:keydown.enter="update"
                                               class="w-full rounded-lg border-0 bg-panel px-3.5 py-2.5 text-[0.9rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                                        @error('editTitle') <p class="text-[0.8rem] text-danger">{{ $message }}</p> @enderror

                                        <div class="flex flex-wrap items-center gap-2">
                                            <select wire:model="editCategory"
                                                    class="rounded-lg border-0 bg-panel px-3 py-2.5 text-[0.85rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                                                <option value="">— no category —</option>
                                                @foreach ($this->categories as $cat)
                                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                                @endforeach
                                            </select>

                                            <button wire:click="update"
                                                    class="flex items-center gap-2 rounded-lg bg-action px-5 py-2.5 text-[0.85rem] font-medium text-white transition hover:brightness-110">
                                                <x-icon name="check" style="solid" class="text-[11px]" />
                                                Save
                                            </button>

                                            <button wire:click="cancel"
                                                    class="rounded-lg bg-panel px-4 py-2.5 text-[0.85rem] text-paper/55 transition hover:text-paper">
                                                Cancel
                                            </button>

                                            <span class="ml-auto flex items-center gap-2 text-[0.75rem] text-paper/25">
                                                <x-icon name="lock" style="solid" class="text-[10px]" />
                                                /sounds/{{ $sound->slug }}
                                            </span>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @else
                            @php
                                $peaks = $this->peaks($sound);
                                $url = $this->previewUrl($sound);
                                $live = $sound->status === 'published';
                            @endphp

                            <tr wire:key="snd-{{ $sound->id }}" class="group/row transition hover:bg-paper/[0.03]">
                                <td class="px-5 py-3">
                                    <input type="checkbox" wire:model.live="selected" value="{{ $sound->id }}"
                                           class="size-4 rounded border-0 bg-raised text-brand focus:ring-2 focus:ring-brand/40" />
                                </td>

                                {{-- ── Waveform + play ────────────────────────────────
                                     Bars are inline-styled, not classed: the height is a
                                     number that comes out of the database, and Tailwind
                                     only generates classes it can see at build time. --}}
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <button type="button"
                                                x-on:click="toggle({{ $sound->id }}, @js($url))"
                                                class="grid size-7 shrink-0 place-items-center rounded-full bg-brand text-white transition duration-200 ease-dbelo disabled:opacity-40"
                                                @disabled(! $url)>
                                            <span x-show="playing !== {{ $sound->id }}">
                                                <x-icon name="play" style="solid" class="text-[9px]" />
                                            </span>
                                            <span x-show="playing === {{ $sound->id }}" style="display: none;">
                                                <x-icon name="pause" style="solid" class="text-[9px]" />
                                            </span>
                                        </button>

                                        @if ($peaks)
                                            <div class="flex items-end" style="height: 26px; gap: 1px;">
                                                @foreach ($peaks as $peak)
                                                    <span class="shrink-0 rounded-full"
                                                          style="width: 1px;
                                                                 height: {{ max(8, (int) round($peak * 100)) }}%;
                                                                 background: linear-gradient(to top,
                                                                     color-mix(in srgb, var(--color-brand) {{ $live ? 30 : 15 }}%, transparent),
                                                                     color-mix(in srgb, var(--color-brand) {{ $live ? 85 : 40 }}%, transparent));"></span>
                                                @endforeach
                                            </div>
                                        @elseif ($sound->processing_error)
                                            <span class="text-[0.72rem] text-danger">failed</span>
                                        @else
                                            <span class="flex items-center gap-1.5 text-[0.72rem] text-paper/30">
                                                <x-icon name="gear" style="solid" class="fa-spin text-[9px]" />
                                                converting
                                            </span>
                                        @endif
                                    </div>
                                </td>

                                {{-- ── Title, flags, status ── --}}
                                <td class="min-w-0 px-3 py-3">
                                    <div class="truncate text-[0.89rem]">{{ $sound->title }}</div>

                                    <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                        {{-- The chips ARE the toggles: seeing "Premium"
                                             and having to open an editor to change it is
                                             the kind of small friction that adds up. --}}
                                        <button wire:click="toggleFeatured({{ $sound->id }})"
                                                @class([
                                                    'flex items-center gap-1 rounded px-1.5 py-0.5 text-[0.68rem] transition duration-200 ease-dbelo',
                                                    'bg-warning/15 text-warning' => $sound->is_featured,
                                                    'text-paper/20 hover:text-paper/50' => ! $sound->is_featured,
                                                ])>
                                            <x-icon name="star" :style="$sound->is_featured ? 'solid' : 'regular'" class="text-[9px]" />
                                            Featured
                                        </button>

                                        <button wire:click="togglePremium({{ $sound->id }})"
                                                @class([
                                                    'flex items-center gap-1 rounded px-1.5 py-0.5 text-[0.68rem] transition duration-200 ease-dbelo',
                                                    'bg-info/15 text-info' => $sound->is_premium,
                                                    'text-paper/20 hover:text-paper/50' => ! $sound->is_premium,
                                                ])>
                                            <x-icon name="crown" :style="$sound->is_premium ? 'solid' : 'regular'" class="text-[9px]" />
                                            Premium
                                        </button>

                                        <span @class([
                                            'rounded px-1.5 py-0.5 text-[0.68rem]',
                                            'bg-success/15 text-success' => $sound->status === 'published',
                                            'bg-warning/15 text-warning' => $sound->status === 'pending',
                                            'bg-danger/15 text-danger' => in_array($sound->status, ['rejected', 'claimed'], true),
                                            'bg-raised text-paper/40' => in_array($sound->status, ['draft', 'processing'], true),
                                        ])>{{ $sound->status }}</span>

                                        @if ($sound->publish_when_ready && ! $sound->processed_at)
                                            <span class="rounded bg-raised px-1.5 py-0.5 text-[0.68rem] text-paper/40">
                                                goes live when ready
                                            </span>
                                        @endif
                                    </div>

                                    <div class="mt-1 truncate text-[0.7rem] text-paper/25">
                                        {{ $sound->user?->name ?? 'No uploader' }} · {{ $sound->created_at?->diffForHumans() }}
                                    </div>
                                </td>

                                <td class="px-3 py-3 text-[0.82rem] text-paper/55">
                                    {{ $sound->category?->name ?? '—' }}
                                </td>

                                <td class="px-3 py-3 text-[0.82rem] tabular-nums text-paper/55">
                                    {{ $sound->duration_ms ? $sound->durationForHumans() : '—' }}
                                </td>

                                <td class="px-3 py-3">
                                    <div class="text-[0.82rem] tabular-nums text-paper/55">{{ number_format($sound->downloads_count) }}</div>
                                    <div class="text-[0.7rem] tabular-nums text-paper/25">{{ number_format($sound->plays_count) }} plays</div>
                                </td>

                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($status === 'trashed')
                                            <x-admin.icon-button icon="rotate-left" variant="brand" label="Restore"
                                                                 wire:click="restore({{ $sound->id }})" />

                                            <x-admin.icon-button icon="fire" label="Delete for good, with its files"
                                                                 class="hover:!bg-danger/15 hover:!text-danger"
                                                                 wire:click="destroy({{ $sound->id }})"
                                                                 wire:confirm="Delete “{{ $sound->title }}” and its audio permanently?" />
                                        @else
                                            {{-- Offered whenever nothing came out the other side, not only
                                                 on a recorded failure. A job that was never picked up leaves no
                                                 error to show, and that is exactly the case where a person is
                                                 looking for a button to press. --}}
                                            @if (! $sound->processed_at)
                                                <x-admin.icon-button icon="rotate-right" variant="brand" label="Process again"
                                                                     wire:click="reprocess({{ $sound->id }})" />
                                            @endif

                                            @if ($live)
                                                <x-admin.icon-button icon="eye-slash" label="Unpublish"
                                                                     wire:click="unpublish({{ $sound->id }})" />
                                            @else
                                                <x-admin.icon-button icon="circle-check" variant="brand" label="Publish"
                                                                     wire:click="publish({{ $sound->id }})" />
                                            @endif

                                            @if ($live)
                                                <a href="{{ route('sounds.show', $sound) }}" target="_blank" rel="noopener">
                                                    <x-admin.icon-button icon="arrow-up-right-from-square" label="Open on the site" />
                                                </a>
                                            @endif

                                            <x-admin.icon-button icon="pen" label="Edit" wire:click="edit({{ $sound->id }})" />

                                            <x-admin.icon-button icon="trash" label="Move to trash"
                                                                 class="hover:!bg-danger/15 hover:!text-danger"
                                                                 wire:click="delete({{ $sound->id }})" />
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7" class="px-5 py-16 text-center">
                                <x-icon name="waveform-lines" style="regular" class="text-[24px] text-paper/20" />
                                <p class="mt-3 text-[0.9rem] text-paper/45">
                                    @if ($search || $category || $type)
                                        Nothing matches those filters
                                    @elseif ($status === 'trashed')
                                        The trash is empty
                                    @else
                                        No sounds here yet
                                    @endif
                                </p>

                                @if (! $search && ! $category && ! $type && $status !== 'trashed')
                                    <a href="{{ route('admin.bulk-upload') }}"
                                       class="mt-4 inline-flex items-center gap-2 rounded-lg bg-action px-4 py-2.5 text-[0.85rem] font-medium text-white transition hover:brightness-110">
                                        <x-icon name="layer-plus" style="solid" class="text-[12px]" />
                                        Upload some
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-hairline">
            <x-admin.pagination :paginator="$this->sounds" prefix="snd" />
        </div>
    </div>
</div>
