<?php

use App\Actions\AnonymiseUser;
use App\Models\ActivityLog;
use App\Models\Plan;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Users')] class extends Component {
    use WithPagination;

    // ── Filters ──
    #[Url(as: 'q', except: '')] public string $search = '';
    #[Url(except: '')] public string $role = '';
    #[Url(except: '')] public string $status = '';
    #[Url(except: '')] public string $verified = '';
    #[Url(except: '')] public string $plan = '';
    #[Url(except: '')] public string $activity = '';
    #[Url(except: 'newest')] public string $sort = 'newest';

    public bool $showFilters = false;

    // ── Suspension ──
    public ?int $suspending = null;
    public string $reason = '';

    // ── Deletion (anonymisation) ──
    public ?int $deleting = null;
    public string $confirmation = '';

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
        $total = User::real()->count();
        $verified = User::real()
            ->where(fn ($q) => $q->whereNotNull('email_verified_at')->orWhereNotNull('oauth_provider'))
            ->count();

        return [
            ['label' => 'Total', 'value' => $total, 'icon' => 'users', 'tone' => 'neutral'],
            ['label' => 'New this week', 'value' => User::real()->where('created_at', '>=', now()->subWeek())->count(), 'icon' => 'user-plus', 'tone' => 'success'],
            ['label' => 'Verified', 'value' => $total ? round($verified / $total * 100).'%' : '—', 'icon' => 'circle-check', 'tone' => $total && ($verified / $total) < 0.6 ? 'warning' : 'info'],
            ['label' => 'Subscribers', 'value' => User::real()->whereHas('subscriptions', fn ($q) => $q->active())->count(), 'icon' => 'crown', 'tone' => 'brand'],
            ['label' => 'Suspended', 'value' => User::real()->suspended()->count(), 'icon' => 'ban', 'tone' => 'danger'],
        ];
    }

    #[Computed]
    public function plans()
    {
        return Plan::orderBy('sort_order')->get();
    }

    #[Computed]
    public function users()
    {
        return User::query()
            ->withCount(['sounds', 'downloads'])
            ->with(['subscriptions' => fn ($q) => $q->active()->with('plan')])
            // Deleted accounts are still rows, but they are nobody: they only
            // appear when you go looking for them.
            ->when($this->status === 'deleted',
                fn ($q) => $q->whereNotNull('anonymised_at'),
                fn ($q) => $q->real()->when($this->status, fn ($w, $s) => $w->where('status', $s)))
            ->when($this->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")
                ->orWhere('username', 'like', "%{$s}%")))
            ->when($this->role, fn ($q, $r) => $q->where('role', $r))
            // Google accounts arrive already verified; email accounts have to
            // click a link. Telling them apart is the difference between an
            // admin who chases the right people and one who chases everyone.
            ->when($this->verified === 'email', fn ($q) => $q->whereNotNull('email_verified_at')->whereNull('oauth_provider'))
            ->when($this->verified === 'google', fn ($q) => $q->whereNotNull('oauth_provider'))
            ->when($this->verified === 'no', fn ($q) => $q->whereNull('email_verified_at')->whereNull('oauth_provider'))
            ->when($this->plan === 'free', fn ($q) => $q->whereDoesntHave('subscriptions', fn ($s) => $s->active()))
            ->when($this->plan && $this->plan !== 'free', fn ($q) => $q->whereHas('subscriptions', fn ($s) => $s->active()->where('plan_id', $this->plan)))
            // "Dormant" is the filter that actually finds something useful:
            // accounts that registered and never came back.
            ->when($this->activity === 'active', fn ($q) => $q->where('last_seen_at', '>=', now()->subDays(30)))
            ->when($this->activity === 'dormant', fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('last_seen_at')->orWhere('last_seen_at', '<', now()->subDays(30))))
            ->when($this->activity === 'downloaders', fn ($q) => $q->has('downloads'))
            ->when($this->activity === 'contributors', fn ($q) => $q->has('sounds'))
            ->tap(fn ($q) => match ($this->sort) {
                'oldest' => $q->oldest(),
                'name' => $q->orderBy('name'),
                'downloads' => $q->orderByDesc('downloads_count'),
                'active' => $q->orderByDesc('last_seen_at'),
                default => $q->latest(),
            })
            ->paginate(10);
    }

    #[Computed]
    public function activeFilters(): int
    {
        return collect([$this->role, $this->status, $this->verified, $this->plan, $this->activity])
            ->filter()->count();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'role', 'status', 'verified', 'plan', 'activity', 'sort']);
        $this->resetPage();
    }

    public function setRole(int $userId, string $role): void
    {
        abort_unless(in_array($role, [User::ROLE_USER, User::ROLE_COLLABORATOR, User::ROLE_ADMIN], true), 422);

        $user = User::findOrFail($userId);

        if ($user->id === auth()->id()) {
            session()->flash('error', 'You cannot change your own role.');

            return;
        }

        $previous = $user->role;
        $user->forceFill(['role' => $role])->save();

        ActivityLog::record('user.role.changed', $user,
            "{$user->name}: {$previous} → {$role}", ['from' => $previous, 'to' => $role]);

        $this->refresh();
        session()->flash('ok', "{$user->name} is now a {$role}.");
    }

    public function startSuspend(int $id): void
    {
        $this->cancelDelete();
        $this->suspending = $id;
        $this->reason = '';
    }

    public function cancelSuspend(): void
    {
        $this->reset(['suspending', 'reason']);
        $this->resetErrorBag();
    }

    public function suspend(int $id): void
    {
        $this->validate(['reason' => ['required', 'string', 'min:5', 'max:200']], [
            'reason.required' => 'Say why — the user sees this message when they try to sign in.',
        ]);

        $user = User::findOrFail($id);

        if ($user->id === auth()->id() || $user->isAdmin()) {
            session()->flash('error', 'Admins cannot be suspended from here.');

            return;
        }

        $user->forceFill([
            'status' => User::STATUS_SUSPENDED,
            'suspended_at' => now(),
            'suspension_reason' => $this->reason,
        ])->save();

        ActivityLog::record('user.suspended', $user, "{$user->name} suspended", ['reason' => $this->reason]);

        $this->cancelSuspend();
        $this->refresh();

        session()->flash('ok', "{$user->name} suspended.");
    }

    public function restore(int $id): void
    {
        $user = User::findOrFail($id);

        if ($user->isAnonymised()) {
            session()->flash('error', 'A deleted account cannot be restored.');

            return;
        }

        $user->forceFill([
            'status' => User::STATUS_ACTIVE,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        ActivityLog::record('user.restored', $user, "{$user->name} restored");

        $this->refresh();
        session()->flash('ok', "{$user->name} restored.");
    }

    /** Classic support request: "the verification email never arrived". */
    public function verifyManually(int $id): void
    {
        $user = User::findOrFail($id);

        if ($user->isVerified()) {
            return;
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        ActivityLog::record('user.verified.manually', $user, "{$user->name} verified by hand");

        $this->refresh();
        session()->flash('ok', "{$user->name} marked as verified.");
    }

    // ── Deletion ──────────────────────────────────────────────────────
    // "Delete" here means anonymise. See App\Actions\AnonymiseUser for why
    // the row itself has to survive.

    public function startDelete(int $id): void
    {
        $this->cancelSuspend();
        $this->deleting = $id;
        $this->confirmation = '';
    }

    public function cancelDelete(): void
    {
        $this->reset(['deleting', 'confirmation']);
        $this->resetErrorBag();
    }

    public function anonymise(int $id): void
    {
        // Typing the word is the whole safeguard: this cannot be undone, so
        // it must not be reachable by a mis-click.
        $this->validate(['confirmation' => ['required', 'in:DELETE']], [
            'confirmation.in' => 'Type DELETE in capitals to confirm.',
            'confirmation.required' => 'Type DELETE in capitals to confirm.',
        ]);

        $user = User::findOrFail($id);
        $name = $user->name;

        try {
            app(AnonymiseUser::class)($user);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->cancelDelete();
        $this->refresh();

        session()->flash('ok', "{$name} was deleted. Their sounds and download history stay in place.");
    }

    protected function refresh(): void
    {
        unset($this->users, $this->stats);
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
            <div class="group rounded-2xl border border-hairline bg-panel p-5 transition duration-300 ease-dbelo hover:border-paper/15">
                <div class="flex items-start justify-between">
                    <span class="text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">{{ $stat['label'] }}</span>
                    <span @class([
                        'grid size-9 place-items-center rounded-full transition duration-200 ease-dbelo',
                        'bg-brand/15 text-brand' => $stat['tone'] === 'brand',
                        'bg-success/15 text-success' => $stat['tone'] === 'success',
                        'bg-warning/15 text-warning' => $stat['tone'] === 'warning',
                        'bg-info/15 text-info' => $stat['tone'] === 'info',
                        'bg-danger/15 text-danger' => $stat['tone'] === 'danger' && $stat['value'] > 0,
                        'bg-raised text-paper/40' => $stat['tone'] === 'neutral' || ($stat['tone'] === 'danger' && ! $stat['value']),
                    ])>
                        <x-icon :name="$stat['icon']" style="solid" class="text-[13px]" />
                    </span>
                </div>
                <div class="mt-3 text-[1.9rem] font-semibold leading-none tracking-[-0.03em]">{{ $stat['value'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- ══════ TABLE ══════ --}}
    <div class="rounded-2xl border border-hairline bg-panel">

        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
            <h2 class="text-[0.95rem] font-medium">
                All users <span class="ml-1.5 text-paper/35">{{ $this->users->total() }}</span>
            </h2>

            <div class="flex flex-wrap items-center gap-2">
                <div class="flex items-center gap-2.5 rounded-lg bg-raised px-3 py-2">
                    <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name, email, username…"
                           class="w-48 border-0 bg-transparent p-0 text-[0.83rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-0" />
                </div>

                <button wire:click="$toggle('showFilters')"
                        @class([
                            'flex items-center gap-2 rounded-lg px-3.5 py-2 text-[0.82rem] transition duration-200 ease-dbelo',
                            'bg-action text-white' => $showFilters || $this->activeFilters,
                            'bg-raised text-paper/55 hover:text-paper' => ! $showFilters && ! $this->activeFilters,
                        ])>
                    <x-icon name="sliders" style="solid" class="text-[11px]" />
                    Filters
                    @if ($this->activeFilters)
                        <span class="grid size-[18px] place-items-center rounded-full bg-white/25 text-[0.66rem] font-bold">{{ $this->activeFilters }}</span>
                    @endif
                </button>

                <select wire:model.live="sort"
                        class="rounded-lg border-0 bg-raised px-3 py-2 text-[0.82rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                    <option value="newest">Newest</option>
                    <option value="oldest">Oldest</option>
                    <option value="name">Name A–Z</option>
                    <option value="downloads">Most downloads</option>
                    <option value="active">Recently active</option>
                </select>
            </div>
        </div>

        {{-- Advanced filters --}}
        @if ($showFilters)
            <div class="grid gap-4 border-b border-hairline bg-canvas/40 px-5 py-4 sm:grid-cols-2 xl:grid-cols-5">
                @foreach ([
                    ['role', 'Role', ['' => 'Any role', 'user' => 'Users', 'collaborator' => 'Contributors', 'admin' => 'Admins']],
                    ['status', 'Status', ['' => 'Any status', 'active' => 'Active', 'suspended' => 'Suspended', 'deleted' => 'Deleted']],
                    ['verified', 'Verified', ['' => 'Any', 'email' => 'By email', 'google' => 'Google account', 'no' => 'Not verified']],
                    ['activity', 'Activity', ['' => 'Any activity', 'active' => 'Seen in 30 days', 'dormant' => 'Dormant', 'downloaders' => 'Has downloads', 'contributors' => 'Has uploads']],
                ] as [$model, $label, $options])
                    <label class="block">
                        <span class="mb-2 block text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">{{ $label }}</span>
                        <select wire:model.live="{{ $model }}"
                                class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.85rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                            @foreach ($options as $value => $text)
                                <option value="{{ $value }}">{{ $text }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach

                <label class="block">
                    <span class="mb-2 block text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">Plan</span>
                    <select wire:model.live="plan"
                            class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.85rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                        <option value="">Any plan</option>
                        <option value="free">No subscription</option>
                        @foreach ($this->plans as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                </label>

                @if ($this->activeFilters || $search)
                    <div class="sm:col-span-2 xl:col-span-5">
                        <button wire:click="clearFilters" class="text-[0.82rem] text-paper/45 underline transition hover:text-paper">
                            Clear all filters
                        </button>
                    </div>
                @endif
            </div>
        @endif

        <table class="w-full text-left">
            <thead>
                <tr class="border-b border-hairline text-[0.68rem] uppercase tracking-[0.13em] text-paper/30">
                    <th class="w-12 px-5 py-2.5 font-medium">#</th>
                    <th class="px-3 py-2.5 font-medium">User</th>
                    <th class="w-28 px-3 py-2.5 font-medium">Registered</th>
                    <th class="w-28 px-3 py-2.5 font-medium">Verified</th>
                    <th class="w-28 px-3 py-2.5 font-medium">Status</th>
                    <th class="w-24 px-3 py-2.5 font-medium">Plan</th>
                    <th class="w-36 px-3 py-2.5 font-medium">Role</th>
                    <th class="w-[170px] px-5 py-2.5 text-right font-medium">Actions</th>
                </tr>
            </thead>

            <tbody class="divide-y divide-hairline">
                @php $row = ($this->users->currentPage() - 1) * $this->users->perPage(); @endphp

                @forelse ($this->users as $user)
                    @php
                        $row++;
                        $subscription = $user->subscriptions->first();
                        $isSelf = $user->id === auth()->id();
                        $gone = $user->isAnonymised();
                    @endphp

                    <tr wire:key="u-{{ $user->id }}" @class([
                        'transition hover:bg-paper/[0.03]',
                        'opacity-45' => $gone,
                    ])>
                        <td class="px-5 py-3 text-[0.8rem] tabular-nums text-paper/25">{{ $row }}</td>

                        <td class="px-3 py-3">
                            <div class="flex items-center gap-3">
                                <span @class([
                                    'grid size-9 shrink-0 place-items-center rounded-full text-[0.72rem] font-semibold',
                                    'bg-raised text-paper/30' => $gone,
                                    'bg-danger/15 text-danger' => ! $gone && $user->isSuspended(),
                                    'bg-brand text-white' => ! $gone && ! $user->isSuspended(),
                                ])>
                                    @if ($gone)
                                        <x-icon name="user-slash" style="solid" class="text-[12px]" />
                                    @else
                                        {{ $user->initials() }}
                                    @endif
                                </span>

                                <div class="min-w-0">
                                    <div class="flex items-center gap-1.5">
                                        @if ($gone)
                                            <span class="truncate text-[0.89rem] italic text-paper/45">Deleted user</span>
                                        @else
                                            <a href="{{ route('admin.users.show', $user) }}" wire:navigate
                                               class="truncate text-[0.89rem] transition hover:text-brand">{{ $user->name }}</a>
                                        @endif
                                        @if ($isSelf)<span class="shrink-0 text-[0.68rem] text-paper/30">you</span>@endif
                                    </div>
                                    <div class="truncate text-[0.72rem] text-paper/25">
                                        {{ $gone ? '—' : $user->email }}
                                        · {{ $user->downloads_count }} dl
                                        @if ($user->sounds_count) · {{ $user->sounds_count }} up @endif
                                    </div>
                                </div>
                            </div>
                        </td>

                        <td class="px-3 py-3">
                            <div class="text-[0.8rem] text-paper/60">{{ $user->created_at->format('M j, Y') }}</div>
                            <div class="text-[0.7rem] text-paper/25">
                                {{ $user->last_seen_at ? 'seen '.$user->last_seen_at->diffForHumans(short: true) : 'never seen' }}
                            </div>
                        </td>

                        {{-- Verified: how the account proved it is real, not just whether. --}}
                        <td class="px-3 py-3">
                            @php $source = $user->verificationSource(); @endphp

                            @if ($gone)
                                <span class="text-[0.75rem] text-paper/20">—</span>
                            @elseif ($source === 'google')
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-info/15 px-2.5 py-1 text-[0.72rem] text-info">
                                    <x-icon name="google" style="brands" class="text-[10px]" /> Google
                                </span>
                            @elseif ($source)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-success/15 px-2.5 py-1 text-[0.72rem] text-success">
                                    <x-icon name="envelope-circle-check" style="solid" class="text-[10px]" /> Email
                                </span>
                            @else
                                {{-- The chip is the button: verifying by hand is a one-click
                                     fix for "the email never arrived", and it does not
                                     deserve its own icon in an already busy row. --}}
                                <button wire:click="verifyManually({{ $user->id }})"
                                        wire:confirm="Mark {{ $user->name }} as verified without them clicking the link?"
                                        class="group/tip relative inline-flex items-center gap-1.5 rounded-full bg-warning/15 px-2.5 py-1 text-[0.72rem] text-warning transition duration-200 ease-dbelo hover:bg-warning/25">
                                    <x-icon name="circle-exclamation" style="solid" class="text-[10px]" /> Pending
                                    <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">
                                        Verify by hand
                                    </span>
                                </button>
                            @endif
                        </td>

                        <td class="px-3 py-3">
                            @if ($gone)
                                <span class="rounded-full bg-raised px-2.5 py-1 text-[0.72rem] text-paper/35">Deleted</span>
                            @elseif ($user->isSuspended())
                                <span class="group/tip relative inline-flex rounded-full bg-danger/15 px-2.5 py-1 text-[0.72rem] text-danger">
                                    Suspended
                                    @if ($user->suspension_reason)
                                        <span class="pointer-events-none absolute bottom-full left-0 z-50 mb-2 w-52 rounded-lg bg-paper px-3 py-2 text-[0.72rem] text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">
                                            {{ $user->suspension_reason }}
                                        </span>
                                    @endif
                                </span>
                            @else
                                <span class="rounded-full bg-success/15 px-2.5 py-1 text-[0.72rem] text-success">Active</span>
                            @endif
                        </td>

                        <td class="px-3 py-3">
                            @if ($subscription)
                                <span class="rounded-full bg-brand/15 px-2.5 py-1 text-[0.72rem] text-brand">{{ $subscription->plan->name }}</span>
                            @else
                                <span class="text-[0.75rem] text-paper/25">Free</span>
                            @endif
                        </td>

                        <td class="px-3 py-3">
                            @if ($gone)
                                <span class="text-[0.75rem] text-paper/20">—</span>
                            @else
                                <div class="flex gap-1">
                                    @foreach (['user' => 'U', 'collaborator' => 'C', 'admin' => 'A'] as $key => $letter)
                                        <button wire:click="setRole({{ $user->id }}, '{{ $key }}')" @disabled($isSelf)
                                                class="group/tip relative grid size-7 place-items-center rounded-full text-[0.7rem] font-semibold transition duration-200 ease-dbelo disabled:opacity-30
                                                       {{ $user->role === $key ? 'bg-brand text-white' : 'bg-raised text-paper/40 hover:bg-paper/[0.10] hover:text-paper' }}">
                                            {{ $letter }}
                                            <span class="pointer-events-none absolute bottom-full left-1/2 z-50 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-paper px-2.5 py-1 text-[0.7rem] font-medium capitalize text-ink opacity-0 shadow-xl transition group-hover/tip:opacity-100">{{ $key }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        </td>

                        <td class="px-5 py-3">
                            <div class="flex items-center justify-end gap-1">
                                @if (! $gone)
                                    <a href="{{ route('admin.users.show', $user) }}" wire:navigate>
                                        <x-admin.icon-button icon="eye" label="View profile" />
                                    </a>

                                    <a href="{{ route('admin.users.show', ['user' => $user, 'edit' => 1]) }}" wire:navigate>
                                        <x-admin.icon-button icon="pen-to-square" label="Edit" />
                                    </a>

                                    @unless ($isSelf || $user->isAdmin())
                                        <a href="{{ route('admin.impersonate', $user) }}">
                                            <x-admin.icon-button icon="user-secret" label="View as this user" />
                                        </a>
                                    @endunless

                                    @if ($user->isSuspended())
                                        <x-admin.icon-button icon="rotate-left" label="Restore account"
                                                             class="!text-success hover:!bg-success/15"
                                                             wire:click="restore({{ $user->id }})" />
                                    @elseif (! $isSelf && ! $user->isAdmin())
                                        <x-admin.icon-button icon="ban" label="Suspend"
                                                             class="hover:!bg-warning/15 hover:!text-warning"
                                                             wire:click="startSuspend({{ $user->id }})" />
                                    @endif

                                    @unless ($isSelf || $user->isAdmin())
                                        <x-admin.icon-button icon="trash" label="Delete"
                                                             class="hover:!bg-danger/15 hover:!text-danger"
                                                             wire:click="startDelete({{ $user->id }})" />
                                    @endunless
                                @else
                                    <span class="text-[0.72rem] text-paper/20">{{ $user->anonymised_at->format('M j, Y') }}</span>
                                @endif
                            </div>
                        </td>
                    </tr>

                    @if ($suspending === $user->id)
                        <tr wire:key="susp-{{ $user->id }}" class="bg-raised">
                            <td colspan="8" class="px-5 py-4">
                                <span class="mb-2 block text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">
                                    Why are you suspending {{ $user->name }}?
                                </span>
                                <div class="flex flex-wrap gap-2">
                                    <input type="text" wire:model="reason" autofocus
                                           wire:keydown.enter="suspend({{ $user->id }})"
                                           placeholder="Automated mass downloading from a single IP."
                                           class="min-w-0 flex-1 rounded-lg border-0 bg-panel px-3.5 py-2.5 text-[0.88rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-warning/40" />
                                    <button wire:click="suspend({{ $user->id }})"
                                            class="rounded-lg bg-warning px-5 py-2.5 text-[0.85rem] font-medium text-ink transition hover:brightness-110">
                                        Suspend
                                    </button>
                                    <button wire:click="cancelSuspend"
                                            class="rounded-lg bg-panel px-4 py-2.5 text-[0.85rem] text-paper/55 transition hover:text-paper">
                                        Cancel
                                    </button>
                                </div>
                                @error('reason') <p class="mt-2 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                                <p class="mt-2 text-[0.75rem] text-paper/30">The user sees this message when they try to sign in. Suspension is reversible.</p>
                            </td>
                        </tr>
                    @endif

                    @if ($deleting === $user->id)
                        <tr wire:key="del-{{ $user->id }}" class="bg-raised">
                            <td colspan="8" class="px-5 py-4">
                                <div class="flex items-start gap-3">
                                    <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full bg-danger/15 text-danger">
                                        <x-icon name="triangle-exclamation" style="solid" class="text-[12px]" />
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <p class="text-[0.88rem] font-medium">Delete {{ $user->name }}?</p>

                                        <p class="mt-1.5 text-[0.78rem] leading-relaxed text-paper/45">
                                            Their name, email, avatar, password, passkeys and sessions are erased and
                                            the username <span class="text-paper/70">{{ $user->username ?: '—' }}</span>
                                            is freed. <span class="text-paper/70">This cannot be undone.</span>
                                        </p>

                                        <p class="mt-1.5 text-[0.78rem] leading-relaxed text-paper/45">
                                            What stays:
                                            <span class="text-paper/70">{{ $user->sounds_count }}</span> sound{{ $user->sounds_count === 1 ? '' : 's' }}
                                            in the catalogue, now credited to “Deleted user”, and
                                            <span class="text-paper/70">{{ $user->downloads_count }}</span> download{{ $user->downloads_count === 1 ? '' : 's' }}
                                            with their frozen licences — those are the proof the licences were granted.
                                        </p>

                                        <div class="mt-3 flex flex-wrap gap-2">
                                            <input type="text" wire:model="confirmation" autofocus
                                                   wire:keydown.enter="anonymise({{ $user->id }})"
                                                   placeholder="Type DELETE to confirm"
                                                   class="min-w-0 flex-1 rounded-lg border-0 bg-panel px-3.5 py-2.5 text-[0.88rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-danger/40" />
                                            <button wire:click="anonymise({{ $user->id }})"
                                                    class="rounded-lg bg-danger px-5 py-2.5 text-[0.85rem] font-medium text-white transition hover:brightness-110">
                                                Delete permanently
                                            </button>
                                            <button wire:click="cancelDelete"
                                                    class="rounded-lg bg-panel px-4 py-2.5 text-[0.85rem] text-paper/55 transition hover:text-paper">
                                                Cancel
                                            </button>
                                        </div>

                                        @error('confirmation') <p class="mt-2 text-[0.8rem] text-danger">{{ $message }}</p> @enderror

                                        <p class="mt-2 text-[0.75rem] text-paper/30">
                                            Looking for something reversible? Suspend instead.
                                        </p>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="8" class="px-5 py-16 text-center">
                            <x-icon name="users" style="regular" class="text-[24px] text-paper/20" />
                            <p class="mt-3 text-[0.9rem] text-paper/45">No users match these filters</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if ($this->users->hasPages())
            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-hairline px-5 py-3.5">
                <span class="text-[0.78rem] text-paper/35">
                    {{ $this->users->firstItem() }}–{{ $this->users->lastItem() }} of {{ $this->users->total() }}
                </span>

                <div class="flex items-center gap-1.5">
                    <x-admin.icon-button icon="chevron-left" variant="muted" label="Previous"
                                         wire:click="previousPage" @disabled($this->users->onFirstPage()) />

                    @foreach ($this->users->getUrlRange(max(1, $this->users->currentPage() - 2), min($this->users->lastPage(), $this->users->currentPage() + 2)) as $page => $url)
                        <button wire:click="gotoPage({{ $page }})" wire:key="upg-{{ $page }}"
                                @class([
                                    'grid size-8 place-items-center rounded-full text-[0.78rem] tabular-nums transition duration-200 ease-dbelo',
                                    'bg-brand text-white' => $page === $this->users->currentPage(),
                                    'text-paper/40 hover:bg-paper/[0.10] hover:text-paper' => $page !== $this->users->currentPage(),
                                ])>{{ $page }}</button>
                    @endforeach

                    <x-admin.icon-button icon="chevron-right" variant="muted" label="Next"
                                         wire:click="nextPage" @disabled(! $this->users->hasMorePages()) />
                </div>
            </div>
        @endif
    </div>
</div>
