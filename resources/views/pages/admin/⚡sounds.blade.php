<?php

use App\Jobs\ProcessSoundUpload;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Collection as Pack;
use App\Models\Sound;
use App\Models\SoundFile;
use App\Models\Tag;
use App\Support\AdminNav;
use App\Support\AutoTags;
use App\Support\Music;
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

    /*
     * What is NOT filled in — the only filter here that asks about absence.
     *
     * Every other filter narrows by a value a sound has. This one narrows by
     * a value it does not, which is the question you actually arrive with:
     * the row already says "No description" when there is none, but finding
     * those rows meant paging through the whole catalogue reading a column.
     *
     * It is a URL property so the bell can link straight here. See the
     * $params map in App\Support\Notices.
     */
    #[Url(except: '')] public string $missing = '';

    /** @var array<int, int> */
    public array $selected = [];

    // ── Inline edit, in the row itself ──
    public ?int $editing = null;
    public string $editTitle = '';
    public string $editCategory = '';

    /**
     * Tags, comma separated, exactly as the sound's own edit page takes them.
     *
     * They were missing from this row, and that was the wrong side of the
     * workflow: the only other tag editor is linked from Admin → In review,
     * which a sound leaves the moment it is published. So a published sound's
     * tags were effectively frozen unless you knew the URL by heart — and
     * tagging is precisely what you want to do while looking at the
     * catalogue together, not before.
     */
    public string $editTags = '';

    /**
     * The description, editable in the row.
     *
     * It was reachable only from the sound's own page, which meant checking
     * what a hundred sounds say involved a hundred navigations. The whole
     * point of an inline editor is that the catalogue can be corrected from
     * the list it is being read in.
     */
    public string $editDescription = '';

    /*
     * Sound effect or music, editable from the row.
     *
     * The filter above this list could already narrow by type; nothing could
     * CHANGE one. That gap is how the first four sounds in this catalogue
     * ended up as music filed under 'sfx' — the bulk uploader offers the
     * choice, the default is 'sfx', and after that there was no screen in
     * the application that could correct it.
     */
    public string $editType = Music::TYPE_SFX;

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
        if (in_array($property, ['status', 'search', 'category', 'pack', 'type', 'sort', 'missing'], true)) {
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
            /*
            | ── WHAT IS MISSING ────────────────────────────────────────────
            |
            | Both spellings of empty, and they are not the same row: NULL is
            | a sound nobody ever opened, '' is one somebody opened, cleared
            | and saved. whereNull() alone silently under-reports the second
            | kind, and under-reporting is worse than not reporting — it says
            | the backlog is smaller than it is.
            |
            | Wrapped in its own closure so the OR cannot leak out and
            | dissolve the status, category and search clauses around it.
            | Without the wrapper, "published AND (null OR '')" becomes
            | "published AND null OR ''" and the filter starts returning
            | drafts.
            */
            ->when($this->missing === 'description', fn ($q) => $q
                ->where(fn ($w) => $w->whereNull('description')->orWhere('description', '')))
            /*
            | Fewer tags than AutoTags::MINIMUM, which is the number below
            | which a sound is effectively unfindable: tags are what the
            | search engine matches on beyond the title.
            |
            | has('tags', '<', n) and not doesntHave('tags'): a sound with one
            | tag is not tagged, it is started. Counting only the zero case
            | would call that one finished.
            */
            ->when($this->missing === 'tags', fn ($q) => $q
                ->has('tags', '<', AutoTags::MINIMUM))
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

    /**
     * How much is unfinished, for the labels on the Missing filter.
     *
     * Read from AdminNav::counts() rather than counted again here, and that
     * is the whole point: the bell links to this screen with a number in the
     * sentence, and the screen it lands on has to show the same number. Two
     * independent counts of the same thing is how "124 sounds have no
     * description" lands on a filter that says 118 and neither is believed
     * again.
     *
     * AdminNav::counts() is cached for a minute and already read once per
     * admin render for the sidebar, so this costs nothing.
     *
     * Both numbers count PUBLISHED sounds only — the filter itself does not,
     * because combining it with the Drafts tab is a reasonable thing to want.
     * That is why the labels below say "published".
     *
     * @return array{description: int, tags: int}
     */
    #[Computed]
    public function gaps(): array
    {
        $counts = AdminNav::counts();

        return [
            'description' => (int) ($counts['undescribed'] ?? 0),
            'tags' => (int) ($counts['untagged'] ?? 0),
        ];
    }

    #[Computed]
    public function categories()
    {
        return Category::orderBy('parent_id')->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * The two types, for the inline editor's select.
     *
     * Through a computed property because a single-file Livewire component
     * compiles its class and its template separately: the `use App\Support\Music`
     * at the top of this file is not in scope in the markup below.
     */
    #[Computed]
    public function types(): array
    {
        return Music::TYPES;
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
        $this->editTags = $sound->tags->pluck('name')->join(', ');
        $this->editDescription = (string) $sound->description;
        $this->editType = $sound->type ?: Music::TYPE_SFX;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->reset(['editing', 'editTitle', 'editCategory', 'editTags', 'editDescription', 'editType']);
        $this->resetErrorBag();
    }

    public function update(): void
    {
        $this->validate([
            'editTitle' => ['required', 'string', 'min:2', 'max:120'],
            'editTags' => ['nullable', 'string', 'max:255'],
            // 2000 matches the sound's own edit page. Two limits on one
            // column means one screen accepts what the other rejects, and
            // the person who hits it has no way to know which is the rule.
            'editDescription' => ['nullable', 'string', 'max:2000'],
            'editType' => ['required', 'string', 'in:'.implode(',', array_keys(Music::TYPES))],
        ]);

        $sound = Sound::findOrFail($this->editing);

        // The slug is NOT regenerated. Once a sound has been published its
        // URL may be linked from anywhere, and quietly moving it creates a
        // 404 nobody knows about — that is what Tools → Redirects is for.
        $sound->update([
            'title' => trim($this->editTitle),
            'category_id' => $this->editCategory ?: null,
            'description' => trim($this->editDescription) ?: null,
            'type' => $this->editType,
        ]);

        /*
         * Demoting a track to a sound effect takes its music row with it.
         *
         * Same rule as the sound's own edit page, for the same reason: a row
         * in `music_attributes` belonging to something that is not music is
         * invisible on every screen and still answers queries. "Tracks in C
         * minor" would start returning door slams.
         *
         * Only on the way DOWN. Switching an effect to music leaves the
         * fields empty, which is correct — nobody has filled them in yet,
         * and the sound's own page is where that happens.
         */
        if ($this->editType !== Music::TYPE_MUSIC) {
            $sound->musicAttribute()->delete();
        }

        /*
         * sync(), so an emptied box removes every tag from THIS sound. The
         * tag rows themselves survive — they belong to the catalogue, not to
         * one recording, and deleting "thunder" because one sound stopped
         * using it would take it off every other sound's page too.
         *
         * Parsed by Tag::idsFromList so this row and the sound's own edit
         * page can never disagree about what a comma means.
         */
        $sound->tags()->sync(Tag::idsFromList($this->editTags));

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
    // Emptying the trash
    //
    // Sounds are NOT pruned. Sound uses SoftDeletes and not Prunable, and
    // claude/retencion-de-datos.md lists no rule for them — on purpose:
    // moving a sound to the trash says "this does not go on the site", not
    // "destroy the master", and a job running at 03:50 with nobody watching
    // must not make the second decision on the operator's behalf.
    //
    // The consequence is that the trash only grows, and every row in it is
    // still holding its WAV. Emptying it was a per-row button, so a lot of
    // forty was forty clicks and the disk stayed full until somebody did
    // them all.
    //
    // WHAT MAKES THIS SAFE ENOUGH TO EXIST: it reports before it offers,
    // the numbers are counted rather than estimated, and the button cannot
    // be pressed by accident — the word DELETE has to be typed. It is the
    // only action in the panel that cannot be undone, and it is the only
    // one that asks you to type something.
    // ---------------------------------------------------------------

    /** Anything trashed longer ago than this is offered separately. */
    public const TRASH_OLD_DAYS = 30;

    /** Which confirmation is open: '' (none), 'old' or 'all'. */
    public string $emptying = '';

    /** What was typed into it. Must read DELETE, exactly. */
    public string $emptyConfirm = '';

    /**
     * What is in the trash, counted — never estimated.
     *
     * A warning that says "about 1 GB" is a warning nobody can check, and
     * this is the one screen where the number is the whole argument for
     * trusting the button. size_bytes is a real column on sound_files, so
     * the sum is exact rather than a guess from the durations.
     *
     * @return array{count: int, bytes: int, old: int, oldBytes: int}
     */
    #[Computed]
    public function trashReport(): array
    {
        $cutoff = now()->subDays(self::TRASH_OLD_DAYS);

        $bytesFor = fn ($q) => (int) SoundFile::whereIn('sound_id', $q->select('id'))->sum('size_bytes');

        return [
            'count' => Sound::onlyTrashed()->count(),
            'bytes' => $bytesFor(Sound::onlyTrashed()),
            'old' => Sound::onlyTrashed()->where('deleted_at', '<', $cutoff)->count(),
            'oldBytes' => $bytesFor(Sound::onlyTrashed()->where('deleted_at', '<', $cutoff)),
        ];
    }

    public function startEmpty(string $scope): void
    {
        $this->emptying = in_array($scope, ['old', 'all'], true) ? $scope : '';
        $this->emptyConfirm = '';
    }

    public function cancelEmpty(): void
    {
        $this->emptying = '';
        $this->emptyConfirm = '';
    }

    /**
     * Destroy what the open confirmation covers.
     *
     * forceDelete() ONE MODEL AT A TIME, never a mass delete, because the
     * Sound model's deleting hook is what removes the audio from disk and a
     * query-builder delete does not fire it. Doing this the fast way would
     * free the database and leave every master exactly where it was — the
     * precise failure this button exists to fix, achieved invisibly.
     *
     * Chunked so a trash of two thousand does not load two thousand models
     * with their files into memory at once.
     */
    public function emptyTrash(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        if ($this->emptying === '' || strtoupper(trim($this->emptyConfirm)) !== 'DELETE') {
            return;
        }

        // Re-counted here rather than read from the computed property: the
        // report was rendered before the typing started, and between then
        // and now another admin may have restored something. The message
        // has to describe what this call actually destroyed.
        $query = Sound::onlyTrashed()
            ->when($this->emptying === 'old',
                fn ($q) => $q->where('deleted_at', '<', now()->subDays(self::TRASH_OLD_DAYS)));

        $destroyed = 0;
        $freed = 0;

        $query->with('files')->chunkById(50, function ($sounds) use (&$destroyed, &$freed) {
            foreach ($sounds as $sound) {
                $freed += (int) $sound->files->sum('size_bytes');
                $sound->forceDelete();
                $destroyed++;
            }
        });

        ActivityLog::record(
            'sounds.trash_emptied',
            null,
            "{$destroyed} sound(s), ".$this->humanBytes($freed).' freed'
        );

        $this->cancelEmpty();
        $this->refresh();

        session()->flash('ok', $destroyed === 0
            ? 'The trash was already empty.'
            : "{$destroyed} sound(s) destroyed. ".$this->humanBytes($freed).' freed.');
    }

    /** Bytes as something a person can judge. Shared by the panel and the log. */
    public function humanBytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1_073_741_824 => number_format($bytes / 1_073_741_824, 1).' GB',
            $bytes >= 1_048_576 => number_format($bytes / 1_048_576, 0).' MB',
            $bytes > 0 => number_format($bytes / 1024, 0).' KB',
            default => 'no files',
        };
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
        /*
         * `gaps` goes with them.
         *
         * It is memoised for the request like any computed, so without this
         * line writing a description would move the row out of the filtered
         * list while the label beside it still said the old number — the one
         * contradiction this screen exists to avoid. Forgetting the cache
         * below is not enough on its own: the computed never asks again.
         */
        unset($this->sounds, $this->counts, $this->gaps, $this->trashReport);
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

        {{-- ── WHAT IS MISSING ──────────────────────────────────────────
             The only filter here that asks about absence, and the one the
             bell links to. Ringed in amber while it is on, because unlike
             every other filter this one hides most of the catalogue — and a
             filter you forgot you left on reads as "the catalogue is nearly
             empty", which is a bad ten minutes.

             The counts are in the labels so the size of the job is visible
             BEFORE selecting it. "No description (124)" answers the question;
             selecting it and counting rows across thirteen pages does not.

             They say "published" because that is what is counted. The filter
             itself respects whichever tab is open, so it composes with Drafts
             — the number just stops matching, which is correct and is why the
             word is there. --}}
        <select wire:model.live="missing"
                @class([
                    'rounded-lg border-0 px-3 py-2.5 text-[0.83rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40',
                    'bg-raised' => $missing === '',
                    'bg-warning/15 ring-1 ring-warning/40' => $missing !== '',
                ])>
            <option value="">Complete & incomplete</option>
            <option value="description">
                No description{{ $this->gaps['description'] ? ' ('.number_format($this->gaps['description']).' published)' : '' }}
            </option>
            <option value="tags">
                Under {{ AutoTags::MINIMUM }} tags{{ $this->gaps['tags'] ? ' ('.number_format($this->gaps['tags']).' published)' : '' }}
            </option>
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

    {{-- ══════ THE TRASH REPORT ══════════════════════════════════════════
         Only on the Trash tab, because it is the only tab where it is true.

         It is a REPORT first and a control second, and in that order on
         purpose: the numbers are counted from size_bytes, not estimated, and
         they are the entire reason anybody should trust the button under
         them. "About a gigabyte" is a figure nobody can check.

         The two scopes exist because they answer different fears. Emptying
         what has sat here for a month is housekeeping. Emptying everything
         includes what you deleted twenty minutes ago, which is the one you
         regret. --}}
    @if ($status === 'trashed' && $this->trashReport['count'] > 0)
        <div class="mb-5 rounded-2xl border border-hairline bg-panel px-5 py-4">
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                <div class="flex items-center gap-3">
                    <span class="grid size-9 place-items-center rounded-full bg-warning/15 text-warning">
                        <x-icon name="trash" style="solid" class="text-[12px]" />
                    </span>
                    <div>
                        <div class="text-[0.9rem]">
                            {{ number_format($this->trashReport['count']) }} sound(s) in the trash
                        </div>
                        <div class="text-[0.78rem] text-paper/40">
                            Holding {{ $this->humanBytes($this->trashReport['bytes']) }} that nothing points at.
                            They are never deleted automatically.
                        </div>
                    </div>
                </div>

                @if ($emptying === '')
                    <div class="ml-auto flex flex-wrap items-center gap-2.5">
                        @if ($this->trashReport['old'] > 0)
                            <button wire:click="startEmpty('old')"
                                    class="rounded-lg bg-raised px-4 py-2 text-[0.82rem] text-paper/70 transition hover:text-paper">
                                Empty what is over {{ $this::TRASH_OLD_DAYS }} days
                                <span class="ml-1 text-paper/35">
                                    ({{ number_format($this->trashReport['old']) }} · {{ $this->humanBytes($this->trashReport['oldBytes']) }})
                                </span>
                            </button>
                        @endif

                        <button wire:click="startEmpty('all')"
                                class="rounded-lg px-4 py-2 text-[0.82rem] text-danger/80 transition hover:bg-danger/10 hover:text-danger">
                            Empty everything
                        </button>
                    </div>
                @endif
            </div>

            {{-- ── THE CONFIRMATION ──────────────────────────────────────
                 Typed, not clicked. Every other destructive control here
                 uses wire:confirm, which is one keystroke away from done;
                 this is the only action in the panel that cannot be undone
                 and the only one that destroys files, so it is the only one
                 that asks you to write something.

                 The sentence above the field names the real count and the
                 real size for THIS scope. A confirmation that says "are you
                 sure?" has told you nothing you did not already know. --}}
            @if ($emptying !== '')
                @php
                    $scopeCount = $emptying === 'old' ? $this->trashReport['old'] : $this->trashReport['count'];
                    $scopeBytes = $emptying === 'old' ? $this->trashReport['oldBytes'] : $this->trashReport['bytes'];
                @endphp

                <div class="mt-4 rounded-xl border border-danger/30 bg-danger/[0.07] px-4 py-3.5">
                    <p class="text-[0.86rem] leading-relaxed">
                        This destroys <strong>{{ number_format($scopeCount) }} sound(s)</strong>
                        and <strong>{{ $this->humanBytes($scopeBytes) }}</strong> of audio files, permanently.
                        @if ($emptying === 'all' && $this->trashReport['old'] < $this->trashReport['count'])
                            That includes
                            {{ number_format($this->trashReport['count'] - $this->trashReport['old']) }}
                            deleted in the last {{ $this::TRASH_OLD_DAYS }} days.
                        @endif
                        There is no undo and no backup of these files.
                    </p>

                    <div class="mt-3 flex flex-wrap items-center gap-2.5">
                        <input type="text" wire:model.live="emptyConfirm" placeholder="Type DELETE"
                               autocomplete="off" spellcheck="false"
                               class="w-44 rounded-lg border-0 bg-raised px-3.5 py-2 text-[0.85rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-2 focus:ring-danger/40" />

                        <button wire:click="emptyTrash" wire:loading.attr="disabled" wire:target="emptyTrash"
                                @disabled(strtoupper(trim($emptyConfirm)) !== 'DELETE')
                                class="flex items-center gap-2 rounded-lg bg-danger px-4 py-2 text-[0.82rem] font-medium text-white transition disabled:cursor-not-allowed disabled:opacity-35">
                            <x-icon name="spinner-third" style="solid" class="animate-spin text-[11px]" wire:loading wire:target="emptyTrash" />
                            Destroy {{ number_format($scopeCount) }} sound(s)
                        </button>

                        <button wire:click="cancelEmpty"
                                class="px-3 text-[0.82rem] text-paper/45 underline underline-offset-2 transition hover:text-paper">
                            Cancel
                        </button>
                    </div>
                </div>
            @endif
        </div>
    @endif

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

                                        {{-- Tags.

                                             A plain comma-separated box rather than a
                                             picker, and the same one the sound's own edit
                                             page uses. Typing a name that does not exist
                                             creates it; clearing one out of the list
                                             detaches it from this sound and leaves the tag
                                             itself alone.

                                             Enter saves, like the title above it — the
                                             whole point of editing in the row is not
                                             reaching for the mouse. --}}
                                        <div class="relative">
                                            <x-icon name="tag" style="solid"
                                                    class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[0.72rem] text-paper/25" />

                                            <input type="text" wire:model="editTags" wire:keydown.enter="update"
                                                   placeholder="thunder, storm, rumble, distant — separated by commas"
                                                   maxlength="255" autocomplete="off"
                                                   class="w-full rounded-lg border-0 bg-panel py-2.5 pl-9 pr-3.5 text-[0.85rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                                        </div>
                                        @error('editTags') <p class="text-[0.8rem] text-danger">{{ $message }}</p> @enderror

                                        {{-- Description.

                                             No Enter-to-save here, unlike the two fields
                                             above: this is prose and a line break in it is
                                             a paragraph, not a submit. A textarea whose
                                             Enter key saves the form is a textarea you
                                             cannot write two sentences in. --}}
                                        <textarea wire:model="editDescription" rows="3" maxlength="2000"
                                                  placeholder="What the sound is, and what someone would use it for."
                                                  class="w-full rounded-lg border-0 bg-panel px-3.5 py-2.5 text-[0.85rem] leading-relaxed text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40"></textarea>
                                        @error('editDescription') <p class="text-[0.8rem] text-danger">{{ $message }}</p> @enderror

                                        <div class="flex flex-wrap items-center gap-2">
                                            <select wire:model="editCategory"
                                                    class="rounded-lg border-0 bg-panel px-3 py-2.5 text-[0.85rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                                                <option value="">— no category —</option>
                                                @foreach ($this->categories as $cat)
                                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                                @endforeach
                                            </select>

                                            {{--
                                                Sound effect or music, from the row.

                                                Here and not only on the sound's own page
                                                because misclassification is found by
                                                READING THE LIST — you notice four tracks
                                                sitting among the effects — and the fix
                                                should happen where the problem was seen.
                                                Sending somebody to four separate pages to
                                                correct four rows is how catalogues stay
                                                wrong.

                                                The music details themselves (genre, mood,
                                                BPM, key) stay on the sound's own page:
                                                five more controls in a table row is a form
                                                pretending to be a list.
                                            --}}
                                            <select wire:model="editType"
                                                    class="rounded-lg border-0 bg-panel px-3 py-2.5 text-[0.85rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                                                @foreach ($this->types as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
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
                            {{-- One-line form only. This file must never
                                 carry a block @ php … @ endphp as well: Blade
                                 lifts raw PHP blocks before compiling and
                                 pairs the FIRST opener with the first closer,
                                 swallowing everything in between. It took the
                                 sound page down once already. --}}
                            @php($peaks = $this->peaks($sound))
                            @php($url = $this->previewUrl($sound))
                            @php($live = $sound->status === 'published')

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

                                        {{--
                                            Only music wears a chip; effects wear nothing.

                                            A badge on every row carries no information —
                                            it is the same word a thousand times. dbelo is
                                            a sound-effects library, so an effect is the
                                            unremarkable case and marking it would just
                                            add noise to the one column that has to stay
                                            scannable. Music is the exception, and the
                                            exception is what a chip is for.
                                        --}}
                                        @if ($sound->type === 'music')
                                            <span class="flex items-center gap-1 rounded bg-brand/15 px-1.5 py-0.5 text-[0.68rem] text-brand">
                                                <x-icon name="music" style="solid" class="text-[9px]" />
                                                Music
                                            </span>
                                        @endif
                                    </div>

                                    {{-- ── THE DESCRIPTION, ON ONE LINE ───────────────
                                         truncate, not line-clamp-2: every row has to be
                                         the same height or the table stops being scannable,
                                         and a column that grows with the longest text is a
                                         column that decides the layout for all the others.

                                         The full text is in the title attribute, so hovering
                                         reads it without opening anything, and the editor is
                                         one click away for changing it.

                                         When there is none, the row SAYS so. An empty cell
                                         and a cell nobody filled in look identical, and the
                                         whole reason for putting this column here is finding
                                         the ones that are missing. --}}
                                    @if (filled($sound->description))
                                        <div class="mt-1 truncate text-[0.75rem] text-paper/40"
                                             title="{{ $sound->description }}">{{ $sound->description }}</div>
                                    @else
                                        <button wire:click="edit({{ $sound->id }})"
                                                class="mt-1 flex items-center gap-1.5 text-[0.72rem] text-paper/20 transition hover:text-warning">
                                            <x-icon name="align-left" style="solid" class="text-[9px]" />
                                            No description
                                        </button>
                                    @endif

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
