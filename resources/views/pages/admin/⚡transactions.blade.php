<?php

use App\Models\Transaction;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The ledger, and the only place the real revenue number lives.
 *
 * ── GROSS IS THE NUMBER THAT FEELS GOOD, NET IS THE ONE THAT PAYS ────────
 *
 * Every other screen in this panel could show gross and be wrong by a
 * tenth. PayPal keeps 3.49% + $0.49, plus 1.5% when the buyer is abroad, and
 * the flat part means the share it keeps DEPENDS ON THE PRICE: about 5% of
 * an $89 annual and about 11% of a $9 day pass. No single rate can be
 * assumed, which is why the fee is stored per row and totalled here rather
 * than calculated anywhere.
 *
 * So this screen leads with net and shows gross beside it. The gap between
 * them is a real cost and deserves to be looked at, not hidden behind one
 * confident-looking figure.
 *
 * ── REFUNDS ARE EXCLUDED, NOT SUBTRACTED ─────────────────────────────────
 *
 * Transaction::earned() takes only completed rows. A refunded payment is not
 * a smaller sale, it is a sale that stopped existing, and averaging the two
 * into one total describes neither. Refunds get their own figure.
 *
 * ── AND IT WILL BE EMPTY FOR A WHILE ─────────────────────────────────────
 *
 * Nothing writes to this table until the PayPal webhook handler exists. The
 * schema is here first so that module has a target to write into, and so
 * that the first payment has somewhere to land instead of somewhere to be
 * discovered missing.
 */
new #[Layout('layouts.admin')] #[Title('Transactions')] class extends Component {
    use WithPagination;

    #[Url(except: 'all')] public string $status = 'all';
    #[Url(except: '30d')] public string $period = '30d';
    #[Url(as: 'q', except: '')] public string $search = '';

    /** The row whose raw payload is open. */
    public ?int $inspecting = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'period', 'search'], true)) {
            $this->resetPage();
            $this->inspecting = null;
        }
    }

    #[Computed]
    public function ready(): bool
    {
        return Schema::hasTable('transactions');
    }

    /** The cut-off for the chosen window, or null for everything. */
    private function since(): ?string
    {
        return match ($this->period) {
            '30d' => now()->subDays(30)->toDateTimeString(),
            'month' => now()->startOfMonth()->toDateTimeString(),
            'year' => now()->startOfYear()->toDateTimeString(),
            default => null,
        };
    }

    #[Computed]
    public function rows()
    {
        return Transaction::query()
            ->with(['user:id,name,email'])
            ->inPeriod($this->since())
            ->when($this->status !== 'all', fn ($q) => $this->status === 'refunded'
                ? $q->whereIn('status', ['refunded', 'partially_refunded'])
                : $q->where('status', $this->status))
            ->when($this->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('email', 'like', "%{$s}%")
                ->orWhere('external_id', 'like', "%{$s}%")
                ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$s}%"))))
            // paid_at, not created_at: a webhook that lands at 00:04 on the
            // first belongs to last month's total, not this one.
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /**
     * The money, for the window on screen.
     *
     * One grouped query rather than five counts — this runs on every render
     * of a screen whose whole purpose is to be filtered.
     */
    #[Computed]
    public function totals(): array
    {
        if (! $this->ready) {
            return ['gross' => 0, 'fees' => 0, 'net' => 0, 'refunded' => 0, 'sales' => 0];
        }

        $earned = Transaction::earned()->inPeriod($this->since())
            ->selectRaw('COALESCE(SUM(amount_cents),0) g, COALESCE(SUM(fee_cents),0) f, COALESCE(SUM(net_cents),0) n, COUNT(*) c')
            ->first();

        $refunded = (int) Transaction::whereIn('status', ['refunded', 'partially_refunded'])
            ->inPeriod($this->since())
            ->sum('refunded_cents');

        return [
            'gross' => (int) $earned->g,
            'fees' => (int) $earned->f,
            'net' => (int) $earned->n,
            'sales' => (int) $earned->c,
            'refunded' => $refunded,
        ];
    }

    public function money(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }

    public function inspect(int $id): void
    {
        $this->inspecting = $this->inspecting === $id ? null : $id;
    }
}; ?>

<div class="space-y-5">

    @if (! $this->ready)
        <x-admin.migration-pending what="Transactions" table="transactions" />
    @else

        {{-- ══════════════════════ The money ══════════════════════
             Net first and largest. Every other screen can afford to show
             gross; this one is where the difference has to be visible. --}}
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl border border-success/25 bg-success/[0.05] px-5 py-4">
                <div class="flex items-center gap-3">
                    <x-admin.icon-chip icon="wallet" tone="success" />
                    <div class="min-w-0 flex-1">
                        <div class="text-[1.5rem] font-medium tabular-nums leading-none text-success">{{ $this->money($this->totals['net']) }}</div>
                        <div class="mt-1 text-[0.78rem] text-paper/50">Yours</div>
                    </div>
                </div>
                <p class="mt-2.5 text-[0.73rem] leading-relaxed text-paper/35">
                    What landed after the gateway took its cut. This is the number that pays the server.
                </p>
            </div>

            @foreach ([
                ['Charged', $this->totals['gross'], 'receipt', 'muted', 'What buyers were charged, before fees.'],
                ['Fees', $this->totals['fees'], 'scissors', 'warning', 'What PayPal kept. The flat $0.49 makes this hurt most on small sales.'],
                ['Refunded', $this->totals['refunded'], 'arrow-rotate-left', 'danger', 'Money given back. Excluded from the totals, not subtracted from them.'],
            ] as [$label, $value, $icon, $tone, $help])
                <div class="rounded-2xl border border-hairline bg-panel px-5 py-4" wire:key="tot-{{ $label }}">
                    <div class="flex items-center gap-3">
                        <x-admin.icon-chip :icon="$icon" :tone="$tone" />
                        <div class="min-w-0 flex-1">
                            <div class="text-[1.3rem] font-medium tabular-nums leading-none">{{ $this->money($value) }}</div>
                            <div class="mt-1 text-[0.78rem] text-paper/45">{{ $label }}</div>
                        </div>
                    </div>
                    <p class="mt-2.5 text-[0.73rem] leading-relaxed text-paper/30">{{ $help }}</p>
                </div>
            @endforeach
        </div>

        @if ($this->totals['sales'] > 0)
            <p class="px-1 text-[0.78rem] text-paper/35">
                {{ $this->totals['sales'] }} {{ $this->totals['sales'] === 1 ? 'sale' : 'sales' }} ·
                the gateway kept
                <span class="text-paper/60">{{ number_format($this->totals['gross'] > 0 ? $this->totals['fees'] / $this->totals['gross'] * 100 : 0, 1) }}%</span>
                of what was charged ·
                average sale {{ $this->money((int) round($this->totals['net'] / max(1, $this->totals['sales']))) }} net
            </p>
        @endif

        {{-- ══════════════════════ Filters ══════════════════════ --}}
        <div class="flex flex-wrap items-center gap-2.5">
            @foreach (['30d' => 'Last 30 days', 'month' => 'This month', 'year' => 'This year', 'all' => 'All time'] as $key => $label)
                <button type="button" wire:click="$set('period', '{{ $key }}')"
                        @class([
                            'rounded-lg px-3.5 py-2 text-[0.83rem] transition',
                            'bg-raised text-paper' => $period === $key,
                            'text-paper/45 hover:bg-raised/60 hover:text-paper/80' => $period !== $key,
                        ])
                        wire:key="per-{{ $key }}">{{ $label }}</button>
            @endforeach

            <div class="ml-auto flex flex-wrap items-center gap-2.5">
                <div class="flex w-60 items-center gap-2.5 rounded-lg bg-raised px-3 py-2">
                    <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Email or gateway id…"
                           class="w-full border-0 bg-transparent p-0 text-[0.85rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-0" />
                </div>

                <select wire:model.live="status"
                        class="rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.83rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                    <option value="all">Every status</option>
                    <option value="completed">Completed</option>
                    <option value="refunded">Refunded</option>
                    <option value="pending">Pending</option>
                    <option value="failed">Failed</option>
                </select>
            </div>
        </div>

        {{-- ══════════════════════ The rows ══════════════════════ --}}
        <div class="overflow-hidden rounded-2xl border border-hairline bg-panel">
            @forelse ($this->rows as $tx)
                <div class="border-b border-hairline last:border-0" wire:key="tx-{{ $tx->id }}">
                    <div class="flex flex-wrap items-center gap-4 px-5 py-3.5">

                        <div class="w-28 shrink-0 text-[0.78rem] text-paper/45">
                            {{ $tx->paid_at?->format('j M Y') ?? '—' }}
                            <div class="text-[0.7rem] text-paper/25">{{ $tx->paid_at?->format('H:i') }}</div>
                        </div>

                        <div class="min-w-[11rem] flex-1">
                            {{-- The snapshot email, falling back to the live
                                 account. The row has to still say who paid
                                 after the account is gone. --}}
                            <div class="truncate text-[0.85rem]">{{ $tx->email ?? $tx->user?->email ?? 'Unknown' }}</div>
                            <div class="mt-0.5 truncate text-[0.74rem] text-paper/35">
                                {{ $tx->plan_name ?? '—' }}
                                @if ($tx->type === 'one_time')
                                    <span class="ml-1 rounded-full bg-raised px-1.5 py-0.5 text-[0.65rem] text-paper/45">one-off</span>
                                @endif
                            </div>
                        </div>

                        <div class="w-20 shrink-0 text-right">
                            <div class="text-[0.86rem] tabular-nums">{{ $tx->amountForHumans() }}</div>
                            <div class="text-[0.7rem] tabular-nums text-paper/30">charged</div>
                        </div>

                        <div class="w-20 shrink-0 text-right">
                            <div class="text-[0.86rem] tabular-nums text-warning/80">−{{ $this->money($tx->fee_cents) }}</div>
                            <div class="text-[0.7rem] tabular-nums text-paper/30">
                                {{ $tx->feeRate() !== null ? number_format($tx->feeRate(), 1).'%' : 'fee' }}
                            </div>
                        </div>

                        <div class="w-20 shrink-0 text-right">
                            <div class="text-[0.86rem] font-medium tabular-nums">{{ $tx->netForHumans() }}</div>
                            <div class="text-[0.7rem] tabular-nums text-paper/30">yours</div>
                        </div>

                        <div class="w-24 shrink-0">
                            <span @class([
                                'rounded-full px-2.5 py-0.5 text-[0.72rem] font-medium',
                                'bg-success/15 text-success' => $tx->status === 'completed',
                                'bg-danger/15 text-danger' => $tx->isRefunded(),
                                'bg-warning/15 text-warning' => $tx->status === 'pending',
                                'bg-raised text-paper/45' => $tx->status === 'failed',
                            ])>{{ ucfirst(str_replace('_', ' ', $tx->status)) }}</span>
                        </div>

                        <div class="flex w-28 shrink-0 items-center justify-end gap-1.5">
                            @if ($tx->external_id)
                                <span class="truncate font-mono text-[0.68rem] text-paper/25" title="{{ $tx->external_id }}">{{ Str::limit($tx->external_id, 10) }}</span>
                            @endif

                            @if ($tx->payload)
                                <x-admin.icon-button icon="code" variant="ghost" label="Raw event"
                                                     wire:click="inspect({{ $tx->id }})" />
                            @endif
                        </div>
                    </div>

                    {{-- ── The raw event ──
                         Here because the answer to "why does this row say
                         that" is always in the payload, and going to look for
                         it in a log file is how a five-minute question
                         becomes an afternoon. --}}
                    @if ($inspecting === $tx->id)
                        <div class="border-t border-hairline bg-canvas/40 px-5 py-4">
                            <div class="mb-2 text-[0.72rem] uppercase tracking-[0.14em] text-paper/30">
                                As {{ $tx->gateway }} sent it
                            </div>
<pre class="max-h-80 overflow-auto rounded-lg bg-canvas px-3.5 py-3 font-mono text-[0.72rem] leading-relaxed text-paper/55">{{ json_encode($tx->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        </div>
                    @endif
                </div>
            @empty
                <div class="px-5 py-12 text-center">
                    <span class="mx-auto grid size-11 place-items-center rounded-full bg-raised text-paper/25">
                        <x-icon name="receipt" style="regular" class="text-[0.95rem]" />
                    </span>
                    <p class="mx-auto mt-3 max-w-[52ch] text-[0.83rem] leading-relaxed text-paper/45">
                        @if ($status !== 'all' || $search)
                            Nothing matches that filter.
                        @else
                            No payments yet. Nothing writes to this table until the PayPal webhook handler exists —
                            the schema is here first so the first payment has somewhere to land.
                        @endif
                    </p>
                </div>
            @endforelse
        </div>

        @if ($this->rows->hasPages())
            <x-admin.pagination :paginator="$this->rows" />
        @endif

        {{-- ══════════════════════════════════════════════════════════════
             WHAT THIS SCREEN DELIBERATELY CANNOT DO
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel px-5 py-4">
            <div class="text-[0.9rem] text-paper/70">Nothing here can be edited, and no refund starts here</div>
            <p class="mt-1.5 max-w-[85ch] text-[0.8rem] leading-relaxed text-paper/40">
                A ledger that gets corrected in place cannot be reconciled against the gateway that holds the real
                record — the moment these two disagree, this one is wrong and there is no way to prove it. Rows are
                written by the webhook handler and never touched again.
                <span class="text-paper/60">Refunds are issued inside PayPal</span>, and the refund webhook updates the
                row here. Doing it the other way round would mark money returned that never left.
            </p>
        </div>
    @endif
</div>
