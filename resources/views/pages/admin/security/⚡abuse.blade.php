<?php

use App\Models\AbuseSignal;
use App\Models\IpBlock;
use App\Services\SecurityWatch;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Blocks & abuse')] class extends Component {
    public string $newIp = '';

    public string $newReason = '';

    public string $duration = '24';

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
        return \Illuminate\Support\Facades\Schema::hasTable('abuse_signals');
    }

    /** What the watchers are looking for, read from their own constants. */
    #[Computed]
    public function rules(): array
    {
        return SecurityWatch::rules();
    }

    #[Computed]
    public function signals()
    {
        return AbuseSignal::query()
            ->with('user:id,name,email')
            ->orderByRaw("FIELD(status, 'open', 'reviewed', 'ignored')")
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get();
    }

    #[Computed]
    public function blocks()
    {
        return IpBlock::query()
            ->with('author:id,name')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();
    }

    /** Run the pattern scan on demand rather than waiting for the scheduler. */
    public function scan(): void
    {
        $found = app(SecurityWatch::class)->scan();

        unset($this->signals);

        session()->flash('scanned', $found);
    }

    public function addBlock(): void
    {
        $data = $this->validate([
            'newIp' => ['required', 'ip'],
            'newReason' => ['required', 'string', 'max:190'],
            'duration' => ['required', 'in:1,24,168,permanent'],
        ]);

        IpBlock::updateOrCreate(
            ['ip_address' => $data['newIp']],
            [
                'reason' => $data['newReason'],
                'source' => 'manual',
                'expires_at' => $data['duration'] === 'permanent' ? null : now()->addHours((int) $data['duration']),
                'created_by' => auth()->id(),
            ],
        );

        $this->reset('newIp', 'newReason');
        $this->duration = '24';

        unset($this->blocks);
    }

    public function unblock(int $id): void
    {
        IpBlock::findOrFail($id)->delete();

        unset($this->blocks);
    }

    public function markReviewed(int $id): void
    {
        AbuseSignal::findOrFail($id)->update(['status' => 'reviewed']);

        unset($this->signals);
    }

    public function ignore(int $id): void
    {
        AbuseSignal::findOrFail($id)->update(['status' => 'ignored']);

        unset($this->signals);
    }

    /** Block the address a signal is about, in one move. */
    public function blockSignal(int $id): void
    {
        $signal = AbuseSignal::findOrFail($id);

        if (! $signal->ip_address) {
            return;
        }

        IpBlock::updateOrCreate(
            ['ip_address' => $signal->ip_address],
            [
                'reason' => $signal->meaning()['label'].' — '.$signal->detail,
                'source' => 'manual',
                'expires_at' => now()->addDay(),
                'created_by' => auth()->id(),
            ],
        );

        $signal->update(['status' => 'reviewed']);

        unset($this->signals, $this->blocks);
    }
}; ?>

<div class="space-y-5">

@if (! $this->ready)
    <x-admin.migration-pending table="abuse_signals" what="Blocks and abuse" />
@else

    {{-- ══════════════════════════════════════════════════════════════
         SIGNALS
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-hairline px-5 py-4">
            <div>
                <h2 class="text-[0.95rem] font-medium">What the site noticed</h2>
                <p class="mt-0.5 max-w-[70ch] text-[0.78rem] leading-relaxed text-paper/40">
                    Patterns that are invisible one request at a time. A single failed login is nothing; forty
                    failures against forty different addresses from one place is a list of leaked passwords being
                    tried, and no rate limit can see it because every attempt on its own looks ordinary.
                </p>
            </div>

            <button type="button" wire:click="scan" wire:loading.attr="disabled"
                    class="flex shrink-0 items-center gap-2 rounded-lg bg-raised px-3.5 py-2 text-[0.82rem] transition hover:bg-brand hover:text-white disabled:opacity-50">
                <x-icon name="radar" style="solid" class="text-[0.75rem]" wire:loading.class="fa-spin" wire:target="scan" />
                Scan now
            </button>
        </div>

        @if (session('scanned') !== null)
            <div class="border-b border-hairline bg-info/[0.06] px-5 py-3 text-[0.84rem] text-paper/70">
                Scan finished — {{ session('scanned') }} {{ Str::plural('pattern', session('scanned')) }} matched.
            </div>
        @endif

        <div class="divide-y divide-hairline">
            @forelse ($this->signals as $signal)
                @php $meaning = $signal->meaning(); @endphp

                <div class="flex items-start gap-3.5 px-5 py-4" wire:key="sig-{{ $signal->id }}">
                    <span @class([
                        'mt-1.5 size-2 shrink-0 rounded-full',
                        'bg-danger' => $signal->isOpen() && in_array($signal->kind, ['credential_stuffing', 'spray'], true),
                        'bg-warning' => $signal->isOpen() && ! in_array($signal->kind, ['credential_stuffing', 'spray'], true),
                        'bg-paper/20' => ! $signal->isOpen(),
                    ])></span>

                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-raised text-paper/50">
                        <x-icon :name="$meaning['icon']" style="solid" class="text-[12px]" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-[0.88rem] text-paper/85">{{ $meaning['label'] }}</span>

                            @if ($signal->status === 'reviewed')
                                <span class="rounded-full bg-raised px-2 py-0.5 text-[0.64rem] uppercase tracking-[0.1em] text-paper/40">Reviewed</span>
                            @elseif ($signal->status === 'ignored')
                                <span class="rounded-full bg-raised px-2 py-0.5 text-[0.64rem] uppercase tracking-[0.1em] text-paper/40">Ignored</span>
                            @endif
                        </div>

                        <p class="mt-1 text-[0.84rem] text-paper/60">{{ $signal->detail }}</p>

                        <p class="mt-1.5 max-w-[70ch] text-[0.8rem] leading-relaxed text-paper/35">{{ $meaning['what'] }}</p>

                        <div class="mt-2 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-[0.74rem] text-paper/30">
                            @if ($signal->ip_address)
                                <span class="font-mono">{{ $signal->ip_address }}</span>
                                <span class="opacity-40">·</span>
                            @endif
                            @if ($signal->user)
                                <a href="{{ route('admin.users.show', $signal->user) }}" wire:navigate class="transition hover:text-brand">
                                    {{ $signal->user->name }}
                                </a>
                                <span class="opacity-40">·</span>
                            @endif
                            <span title="{{ $signal->last_seen_at?->toDayDateTimeString() }}">
                                last {{ $signal->last_seen_at?->diffForHumans() }}
                            </span>
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-1.5">
                        @if ($signal->isOpen())
                            @if ($signal->ip_address)
                                <button type="button" wire:click="blockSignal({{ $signal->id }})"
                                        class="rounded-lg bg-raised px-3 py-1.5 text-[0.76rem] transition hover:bg-danger hover:text-white">
                                    Block 24h
                                </button>
                            @endif

                            <x-admin.icon-button icon="check" variant="muted" label="Mark reviewed"
                                                 wire:click="markReviewed({{ $signal->id }})" />
                            <x-admin.icon-button icon="bell-slash" variant="muted" label="Ignore"
                                                 wire:click="ignore({{ $signal->id }})" />
                        @endif
                    </div>
                </div>
            @empty
                <div class="px-5 py-14 text-center">
                    <x-icon name="shield-check" style="solid" class="text-[24px] text-success" />
                    <p class="mt-3 text-[0.9rem] text-paper/45">Nothing unusual</p>
                    <p class="mx-auto mt-1 max-w-[52ch] text-[0.8rem] leading-relaxed text-paper/30">
                        On a site with little traffic this is the expected state. The patterns need something to
                        pattern against.
                    </p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         BLOCKS
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel">
        <div class="border-b border-hairline px-5 py-4">
            <h2 class="text-[0.95rem] font-medium">Blocked addresses</h2>
            <p class="mt-0.5 max-w-[74ch] text-[0.78rem] leading-relaxed text-paper/40">
                An address is not a person. Thousands of phones share one behind carrier NAT, an office shares one,
                and home connections rotate theirs — so a permanent block is a permanent block of a neighbourhood,
                most of whom were never involved, plus whoever inherits the address next month.
                <strong class="text-paper/60">Prefer hours.</strong>
            </p>
        </div>

        <form wire:submit="addBlock" class="flex flex-wrap items-start gap-3 border-b border-hairline px-5 py-4">
            <div class="min-w-[140px]">
                <input type="text" wire:model="newIp" placeholder="203.0.113.42"
                       class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 font-mono text-[0.82rem] placeholder:text-paper/25 focus:outline-none focus:ring-1 focus:ring-brand" />
                @error('newIp') <p class="mt-1 text-[0.74rem] text-danger">{{ $message }}</p> @enderror
            </div>

            <div class="min-w-[200px] flex-1">
                <input type="text" wire:model="newReason" placeholder="Why"
                       class="w-full rounded-lg border-0 bg-raised px-3.5 py-2.5 text-[0.84rem] placeholder:text-paper/25 focus:outline-none focus:ring-1 focus:ring-brand" />
                @error('newReason') <p class="mt-1 text-[0.74rem] text-danger">{{ $message }}</p> @enderror
            </div>

            <select wire:model="duration"
                    class="rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.84rem] focus:outline-none focus:ring-1 focus:ring-brand">
                <option value="1">1 hour</option>
                <option value="24">24 hours</option>
                <option value="168">7 days</option>
                <option value="permanent">Permanent</option>
            </select>

            <button type="submit"
                    class="rounded-lg bg-brand px-4 py-2.5 text-[0.82rem] text-white transition hover:brightness-110">
                Block
            </button>
        </form>

        <div class="divide-y divide-hairline">
            @forelse ($this->blocks as $block)
                <div class="flex items-center gap-3.5 px-5 py-3" wire:key="blk-{{ $block->id }}">
                    <span @class([
                        'size-2 shrink-0 rounded-full',
                        'bg-paper/20' => $block->hasExpired(),
                        'bg-danger' => ! $block->hasExpired() && $block->isPermanent(),
                        'bg-warning' => ! $block->hasExpired() && ! $block->isPermanent(),
                    ])></span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-[0.84rem] text-paper/80">{{ $block->ip_address }}</span>

                            @if ($block->source === 'auto')
                                <span class="rounded-full bg-raised px-2 py-0.5 text-[0.64rem] uppercase tracking-[0.1em] text-paper/40">Auto</span>
                            @endif

                            @if ($block->isPermanent())
                                <span class="rounded-full bg-danger/15 px-2 py-0.5 text-[0.64rem] uppercase tracking-[0.1em] text-danger">Permanent</span>
                            @elseif ($block->hasExpired())
                                <span class="rounded-full bg-raised px-2 py-0.5 text-[0.64rem] uppercase tracking-[0.1em] text-paper/35">Expired</span>
                            @endif
                        </div>

                        <div class="mt-0.5 truncate text-[0.76rem] text-paper/35">
                            {{ $block->reason }}
                            @if ($block->hits > 0)
                                <span class="opacity-60">· {{ number_format($block->hits) }} refused</span>
                            @endif
                        </div>
                    </div>

                    <span class="shrink-0 text-[0.74rem] tabular-nums text-paper/35">
                        {{ $block->isPermanent() ? '—' : ($block->hasExpired() ? 'over' : 'until '.$block->expires_at->diffForHumans(short: true)) }}
                    </span>

                    <x-admin.icon-button icon="trash" variant="muted" label="Remove"
                                         wire:click="unblock({{ $block->id }})" />
                </div>
            @empty
                <p class="px-5 py-12 text-center text-[0.85rem] text-paper/35">Nothing blocked.</p>
            @endforelse
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════════════
         WHAT IS BEING WATCHED

         An abuse screen spends almost all of its life empty. An empty screen
         that does not say what it is looking for is indistinguishable from
         one that is not looking at all — so this is what makes "nothing
         here" mean something.

         Read from the detectors' own constants: a second copy of the numbers
         would disagree with the behaviour the first time either changed.
         ══════════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl border border-hairline bg-panel">
        <div class="border-b border-hairline px-5 py-4">
            <h2 class="text-[0.95rem] font-medium">What is being watched</h2>
            <p class="mt-0.5 max-w-[74ch] text-[0.78rem] leading-relaxed text-paper/40">
                These are the thresholds in force right now. Nothing above means none of them was crossed —
                which on a quiet site is the expected answer, not a fault.
            </p>
        </div>

        <div class="divide-y divide-hairline">
            @foreach ($this->rules as $rule)
                @php $meaning = App\Models\AbuseSignal::KINDS[$rule['kind']] ?? ['label' => $rule['kind'], 'icon' => 'shield-halved']; @endphp

                <div class="flex items-start gap-3.5 px-5 py-4" wire:key="rule-{{ $rule['kind'] }}">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-raised text-paper/50">
                        <x-icon :name="$meaning['icon']" style="solid" class="text-[12px]" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-[0.88rem] text-paper/85">{{ $meaning['label'] }}</span>

                            @unless ($rule['calibrated'])
                                {{-- Said out loud on purpose. Whoever has to
                                     raise this number later needs to know it
                                     was never grounded in anything. --}}
                                <span class="rounded-full bg-warning/15 px-2 py-0.5 text-[0.64rem] font-semibold uppercase tracking-[0.1em] text-warning">
                                    Guess
                                </span>
                            @endunless
                        </div>

                        <p class="mt-1 text-[0.84rem] text-paper/60">
                            {{ $rule['trigger'] }}
                            <span class="text-paper/35">{{ $rule['window'] }}</span>
                        </p>

                        <p class="mt-1.5 max-w-[74ch] text-[0.8rem] leading-relaxed text-paper/35">{{ $rule['why'] }}</p>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- The limits that are always on. Not signals: these REFUSE the
             request rather than filing a report about it, and somebody
             looking at an empty abuse screen is really asking "am I
             protected at all?". This is the honest half of that answer. --}}
        <div class="border-t border-hairline px-5 py-4">
            <div class="micro mb-2.5 text-paper/30">Already refusing requests</div>

            <ul class="space-y-1.5 text-[0.82rem] text-paper/50">
                <li>· 5 sign-in attempts a minute, per email and address</li>
                <li>· 5 two-factor attempts a minute · 10 passkey attempts a minute</li>
                <li>· Downloads, searches and uploads each throttled per account</li>
                <li>· Blocked addresses refused before anything else runs</li>
            </ul>

            <p class="mt-3 text-[0.76rem] leading-relaxed text-paper/30">
                Defined in <code class="rounded bg-raised px-1.5 py-0.5 text-[0.7rem] text-paper/50">FortifyServiceProvider::configureRateLimiting()</code>
                and <code class="rounded bg-raised px-1.5 py-0.5 text-[0.7rem] text-paper/50">AppServiceProvider</code>.
                Every refusal is recorded as a <em>rate-limited</em> row in the access log — which is how you see the limit working
                instead of trusting that it does.
            </p>
        </div>
    </div>

@endif
</div>
