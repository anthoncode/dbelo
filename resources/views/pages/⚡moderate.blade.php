<?php

use App\Models\Category;
use App\Models\Sound;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.site')] #[Title('Moderation')] class extends Component {

    #[Url(except: 'pending')]
    public string $tab = 'pending';

    /** Per-row edits, keyed by sound id. Moderating usually means fixing a
     *  sloppy title or a missing category, not just saying yes or no. */
    public array $drafts = [];

    public ?int $rejecting = null;

    public string $reason = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $this->hydrateDrafts();
    }

    public function updatedTab(): void
    {
        $this->rejecting = null;
        unset($this->sounds);
        $this->hydrateDrafts();
    }

    protected function hydrateDrafts(): void
    {
        foreach ($this->sounds as $sound) {
            $this->drafts[$sound->id] ??= [
                'title' => $sound->title,
                'category_id' => (string) ($sound->category_id ?? ''),
                'is_premium' => (bool) $sound->is_premium,
            ];
        }
    }

    #[Computed]
    public function categories()
    {
        return Category::orderBy('parent_id')->orderBy('sort_order')->get();
    }

    #[Computed]
    public function sounds()
    {
        return Sound::query()
            ->where('status', $this->tab)
            ->with(['files', 'category', 'user', 'tags'])
            ->latest('created_at')
            ->limit(40)
            ->get();
    }

    #[Computed]
    public function counts(): array
    {
        return Sound::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }

    public function approve(int $id): void
    {
        $sound = Sound::findOrFail($id);
        $draft = $this->drafts[$id] ?? [];

        $title = trim($draft['title'] ?? $sound->title);

        if ($title === '') {
            $this->addError("drafts.{$id}.title", 'A title is required.');

            return;
        }

        // The slug only changes while the sound has never been public, so a
        // published URL never breaks under someone who bookmarked it.
        if ($title !== $sound->title && ! $sound->published_at) {
            $sound->slug = app(\App\Services\SoundImporter::class)->uniqueSlug($title);
        }

        $sound->fill([
            'title' => $title,
            'category_id' => $draft['category_id'] ?: null,
            'is_premium' => (bool) ($draft['is_premium'] ?? false),
            'status' => 'published',
            'published_at' => $sound->published_at ?? now(),
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ])->save();

        $this->refreshList("“{$sound->title}” is live.");
    }

    public function startReject(int $id): void
    {
        $this->rejecting = $id;
        $this->reason = '';
    }

    public function cancelReject(): void
    {
        $this->rejecting = null;
        $this->reason = '';
    }

    public function reject(int $id): void
    {
        $this->validate([
            // Without a reason the contributor re-uploads the same thing and
            // you both waste the round trip.
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ], [
            'reason.required' => 'Explain why, so the contributor can fix it.',
            'reason.min' => 'Give at least a short sentence.',
        ]);

        Sound::findOrFail($id)->update([
            'status' => 'rejected',
            'rejection_reason' => $this->reason,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $this->rejecting = null;
        $this->reason = '';

        $this->refreshList('Rejected.');
    }

    public function restore(int $id): void
    {
        Sound::findOrFail($id)->update([
            'status' => 'pending',
            'rejection_reason' => null,
        ]);

        $this->refreshList('Back in the queue.');
    }

    public function unpublish(int $id): void
    {
        Sound::findOrFail($id)->update(['status' => 'pending']);

        $this->refreshList('Taken offline.');
    }

    protected function refreshList(string $message): void
    {
        unset($this->sounds, $this->counts);
        $this->hydrateDrafts();
        session()->flash('moderated', $message);
    }
}; ?>

<div>
    <div class="mx-auto max-w-5xl">

        <div class="mb-7">
            <div class="micro">Admin</div>
            <h1 class="mt-2 text-3xl font-semibold">Moderation</h1>
            <p class="mt-2 text-ink/60 dark:text-paper/60">
                Review what contributors upload before it reaches the catalogue.
            </p>
        </div>

        @if (session('moderated'))
            <div class="mb-6 flex items-center gap-3 rounded-card bg-surface p-4 shadow-soft-md dark:bg-surface-dark">
                <span class="grid size-9 shrink-0 place-items-center rounded-[12px] bg-brand text-white">
                    <x-icon name="check" style="solid" class="text-sm" />
                </span>
                <span class="text-[0.95rem]">{{ session('moderated') }}</span>
            </div>
        @endif

        {{-- Tabs --}}
        <div class="mb-6 flex flex-wrap gap-2">
            @foreach ([
                'pending' => 'In review',
                'published' => 'Live',
                'rejected' => 'Rejected',
                'processing' => 'Processing',
            ] as $key => $label)
                <button wire:click="$set('tab', '{{ $key }}')"
                        class="flex items-center gap-2 rounded-full px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5
                               {{ $tab === $key ? 'bg-ink text-paper dark:bg-brand' : 'bg-surface text-ink/60 dark:bg-surface-dark dark:text-paper/60' }}">
                    {{ $label }}
                    <span class="rounded-full px-2 py-0.5 text-[0.72rem] {{ $tab === $key ? 'bg-paper/20' : 'bg-ink/[0.06] dark:bg-paper/10' }}">
                        {{ $this->counts[$key] ?? 0 }}
                    </span>
                </button>
            @endforeach
        </div>

        {{-- Queue --}}
        <div class="space-y-4">
            @forelse ($this->sounds as $sound)
                <div wire:key="mod-{{ $sound->id }}" class="rounded-card bg-surface p-6 shadow-soft-md dark:bg-surface-dark">

                    <div class="mb-5 flex flex-wrap items-start justify-between gap-4">
                        <div class="micro">
                            by {{ $sound->user->name }} · {{ $sound->created_at->diffForHumans() }}
                            @if ($sound->duration_ms) · {{ $sound->durationForHumans() }} @endif
                            @if ($sound->sample_rate) · {{ number_format($sound->sample_rate / 1000, 1) }} kHz @endif
                            @if ($sound->channels) · {{ $sound->channels === 1 ? 'Mono' : 'Stereo' }} @endif
                        </div>

                        @if ($sound->tags->isNotEmpty())
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($sound->tags as $tag)
                                    <span class="rounded-full bg-paper px-3 py-1 text-[0.75rem] text-ink/60 dark:bg-paper/10 dark:text-paper/60">{{ $tag->name }}</span>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    {{-- Listen before deciding --}}
                    <x-waveform-player :sound="$sound" :bars="110" height="h-14" class="mb-6" />

                    @if ($sound->processing_error)
                        <div class="mb-5 rounded-control bg-brand/10 px-4 py-3 text-sm text-brand">
                            <x-icon name="triangle-exclamation" style="solid" class="mr-2" />
                            {{ $sound->processing_error }}
                        </div>
                    @endif

                    @if ($sound->status === 'rejected' && $sound->rejection_reason)
                        <div class="mb-5 rounded-control bg-ink/[0.05] px-4 py-3 text-sm dark:bg-paper/10">
                            <span class="micro block mb-1">Reason given</span>
                            {{ $sound->rejection_reason }}
                        </div>
                    @endif

                    {{-- Editable fields --}}
                    <div class="grid gap-4 md:grid-cols-[1fr_240px]">
                        <label class="block">
                            <span class="micro mb-2 block">Title</span>
                            <input type="text" wire:model="drafts.{{ $sound->id }}.title"
                                   class="w-full rounded-control border-0 bg-paper px-4 py-2.5 text-[0.95rem] shadow-soft-sm focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-paper/10" />
                            @error("drafts.{$sound->id}.title") <span class="mt-1 block text-sm text-brand">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="micro mb-2 block">Category</span>
                            <select wire:model="drafts.{{ $sound->id }}.category_id"
                                    class="w-full rounded-control border-0 bg-paper px-4 py-2.5 text-[0.95rem] shadow-soft-sm focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-paper/10">
                                <option value="">Uncategorised</option>
                                @foreach ($this->categories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->parent_id ? '— ' : '' }}{{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </label>
                    </div>

                    {{-- Actions --}}
                    <div class="mt-6 flex flex-wrap items-center gap-3">
                        <button wire:click="$toggle('drafts.{{ $sound->id }}.is_premium')"
                                class="flex items-center gap-2 rounded-full px-4 py-2.5 text-[0.83rem] shadow-soft-sm transition duration-300 ease-dbelo hover:-translate-y-0.5
                                       {{ ($drafts[$sound->id]['is_premium'] ?? false) ? 'bg-brand text-white' : 'bg-paper dark:bg-paper/10' }}">
                            <x-icon name="crown" :style="($drafts[$sound->id]['is_premium'] ?? false) ? 'solid' : 'regular'" class="text-xs" />
                            Premium
                        </button>

                        <div class="ml-auto flex flex-wrap gap-3">
                            @if ($sound->status === 'published')
                                <a href="{{ route('sounds.show', $sound) }}" target="_blank"
                                   class="flex items-center gap-2 rounded-full bg-paper px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-paper/10">
                                    <x-icon name="arrow-up-right-from-square" style="solid" class="text-xs" /> View
                                </a>
                                <button wire:click="unpublish({{ $sound->id }})"
                                        class="rounded-full bg-paper px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-paper/10">
                                    Take offline
                                </button>
                            @elseif ($sound->status === 'rejected')
                                <button wire:click="restore({{ $sound->id }})"
                                        class="rounded-full bg-paper px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-paper/10">
                                    Back to review
                                </button>
                            @else
                                <button wire:click="startReject({{ $sound->id }})"
                                        class="rounded-full bg-paper px-5 py-2.5 text-[0.85rem] shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-paper/10">
                                    Reject
                                </button>
                            @endif

                            @if ($sound->status !== 'published')
                                <button wire:click="approve({{ $sound->id }})" wire:loading.attr="disabled"
                                        class="flex items-center gap-2 rounded-full bg-brand px-6 py-2.5 text-[0.85rem] font-medium text-white shadow-brand transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:shadow-brand-lg">
                                    <x-icon name="check" style="solid" class="text-xs" />
                                    Publish
                                </button>
                            @endif
                        </div>
                    </div>

                    {{-- Rejection reason --}}
                    @if ($rejecting === $sound->id)
                        <div class="mt-5 rounded-control bg-paper p-4 dark:bg-paper/10">
                            <span class="micro mb-2 block">Why are you rejecting this?</span>
                            <textarea wire:model="reason" rows="2" autofocus
                                      placeholder="Background noise throughout, and the file clips at 0:03."
                                      class="w-full rounded-control border-0 bg-surface px-4 py-3 text-[0.92rem] shadow-soft-sm placeholder:text-ink/30 focus:outline-none focus:ring-2 focus:ring-brand/30 dark:bg-surface-dark dark:placeholder:text-paper/30"></textarea>
                            @error('reason') <span class="mt-1 block text-sm text-brand">{{ $message }}</span> @enderror

                            <div class="mt-3 flex gap-3">
                                <button wire:click="reject({{ $sound->id }})"
                                        class="rounded-full bg-ink px-5 py-2.5 text-[0.85rem] text-paper shadow-soft-sm transition hover:-translate-y-0.5 dark:bg-brand">
                                    Confirm rejection
                                </button>
                                <button wire:click="cancelReject" class="px-3 text-sm text-ink/50 underline dark:text-paper/50">Cancel</button>
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="rounded-card bg-surface py-20 text-center shadow-soft-md dark:bg-surface-dark">
                    <p class="text-lg">Nothing here</p>
                    <p class="micro mt-2">
                        {{ $tab === 'pending' ? 'The review queue is empty' : 'No sounds with this status' }}
                    </p>
                </div>
            @endforelse
        </div>
    </div>
</div>
