<?php

use App\Models\IpBlock;
use App\Models\LoginAttempt;
use App\Services\SecurityWatch;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Access log')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $outcome = 'all';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    /**
     * Asked fresh on every render, never stored.
     *
     * This was a public property set in mount(), which is a snapshot — and
     * Livewire keeps public properties across updates, while wire:navigate
     * keeps whole rendered pages in its back/forward cache. So after running
     * the migration the screen could still be showing a "not migrated"
     * answer from before it, with no way to tell that it was stale. A
     * computed property is recomputed per request and cannot lie about the
     * present.
     */
    #[Computed]
    public function ready(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('login_attempts');
    }

    public function updated(string $key): void
    {
        if (in_array($key, ['search', 'outcome'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function attempts()
    {
        return LoginAttempt::query()
            ->with('user:id,name')
            ->search($this->search)
            ->when($this->outcome === 'failed', fn ($q) => $q->failed())
            ->when($this->outcome === 'success', fn ($q) => $q->where('outcome', 'success'))
            ->when($this->outcome === 'admin', fn ($q) => $q->where('is_admin', true))
            ->latest('created_at')
            ->paginate(30);
    }

    #[Computed]
    public function summary(): array
    {
        return app(SecurityWatch::class)->summary();
    }

    #[Computed]
    public function offenders(): array
    {
        return app(SecurityWatch::class)->topOffenders();
    }

    /** Block straight from the row that made you want to. */
    public function block(string $ip): void
    {
        IpBlock::updateOrCreate(
            ['ip_address' => $ip],
            [
                'reason' => 'Blocked from the access log',
                'source' => 'manual',
                // An hour, not forever. See the migration for why permanent
                // blocks are the deliberate, awkward choice.
                'expires_at' => now()->addHours(24),
                'created_by' => auth()->id(),
            ],
        );

        unset($this->offenders);

        session()->flash('blocked', $ip);
    }
}; ?>

<div class="space-y-5">

@if (! $this->ready)
    <x-admin.migration-pending table="login_attempts" what="The access log" />
@else

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Failed, 24h', 'value' => $this->summary['failed'], 'icon' => 'circle-xmark', 'alarm' => $this->summary['failed'] > 20],
            ['label' => 'Rate-limited', 'value' => $this->summary['lockouts'], 'icon' => 'shield-halved', 'alarm' => false, 'hint' => 'the limit working'],
            ['label' => 'Admin sign-ins', 'value' => $this->summary['admin'], 'icon' => 'user-shield', 'alarm' => false],
            ['label' => 'Open signals', 'value' => $this->summary['signals'], 'icon' => 'triangle-exclamation', 'alarm' => $this->summary['signals'] > 0],
        ] as $card)
            <div class="rounded-2xl border border-hairline bg-panel p-5" wire:key="sum-{{ $card['label'] }}">
                <div class="flex items-start justify-between">
                    <span class="text-[0.72rem] uppercase tracking-[0.14em] text-paper/35">{{ $card['label'] }}</span>
                    <span @class([
                        'grid size-9 place-items-center rounded-full',
                        'bg-warning/15 text-warning' => $card['alarm'],
                        'bg-raised text-paper/45' => ! $card['alarm'],
                    ])>
                        <x-icon :name="$card['icon']" style="solid" class="text-[13px]" />
                    </span>
                </div>
                <div class="mt-3 text-[2rem] font-semibold leading-none tracking-[-0.03em]">{{ number_format($card['value']) }}</div>
                @isset($card['hint'])
                    <div class="mt-2 text-[0.78rem] text-paper/35">{{ $card['hint'] }}</div>
                @endisset
            </div>
        @endforeach
    </div>

    @if (session('blocked'))
        <div class="rounded-2xl border border-success/25 bg-success/[0.06] px-5 py-3.5 text-[0.85rem] text-paper/70">
            <x-icon name="circle-check" style="solid" class="mr-1.5 text-[0.8rem] text-success" />
            {{ session('blocked') }} is blocked for 24 hours. Manage it under Blocks &amp; abuse.
        </div>
    @endif

    <div class="grid gap-5 xl:grid-cols-[1.6fr_1fr]">

        {{-- ═══════════════════ THE LOG ═══════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="flex flex-wrap items-center gap-3 border-b border-hairline px-5 py-4">
                <div class="relative min-w-[200px] flex-1">
                    <span class="pointer-events-none absolute inset-y-0 left-0 grid w-11 place-items-center">
                        <x-icon name="magnifying-glass" style="solid" class="text-[0.78rem] text-paper/30" />
                    </span>
                    <input type="search" wire:model.live.debounce.300ms="search"
                           placeholder="Email, IP, browser…"
                           class="w-full rounded-lg border-0 bg-raised py-2.5 pl-11 pr-3.5 text-[0.84rem] placeholder:text-paper/30 focus:outline-none focus:ring-1 focus:ring-brand" />
                </div>

                @foreach (['all' => 'All', 'failed' => 'Failed', 'success' => 'Succeeded', 'admin' => 'Admin'] as $key => $label)
                    <button type="button" wire:click="$set('outcome', '{{ $key }}')" wire:key="oc-{{ $key }}"
                            @class([
                                'rounded-full px-3.5 py-1.5 text-[0.78rem] transition duration-200 ease-dbelo',
                                'bg-brand text-white' => $outcome === $key,
                                'bg-raised text-paper/55 hover:text-paper' => $outcome !== $key,
                            ])>{{ $label }}</button>
                @endforeach
            </div>

            <div class="divide-y divide-hairline">
                @forelse ($this->attempts as $attempt)
                    @php
                        [$dot, $word] = match ($attempt->outcome) {
                            'success' => ['bg-success', 'signed in'],
                            'lockout' => ['bg-info', 'rate-limited'],
                            'two_factor' => ['bg-warning', 'failed 2FA'],
                            default => ['bg-danger', 'failed'],
                        };
                    @endphp

                    <div class="flex items-center gap-3.5 px-5 py-3" wire:key="att-{{ $attempt->id }}">
                        <span class="size-2 shrink-0 rounded-full {{ $dot }}"></span>

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="min-w-0 truncate text-[0.86rem] text-paper/80">
                                    {{ $attempt->email ?? 'unknown address' }}
                                </span>

                                @if ($attempt->is_admin)
                                    <span class="rounded-full bg-brand/15 px-2 py-0.5 text-[0.64rem] font-semibold uppercase tracking-[0.1em] text-brand">
                                        Admin
                                    </span>
                                @endif
                            </div>

                            <div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[0.74rem] text-paper/35">
                                <span>{{ $word }}</span>
                                <span class="opacity-40">·</span>
                                <span class="font-mono">{{ $attempt->ip_address }}</span>
                                <span class="opacity-40">·</span>
                                <span class="min-w-0 truncate" title="{{ $attempt->user_agent }}">
                                    {{ Str::limit($attempt->user_agent, 46) }}
                                </span>
                            </div>
                        </div>

                        <span class="shrink-0 text-[0.74rem] tabular-nums text-paper/35"
                              title="{{ $attempt->created_at?->toDayDateTimeString() }}">
                            {{ $attempt->created_at?->diffForHumans(short: true) }}
                        </span>
                    </div>
                @empty
                    <div class="px-5 py-16 text-center">
                        <x-icon name="shield-halved" style="solid" class="text-[24px] text-paper/20" />
                        <p class="mt-3 text-[0.9rem] text-paper/45">Nothing recorded yet</p>
                        <p class="mt-1 text-[0.8rem] text-paper/30">
                            Every sign-in, failure and rate-limit lands here from the moment the migration runs.
                        </p>
                    </div>
                @endforelse
            </div>

            <div class="border-t border-hairline">
                <x-admin.pagination :paginator="$this->attempts" prefix="acc" />
            </div>
        </div>

        {{-- ═══════════════════ WHO KEEPS FAILING ═══════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <div class="border-b border-hairline px-5 py-4">
                <h2 class="text-[0.95rem] font-medium">Most failures, 7 days</h2>
                <p class="mt-0.5 text-[0.78rem] text-paper/40">
                    Many <em>different</em> accounts from one address is the shape that matters — that is a list of
                    leaked passwords being tried, and no per-account limit can see it.
                </p>
            </div>

            <div class="divide-y divide-hairline">
                @forelse ($this->offenders as $row)
                    <div class="flex items-center gap-3 px-5 py-3" wire:key="off-{{ $row['ip'] }}">
                        <div class="min-w-0 flex-1">
                            <div class="font-mono text-[0.82rem] text-paper/75">{{ $row['ip'] }}</div>
                            <div class="mt-0.5 text-[0.74rem] text-paper/35">
                                {{ $row['failures'] }} failures ·
                                <span class="{{ $row['accounts'] >= 6 ? 'text-warning' : '' }}">
                                    {{ $row['accounts'] }} {{ Str::plural('account', $row['accounts']) }}
                                </span>
                            </div>
                        </div>

                        <button type="button" wire:click="block('{{ $row['ip'] }}')"
                                class="shrink-0 rounded-lg bg-raised px-3 py-1.5 text-[0.76rem] transition hover:bg-danger hover:text-white">
                            Block 24h
                        </button>
                    </div>
                @empty
                    <p class="px-5 py-10 text-center text-[0.85rem] text-paper/35">No failures this week.</p>
                @endforelse
            </div>
        </div>
    </div>
@endif
</div>
