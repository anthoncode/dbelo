<?php

use App\Models\ActivityLog;
use App\Models\Sound;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Contributors')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')] public string $search = '';
    #[Url(except: '')] public string $activity = '';
    #[Url(except: '')] public string $flag = '';
    #[Url(except: 'uploads')] public string $sort = 'uploads';

    /** Which contributor's recent uploads are expanded. */
    public ?int $open = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updated(string $property): void
    {
        if ($property !== 'page') {
            $this->resetPage();
        }
    }

    #[Computed]
    public function stats(): array
    {
        $published = Sound::where('status', Sound::STATUS_PUBLISHED)->count();
        $rejected = Sound::where('status', Sound::STATUS_REJECTED)->count();
        $reviewed = $published + $rejected;

        return [
            ['label' => 'Contributors', 'value' => User::real()->where('role', User::ROLE_COLLABORATOR)->count(), 'icon' => 'user-music', 'tone' => 'brand'],
            ['label' => 'Active this month', 'value' => User::real()->whereHas('sounds', fn ($q) => $q->where('created_at', '>=', now()->subDays(30)))->count(), 'icon' => 'bolt', 'tone' => 'success'],
            ['label' => 'Waiting review', 'value' => Sound::where('status', Sound::STATUS_PENDING)->count(), 'icon' => 'clipboard-check', 'tone' => 'warning'],
            // The number that tells you whether contributions are worth the
            // moderation time they cost.
            ['label' => 'Approval rate', 'value' => $reviewed ? round($published / $reviewed * 100).'%' : '—', 'icon' => 'circle-check', 'tone' => $reviewed && ($published / $reviewed) < 0.6 ? 'warning' : 'info'],
            ['label' => 'Third-party audio', 'value' => Sound::where('source', '!=', 'original')->count(), 'icon' => 'file-contract', 'tone' => 'neutral'],
        ];
    }

    #[Computed]
    public function contributors()
    {
        return User::query()
            ->real()
            // A contributor is anyone who holds the role OR has ever uploaded.
            // Someone demoted last month still has sounds in the catalogue,
            // and those sounds are still your responsibility.
            ->where(fn ($q) => $q->where('role', User::ROLE_COLLABORATOR)->orHas('sounds'))
            ->withCount([
                'sounds as published_count' => fn ($q) => $q->where('status', Sound::STATUS_PUBLISHED),
                'sounds as pending_count' => fn ($q) => $q->where('status', Sound::STATUS_PENDING),
                'sounds as rejected_count' => fn ($q) => $q->where('status', Sound::STATUS_REJECTED),
                'sounds as claimed_count' => fn ($q) => $q->where('status', Sound::STATUS_CLAIMED),
                'sounds as third_party_count' => fn ($q) => $q->where('source', '!=', 'original'),
            ])
            ->withSum('sounds as earned_downloads', 'downloads_count')
            ->withMax('sounds as last_upload_at', 'created_at')
            ->when($this->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")
                ->orWhere('username', 'like', "%{$s}%")))
            ->when($this->activity === 'active', fn ($q) => $q->whereHas('sounds', fn ($s) => $s->where('created_at', '>=', now()->subDays(30))))
            ->when($this->activity === 'dormant', fn ($q) => $q->whereDoesntHave('sounds', fn ($s) => $s->where('created_at', '>=', now()->subDays(90))))
            ->when($this->activity === 'never', fn ($q) => $q->doesntHave('sounds'))
            // The three signals worth filtering on, in order of how much
            // trouble they represent.
            ->when($this->flag === 'claimed', fn ($q) => $q->whereHas('sounds', fn ($s) => $s->where('status', Sound::STATUS_CLAIMED)))
            ->when($this->flag === 'third_party', fn ($q) => $q->whereHas('sounds', fn ($s) => $s->where('source', '!=', 'original')))
            ->when($this->flag === 'pending', fn ($q) => $q->whereHas('sounds', fn ($s) => $s->where('status', Sound::STATUS_PENDING)))
            ->tap(fn ($q) => match ($this->sort) {
                'downloads' => $q->orderByDesc('earned_downloads'),
                'recent' => $q->orderByDesc('last_upload_at'),
                'name' => $q->orderBy('name'),
                default => $q->orderByDesc('published_count'),
            })
            ->paginate(10);
    }

    #[Computed]
    public function recentUploads()
    {
        if (! $this->open) {
            return collect();
        }

        return Sound::withTrashed()
            ->where('user_id', $this->open)
            ->latest('created_at')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function activeFilters(): int
    {
        return collect([$this->activity, $this->flag])->filter()->count();
    }

    public function toggle(int $id): void
    {
        $this->open = $this->open === $id ? null : $id;
        unset($this->recentUploads);
    }

    /**
     * Contributor Agreement §8: uploading material you have no rights to,
     * mass-uploading duplicates or gaming the metadata are grounds for
     * removing contributor status. This is that button.
     */
    public function demote(int $id): void
    {
        $user = User::findOrFail($id);

        if ($user->id === auth()->id() || $user->isAdmin()) {
            session()->flash('error', 'Admins are not demoted from here.');

            return;
        }

        $user->forceFill(['role' => User::ROLE_USER])->save();

        ActivityLog::record('user.role.changed', $user,
            "{$user->name}: contributor → user",
            ['from' => User::ROLE_COLLABORATOR, 'to' => User::ROLE_USER]);

        unset($this->contributors, $this->stats);

        session()->flash('ok', "{$user->name} can no longer upload. Their published sounds stay in the catalogue.");
    }

    public function promote(int $id): void
    {
        $user = User::findOrFail($id);

        $user->forceFill(['role' => User::ROLE_COLLABORATOR])->save();

        ActivityLog::record('user.role.changed', $user,
            "{$user->name}: user → contributor",
            ['from' => User::ROLE_USER, 'to' => User::ROLE_COLLABORATOR]);

        unset($this->contributors, $this->stats);

        session()->flash('ok', "{$user->name} can upload again.");
    }
}; ?>

<div>
    @if (session('ok'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-success/30 bg-success/10 px-4 py-3">
            <x-icon name="circle-check" style="solid" class="text-success" />
            <span class="text-[0.88rem]">{{ session('ok') }}</span>
        </div>
    @endif

    @if (session('error'))
        <div class="mb-5 flex items-center gap-3 rounded-xl border border-danger/30 bg-danger/10 px-4 py-3">
            <x-icon name="triangle-exclamation" style="solid" class="text-danger" />
            <span class="text-[0.88rem]">{{ session('error') }}</span>
        </div>
    @endif

    {{-- ══════ STATS ══════ --}}
    <div class="mb-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ($this->stats as $stat)
            <div class="rounded-2xl border border-hairline bg-panel p-5">
                <div class="flex items-start justify-between">
                    <span class="text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">{{ $stat['label'] }}</span>
                    <span @class([
                        'grid size-9 place-items-center rounded-full',
                        'bg-brand/15 text-brand' => $stat['tone'] === 'brand',
                        'bg-success/15 text-success' => $stat['tone'] === 'success',
                        'bg-warning/15 text-warning' => $stat['tone'] === 'warning' && $stat['value'] > 0,
                        'bg-info/15 text-info' => $stat['tone'] === 'info',
                        'bg-raised text-paper/40' => $stat['tone'] === 'neutral' || ($stat['tone'] === 'warning' && ! $stat['value']),
                    ])>
                        <x-icon :name="$stat['icon']" style="solid" class="text-[13px]" />
                    </span>
                </div>
                <div class="mt-3 text-[1.9rem] font-semibold leading-none tracking-[-0.03em]">{{ $stat['value'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="rounded-2xl border border-hairline bg-panel">

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
            <h2 class="text-[0.95rem] font-medium">
                Contributors <span class="ml-1.5 text-paper/35">{{ $this->contributors->total() }}</span>
            </h2>

            <div class="flex flex-wrap items-center gap-2">
                <div class="flex items-center gap-2.5 rounded-lg bg-raised px-3 py-2">
                    <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name, email…"
                           class="w-40 border-0 bg-transparent p-0 text-[0.83rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-0" />
                </div>

                <select wire:model.live="activity"
                        class="rounded-lg border-0 bg-raised px-3 py-2 text-[0.82rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                    <option value="">Any activity</option>
                    <option value="active">Uploaded in 30 days</option>
                    <option value="dormant">Nothing in 90 days</option>
                    <option value="never">Never uploaded</option>
                </select>

                <select wire:model.live="flag"
                        class="rounded-lg border-0 bg-raised px-3 py-2 text-[0.82rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                    <option value="">No flag</option>
                    <option value="claimed">Has a claimed sound</option>
                    <option value="third_party">Declared third-party audio</option>
                    <option value="pending">Waiting review</option>
                </select>

                <select wire:model.live="sort"
                        class="rounded-lg border-0 bg-raised px-3 py-2 text-[0.82rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                    <option value="uploads">Most published</option>
                    <option value="downloads">Most downloaded</option>
                    <option value="recent">Recently active</option>
                    <option value="name">Name A–Z</option>
                </select>
            </div>
        </div>

        <table class="w-full text-left">
            <thead>
                <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                    <th class="w-12 px-5 py-2.5 font-medium">#</th>
                    <th class="px-3 py-2.5 font-medium">Contributor</th>
                    <th class="w-40 px-3 py-2.5 font-medium">Uploads</th>
                    <th class="w-32 px-3 py-2.5 font-medium">Approval</th>
                    <th class="w-24 px-3 py-2.5 font-medium">Downloads</th>
                    <th class="w-28 px-3 py-2.5 font-medium">Last upload</th>
                    <th class="w-[120px] px-5 py-2.5 text-right font-medium">Actions</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-hairline">
                @php $row = ($this->contributors->currentPage() - 1) * $this->contributors->perPage(); @endphp

                @forelse ($this->contributors as $user)
                    @php
                        $row++;
                        $reviewed = $user->published_count + $user->rejected_count;
                        $rate = $reviewed ? (int) round($user->published_count / $reviewed * 100) : null;
                        $isOpen = $this->open === $user->id;
                        $lastUpload = $user->last_upload_at ? Carbon::parse($user->last_upload_at) : null;
                    @endphp

                    <tr wire:key="c-{{ $user->id }}" class="transition hover:bg-paper/[0.03]">
                        <td class="px-5 py-3 text-[0.8rem] tabular-nums text-paper/25">{{ $row }}</td>

                        <td class="px-3 py-3">
                            <div class="flex items-center gap-3">
                                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand text-[0.72rem] font-semibold text-white">
                                    {{ $user->initials() }}
                                </span>

                                <div class="min-w-0">
                                    <div class="flex items-center gap-1.5">
                                        <a href="{{ route('admin.users.show', $user) }}" wire:navigate
                                           class="truncate text-[0.89rem] transition hover:text-brand">{{ $user->name }}</a>

                                        @if ($user->isAdmin())
                                            <span class="shrink-0 rounded-full bg-raised px-2 py-0.5 text-[0.65rem] text-paper/45">admin</span>
                                        @elseif ($user->role !== 'collaborator')
                                            {{-- Demoted but their sounds are still live. --}}
                                            <span class="shrink-0 rounded-full bg-raised px-2 py-0.5 text-[0.65rem] text-paper/45">former</span>
                                        @endif

                                        @if ($user->claimed_count)
                                            <span class="group/tip relative shrink-0">
                                                <x-icon name="shield-exclamation" style="solid" class="text-[11px] text-danger" />
                                                <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">
                                                    {{ $user->claimed_count }} sound(s) under claim
                                                </span>
                                            </span>
                                        @endif

                                        @if ($user->third_party_count)
                                            <span class="group/tip relative shrink-0">
                                                <x-icon name="file-contract" style="solid" class="text-[11px] text-warning" />
                                                <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">
                                                    {{ $user->third_party_count }} declared as third-party
                                                </span>
                                            </span>
                                        @endif
                                    </div>
                                    <div class="truncate text-[0.72rem] text-paper/25">{{ $user->email }}</div>
                                </div>
                            </div>
                        </td>

                        {{-- Three states in one cell: what is live, what is waiting
                             for you, and what you already said no to. --}}
                        <td class="px-3 py-3">
                            <div class="flex items-center gap-2.5 text-[0.8rem] tabular-nums">
                                <span class="group/tip relative text-success">
                                    {{ $user->published_count }}
                                    <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">Published</span>
                                </span>
                                <span class="text-paper/15">/</span>
                                <span class="group/tip relative {{ $user->pending_count ? 'text-warning' : 'text-paper/20' }}">
                                    {{ $user->pending_count }}
                                    <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">Waiting review</span>
                                </span>
                                <span class="text-paper/15">/</span>
                                <span class="group/tip relative {{ $user->rejected_count ? 'text-danger' : 'text-paper/20' }}">
                                    {{ $user->rejected_count }}
                                    <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">Rejected</span>
                                </span>
                            </div>
                        </td>

                        <td class="px-3 py-3">
                            @if ($rate === null)
                                <span class="text-[0.75rem] text-paper/20">nothing reviewed</span>
                            @else
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 w-14 overflow-hidden rounded-full bg-raised">
                                        <div @class([
                                            'h-full rounded-full',
                                            'bg-success' => $rate >= 70,
                                            'bg-warning' => $rate >= 40 && $rate < 70,
                                            'bg-danger' => $rate < 40,
                                        ]) style="width: {{ $rate }}%"></div>
                                    </div>
                                    <span class="text-[0.78rem] tabular-nums text-paper/55">{{ $rate }}%</span>
                                </div>
                            @endif
                        </td>

                        <td class="px-3 py-3 text-[0.8rem] tabular-nums text-paper/55">
                            {{ number_format($user->earned_downloads ?? 0) }}
                        </td>

                        <td class="px-3 py-3 text-[0.78rem] text-paper/40">
                            {{ $lastUpload ? $lastUpload->diffForHumans(short: true) : '—' }}
                        </td>

                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <x-admin.icon-button :icon="$isOpen ? 'chevron-up' : 'list-music'"
                                                     label="Last uploads" wire:click="toggle({{ $user->id }})" />

                                <a href="{{ route('admin.users.show', $user) }}" wire:navigate>
                                    <x-admin.icon-button icon="eye" label="View profile" />
                                </a>

                                @unless ($user->isAdmin() || $user->id === auth()->id())
                                    @if ($user->role === 'collaborator')
                                        <x-admin.icon-button icon="user-slash" label="Remove contributor status"
                                                             class="hover:!bg-danger/15 hover:!text-danger"
                                                             wire:click="demote({{ $user->id }})"
                                                             wire:confirm="Remove upload rights from {{ $user->name }}? Their published sounds stay in the catalogue." />
                                    @else
                                        <x-admin.icon-button icon="user-plus" label="Make contributor again"
                                                             class="hover:!bg-success/15 hover:!text-success"
                                                             wire:click="promote({{ $user->id }})" />
                                    @endif
                                @endunless
                            </div>
                        </td>
                    </tr>

                    @if ($isOpen)
                        <tr wire:key="open-{{ $user->id }}" class="bg-raised">
                            <td colspan="7" class="px-5 py-4">
                                <span class="mb-3 block text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">
                                    Last uploads by {{ $user->name }}
                                </span>

                                @forelse ($this->recentUploads as $sound)
                                    <div class="flex flex-wrap items-center gap-3 border-b border-hairline/60 py-2.5 last:border-0">
                                        <span @class([
                                            'shrink-0 rounded-full px-2.5 py-1 text-[0.7rem] capitalize',
                                            'bg-success/15 text-success' => $sound->status === 'published',
                                            'bg-warning/15 text-warning' => $sound->status === 'pending',
                                            'bg-danger/15 text-danger' => $sound->status === 'rejected',
                                            'bg-info/15 text-info' => $sound->status === 'claimed',
                                            'bg-panel text-paper/35' => in_array($sound->status, ['draft'], true),
                                        ])>{{ $sound->status }}</span>

                                        <a href="{{ route('sounds.show', $sound) }}" target="_blank"
                                           class="min-w-0 flex-1 truncate text-[0.86rem] transition hover:text-brand">{{ $sound->title }}</a>

                                        @if ($sound->isThirdParty())
                                            <span class="shrink-0 text-[0.72rem] text-warning">
                                                {{ $sound->original_author ? 'via '.$sound->original_author : 'third-party, no author given' }}
                                            </span>
                                        @endif

                                        <span class="shrink-0 text-[0.72rem] text-paper/25">{{ $sound->created_at->diffForHumans(short: true) }}</span>
                                    </div>

                                    @if ($sound->rejection_reason)
                                        <p class="pb-2.5 text-[0.75rem] text-paper/35">Rejected: {{ $sound->rejection_reason }}</p>
                                    @endif
                                @empty
                                    <p class="py-4 text-center text-[0.85rem] text-paper/35">Nothing uploaded yet.</p>
                                @endforelse
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="7" class="px-5 py-16 text-center">
                            <x-icon name="user-music" style="regular" class="text-[24px] text-paper/20" />
                            <p class="mt-3 text-[0.9rem] text-paper/45">Nobody matches these filters</p>
                            <p class="mt-1 text-[0.8rem] text-paper/25">
                                Contributors are promoted by hand from
                                <a href="{{ route('admin.users') }}" wire:navigate class="underline hover:text-paper">All users</a>.
                            </p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($this->contributors->hasPages())
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-hairline px-5 py-3.5">
                <span class="text-[0.78rem] text-paper/35">
                    {{ $this->contributors->firstItem() }}–{{ $this->contributors->lastItem() }} of {{ $this->contributors->total() }}
                </span>

                <div class="flex items-center gap-1.5">
                    <x-admin.icon-button icon="chevron-left" variant="muted" label="Previous"
                                         wire:click="previousPage" @disabled($this->contributors->onFirstPage()) />

                    @foreach ($this->contributors->getUrlRange(max(1, $this->contributors->currentPage() - 2), min($this->contributors->lastPage(), $this->contributors->currentPage() + 2)) as $page => $url)
                        <button wire:click="gotoPage({{ $page }})" wire:key="cpg-{{ $page }}"
                                @class([
                                    'grid size-8 place-items-center rounded-full text-[0.78rem] tabular-nums transition duration-200 ease-dbelo',
                                    'bg-brand text-white' => $page === $this->contributors->currentPage(),
                                    'text-paper/40 hover:bg-paper/[0.10] hover:text-paper' => $page !== $this->contributors->currentPage(),
                                ])>{{ $page }}</button>
                    @endforeach

                    <x-admin.icon-button icon="chevron-right" variant="muted" label="Next"
                                         wire:click="nextPage" @disabled(! $this->contributors->hasMorePages()) />
                </div>
            </div>
        @endif
    </div>
</div>
