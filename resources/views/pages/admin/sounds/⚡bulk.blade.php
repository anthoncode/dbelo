<?php

use App\Jobs\ProcessSoundUpload;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Collection as Pack;
use App\Models\License;
use App\Models\Sound;
use App\Models\SoundFile;
use App\Models\Tag;
use App\Services\SoundImporter;
use App\Support\FilenameMeta;
use App\Support\UploadLimits;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.admin')] #[Title('Bulk upload')] class extends Component {
    use WithFileUploads;

    /** @var array<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $files = [];

    /**
     * One entry per imported sound, keyed by its id.
     *
     * The Sound rows exist in the database from the moment the file lands —
     * they are not held in component state until a final save. Two reasons:
     * a reload in the middle of naming fifty sounds would otherwise lose
     * every uploaded file, and creating the record immediately is what lets
     * ffmpeg start converting while the admin is still typing titles.
     */
    public array $rows = [];

    /** @var array<int, int> */
    public array $selected = [];

    /** Files rejected as duplicates, kept on screen so it is not a silent skip. */
    public array $skipped = [];

    // ── Apply to many at once ──
    public string $bulkCategory = '';
    public string $bulkLicense = '';
    public string $bulkTags = '';
    public string $bulkType = '';
    public string $bulkPremium = '';

    // ── What happens on save ──
    public bool $publishWhenReady = true;
    public bool $makePack = false;
    public string $packName = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $this->bulkLicense = (string) (License::where('slug', 'dbelo-standard')->value('id')
            ?? License::value('id') ?? '');
    }

    // ---------------------------------------------------------------
    // Options
    // ---------------------------------------------------------------

    #[Computed]
    public function categories()
    {
        return Category::orderBy('parent_id')->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * What one POST may contain, handed to the drop zone so it can split a
     * drop into batches that fit instead of failing at the boundary.
     *
     * The two group numbers are policy, not limits: staying well under the
     * ceiling means one slow file cannot take a whole drop down with it, and
     * a failed group is six files to retry rather than fifty.
     */
    #[Computed]
    public function limits(): array
    {
        $limits = UploadLimits::all();

        return $limits + [
            'group_files' => max(1, min($limits['max_files'] - 2, 6)),
            'group_bytes' => (int) floor($limits['per_request'] * 0.75),
        ];
    }

    #[Computed]
    public function licenses()
    {
        return License::orderBy('id')->get();
    }

    /**
     * The live Sound records behind the rows.
     *
     * Kept separate from $rows on purpose: $rows holds what the admin is
     * typing, this holds what the queue has done. Mixing the two means a
     * background update overwrites a half-typed title.
     */
    #[Computed]
    public function sounds()
    {
        if ($this->rows === []) {
            return collect();
        }

        return Sound::whereIn('id', array_keys($this->rows))->get()->keyBy('id');
    }

    /** Polling only runs while something is still converting. */
    #[Computed]
    public function isProcessing(): bool
    {
        return $this->sounds->contains(
            fn ($s) => $s->processed_at === null && $s->processing_error === null
        );
    }

    // ---------------------------------------------------------------
    // Intake
    // ---------------------------------------------------------------

    /**
     * Fires as soon as Livewire finishes receiving the files.
     *
     * Import happens here rather than behind a second button so that
     * conversion starts at the earliest possible moment. By the time
     * fifty titles have been typed, most of the waveforms are done.
     */
    public function updatedFiles(): void
    {
        // Resolved here, not injected: Livewire calls lifecycle hooks with
        // ($value, $key) and does not run them through the container.
        $importer = app(SoundImporter::class);

        /*
        | NO $this->validate() over the whole array, and that is the fix for
        | "it only uploaded a few of them".
        |
        | A failed validate() throws, which aborts this method — so the
        | batch-done event at the bottom never fires, and the drop zone waits
        | forever for a signal that is not coming. One unsupported file used
        | to stall every file behind it, silently.
        |
        | Each file is now judged on its own and a bad one is set aside with
        | a reason. Same rule as the queue: one broken item must never stop
        | the run.
        */
        foreach (array_slice($this->files, 0, $this->batchSize()) as $file) {
            try {
                $this->take($importer, $file);
            } catch (\Throwable $e) {
                report($e);

                $this->skipped[] = [
                    'name' => $file->getClientOriginalName(),
                    'reason' => 'Could not be read: '.$e->getMessage(),
                ];
            }
        }

        $this->files = [];
        $this->refresh();

        // Always, whatever happened above. The drop zone is waiting for it.
        $this->dispatch('batch-done');
    }

    /** One file, start to finish. Throws only on something truly unexpected. */
    protected function take(SoundImporter $importer, $file): void
    {
        $path = $file->getRealPath();
        $name = $file->getClientOriginalName();

        if (! UploadLimits::accepts($name)) {
            $extension = strtoupper(pathinfo($name, PATHINFO_EXTENSION)) ?: 'no extension';

            $this->skipped[] = [
                'name' => $name,
                'reason' => "{$extension} is not an audio format we read.",
            ];

            return;
        }

        if ($file->getSize() > UploadLimits::perFileBytes()) {
            $this->skipped[] = [
                'name' => $name,
                'reason' => 'Over the '.UploadLimits::forHumans(UploadLimits::perFileBytes()).' limit.',
            ];

            return;
        }

        // Hashed BEFORE anything is stored: finding the duplicate after
        // writing the master would mean 35 MB of rubbish on disk to
        // clean up, every time.
        $checksum = hash_file('sha256', $path);
        $existing = $this->duplicateOf($checksum);

        if ($existing) {
            $this->skipped[] = [
                'name' => $name,
                'reason' => 'Already in the catalogue, byte for byte.',
                'title' => $existing->title,
                'slug' => $existing->slug,
            ];

            return;
        }

        $meta = FilenameMeta::parse($name);

        $sound = $importer->import(
            $path,
            $name,
            auth()->user(),
            null,
            null,
            ['title' => $meta['title'], 'checksum' => $checksum],
        );

        $this->rows[$sound->id] = [
            'title' => $meta['title'],
            'category_id' => (string) ($meta['category_id'] ?? $this->bulkCategory),
            'license_id' => $this->bulkLicense,
            'tags' => $this->bulkTags,
            'type' => $this->bulkType ?: 'sfx',
            'is_premium' => $this->bulkPremium === '1',
            'filename' => $name,
            'size' => $file->getSize(),
            'ucs' => $meta['ucs'],
            'guessed' => $meta['category_id'] !== null,
        ];

        $this->selected[] = $sound->id;

        ProcessSoundUpload::dispatch($sound);
    }

    /**
     * How many files this request may take.
     *
     * Read from PHP rather than fixed, because max_file_uploads is the one
     * that discards the surplus with no error at all — so the cap has to
     * follow whatever the server is actually configured for.
     */
    protected function batchSize(): int
    {
        return max(1, min(UploadLimits::maxFiles(), 40));
    }

    /**
     * Is this exact audio already in the catalogue?
     *
     * Trashed rows count. A sound removed after a copyright claim is soft
     * deleted, and silently letting the same file back in under a new name
     * would undo the takedown.
     */
    protected function duplicateOf(string $checksum): ?Sound
    {
        $soundId = SoundFile::where('purpose', 'original')
            ->where('checksum', $checksum)
            ->value('sound_id');

        return $soundId ? Sound::withTrashed()->find($soundId) : null;
    }

    // ---------------------------------------------------------------
    // Editing many at once
    // ---------------------------------------------------------------

    public function applyBulk(bool $onlySelected = true): void
    {
        $targets = $onlySelected ? $this->selected : array_keys($this->rows);

        foreach ($targets as $id) {
            if (! isset($this->rows[$id])) {
                continue;
            }

            // A blank field in the bar means "leave this one alone", not
            // "clear it" — otherwise pressing Apply for the tags would wipe
            // every category that was guessed from a filename.
            if ($this->bulkCategory !== '') {
                $this->rows[$id]['category_id'] = $this->bulkCategory;
                $this->rows[$id]['guessed'] = false;
            }

            if ($this->bulkLicense !== '') {
                $this->rows[$id]['license_id'] = $this->bulkLicense;
            }

            if ($this->bulkTags !== '') {
                $this->rows[$id]['tags'] = $this->bulkTags;
            }

            if ($this->bulkType !== '') {
                $this->rows[$id]['type'] = $this->bulkType;
            }

            if ($this->bulkPremium !== '') {
                $this->rows[$id]['is_premium'] = $this->bulkPremium === '1';
            }
        }

        session()->flash('ok', count($targets).' row(s) updated.');
    }

    public function toggleAll(): void
    {
        $this->selected = count($this->selected) === count($this->rows)
            ? []
            : array_keys($this->rows);
    }

    public function togglePremium(int $id): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id]['is_premium'] = ! $this->rows[$id]['is_premium'];
        }
    }

    /**
     * Drop one file from the batch entirely.
     *
     * A force delete, not a soft one: the Sound model's deleting hook wipes
     * the stored audio only on a force delete, and a draft nobody has seen
     * has no history worth keeping — just a master taking up disk.
     */
    public function removeRow(int $id): void
    {
        Sound::find($id)?->forceDelete();

        unset($this->rows[$id]);
        $this->selected = array_values(array_diff($this->selected, [$id]));

        $this->refresh();
    }

    public function clearSkipped(): void
    {
        $this->skipped = [];
    }

    // ---------------------------------------------------------------
    // Save
    // ---------------------------------------------------------------

    public function save(): void
    {
        if ($this->rows === []) {
            $this->addError('rows', 'Nothing to save yet.');

            return;
        }

        $missing = collect($this->rows)->filter(fn ($row) => trim($row['title']) === '');

        if ($missing->isNotEmpty()) {
            $this->addError('rows', $missing->count().' row(s) still have no title.');

            return;
        }

        if ($this->makePack && trim($this->packName) === '') {
            $this->addError('packName', 'Give the pack a name.');

            return;
        }

        $pack = $this->makePack ? $this->pack() : null;
        $order = 0;

        foreach ($this->rows as $id => $row) {
            $sound = Sound::find($id);

            if (! $sound) {
                continue;
            }

            $title = trim($row['title']);

            $sound->update([
                'title' => $title,
                'slug' => $this->slugFor($sound, $title),
                'category_id' => $row['category_id'] ?: null,
                'license_id' => $row['license_id'] ?: null,
                'type' => $row['type'],
                'is_premium' => (bool) $row['is_premium'],
                'publish_when_ready' => $this->publishWhenReady,
            ]);

            // Already through the queue? Then honour the choice now — the
            // job has been and gone and will not come back to read it.
            if ($sound->processed_at && $this->publishWhenReady && $sound->status !== 'published') {
                $sound->update([
                    'status' => 'published',
                    'published_at' => $sound->published_at ?? now(),
                ]);
            }

            $sound->tags()->sync($this->tagIds($row['tags']));

            $pack?->sounds()->syncWithoutDetaching([
                $sound->id => ['sort_order' => $order++, 'created_at' => now()],
            ]);
        }

        $pack?->refreshCount();

        $count = count($this->rows);

        ActivityLog::record('sounds.bulk_imported', null,
            "{$count} sound(s) imported".($pack ? " into pack “{$pack->name}”" : ''));

        $this->reset(['rows', 'selected', 'skipped', 'makePack', 'packName', 'bulkTags']);

        $this->refresh();

        session()->flash('ok', $this->publishWhenReady
            ? "{$count} sound(s) saved. Each goes live the moment its conversion finishes."
            : "{$count} sound(s) saved as drafts, waiting in review.");
    }

    protected function pack(): Pack
    {
        return Pack::create([
            'user_id' => auth()->id(),
            'name' => trim($this->packName),
            'slug' => Pack::uniqueSlug($this->packName),
            'is_public' => false,
            // What makes a collection a pack rather than someone's folder.
            'is_featured' => true,
        ]);
    }

    /**
     * A fresh slug while the sound has never been public.
     *
     * Once something has a published_at the URL is out in the world and may
     * be linked, so renaming must not move it — that is what the Redirects
     * screen is for, and silently changing it here would create a 404
     * nobody knows about.
     */
    protected function slugFor(Sound $sound, string $title): string
    {
        if ($sound->published_at !== null) {
            return $sound->slug;
        }

        $base = Str::slug($title) ?: 'sound';
        $slug = $base;
        $i = 2;

        while (Sound::withTrashed()->where('slug', $slug)->where('id', '!=', $sound->id)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /** @return array<int, int> */
    protected function tagIds(string $raw): array
    {
        return collect(explode(',', $raw))
            ->map(fn ($t) => trim($t))
            ->filter()
            ->unique()
            ->take(15)
            ->map(fn ($name) => Tag::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name],
            )->id)
            ->all();
    }

    protected function refresh(): void
    {
        unset($this->sounds, $this->isProcessing);
    }
}; ?>

<div @if ($this->isProcessing) wire:poll.3s @endif>
    @if (session('ok'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-success/30 bg-success/10 px-4 py-3">
            <x-icon name="circle-check" style="solid" class="text-success" />
            <span class="text-[0.88rem]">{{ session('ok') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-danger/30 bg-warning/10 px-4 py-3">
            <x-icon name="triangle-exclamation" style="solid" class="text-warning" />
            <span class="text-[0.88rem]">{{ session('error') }}</span>
        </div>
    @endif

    <div class="mb-5">
        <h1 class="text-[1.3rem] font-semibold tracking-[-0.02em]">Bulk upload</h1>
        <p class="mt-1 max-w-2xl text-[0.83rem] leading-relaxed text-paper/35">
            Conversion starts the moment a file lands, so the waveforms are being built while you type the titles.
            Nothing goes live until you press save.
        </p>
    </div>

    <div class="grid gap-5 xl:grid-cols-[340px_1fr]">

        {{-- ══════ COLUMN 1 — DROP, APPLY, SAVE ══════ --}}
        <div class="h-fit space-y-5">

            {{-- Drop zone --}}
            <div x-data="{
                    limits: {{ Js::from($this->limits) }},

                    /*
                     * Livewire sends every selected file in ONE request, so a
                     * drop of fifteen 30 MB files is a 450 MB POST that PHP
                     * refuses whole — and the failure looks like the upload
                     * simply stopping. So the drop is held here and fed to
                     * the input in groups that fit inside post_max_size.
                     */
                    queue: [],
                    inFlight: 0,
                    done: 0,
                    busy: false,

                    /* Bumped per group. The safety timer captures it and only
                       fires if the same group is still in flight — without
                       that, a stale timer releases whichever group happens to
                       be running and two uploads go out at once. */
                    gen: 0,

                    over: false,
                    uploading: false,
                    progress: 0,
                    rejected: [],

                    accept(list) {
                        this.rejected = []

                        const files = Array.from(list).filter((file) => {
                            // Caught here rather than server-side: an
                            // oversized file is rejected by Livewire before
                            // any of our validation runs, and it takes the
                            // rest of its group down with it.
                            if (file.size > this.limits.per_file) {
                                this.rejected.push(file.name + ' — too big')
                                return false
                            }

                            const extension = file.name.split('.').pop().toLowerCase()

                            if (! this.limits.formats.includes(extension)) {
                                this.rejected.push(file.name + ' — not audio')
                                return false
                            }

                            return true
                        })

                        this.queue = this.queue.concat(files)
                        this.pump()
                    },

                    pump() {
                        if (this.busy) return

                        if (this.queue.length === 0) {
                            this.uploading = false
                            return
                        }

                        const group = []
                        let bytes = 0

                        while (this.queue.length
                               && group.length < this.limits.group_files
                               && (group.length === 0
                                   || bytes + this.queue[0].size <= this.limits.group_bytes)) {
                            bytes += this.queue[0].size
                            group.push(this.queue.shift())
                        }

                        const transfer = new DataTransfer()
                        group.forEach((file) => transfer.items.add(file))

                        this.gen++
                        this.inFlight = group.length
                        this.busy = true
                        this.uploading = true
                        this.progress = 0

                        this.$refs.input.files = transfer.files
                        this.$refs.input.dispatchEvent(new Event('change'))
                    },

                    /* Guarded so the safety timer and the server event cannot
                       both release the same group and send two at once. */
                    release() {
                        if (! this.busy) return

                        this.done += this.inFlight
                        this.inFlight = 0
                        this.busy = false
                        this.pump()
                    },

                    /* If the server event never arrives — an exception mid
                       import, a dropped connection — the queue would sit
                       there looking busy forever. */
                    armFallback() {
                        const generation = this.gen
                        setTimeout(() => {
                            if (this.gen === generation) this.release()
                        }, 45000)
                    },

                    get total() {
                        return this.done + this.inFlight + this.queue.length
                    },
                }"
                 x-on:batch-done.window="release()"
                 x-on:livewire-upload-progress="progress = $event.detail.progress"
                 x-on:livewire-upload-error="release()"
                 x-on:livewire-upload-finish="armFallback()"
                 x-on:dragover.prevent="over = true"
                 x-on:dragleave.prevent="over = false"
                 x-on:drop.prevent="over = false; accept($event.dataTransfer.files)"
                 class="rounded-2xl border border-hairline bg-panel p-5">

                <div x-on:click="$refs.picker.click()"
                     :class="over ? 'border-action bg-action/10' : 'border-hairline hover:border-paper/25'"
                     class="cursor-pointer rounded-xl border border-dashed px-5 py-8 text-center transition duration-200 ease-dbelo">

                    <template x-if="! uploading">
                        <div>
                            <x-icon name="waveform-lines" style="regular" class="text-[26px] text-paper/25" />
                            <p class="mt-3 text-[0.88rem]">Drop audio here</p>
                            <p class="mt-1 text-[0.75rem] text-paper/30">
                                WAV, MP3, AIFF, FLAC, M4A, OPUS and more · drop as many as you like
                            </p>
                        </div>
                    </template>

                    <template x-if="uploading">
                        <div>
                            <x-icon name="arrow-up-from-bracket" style="solid" class="text-[22px] text-action" />
                            <p class="mt-3 text-[0.88rem]">
                                Uploading <span x-text="done + 1"></span> of <span x-text="total"></span>
                            </p>
                            <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-raised">
                                <div class="h-full rounded-full bg-action transition-all" :style="`width: ${progress}%`"></div>
                            </div>
                            <p class="mt-2 text-[0.72rem] text-paper/30">Sent in batches so one big file cannot stall the rest</p>
                        </div>
                    </template>
                </div>

                {{-- The real file input. It never receives the drop directly:
                     the queue above decides what goes in it and when. --}}
                <input type="file" x-ref="input" multiple wire:model="files" class="hidden"
                       accept="{{ App\Support\UploadLimits::acceptAttribute() }}" />

                {{-- And a second one for the click-to-browse path, so picking
                     files goes through the same queue instead of straight
                     into Livewire. --}}
                <input type="file" x-ref="picker" multiple class="hidden"
                       accept="{{ App\Support\UploadLimits::acceptAttribute() }}"
                       x-on:change="accept($event.target.files); $event.target.value = ''" />

                <template x-if="rejected.length">
                    <div class="mt-4 rounded-lg bg-danger/15 px-3.5 py-3">
                        <p class="text-[0.8rem] text-danger">
                            <span x-text="rejected.length"></span> file(s) not sent
                        </p>
                        <p class="mt-1 text-[0.72rem] leading-relaxed text-paper/40">
                            Audio only, up to {{ UploadLimits::forHumans($this->limits['per_file']) }} each.
                        </p>
                        <ul class="mt-2 space-y-0.5">
                            <template x-for="name in rejected" :key="name">
                                <li class="truncate font-mono text-[0.7rem] text-paper/30" x-text="name"></li>
                            </template>
                        </ul>
                    </div>
                </template>

                @error('files.*') <p class="mt-3 text-[0.8rem] text-danger">{{ $message }}</p> @enderror

                <p class="mt-4 text-[0.73rem] leading-relaxed text-paper/25">
                    The filename becomes the title, minus take numbers. If it follows the
                    <span class="text-paper/45">UCS</span> convention the category is proposed too — always as a
                    suggestion you confirm.
                </p>
            </div>

            {{-- What this server actually accepts --}}
            <div class="rounded-2xl border border-hairline bg-panel p-5">
                <div class="flex items-center gap-3">
                    <x-admin.icon-chip icon="gauge-high" tone="muted" />
                    <div class="min-w-0 flex-1">
                        <div class="text-[0.88rem]">This server accepts</div>
                        <div class="text-[0.75rem] text-paper/30">Read from PHP, not assumed</div>
                    </div>
                </div>

                <dl class="mt-4 space-y-2 text-[0.78rem]">
                    @foreach ([
                        ['Per file', UploadLimits::forHumans($this->limits['per_file'])],
                        ['Per request', UploadLimits::forHumans($this->limits['per_request'])],
                        ['Files at once', $this->limits['max_files']],
                    ] as [$label, $value])
                        <div class="flex items-center justify-between">
                            <dt class="text-paper/35">{{ $label }}</dt>
                            <dd class="tabular-nums text-paper/70">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if ($this->limits['too_low'])
                    {{-- PHP's out-of-the-box 2 MB. Fine for an avatar, useless
                         for audio — and nothing else in the app would ever
                         mention the number, so it says it here with the file
                         to edit and the lines to put in it. --}}
                    <div class="mt-4 rounded-xl bg-warning/10 p-4">
                        <div class="flex items-center gap-2 text-[0.8rem] text-warning">
                            <x-icon name="triangle-exclamation" style="solid" class="text-[11px]" />
                            Too low for audio
                        </div>

                        <p class="mt-2 text-[0.73rem] leading-relaxed text-paper/40">
                            This is PHP's own default, not something dbelo sets. Almost any real recording is over it.
                        </p>

                        @if ($this->limits['ini_file'])
                            <p class="mt-3 text-[0.7rem] uppercase tracking-[0.14em] text-paper/30">Edit this file</p>
                            <code class="mt-1 block break-all font-mono text-[0.72rem] text-paper/60">{{ $this->limits['ini_file'] }}</code>
                        @endif

                        <p class="mt-3 text-[0.7rem] uppercase tracking-[0.14em] text-paper/30">Set</p>
<pre class="mt-1 overflow-x-auto font-mono text-[0.72rem] leading-relaxed text-paper/60">upload_max_filesize = 256M
post_max_size = 300M
max_file_uploads = 40
memory_limit = 512M</pre>

                        <p class="mt-3 text-[0.72rem] leading-relaxed text-paper/30">
                            Then restart Herd. <span class="font-mono text-paper/45">post_max_size</span> must stay
                            above <span class="font-mono text-paper/45">upload_max_filesize</span> — it covers the
                            whole request, not one file.
                        </p>
                    </div>
                @elseif ($this->limits['livewire'] <= $this->limits['upload_max_filesize'])
                    <p class="mt-4 text-[0.72rem] leading-relaxed text-paper/25">
                        The binding limit is Livewire's, not PHP's. It lives in
                        <span class="font-mono text-paper/45">AppServiceProvider::configureUploads()</span>.
                    </p>
                @else
                    <p class="mt-4 text-[0.72rem] leading-relaxed text-paper/25">
                        The binding limit is PHP's, in
                        <span class="font-mono text-paper/45">{{ $this->limits['ini_file'] ?? 'php.ini' }}</span>.
                    </p>
                @endif
            </div>

            {{-- Not imported --}}
            @if ($skipped)
                <div class="rounded-2xl border border-danger/30 bg-panel p-5">
                    <div class="flex items-center gap-3">
                        <x-icon name="circle-exclamation" style="solid" class="text-warning" />
                        <div class="min-w-0 flex-1 text-[0.88rem]">{{ count($skipped) }} not imported</div>
                        <x-admin.icon-button icon="xmark" variant="muted" label="Dismiss" wire:click="clearSkipped" />
                    </div>

                    <p class="mt-3 text-[0.73rem] leading-relaxed text-paper/35">
                        Everything else in the drop went through. These are listed rather than dropped in silence,
                        with the reason for each.
                    </p>

                    <ul class="mt-3 space-y-3">
                        @foreach ($skipped as $item)
                            <li class="text-[0.75rem]">
                                <span class="block truncate font-mono text-paper/50">{{ $item['name'] }}</span>
                                <span class="block text-paper/30">{{ $item['reason'] ?? 'Skipped.' }}</span>

                                @if (isset($item['slug']))
                                    <a href="{{ route('sounds.show', $item['slug']) }}" target="_blank" rel="noopener"
                                       class="text-info hover:underline">→ {{ $item['title'] }}</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Apply to many --}}
            @if ($rows)
                <div class="rounded-2xl border border-hairline bg-panel">
                    <div class="flex items-center gap-3 border-b border-hairline px-5 py-4">
                        <x-admin.icon-chip icon="layer-group" tone="brand" />
                        <div class="min-w-0 flex-1">
                            <h2 class="text-[0.95rem] font-medium">Apply to many</h2>
                            <p class="text-[0.73rem] text-paper/30">Blank fields are left alone</p>
                        </div>
                    </div>

                    <div class="space-y-4 p-5">
                        <div>
                            <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Category</label>
                            <select wire:model="bulkCategory"
                                    class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.86rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                                <option value="">— no change —</option>
                                @foreach ($this->categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">License</label>
                            <select wire:model="bulkLicense"
                                    class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.86rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                                <option value="">— no change —</option>
                                @foreach ($this->licenses as $license)
                                    <option value="{{ $license->id }}">{{ $license->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div>
                            <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Tags</label>
                            <input type="text" wire:model="bulkTags" placeholder="city, summer, outdoor"
                                   class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.86rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Type</label>
                                <select wire:model="bulkType"
                                        class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.86rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                                    <option value="">—</option>
                                    <option value="sfx">SFX</option>
                                    <option value="music">Music</option>
                                </select>
                            </div>

                            <div>
                                <label class="mb-2 block text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">Premium</label>
                                <select wire:model="bulkPremium"
                                        class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.86rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                                    <option value="">—</option>
                                    <option value="1">Yes</option>
                                    <option value="0">No</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex gap-2">
                            <button wire:click="applyBulk(true)"
                                    class="flex-1 rounded-lg bg-raised py-2.5 text-[0.83rem] text-paper/70 transition duration-200 ease-dbelo hover:bg-paper/[0.10] hover:text-paper">
                                Selected ({{ count($selected) }})
                            </button>
                            <button wire:click="applyBulk(false)"
                                    class="flex-1 rounded-lg bg-raised py-2.5 text-[0.83rem] text-paper/70 transition duration-200 ease-dbelo hover:bg-paper/[0.10] hover:text-paper">
                                All ({{ count($rows) }})
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Pack + save --}}
                <div class="rounded-2xl border border-hairline bg-panel p-5">
                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="checkbox" wire:model.live="makePack"
                               class="mt-0.5 size-4 rounded border-0 bg-raised text-brand focus:ring-2 focus:ring-brand/40" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-[0.85rem]">Also group them in a pack</span>
                            <span class="block text-[0.73rem] leading-relaxed text-paper/30">
                                Each sound still exists on its own. The pack is an extra page that lists them together.
                            </span>
                        </span>
                    </label>

                    @if ($makePack)
                        <input type="text" wire:model="packName" placeholder="Pack name"
                               class="mt-3 w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.86rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                        @error('packName') <p class="mt-1.5 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                        <p class="mt-2 text-[0.72rem] text-paper/25">Created hidden — publish it from Catalog → Packs.</p>
                    @endif

                    <label class="mt-4 flex cursor-pointer items-start gap-3 border-t border-hairline pt-4">
                        <input type="checkbox" wire:model.live="publishWhenReady"
                               class="mt-0.5 size-4 rounded border-0 bg-raised text-brand focus:ring-2 focus:ring-brand/40" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-[0.85rem]">Publish when ready</span>
                            <span class="block text-[0.73rem] leading-relaxed text-paper/30">
                                Each sound goes live the moment its own conversion finishes. Unticked, they wait in
                                Catalog → In review.
                            </span>
                        </span>
                    </label>

                    @error('rows') <p class="mt-3 text-[0.8rem] text-danger">{{ $message }}</p> @enderror

                    <button wire:click="save" wire:loading.attr="disabled" wire:target="save"
                            class="mt-4 flex w-full items-center justify-center gap-2 rounded-lg bg-action py-3 text-[0.9rem] font-medium text-white transition duration-200 ease-dbelo hover:brightness-110 disabled:opacity-50">
                        <x-icon name="floppy-disk" style="solid" class="text-[12px]" />
                        Save {{ count($rows) }} sound(s)
                    </button>
                </div>
            @endif
        </div>

        {{-- ══════ COLUMN 2 — THE GRID ══════ --}}
        <div class="min-w-0 rounded-2xl border border-hairline bg-panel">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
                <h2 class="text-[0.95rem] font-medium">
                    This batch <span class="ml-1.5 text-paper/35">{{ count($rows) }}</span>
                </h2>

                @if ($rows)
                    <button wire:click="toggleAll"
                            class="text-[0.8rem] text-paper/45 transition hover:text-paper">
                        {{ count($selected) === count($rows) ? 'Deselect all' : 'Select all' }}
                    </button>
                @endif
            </div>

            @if ($rows)
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                            <th class="w-10 px-5 py-2.5"></th>
                            <th class="px-3 py-2.5 font-medium">Title</th>
                            <th class="w-44 px-3 py-2.5 font-medium">Category</th>
                            <th class="w-48 px-3 py-2.5 font-medium">Tags</th>
                            <th class="w-24 px-5 py-2.5 text-right font-medium">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-hairline">
                        @foreach ($rows as $id => $row)
                            @php $sound = $this->sounds[$id] ?? null; @endphp

                            <tr wire:key="row-{{ $id }}" class="transition hover:bg-paper/[0.03]">
                                <td class="px-5 py-3">
                                    <input type="checkbox" wire:model.live="selected" value="{{ $id }}"
                                           class="mt-2.5 size-4 rounded border-0 bg-raised text-brand focus:ring-2 focus:ring-brand/40" />
                                </td>

                                <td class="min-w-0 px-3 py-3">
                                    <input type="text" wire:model="rows.{{ $id }}.title"
                                           class="w-full rounded-lg border-0 bg-raised px-3 py-2 text-[0.86rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />

                                    <div class="mt-1.5 flex flex-wrap items-center gap-2 text-[0.7rem] text-paper/25">
                                        <span class="truncate font-mono">{{ $row['filename'] }}</span>

                                        @if ($row['ucs'])
                                            <span class="rounded bg-info/15 px-1.5 py-0.5 text-info">UCS {{ $row['ucs'] }}</span>
                                        @endif

                                        {{-- What the queue has done with it, live. --}}
                                        @if ($sound?->processing_error)
                                            <span class="rounded bg-danger/15 px-1.5 py-0.5 text-danger">failed</span>
                                        @elseif ($sound?->processed_at)
                                            <span class="rounded bg-success/15 px-1.5 py-0.5 text-success">
                                                {{ gmdate($sound->duration_ms >= 3600000 ? 'H:i:s' : 'i:s', (int) round($sound->duration_ms / 1000)) }}
                                                · {{ $sound->sample_rate ? round($sound->sample_rate / 1000, 1).' kHz' : '' }}
                                            </span>
                                        @else
                                            <span class="flex items-center gap-1 rounded bg-raised px-1.5 py-0.5 text-paper/40">
                                                <x-icon name="gear" style="solid" class="fa-spin text-[9px]" />
                                                converting
                                            </span>
                                        @endif
                                    </div>

                                    @if ($sound?->processing_error)
                                        <p class="mt-1 text-[0.72rem] text-danger/70">{{ $sound->processing_error }}</p>
                                    @endif
                                </td>

                                <td class="px-3 py-3">
                                    <select wire:model="rows.{{ $id }}.category_id"
                                            @class([
                                                'w-full rounded-lg border-0 px-2.5 py-2 text-[0.82rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40',
                                                'bg-info/15' => $row['guessed'],
                                                'bg-raised' => ! $row['guessed'],
                                            ])>
                                        <option value="">— none —</option>
                                        @foreach ($this->categories as $category)
                                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                                        @endforeach
                                    </select>

                                    @if ($row['guessed'])
                                        <p class="mt-1 text-[0.68rem] text-info">from the filename</p>
                                    @endif
                                </td>

                                <td class="px-3 py-3">
                                    <input type="text" wire:model="rows.{{ $id }}.tags" placeholder="tag, tag"
                                           class="w-full rounded-lg border-0 bg-raised px-2.5 py-2 text-[0.82rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                                </td>

                                <td class="px-5 py-3">
                                    <div class="flex items-center justify-end gap-1">
                                        <x-admin.icon-button
                                            icon="crown"
                                            :label="$row['is_premium'] ? 'Premium — click to make free' : 'Free — click to make premium'"
                                            :class="$row['is_premium'] ? '!text-warning' : ''"
                                            wire:click="togglePremium({{ $id }})" />

                                        <x-admin.icon-button icon="trash" label="Remove"
                                                             class="hover:!bg-danger/15 hover:!text-danger"
                                                             wire:click="removeRow({{ $id }})" />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <div class="px-5 py-20 text-center">
                    <x-icon name="waveform-lines" style="regular" class="text-[26px] text-paper/15" />
                    <p class="mt-3 text-[0.9rem] text-paper/45">Nothing in this batch yet</p>
                    <p class="mx-auto mt-1.5 max-w-sm text-[0.8rem] leading-relaxed text-paper/25">
                        Drop files on the left. They appear here as they arrive, already converting, with the title
                        taken from the filename.
                    </p>
                </div>
            @endif
        </div>
    </div>
</div>
