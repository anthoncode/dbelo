<?php

use App\Models\ActivityLog;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Sessions')] class extends Component {
    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    /**
     * Read straight from the sessions table.
     *
     * No new storage and no new writes: SESSION_DRIVER=database already
     * records the user, the address, the browser and the last activity for
     * every open session. The whole feature is a view over data that has
     * been sitting there all along.
     */
    #[Computed]
    public function sessions(): array
    {
        $current = session()->getId();

        return DB::table('sessions')
            ->leftJoin('users', 'users.id', '=', 'sessions.user_id')
            ->select('sessions.id', 'sessions.user_id', 'sessions.ip_address',
                'sessions.user_agent', 'sessions.last_activity', 'users.name', 'users.email')
            ->orderByDesc('sessions.last_activity')
            ->limit(200)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'isCurrent' => $row->id === $current,
                'user' => $row->name,
                'email' => $row->email,
                'userId' => $row->user_id,
                'ip' => $row->ip_address,
                'agent' => $this->readableAgent($row->user_agent),
                'rawAgent' => $row->user_agent,
                'lastActivity' => \Illuminate\Support\Carbon::createFromTimestamp($row->last_activity),
                'guest' => $row->user_id === null,
            ])
            ->all();
    }

    #[Computed]
    public function signedIn(): int
    {
        return count(array_filter($this->sessions, fn ($s) => ! $s['guest']));
    }

    /**
     * A browser name, not a user-agent string.
     *
     * The raw string is 200 characters of version numbers nobody reads, and
     * the question being asked here is "do I recognise this?" — which needs
     * the name of the browser and the machine, and nothing else. The full
     * string stays on hover for when it matters.
     */
    private function readableAgent(?string $agent): string
    {
        if (blank($agent)) {
            return 'Unknown';
        }

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox') => 'Firefox',
            str_contains($agent, 'Chrome') => 'Chrome',
            str_contains($agent, 'Safari') => 'Safari',
            default => 'Browser',
        };

        $platform = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => '',
        };

        return trim("{$browser} · {$platform}", ' ·');
    }

    /**
     * End one session.
     *
     * Deleting the row is what ends it — the driver looks the session up by
     * id on the next request and finds nothing, so the person is signed out
     * wherever they are. There is no gentler mechanism and there does not
     * need to be: this is the button somebody presses when they think
     * another person is inside their account.
     */
    public function revoke(string $id): void
    {
        $row = DB::table('sessions')->where('id', $id)->first();

        if (! $row || $id === session()->getId()) {
            return;
        }

        DB::table('sessions')->where('id', $id)->delete();

        ActivityLog::record(
            'user.session.revoked',
            $row->user_id ? \App\Models\User::find($row->user_id) : null,
            'Session ended from '.($row->ip_address ?? 'unknown address'),
            ['ip' => $row->ip_address],
        );

        unset($this->sessions);
    }

    /** Everything except the browser you are sitting in. */
    public function revokeAllOthers(): void
    {
        $count = DB::table('sessions')->where('id', '!=', session()->getId())->count();

        DB::table('sessions')->where('id', '!=', session()->getId())->delete();

        ActivityLog::record('user.sessions.revoked_all', null, "{$count} sessions ended");

        unset($this->sessions);

        session()->flash('revoked', $count);
    }
}; ?>

<div class="space-y-5">

    <div class="rounded-2xl border border-hairline bg-panel">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-4">
            <div>
                <h2 class="text-[0.95rem] font-medium">Open sessions</h2>
                <p class="mt-0.5 max-w-[64ch] text-[0.78rem] leading-relaxed text-paper/40">
                    Read from the sessions table, which the database session driver has been filling in all along.
                    Ending one signs that browser out on its next request, wherever it is.
                </p>
            </div>

            <button type="button" wire:click="revokeAllOthers"
                    wire:confirm="This signs out every browser except this one, including other people's. Continue?"
                    class="flex shrink-0 items-center gap-2 rounded-lg bg-raised px-3.5 py-2 text-[0.82rem] transition hover:bg-danger hover:text-white">
                <x-icon name="arrow-right-from-bracket" style="solid" class="text-[0.75rem]" />
                End all others
            </button>
        </div>

        @if (session('revoked') !== null)
            <div class="border-b border-hairline bg-success/[0.06] px-5 py-3 text-[0.84rem] text-paper/70">
                <x-icon name="circle-check" style="solid" class="mr-1.5 text-[0.8rem] text-success" />
                {{ session('revoked') }} {{ Str::plural('session', session('revoked')) }} ended.
            </div>
        @endif

        <div class="border-b border-hairline px-5 py-2.5 text-[0.78rem] text-paper/35">
            {{ count($this->sessions) }} total · {{ $this->signedIn }} signed in ·
            {{ count($this->sessions) - $this->signedIn }} anonymous
        </div>

        <div class="divide-y divide-hairline">
            @forelse ($this->sessions as $s)
                <div class="flex items-center gap-3.5 px-5 py-3.5" wire:key="ses-{{ $s['id'] }}">

                    <x-admin.icon-chip :icon="$s['guest'] ? 'user' : 'user-check'"
                                       :tone="$s['guest'] ? 'muted' : 'brand'" />

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-[0.87rem] text-paper/80">
                                {{ $s['user'] ?? 'Not signed in' }}
                            </span>

                            @if ($s['isCurrent'])
                                <span class="rounded-full bg-success/15 px-2 py-0.5 text-[0.64rem] font-semibold uppercase tracking-[0.1em] text-success">
                                    This browser
                                </span>
                            @endif
                        </div>

                        <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[0.74rem] text-paper/35">
                            @if ($s['email'])
                                <span class="truncate">{{ $s['email'] }}</span>
                                <span class="opacity-40">·</span>
                            @endif
                            <span class="font-mono">{{ $s['ip'] }}</span>
                            <span class="opacity-40">·</span>
                            <span title="{{ $s['rawAgent'] }}">{{ $s['agent'] }}</span>
                        </div>
                    </div>

                    <span class="shrink-0 text-right text-[0.74rem] tabular-nums text-paper/35"
                          title="{{ $s['lastActivity']->toDayDateTimeString() }}">
                        {{ $s['lastActivity']->diffForHumans(short: true) }}
                    </span>

                    <div class="w-9 shrink-0">
                        @unless ($s['isCurrent'])
                            {{-- No button on your own session. Signing yourself
                                 out from an admin screen is never what somebody
                                 meant to click, and the Log out menu is right
                                 there for when it is. --}}
                            <x-admin.icon-button icon="xmark" variant="muted" label="End this session"
                                                 wire:click="revoke('{{ $s['id'] }}')" />
                        @endunless
                    </div>
                </div>
            @empty
                <p class="px-5 py-16 text-center text-[0.9rem] text-paper/45">No open sessions.</p>
            @endforelse
        </div>
    </div>

    <div class="rounded-2xl border border-hairline bg-panel px-5 py-4">
        <p class="text-[0.78rem] leading-relaxed text-paper/40">
            <x-icon name="circle-info" style="solid" class="mr-1 text-[0.72rem] text-info" />
            Sessions expire on their own after
            <code class="rounded bg-raised px-1.5 py-0.5 text-[0.72rem] text-paper/60">SESSION_LIFETIME</code>
            minutes of inactivity, so this list is short by design. Anonymous rows are visitors who have a session
            but have never signed in — a cart of one, essentially, and normal.
        </p>
    </div>
</div>
