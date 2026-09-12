<?php

use App\Actions\AnonymiseUser;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('User')] class extends Component {
    public User $user;

    #[Url(except: 'data')] public string $tab = 'data';

    /** Arriving from the pencil in the list puts the cursor in the form. */
    public bool $focusForm = false;

    // ── Editable data ──
    public string $name = '';
    public ?string $username = null;
    public string $email = '';
    public string $role = '';
    public ?string $website = null;
    public ?string $bio = null;

    // ── Destructive flows ──
    public bool $confirmingDelete = false;
    public string $confirmation = '';
    public bool $suspending = false;
    public string $reason = '';

    public function mount(User $user): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $this->user = $user;
        $this->focusForm = request()->boolean('edit');

        $this->fillForm();
    }

    protected function fillForm(): void
    {
        $this->name = $this->user->name;
        $this->username = $this->user->username;
        $this->email = $this->user->email;
        $this->role = $this->user->role;
        $this->website = $this->user->website;
        $this->bio = $this->user->bio;
    }

    protected function isSelf(): bool
    {
        return $this->user->id === auth()->id();
    }

    // ─────────────────────────────────────────────────────────────────
    // Datos editables
    // ─────────────────────────────────────────────────────────────────

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['nullable', 'string', 'min:3', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($this->user->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user->id)],
            'role' => ['required', Rule::in([User::ROLE_USER, User::ROLE_COLLABORATOR, User::ROLE_ADMIN])],
            'website' => ['nullable', 'url', 'max:200'],
            'bio' => ['nullable', 'string', 'max:500'],
        ]);

        if ($this->isSelf() && $data['role'] !== $this->user->role) {
            session()->flash('error', 'You cannot change your own role.');

            return;
        }

        $changed = collect($data)
            ->reject(fn ($value, $key) => $value === $this->user->{$key})
            ->keys();

        if ($changed->isEmpty()) {
            session()->flash('ok', 'Nothing to save — no field changed.');

            return;
        }

        $previousRole = $this->user->role;

        // Changing someone's address means nobody has proved the new one.
        // Leaving the old verification in place would be a quiet lie.
        if ($changed->contains('email') && ! $this->user->oauth_provider) {
            $data['email_verified_at'] = null;
        }

        $this->user->forceFill($data)->save();

        ActivityLog::record('user.updated', $this->user,
            "User #{$this->user->id} edited: ".$changed->join(', '),
            $changed->contains('role')
                ? ['fields' => $changed->all(), 'role_from' => $previousRole, 'role_to' => $this->user->role]
                : ['fields' => $changed->all()]);

        $this->fillForm();
        session()->flash('ok', 'Saved.');
    }

    public function verifyManually(): void
    {
        if ($this->user->isVerified()) {
            return;
        }

        $this->user->forceFill(['email_verified_at' => now()])->save();

        ActivityLog::record('user.verified.manually', $this->user, "User #{$this->user->id} verified by hand");

        session()->flash('ok', 'Marked as verified.');
    }

    // ─────────────────────────────────────────────────────────────────
    // Suspension & deletion
    // ─────────────────────────────────────────────────────────────────

    public function startSuspend(): void
    {
        $this->confirmingDelete = false;
        $this->suspending = true;
        $this->reason = '';
    }

    public function suspend(): void
    {
        $this->validate(['reason' => ['required', 'string', 'min:5', 'max:200']], [
            'reason.required' => 'Say why — the user sees this message when they try to sign in.',
        ]);

        if ($this->isSelf() || $this->user->isAdmin()) {
            session()->flash('error', 'Admins cannot be suspended.');

            return;
        }

        $this->user->forceFill([
            'status' => User::STATUS_SUSPENDED,
            'suspended_at' => now(),
            'suspension_reason' => $this->reason,
        ])->save();

        ActivityLog::record('user.suspended', $this->user, "User #{$this->user->id} suspended", ['reason' => $this->reason]);

        $this->reset(['suspending', 'reason']);
        session()->flash('ok', 'Account suspended.');
    }

    public function restore(): void
    {
        if ($this->user->isAnonymised()) {
            session()->flash('error', 'A deleted account cannot be restored.');

            return;
        }

        $this->user->forceFill([
            'status' => User::STATUS_ACTIVE,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        ActivityLog::record('user.restored', $this->user, "User #{$this->user->id} restored");

        session()->flash('ok', 'Account restored.');
    }

    public function startDelete(): void
    {
        $this->suspending = false;
        $this->confirmingDelete = true;
        $this->confirmation = '';
    }

    public function anonymise(): void
    {
        $this->validate(['confirmation' => ['required', 'in:DELETE']], [
            'confirmation.in' => 'Type DELETE in capitals to confirm.',
            'confirmation.required' => 'Type DELETE in capitals to confirm.',
        ]);

        try {
            app(AnonymiseUser::class)($this->user);
        } catch (\RuntimeException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        session()->flash('ok', 'Account deleted. Sounds and download history stay in place.');

        $this->redirectRoute('admin.users', navigate: true);
    }

    // ─────────────────────────────────────────────────────────────────
    // Suscripción y sesiones
    // ─────────────────────────────────────────────────────────────────

    public function revokeSessions(): void
    {
        if (! Schema::hasTable('sessions')) {
            return;
        }

        $count = DB::table('sessions')->where('user_id', $this->user->id)->delete();

        ActivityLog::record('user.sessions.revoked', $this->user,
            "User #{$this->user->id}: {$count} session(s) closed");

        unset($this->sessions);
        session()->flash('ok', $count ? "{$count} session(s) closed." : 'There were no open sessions.');
    }

    /**
     * Take two-factor off an account that cannot get past it.
     *
     * ── WHY THIS HAS TO EXIST ────────────────────────────────────────────
     *
     * Two-factor is the one setting a person can switch on and then be
     * unable to switch off. Lose the phone and the recovery codes — a stolen
     * handset, a new device the authenticator was never migrated to, codes
     * saved as a screenshot on the same phone — and the account is closed
     * for good. Every other lockout has a way back; this one had none, and
     * the only remedy was editing the database by hand.
     *
     * This screen already SHOWED "Two-factor: Enabled" and offered nothing
     * to do about it, which is the worst of both: it names the problem and
     * withholds the fix.
     *
     * ── NOT ON YOUR OWN ACCOUNT ──────────────────────────────────────────
     *
     * Turning your own off belongs in Settings → Security, which sits behind
     * password confirmation. Allowing it here would mean anyone who reaches
     * an admin session — a borrowed laptop, an unlocked screen — could strip
     * the second factor from that very account without proving they know the
     * password. That is precisely the attack two-factor exists to stop, so
     * the panel must not be a way around it.
     *
     * Fortify's action is used rather than nulling the columns here: it
     * clears the secret, the recovery codes and the confirmation stamp
     * together, and it stays correct if the package changes what it stores.
     */
    public function clearTwoFactor(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        if ($this->user->two_factor_confirmed_at === null) {
            return;
        }

        if ($this->user->id === auth()->id()) {
            session()->flash('error', 'Turn your own two-factor off from Settings → Security, where it asks for your password first.');

            return;
        }

        $disableTwoFactorAuthentication($this->user);

        $this->user->refresh();

        /*
         * Logged loudly, under the key ActivityCatalog already defines —
         * 'user.2fa.disabled', labelled "Two-factor disabled" and rated
         * danger. Inventing a second key for the same event would have given
         * the activity log two names for one thing, and only one of them
         * would have had an icon.
         *
         * Removing somebody's second factor is one of the few admin actions
         * that lowers another person's security rather than their access,
         * and the record of who did it and when is what makes it
         * accountable.
         */
        ActivityLog::record('user.2fa.disabled', $this->user,
            "User #{$this->user->id}: two-factor removed by an admin");

        session()->flash('ok', 'Two-factor removed. They can sign in with their password alone, and set it up again from their own settings.');
    }

    #[Computed]
    public function sessions()
    {
        if (! Schema::hasTable('sessions')) {
            return collect();
        }

        return DB::table('sessions')
            ->where('user_id', $this->user->id)
            ->orderByDesc('last_activity')
            ->limit(20)
            ->get()
            ->map(fn ($s) => (object) [
                'id' => $s->id,
                'ip' => $s->ip_address,
                'agent' => $s->user_agent,
                'device' => $this->describeAgent($s->user_agent),
                'last' => \Carbon\Carbon::createFromTimestamp($s->last_activity),
            ]);
    }

    /** Crude on purpose: enough to recognise a session, not to fingerprint it. */
    protected function describeAgent(?string $agent): string
    {
        if (! $agent) {
            return 'Unknown device';
        }

        $os = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS X') => 'Mac',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'Unknown',
        };

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Safari') => 'Safari',
            default => 'Browser',
        };

        return "{$browser} · {$os}";
    }

    #[Computed]
    public function subscriptions()
    {
        return $this->user->subscriptions()->with('plan')->latest('starts_at')->get();
    }

    // ─────────────────────────────────────────────────────────────────
    // Actividad y descargas
    // ─────────────────────────────────────────────────────────────────

    #[Computed]
    public function downloads()
    {
        return $this->user->downloads()
            ->with(['sound:id,title,slug', 'license:id,name'])
            ->latest('created_at')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function timeline()
    {
        return ActivityLog::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $this->user->id)
            ->with('user:id,name')
            ->latest('created_at')
            ->limit(12)
            ->get();
    }

    #[Computed]
    public function counters(): array
    {
        $plan = $this->user->currentPlan();
        $remaining = $this->user->remainingDownloadsToday();

        return [
            ['label' => 'Downloads', 'value' => $this->user->downloads()->count(), 'icon' => 'arrow-down-to-line', 'tone' => 'info'],
            ['label' => 'Today', 'value' => $this->user->downloadsToday().($remaining === null ? ' / ∞' : ' / '.($this->user->downloadsToday() + $remaining)), 'icon' => 'gauge-high', 'tone' => $remaining !== null && $remaining === 0 ? 'warning' : 'neutral'],
            ['label' => 'Uploads', 'value' => $this->user->sounds()->count(), 'icon' => 'waveform-lines', 'tone' => 'brand'],
            ['label' => 'Favourites', 'value' => $this->user->favorites()->count(), 'icon' => 'heart', 'tone' => 'neutral'],
            ['label' => 'Plan', 'value' => $plan?->name ?? '—', 'icon' => 'crown', 'tone' => 'success'],
        ];
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

    <a href="{{ route('admin.users') }}" wire:navigate
       class="mb-4 inline-flex items-center gap-2 text-[0.8rem] text-paper/35 transition hover:text-paper">
        <x-icon name="arrow-left" style="solid" class="text-[11px]" /> All users
    </a>

    {{-- ══════ HEADER ══════ --}}
    <div class="rounded-2xl border border-hairline bg-panel p-6">
        <div class="flex flex-wrap items-start justify-between gap-5">
            <div class="flex min-w-0 items-center gap-4">
                <span @class([
                    'grid size-14 shrink-0 place-items-center rounded-full text-[1rem] font-semibold',
                    'bg-raised text-paper/30' => $user->isAnonymised(),
                    'bg-danger/15 text-danger' => ! $user->isAnonymised() && $user->isSuspended(),
                    'bg-brand text-white' => ! $user->isAnonymised() && ! $user->isSuspended(),
                ])>
                    @if ($user->isAnonymised())
                        <x-icon name="user-slash" style="solid" class="text-[16px]" />
                    @else
                        {{ $user->initials() }}
                    @endif
                </span>

                <div class="min-w-0">
                    <h1 class="truncate text-[1.35rem] font-semibold tracking-[-0.02em]">{{ $user->name }}</h1>
                    <p class="truncate text-[0.82rem] text-paper/35">
                        {{ $user->email }}{{ $user->username ? ' · @'.$user->username : '' }}
                    </p>

                    <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                        <span class="rounded-full bg-raised px-2.5 py-1 text-[0.7rem] capitalize text-paper/55">{{ $user->role }}</span>

                        @if ($user->isAnonymised())
                            <span class="rounded-full bg-raised px-2.5 py-1 text-[0.7rem] text-paper/35">Deleted {{ $user->anonymised_at->format('M j, Y') }}</span>
                        @elseif ($user->isSuspended())
                            <span class="rounded-full bg-danger/15 px-2.5 py-1 text-[0.7rem] text-danger">Suspended</span>
                        @else
                            <span class="rounded-full bg-success/15 px-2.5 py-1 text-[0.7rem] text-success">Active</span>
                        @endif

                        @php $source = $user->verificationSource(); @endphp
                        @if ($source === 'google')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-info/15 px-2.5 py-1 text-[0.7rem] text-info">
                                <x-icon name="google" style="brands" class="text-[10px]" /> Google account
                            </span>
                        @elseif ($source)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-success/15 px-2.5 py-1 text-[0.7rem] text-success">
                                <x-icon name="envelope-circle-check" style="solid" class="text-[10px]" /> Email verified
                            </span>
                        @elseif (! $user->isAnonymised())
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-warning/15 px-2.5 py-1 text-[0.7rem] text-warning">
                                <x-icon name="circle-exclamation" style="solid" class="text-[10px]" /> Not verified
                            </span>
                        @endif

                        @if ($user->two_factor_confirmed_at)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-info/15 px-2.5 py-1 text-[0.7rem] text-info">
                                <x-icon name="shield-check" style="solid" class="text-[10px]" /> 2FA
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            @unless ($user->isAnonymised())
                <div class="flex items-center gap-1.5">
                    @unless ($user->id === auth()->id() || $user->isAdmin())
                        <a href="{{ route('admin.impersonate', $user) }}">
                            <x-admin.icon-button icon="user-secret" label="View as this user" />
                        </a>
                    @endunless

                    @if ($user->isSuspended())
                        <x-admin.icon-button icon="rotate-left" label="Restore account"
                                             class="!text-success hover:!bg-success/15" wire:click="restore" />
                    @elseif ($user->id !== auth()->id() && ! $user->isAdmin())
                        <x-admin.icon-button icon="ban" label="Suspend"
                                             class="hover:!bg-warning/15 hover:!text-warning" wire:click="startSuspend" />
                    @endif

                    @unless ($user->id === auth()->id() || $user->isAdmin())
                        <x-admin.icon-button icon="trash" label="Delete"
                                             class="hover:!bg-danger/15 hover:!text-danger" wire:click="startDelete" />
                    @endunless
                </div>
            @endunless
        </div>

        @if ($user->isSuspended() && $user->suspension_reason)
            <p class="mt-5 rounded-xl border border-danger/25 bg-danger/10 px-4 py-3 text-[0.82rem] text-paper/70">
                <span class="text-danger">Reason:</span> {{ $user->suspension_reason }}
                <span class="text-paper/30">· since {{ $user->suspended_at?->diffForHumans() }}</span>
            </p>
        @endif

        {{-- Suspend --}}
        @if ($suspending)
            <div class="mt-5 rounded-xl bg-raised p-4">
                <span class="mb-2 block text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">Why are you suspending this account?</span>
                <div class="flex flex-wrap gap-2">
                    <input type="text" wire:model="reason" autofocus wire:keydown.enter="suspend"
                           placeholder="Automated mass downloading from a single IP."
                           class="min-w-0 flex-1 rounded-lg border-0 bg-panel px-3.5 py-2.5 text-[0.88rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-warning/40" />
                    <button wire:click="suspend" class="rounded-lg bg-warning px-5 py-2.5 text-[0.85rem] font-medium text-ink transition hover:brightness-110">Suspend</button>
                    <button wire:click="$set('suspending', false)" class="rounded-lg bg-panel px-4 py-2.5 text-[0.85rem] text-paper/55 transition hover:text-paper">Cancel</button>
                </div>
                @error('reason') <p class="mt-2 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                <p class="mt-2 text-[0.75rem] text-paper/30">Reversible. The user sees this message when they try to sign in.</p>
            </div>
        @endif

        {{-- Delete --}}
        @if ($confirmingDelete)
            <div class="mt-5 rounded-xl border border-danger/25 bg-danger/[0.07] p-4">
                <div class="flex items-start gap-3">
                    <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full bg-danger/15 text-danger">
                        <x-icon name="triangle-exclamation" style="solid" class="text-[12px]" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="text-[0.88rem] font-medium">Delete this account?</p>

                        <p class="mt-1.5 text-[0.78rem] leading-relaxed text-paper/45">
                            Erased: name, email, avatar, biography, password, passkeys, two-factor, Google link,
                            favourites, personal collections and every open session. The username is freed.
                            <span class="text-paper/70">This cannot be undone.</span>
                        </p>

                        <p class="mt-1.5 text-[0.78rem] leading-relaxed text-paper/45">
                            Kept: <span class="text-paper/70">{{ $user->sounds()->count() }}</span> sound(s) in the
                            catalogue, credited to “Deleted user”, and
                            <span class="text-paper/70">{{ $user->downloads()->count() }}</span> download(s) with their
                            frozen licences — the proof those licences were granted.
                        </p>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <input type="text" wire:model="confirmation" autofocus wire:keydown.enter="anonymise"
                                   placeholder="Type DELETE to confirm"
                                   class="min-w-0 flex-1 rounded-lg border-0 bg-panel px-3.5 py-2.5 text-[0.88rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-danger/40" />
                            <button wire:click="anonymise" class="rounded-lg bg-danger px-5 py-2.5 text-[0.85rem] font-medium text-white transition hover:brightness-110">Delete permanently</button>
                            <button wire:click="$set('confirmingDelete', false)" class="rounded-lg bg-panel px-4 py-2.5 text-[0.85rem] text-paper/55 transition hover:text-paper">Cancel</button>
                        </div>

                        @error('confirmation') <p class="mt-2 text-[0.8rem] text-danger">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- ══════ COUNTERS ══════ --}}
    <div class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ($this->counters as $c)
            <div class="rounded-2xl border border-hairline bg-panel p-5">
                <div class="flex items-start justify-between">
                    <span class="text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">{{ $c['label'] }}</span>
                    <span @class([
                        'grid size-9 place-items-center rounded-full',
                        'bg-brand/15 text-brand' => $c['tone'] === 'brand',
                        'bg-success/15 text-success' => $c['tone'] === 'success',
                        'bg-warning/15 text-warning' => $c['tone'] === 'warning',
                        'bg-info/15 text-info' => $c['tone'] === 'info',
                        'bg-raised text-paper/40' => $c['tone'] === 'neutral',
                    ])>
                        <x-icon :name="$c['icon']" style="solid" class="text-[13px]" />
                    </span>
                </div>
                <div class="mt-3 truncate text-[1.5rem] font-semibold leading-none tracking-[-0.03em]">{{ $c['value'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- ══════ TABS ══════ --}}
    <div class="mt-5 flex flex-wrap gap-1.5 rounded-2xl border border-hairline bg-panel p-1.5">
        @foreach ([
            'data' => ['Data', 'id-card'],
            'activity' => ['Activity & downloads', 'clock-rotate-left'],
            'billing' => ['Subscription & sessions', 'credit-card'],
        ] as $key => [$label, $icon])
            <button wire:click="$set('tab', '{{ $key }}')"
                    @class([
                        'flex items-center gap-2 rounded-xl px-4 py-2.5 text-[0.84rem] transition duration-200 ease-dbelo',
                        'bg-raised text-paper' => $tab === $key,
                        'text-paper/45 hover:text-paper' => $tab !== $key,
                    ])>
                <x-icon :name="$icon" style="solid" class="text-[11px]" />
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- ══════ DATA ══════ --}}
    @if ($tab === 'data')
        <div class="mt-5 grid gap-5 lg:grid-cols-3">
            <div class="rounded-2xl border border-hairline bg-panel lg:col-span-2">
                <div class="border-b border-hairline px-5 py-3.5">
                    <h2 class="text-[0.95rem] font-medium">Account details</h2>
                </div>

                @if ($user->isAnonymised())
                    <p class="px-5 py-10 text-center text-[0.85rem] text-paper/35">
                        This account was deleted. There is nothing left to edit.
                    </p>
                @else
                    <div class="grid gap-4 p-5 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-2 block text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">Name</span>
                            <input type="text" wire:model="name" @if ($focusForm) autofocus @endif
                                   class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.88rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                            @error('name') <p class="mt-1.5 text-[0.78rem] text-danger">{{ $message }}</p> @enderror
                        </label>

                        <label class="block">
                            <span class="mb-2 block text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">Username</span>
                            <input type="text" wire:model="username" placeholder="none"
                                   class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.88rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                            @error('username') <p class="mt-1.5 text-[0.78rem] text-danger">{{ $message }}</p> @enderror
                        </label>

                        <label class="block">
                            <span class="mb-2 block text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">Email</span>
                            <input type="email" wire:model="email"
                                   class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.88rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40" />
                            @error('email') <p class="mt-1.5 text-[0.78rem] text-danger">{{ $message }}</p> @enderror
                            @unless ($user->oauth_provider)
                                <p class="mt-1.5 text-[0.72rem] text-paper/30">Changing this clears the verification.</p>
                            @endunless
                        </label>

                        <label class="block">
                            <span class="mb-2 block text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">Role</span>
                            <select wire:model="role" @disabled($user->id === auth()->id())
                                    class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.88rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40 disabled:opacity-40">
                                <option value="user">User</option>
                                <option value="collaborator">Contributor</option>
                                <option value="admin">Admin</option>
                            </select>
                            @if ($user->id === auth()->id())
                                <p class="mt-1.5 text-[0.72rem] text-paper/30">You cannot change your own role.</p>
                            @endif
                        </label>

                        <label class="block sm:col-span-2">
                            <span class="mb-2 block text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">Website</span>
                            <input type="url" wire:model="website" placeholder="https://"
                                   class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.88rem] text-paper placeholder:text-paper/25 focus:outline-none focus:ring-2 focus:ring-brand/40" />
                            @error('website') <p class="mt-1.5 text-[0.78rem] text-danger">{{ $message }}</p> @enderror
                        </label>

                        <label class="block sm:col-span-2">
                            <span class="mb-2 block text-[0.7rem] uppercase tracking-[0.14em] text-paper/35">Bio</span>
                            <textarea wire:model="bio" rows="3"
                                      class="w-full resize-none rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.88rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40"></textarea>
                            @error('bio') <p class="mt-1.5 text-[0.78rem] text-danger">{{ $message }}</p> @enderror
                        </label>

                        <div class="sm:col-span-2">
                            <button wire:click="save"
                                    class="rounded-lg bg-action px-5 py-2.5 text-[0.85rem] font-medium text-white transition duration-200 ease-dbelo hover:brightness-110">
                                Save changes
                            </button>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Side facts: things you read, never edit --}}
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="border-b border-hairline px-5 py-3.5">
                    <h2 class="text-[0.95rem] font-medium">Account</h2>
                </div>

                <dl class="divide-y divide-hairline text-[0.82rem]">
                    @foreach ([
                        ['ID', '#'.$user->id],
                        ['Registered', $user->created_at->format('M j, Y')],
                        ['Last seen', $user->last_seen_at?->diffForHumans() ?? 'Never'],
                        ['Sign-in', $user->oauth_provider ? ucfirst($user->oauth_provider) : 'Email & password'],
                        ['Two-factor', $user->two_factor_confirmed_at ? 'Enabled' : 'Off'],
                        ['Open sessions', $this->sessions->count()],
                    ] as [$label, $value])
                        <div class="flex items-center justify-between gap-3 px-5 py-3">
                            <dt class="text-paper/35">{{ $label }}</dt>
                            <dd class="truncate text-right">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                @unless ($user->isVerified() || $user->isAnonymised())
                    <div class="border-t border-hairline p-5">
                        <button wire:click="verifyManually"
                                class="w-full rounded-lg bg-info/15 px-4 py-2.5 text-[0.83rem] text-info transition hover:bg-info/25">
                            Mark email as verified
                        </button>
                        <p class="mt-2 text-[0.72rem] leading-relaxed text-paper/30">
                            For when the verification email never arrived. It is logged.
                        </p>
                    </div>
                @endunless

                {{-- The way back from the one lockout that had none.

                     Only shown when there is something to remove, and never
                     on your own account — see clearTwoFactor(). --}}
                @if ($user->two_factor_confirmed_at && $user->id !== auth()->id())
                    <div class="border-t border-hairline p-5">
                        <button wire:click="clearTwoFactor"
                                wire:confirm="Remove two-factor from this account? They will sign in with their password alone until they set it up again. This is recorded in the activity log."
                                class="w-full rounded-lg bg-warning/15 px-4 py-2.5 text-[0.83rem] text-warning transition hover:bg-warning/25">
                            Remove two-factor
                        </button>
                        <p class="mt-2 text-[0.72rem] leading-relaxed text-paper/30">
                            For somebody who lost the phone <em>and</em> the recovery codes. Confirm it is really them first — this
                            is the check an attacker would most like you to skip.
                        </p>
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ══════ ACTIVITY ══════ --}}
    @if ($tab === 'activity')
        <div class="mt-5 grid gap-5 lg:grid-cols-2">
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="border-b border-hairline px-5 py-3.5">
                    <h2 class="text-[0.95rem] font-medium">Last downloads</h2>
                </div>

                @forelse ($this->downloads as $download)
                    <div class="flex items-center justify-between gap-3 border-b border-hairline px-5 py-3 last:border-0">
                        <div class="min-w-0">
                            @if ($download->sound)
                                <a href="{{ route('sounds.show', $download->sound) }}" wire:navigate
                                   class="block truncate text-[0.85rem] transition hover:text-brand">{{ $download->sound->title }}</a>
                            @else
                                <span class="block truncate text-[0.85rem] italic text-paper/35">Sound removed</span>
                            @endif
                            <span class="text-[0.72rem] text-paper/25">
                                {{ $download->license?->name ?? data_get($download->license_snapshot, 'name', 'No licence') }}
                            </span>
                        </div>
                        <span class="shrink-0 text-[0.75rem] text-paper/30">{{ $download->created_at?->diffForHumans(short: true) }}</span>
                    </div>
                @empty
                    <p class="px-5 py-10 text-center text-[0.85rem] text-paper/35">No downloads yet.</p>
                @endforelse
            </div>

            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="border-b border-hairline px-5 py-3.5">
                    <h2 class="text-[0.95rem] font-medium">Admin actions on this account</h2>
                </div>

                @forelse ($this->timeline as $entry)
                    <div class="flex items-start gap-3 border-b border-hairline px-5 py-3 last:border-0">
                        <span @class([
                            'mt-1 size-2 shrink-0 rounded-full',
                            'bg-danger' => str_contains($entry->action, 'suspend') || str_contains($entry->action, 'anonymis'),
                            'bg-success' => str_contains($entry->action, 'restore'),
                            'bg-info' => ! str_contains($entry->action, 'suspend') && ! str_contains($entry->action, 'anonymis') && ! str_contains($entry->action, 'restore'),
                        ])></span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[0.83rem]">{{ $entry->description ?? $entry->action }}</p>
                            <p class="text-[0.72rem] text-paper/25">
                                {{ $entry->user?->name ?? 'System' }} · {{ $entry->created_at?->diffForHumans(short: true) }}
                                @if ($reason = data_get($entry->meta, 'reason')) · “{{ $reason }}” @endif
                            </p>
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-10 text-center text-[0.85rem] text-paper/35">Nothing has happened to this account.</p>
                @endforelse
            </div>
        </div>
    @endif

    {{-- ══════ BILLING & SESSIONS ══════ --}}
    @if ($tab === 'billing')
        <div class="mt-5 grid gap-5 lg:grid-cols-2">
            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="border-b border-hairline px-5 py-3.5">
                    <h2 class="text-[0.95rem] font-medium">Subscriptions</h2>
                </div>

                @forelse ($this->subscriptions as $sub)
                    <div class="border-b border-hairline px-5 py-4 last:border-0">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-[0.88rem]">{{ $sub->plan?->name ?? 'Unknown plan' }}</span>
                            <span @class([
                                'rounded-full px-2.5 py-1 text-[0.7rem] capitalize',
                                'bg-success/15 text-success' => $sub->isActive(),
                                'bg-raised text-paper/35' => ! $sub->isActive(),
                            ])>{{ $sub->status }}</span>
                        </div>
                        <p class="mt-1.5 text-[0.75rem] text-paper/30">
                            {{ $sub->starts_at?->format('M j, Y') ?? '—' }}
                            → {{ $sub->ends_at?->format('M j, Y') ?? 'open' }}
                            @if ($sub->cancelled_at) · cancelled {{ $sub->cancelled_at->format('M j, Y') }} @endif
                        </p>
                    </div>
                @empty
                    <p class="px-5 py-10 text-center text-[0.85rem] text-paper/35">
                        Free plan. No subscription has ever been created.
                    </p>
                @endforelse
            </div>

            <div class="rounded-2xl border border-hairline bg-panel">
                <div class="flex items-center justify-between gap-3 border-b border-hairline px-5 py-3.5">
                    <h2 class="text-[0.95rem] font-medium">
                        Open sessions <span class="ml-1 text-paper/35">{{ $this->sessions->count() }}</span>
                    </h2>

                    @if ($this->sessions->isNotEmpty())
                        <button wire:click="revokeSessions"
                                wire:confirm="Close every open session for this account? They will have to sign in again."
                                class="rounded-lg bg-danger/15 px-3.5 py-2 text-[0.8rem] text-danger transition hover:bg-danger/25">
                            Close all
                        </button>
                    @endif
                </div>

                @forelse ($this->sessions as $s)
                    <div class="flex items-center justify-between gap-3 border-b border-hairline px-5 py-3 last:border-0">
                        <div class="min-w-0">
                            <p class="truncate text-[0.83rem]">{{ $s->device }}</p>
                            <p class="text-[0.72rem] text-paper/25">{{ $s->ip ?? 'unknown IP' }}</p>
                        </div>
                        <span class="shrink-0 text-[0.75rem] text-paper/30">{{ $s->last->diffForHumans(short: true) }}</span>
                    </div>
                @empty
                    <p class="px-5 py-10 text-center text-[0.85rem] text-paper/35">
                        No open sessions — nobody is signed in as this user right now.
                    </p>
                @endforelse
            </div>
        </div>
    @endif
</div>
