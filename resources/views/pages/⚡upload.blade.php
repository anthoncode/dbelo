<?php

use App\Jobs\ProcessSoundUpload;
use App\Models\Category;
use App\Models\License;
use App\Models\Sound;
use App\Services\SoundImporter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.site')] #[Title('Upload sounds')] class extends Component {
    use WithFileUploads;

    /** @var array<\Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $files = [];

    public string $categoryId = '';

    public string $licenseId = '';

    public bool $isPremium = false;

    public bool $isLoopable = false;

    public string $tags = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->canUpload(), 403);

        $this->licenseId = (string) (License::where('slug', 'dbelo-standard')->value('id') ?? '');
    }

    public function rules(): array
    {
        return [
            // 200 MB per file: a five minute 96 kHz stereo WAV lands around 170 MB.
            'files.*' => ['file', 'mimes:wav,mp3,aiff,aif,flac,ogg', 'max:204800'],
            'categoryId' => ['nullable', 'exists:categories,id'],
            'licenseId' => ['nullable', 'exists:licenses,id'],
            'tags' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function messages(): array
    {
        return [
            'files.*.mimes' => 'Only WAV, MP3, AIFF, FLAC and OGG files are accepted.',
            'files.*.max' => 'Each file must be under 200 MB.',
        ];
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

    #[Computed]
    public function mySounds()
    {
        return Sound::where('user_id', auth()->id())
            ->with('files')
            ->latest()
            ->limit(20)
            ->get();
    }

    /**
     * True while anything is still being processed, which is what turns
     * the polling on. No sense re-querying every three seconds forever.
     */
    #[Computed]
    public function isProcessing(): bool
    {
        return $this->mySounds->contains(fn ($s) => in_array($s->status, ['draft', 'processing'], true));
    }

    public function removeFile(int $index): void
    {
        unset($this->files[$index]);
        $this->files = array_values($this->files);
    }

    public function save(SoundImporter $importer): void
    {
        $this->validate();

        if ($this->files === []) {
            $this->addError('files', 'Choose at least one audio file.');

            return;
        }

        $category = $this->categoryId ? Category::find($this->categoryId) : null;
        $license = $this->licenseId ? License::find($this->licenseId) : null;

        $tagNames = collect(explode(',', $this->tags))
            ->map(fn ($t) => trim($t))
            ->filter()
            ->unique()
            ->take(15);

        foreach ($this->files as $file) {
            $sound = $importer->import(
                $file->getRealPath(),
                $file->getClientOriginalName(),
                auth()->user(),
                $category,
                $license,
                [
                    'is_premium' => $this->isPremium,
                    'is_loopable' => $this->isLoopable,
                ]
            );

            foreach ($tagNames as $name) {
                $tag = \App\Models\Tag::firstOrCreate(
                    ['slug' => \Illuminate\Support\Str::slug($name)],
                    ['name' => $name]
                );
                $sound->tags()->attach($tag->id);
            }

            // Queued, never inline: ffmpeg on a large WAV takes far longer
            // than a web request is allowed to live.
            ProcessSoundUpload::dispatch($sound);
        }

        $count = count($this->files);

        $this->reset(['files', 'tags', 'isPremium', 'isLoopable']);
        unset($this->mySounds);

        session()->flash('uploaded', "{$count} ".\Illuminate\Support\Str::plural('file', $count).' queued for processing.');
    }
}; ?>

<div>
    <div class="mx-auto max-w-4xl">

        <div class="mb-8">
            <div class="micro">Contribute</div>
            <h1 class="mt-2 text-3xl font-semibold">Upload sounds</h1>
            <p class="mt-2 text-ink/60 dark:text-paper/60">
                Drop your masters here. We read the metadata, build the preview and draw the waveform for you.
            </p>
        </div>

        @if (session('uploaded'))
            <div class="mb-6 flex items-center gap-3 rounded-card bg-surface p-4 shadow-soft-md dark:bg-surface-dark">
                <span class="grid size-9 shrink-0 place-items-center rounded-[12px] bg-brand text-white">
                    <x-icon name="check" style="solid" class="text-sm" />
                </span>
                <div>
                    <div class="text-[0.95rem]">{{ session('uploaded') }}</div>
                    <div class="micro mt-0.5">Processing runs in the background</div>
                </div>
            </div>
        @endif

        {{-- Drop zone --}}
        <div
            x-data="{
                dragging: false,
                uploading: false,
                progress: 0,
            }"
            x-on:livewire-upload-start="uploading = true"
            x-on:livewire-upload-finish="uploading = false; progress = 0"
            x-on:livewire-upload-error="uploading = false"
            x-on:livewire-upload-progress="progress = $event.detail.progress"
            class="rounded-card bg-surface p-3 shadow-soft-md dark:bg-surface-dark"
        >
            <div
                x-on:dragover.prevent="dragging = true"
                x-on:dragleave.prevent="dragging = false"
                x-on:drop.prevent="
                    dragging = false;
                    $refs.input.files = $event.dataTransfer.files;
                    $refs.input.dispatchEvent(new Event('change'));
                "
                x-on:click="$refs.input.click()"
                :class="dragging ? 'border-brand bg-brand/5' : 'border-ink/15 dark:border-paper/15'"
                class="cursor-pointer rounded-control border-2 border-dashed px-6 py-14 text-center transition duration-300 ease-dbelo"
            >
                <input type="file" multiple x-ref="input" wire:model="files" class="hidden"
                       accept=".wav,.mp3,.aiff,.aif,.flac,.ogg" />

                <span class="mx-auto mb-4 grid size-14 place-items-center rounded-[18px] bg-ink/[0.05] text-brand dark:bg-paper/10">
                    <x-icon name="waveform-lines" style="solid" class="text-xl" />
                </span>

                <div class="text-[1.05rem]">Drop audio files here</div>
                <div class="micro mt-2">WAV · MP3 · AIFF · FLAC · OGG — up to 200 MB each</div>
            </div>

            {{-- Upload progress --}}
            <div x-show="uploading" x-cloak class="px-4 pb-2 pt-4">
                <div class="h-[7px] overflow-hidden rounded-full bg-ink/[0.06] dark:bg-paper/10">
                    <div class="h-full rounded-full bg-brand transition-[width] duration-100" :style="`width: ${progress}%`"></div>
                </div>
                <div class="micro mt-2">Uploading… <span x-text="progress + '%'"></span></div>
            </div>

            {{-- Selected files --}}
            @if ($files)
                <div class="mt-3 space-y-1">
                    @foreach ($files as $index => $file)
                        <div wire:key="file-{{ $index }}" class="flex items-center gap-3 rounded-control px-4 py-2.5 hover:bg-ink/[0.04] dark:hover:bg-paper/[0.06]">
                            <x-icon name="file-audio" class="text-brand" />
                            <span class="min-w-0 flex-1 truncate text-[0.9rem]">{{ $file->getClientOriginalName() }}</span>
                            <span class="micro">{{ number_format($file->getSize() / 1048576, 1) }} MB</span>
                            <button type="button" wire:click="removeFile({{ $index }})"
                                    class="grid size-7 place-items-center rounded-full text-ink/40 transition hover:bg-ink/10 hover:text-ink dark:text-paper/40 dark:hover:bg-paper/10 dark:hover:text-paper">
                                <x-icon name="xmark" style="solid" class="text-xs" />
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif

            @error('files.*') <div class="px-4 py-2 text-sm text-brand">{{ $message }}</div> @enderror
        </div>

        {{-- Shared metadata --}}
        <div class="mt-5 rounded-card bg-surface p-7 shadow-soft-md dark:bg-surface-dark">
            <div class="micro mb-5">Applies to every file in this batch</div>

            <div class="grid gap-5 md:grid-cols-2">
                <label class="block">
                    <span class="mb-2 block text-sm text-ink/60 dark:text-paper/60">Category</span>
                    <select wire:model="categoryId"
                            class="w-full rounded-control border-0 bg-paper px-4 py-3 text-[0.95rem] shadow-soft-sm focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-paper/10">
                        <option value="">Uncategorised</option>
                        @foreach ($this->categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->parent_id ? '— ' : '' }}{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-2 block text-sm text-ink/60 dark:text-paper/60">License</span>
                    <select wire:model="licenseId"
                            class="w-full rounded-control border-0 bg-paper px-4 py-3 text-[0.95rem] shadow-soft-sm focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-paper/10">
                        @foreach ($this->licenses as $license)
                            <option value="{{ $license->id }}">{{ $license->name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block md:col-span-2">
                    <span class="mb-2 block text-sm text-ink/60 dark:text-paper/60">Tags</span>
                    <input type="text" wire:model="tags" placeholder="thunder, storm, rumble, distant"
                           class="w-full rounded-control border-0 bg-paper px-4 py-3 text-[0.95rem] shadow-soft-sm placeholder:text-ink/30 focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-paper/10 dark:placeholder:text-paper/30" />
                    <span class="micro mt-2 block">Comma separated. These drive the search, so be generous.</span>
                </label>
            </div>

            <div class="mt-6 flex flex-wrap gap-3">
                <button type="button" wire:click="$toggle('isPremium')"
                        class="flex items-center gap-2 rounded-full px-4 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5
                               {{ $isPremium ? 'bg-brand text-white' : 'bg-paper dark:bg-paper/10' }}">
                    <x-icon name="crown" :style="$isPremium ? 'solid' : 'regular'" class="text-xs" />
                    Premium only
                </button>

                <button type="button" wire:click="$toggle('isLoopable')"
                        class="flex items-center gap-2 rounded-full px-4 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5
                               {{ $isLoopable ? 'bg-brand text-white' : 'bg-paper dark:bg-paper/10' }}">
                    <x-icon name="repeat" :style="$isLoopable ? 'solid' : 'regular'" class="text-xs" />
                    Seamless loop
                </button>
            </div>

            <div class="mt-7 flex items-center gap-4">
                <button wire:click="save" wire:loading.attr="disabled" wire:target="save,files"
                        class="flex items-center gap-2 rounded-full bg-brand px-7 py-3 font-medium text-white shadow-brand transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-brand-lg disabled:opacity-50 disabled:hover:translate-y-0">
                    <x-icon name="arrow-up-from-bracket" style="solid" class="text-sm" />
                    <span wire:loading.remove wire:target="save">Upload {{ $files ? count($files) : '' }}</span>
                    <span wire:loading wire:target="save">Working…</span>
                </button>

                <span class="micro">Sounds land in review, not live</span>
            </div>
        </div>

        {{-- My uploads --}}
        <section class="mt-12" @if ($this->isProcessing) wire:poll.3s @endif>
            <div class="mb-5 flex items-end justify-between">
                <div>
                    <div class="micro">Library</div>
                    <h2 class="mt-2 text-xl font-medium">Your uploads</h2>
                </div>
                @if ($this->isProcessing)
                    <span class="micro flex items-center gap-2">
                        <span class="size-2 animate-pulse rounded-full bg-brand"></span> Processing
                    </span>
                @endif
            </div>

            <div class="rounded-card bg-surface p-3 shadow-soft-md dark:bg-surface-dark">
                @forelse ($this->mySounds as $sound)
                    <div wire:key="mine-{{ $sound->id }}"
                         class="flex flex-wrap items-center gap-4 rounded-control px-4 py-3 transition duration-350 ease-dbelo hover:bg-ink/[0.04] dark:hover:bg-paper/[0.06]">

                        <div class="min-w-0 flex-1">
                            <div class="truncate text-[0.95rem]">{{ $sound->title }}</div>
                            <div class="micro mt-1">
                                {{ $sound->category?->name ?? 'Uncategorised' }}
                                @if ($sound->duration_ms) · {{ $sound->durationForHumans() }} @endif
                                @if ($sound->sample_rate) · {{ number_format($sound->sample_rate / 1000, 1) }} kHz @endif
                            </div>
                        </div>

                        @php
                            $label = match ($sound->status) {
                                'draft' => 'Queued',
                                'processing' => 'Processing',
                                'pending' => 'In review',
                                'published' => 'Live',
                                'rejected' => 'Rejected',
                                default => $sound->status,
                            };
                            $isLive = $sound->status === 'published';
                        @endphp

                        <span class="rounded-full px-3.5 py-1.5 text-[0.78rem] shadow-soft-sm
                                     {{ $isLive ? 'bg-brand text-white' : 'bg-paper dark:bg-paper/10' }}">
                            {{ $label }}
                        </span>

                        @if ($isLive)
                            <a href="{{ route('sounds.show', $sound) }}" wire:navigate
                               class="grid size-9 place-items-center rounded-full bg-paper shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-paper/10">
                                <x-icon name="arrow-right" style="solid" class="text-xs" />
                            </a>
                        @endif

                        @if ($sound->processing_error)
                            <div class="w-full text-sm text-brand">{{ Str::limit($sound->processing_error, 120) }}</div>
                        @endif
                    </div>
                @empty
                    <div class="py-14 text-center">
                        <p>Nothing uploaded yet</p>
                        <p class="micro mt-2">Your sounds will appear here as they process</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>
</div>
