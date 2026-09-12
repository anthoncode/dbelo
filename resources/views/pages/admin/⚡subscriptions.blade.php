<?php

use App\Models\ActivityLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Who is paying, what they have, and how to fix it when PayPal does not.
 *
 * ── WHY THIS SCREEN EXISTS BEFORE THERE IS ANYTHING IN IT ────────────────
 *
 * It will be empty until the PayPal module lands, and that is fine. The
 * reason to have it first is the day a webhook fails: somebody paid, PayPal
 * has their money, and dbelo never heard about it. Without this screen the
 * fix is a SQL client at whatever hour they email you. With it, it is two
 * clicks and a line in the activity log.
 *
 * ── CANCELLING HERE DOES NOT CANCEL AT PAYPAL ────────────────────────────
 *
 * This is the one thing on the screen that can take money from somebody.
 * PayPal holds the billing agreement; this table only records it. Marking a
 * row cancelled stops the ACCESS and does nothing to the CHARGE — so unless
 * the subscription is also cancelled inside PayPal, the person keeps paying
 * for something they can no longer use, every month, until they notice.
 *
 * It is said at the moment of the action, not in a manual, because that is
 * the moment the mistake gets made.
 *
 * ── TWO KINDS OF ENDING, AND THEY ARE NOT THE SAME ───────────────────────
 *
 * "Do not renew" is what a normal cancellation is: the person keeps what
 * they paid for until the period ends, and then it lapses. Cutting them off
 * on the day they cancel is keeping money for a service you stopped
 * providing, which is where disputes come from.
 *
 * "Revoke now" is the harsh one — a refund, a chargeback, an abuse case. It
 * ends access immediately and is styled so nobody reaches for it by
 * accident.
 *
 * The distinction is load-bearing in the data, not just the wording.
 * Subscription::active() requires status = 'active' AND ends_at in the
 * future, so setting status to 'cancelled' cuts access instantly. "Do not
 * renew" therefore leaves the status alone and only stamps cancelled_at —
 * the row lapses on its own when ends_at passes.
 */
new #[Layout('layouts.admin')] #[Title('Subscriptions')] class extends Component {
    use WithPagination;

    #[Url(except: 'all')] public string $status = 'all';
    #[Url(except: '')] public string $plan = '';
    #[Url(as: 'q', except: '')] public string $search = '';

    /** The row a confirmation is open for, and which action. */
    public ?int $confirming = null;
    public string $confirmAction = '';

    // ── Granting by hand ──
    public bool $granting = false;
    public string $grantEmail = '';
    public string $grantPlan = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'plan', 'search'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function ready(): bool
    {
        return Schema::hasTable('subscriptions');
    }

    #[Computed]
    public function rows()
    {
        return Subscription::query()
            ->with(['user:id,name,email', 'plan:id,name,slug,price_cents,interval'])
            ->when($this->status === 'active', fn ($q) => $q->active())
            // Ended, whichever way it ended: the status says so, or the
            // clock ran out while the status still said active.
            ->when($this->status === 'ended', fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('status', ['cancelled', 'expired', 'suspended'])
                ->orWhere('ends_at', '<=', now())))
            // Cancelled but still inside the period they paid for. The group
            // most worth seeing: they are leaving, and they are still here.
            ->when($this->status === 'leaving', fn ($q) => $q->whereNotNull('cancelled_at')
                ->where('status', 'active')
                ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now())))
            ->when($this->plan, fn ($q, $p) => $q->where('plan_id', $p))
            ->when($this->search, fn ($q, $s) => $q->whereHas('user', fn ($u) => $u
                ->where('email', 'like', "%{$s}%")
                ->orWhere('name', 'like', "%{$s}%")))
            ->latest('id')
            ->paginate(10);
    }

    #[Computed]
    public function totals(): array
    {
        if (! $this->ready) {
            return ['active' => 0, 'leaving' => 0, 'ended' => 0, 'manual' => 0];
        }

        return [
            'active' => Subscription::active()->count(),
            'leaving' => Subscription::whereNotNull('cancelled_at')
                ->where('status', 'active')
                ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->count(),
            'ended' => Subscription::where(fn ($w) => $w
                ->whereIn('status', ['cancelled', 'expired', 'suspended'])
                ->orWhere('ends_at', '<=', now()))->count(),
            // Comped, or repaired after a webhook went missing. Worth its own
            // number: these are subscriptions with no money behind them, and
            // counting them inside "active" would flatter every report.
            'manual' => Subscription::where('gateway', 'manual')->count(),
        ];
    }

    #[Computed]
    public function plans()
    {
        return Plan::where('price_cents', '>', 0)->orderBy('sort_order')->get();
    }

    /* ═══════════════════════════ Ending one ═══════════════════════════ */

    public function confirm(int $id, string $action): void
    {
        $this->confirming = $id;
        $this->confirmAction = in_array($action, ['lapse', 'revoke'], true) ? $action : '';
    }

    public function dismiss(): void
    {
        $this->confirming = null;
        $this->confirmAction = '';
    }

    /**
     * "Do not renew" — keeps the access they already paid for.
     *
     * status is deliberately NOT touched. active() reads status AND ends_at
     * together, so leaving the status alone is what lets the row keep
     * granting until the period it was paid for actually runs out.
     */
    public function lapse(int $id): void
    {
        $sub = Subscription::with(['user', 'plan'])->find($id);

        if (! $sub || $sub->cancelled_at) {
            $this->dismiss();

            return;
        }

        $sub->update(['cancelled_at' => now()]);

        ActivityLog::record('subscription.cancelled', $sub->user,
            $sub->user?->email.' — '.$sub->plan?->name.' set to not renew',
            ['subscription' => $sub->id, 'mode' => 'lapse', 'ends_at' => $sub->ends_at?->toDateTimeString(), 'gateway' => $sub->gateway]);

        unset($this->rows, $this->totals);
        $this->dismiss();

        session()->flash('ok', 'Set to not renew. They keep access until the period ends.');
    }

    /** The harsh one: access stops now. Refunds, chargebacks, abuse. */
    public function revoke(int $id): void
    {
        $sub = Subscription::with(['user', 'plan'])->find($id);

        if (! $sub) {
            $this->dismiss();

            return;
        }

        $sub->update([
            'status' => 'cancelled',
            'cancelled_at' => $sub->cancelled_at ?? now(),
            // Both, on purpose: status alone would be enough for active(),
            // but a row whose ends_at is still months away reads as a live
            // subscription to anything that looks at dates rather than state.
            'ends_at' => now(),
        ]);

        ActivityLog::record('subscription.cancelled', $sub->user,
            $sub->user?->email.' — '.$sub->plan?->name.' revoked immediately',
            ['subscription' => $sub->id, 'mode' => 'revoke', 'gateway' => $sub->gateway]);

        unset($this->rows, $this->totals);
        $this->dismiss();

        session()->flash('ok', 'Access ended now.');
    }

    /* ═══════════════════════════ Granting one ═══════════════════════════ */

    public function grant(): void
    {
        $this->validate([
            'grantEmail' => ['required', 'email'],
            'grantPlan' => ['required', 'exists:plans,id'],
        ], [
            'grantPlan.required' => 'Pick a plan.',
        ]);

        $user = User::where('email', $this->grantEmail)->first();

        if (! $user) {
            $this->addError('grantEmail', 'No account with that address. They have to register first — a subscription hangs off a user.');

            return;
        }

        $plan = Plan::find($this->grantPlan);

        if ($user->activeSubscription()) {
            $this->addError('grantEmail', 'That account already has an active subscription. End the current one first, or you will have two and only one of them will be read.');

            return;
        }

        // Derived from the plan rather than typed, so a day pass cannot be
        // granted for a year because somebody left a field alone.
        $ends = match ($plan->interval) {
            'day' => now()->addDay(),
            'week' => now()->addWeek(),
            'year' => now()->addYear(),
            default => now()->addMonth(),
        };

        $sub = Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            // NOT 'paypal'. Nothing was charged, and a revenue report that
            // cannot tell a gift from a sale is a revenue report that lies.
            'gateway' => 'manual',
            'external_id' => null,
            'starts_at' => now(),
            'ends_at' => $ends,
        ]);

        ActivityLog::record('subscription.granted', $user,
            $user->email.' — '.$plan->name.' granted by hand until '.$ends->toDateString(),
            ['subscription' => $sub->id, 'plan' => $plan->slug, 'ends_at' => $ends->toDateTimeString()]);

        $this->reset(['granting', 'grantEmail', 'grantPlan']);
        unset($this->rows, $this->totals);

        session()->flash('ok', $user->email.' now has '.$plan->name.' until '.$ends->toFormattedDateString().'.');
    }

    /* ═══════════════════════════ Reading a row ═══════════════════════════ */

    /** @return array{label: string, tone: string} */
    public function state(Subscription $sub): array
    {
        if ($sub->status !== 'active') {
            return ['label' => ucfirst($sub->status), 'tone' => 'muted'];
        }

        if ($sub->ends_at && $sub->ends_at->isPast()) {
            return ['label' => 'Lapsed', 'tone' => 'muted'];
        }

        if ($sub->cancelled_at) {
            return ['label' => 'Leaving', 'tone' => 'warning'];
        }

        return ['label' => 'Active', 'tone' => 'success'];
    }
}; ?>

<div class="space-y-5">

    @if (! $this->ready)
        <x-admin.migration-pending what="Subscriptions" table="subscriptions" />
    @else

        @if (session('ok'))
            <div class="rounded-2xl border border-success/25 bg-success/[0.06] px-5 py-3.5 text-[0.86rem] text-paper/75">
                <x-icon name="circle-check" style="solid" class="mr-1.5 text-[0.8rem] text-success" />
                {{ session('ok') }}
            </div>
        @endif

        {{-- ══════════════════════ Totals ══════════════════════ --}}
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['Active', $this->totals['active'], 'circle-check', 'success', 'Paying and able to download.'],
                ['Leaving', $this->totals['leaving'], 'hourglass-half', 'warning', 'Cancelled, still inside the period they paid for.'],
                ['Ended', $this->totals['ended'], 'circle-xmark', 'muted', 'Lapsed, cancelled or suspended.'],
                ['By hand', $this->totals['manual'], 'gift', 'brand', 'Granted with no payment behind them.'],
            ] as [$label, $value, $icon, $tone, $help])
                <div class="rounded-2xl border border-hairline bg-panel px-5 py-4" wire:key="tot-{{ $label }}">
                    <div class="flex items-center gap-3">
                        <x-admin.icon-chip :icon="$icon" :tone="$tone" />
                        <div class="min-w-0 flex-1">
                            <div class="text-[1.3rem] font-medium tabular-nums leading-none">{{ $value }}</div>
                            <div class="mt-1 text-[0.78rem] text-paper/45">{{ $label }}</div>
                        </div>
                    </div>
                    <p class="mt-2.5 text-[0.73rem] leading-relaxed text-paper/30">{{ $help }}</p>
                </div>
            @endforeach
        </div>

        {{-- ══════════════════════ Grant by hand ══════════════════════ --}}
        <div class="rounded-2xl border border-hairline bg-panel">
            <button type="button" wire:click="$toggle('granting')"
                    class="flex w-full items-center gap-3 px-5 py-4 text-left transition hover:bg-paper/[0.03]">
                <x-admin.icon-chip icon="gift" tone="brand" />
                <div class="min-w-0 flex-1">
                    <div class="text-[0.92rem]">Give somebody a subscription</div>
                    <p class="mt-0.5 text-[0.78rem] text-paper/40">
                        For a payment PayPal took and never told us about, or for somebody you want to comp.
                    </p>
                </div>
                <x-icon :name="$granting ? 'chevron-up' : 'chevron-down'" style="regular" class="shrink-0 text-[11px] text-paper/30" />
            </button>

            @if ($granting)
                <form wire:submit="grant" class="space-y-4 border-t border-hairline px-5 py-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-1.5 block text-[0.78rem] text-paper/50">Account email</span>
                            <input type="email" wire:model="grantEmail" placeholder="them@example.com"
                                   class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.86rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                            @error('grantEmail') <span class="mt-1 block text-[0.75rem] text-danger">{{ $message }}</span> @enderror
                        </label>

                        <label class="block">
                            <span class="mb-1.5 block text-[0.78rem] text-paper/50">Plan</span>
                            <select wire:model="grantPlan"
                                    class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.86rem] focus:outline-none focus:ring-1 focus:ring-brand">
                                <option value="">Choose…</option>
                                @foreach ($this->plans as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }} — {{ $p->priceForHumans() }} / {{ $p->interval }}</option>
                                @endforeach
                            </select>
                            @error('grantPlan') <span class="mt-1 block text-[0.75rem] text-danger">{{ $message }}</span> @enderror
                        </label>
                    </div>

                    {{-- Said before the button, because afterwards it is a
                         support conversation. --}}
                    <p class="rounded-lg border border-hairline bg-raised/40 px-3.5 py-2.5 text-[0.75rem] leading-relaxed text-paper/45">
                        This charges nobody. It is recorded with
                        <span class="font-mono text-paper/65">gateway = manual</span> so it can be told apart from a real
                        sale later, and the end date comes from the plan's own interval — a day pass ends tomorrow, a
                        yearly ends next year. It does not renew.
                    </p>

                    <div class="flex items-center gap-3">
                        <button type="submit" class="rounded-lg bg-action px-4 py-2 text-[0.85rem] font-medium text-white transition hover:opacity-90">
                            Grant
                        </button>
                        <button type="button" wire:click="$set('granting', false)" class="text-[0.82rem] text-paper/40 transition hover:text-paper">
                            Cancel
                        </button>
                    </div>
                </form>
            @endif
        </div>

        {{-- ══════════════════════ Filters ══════════════════════ --}}
        <div class="flex flex-wrap items-center gap-2.5">
            @foreach ([
                'all' => 'All',
                'active' => 'Active',
                'leaving' => 'Leaving',
                'ended' => 'Ended',
            ] as $key => $label)
                <button type="button" wire:click="$set('status', '{{ $key }}')"
                        @class([
                            'rounded-lg px-3.5 py-2 text-[0.83rem] transition',
                            'bg-raised text-paper' => $status === $key,
                            'text-paper/45 hover:bg-raised/60 hover:text-paper/80' => $status !== $key,
                        ])
                        wire:key="tab-{{ $key }}">{{ $label }}</button>
            @endforeach

            <div class="ml-auto flex flex-wrap items-center gap-2.5">
                <div class="flex w-56 items-center gap-2.5 rounded-lg bg-raised px-3 py-2">
                    <x-icon name="magnifying-glass" style="regular" class="text-[12px] text-paper/35" />
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name or email…"
                           class="w-full border-0 bg-transparent p-0 text-[0.85rem] text-paper placeholder:text-paper/30 focus:outline-none focus:ring-0" />
                </div>

                <select wire:model.live="plan"
                        class="rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.83rem] text-paper focus:outline-none focus:ring-2 focus:ring-brand/40">
                    <option value="">All plans</option>
                    @foreach ($this->plans as $p)
                        <option value="{{ $p->id }}">{{ $p->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        {{-- ══════════════════════ The table ══════════════════════ --}}
        <div class="overflow-hidden rounded-2xl border border-hairline bg-panel">
            @forelse ($this->rows as $sub)
                @php $s = $this->state($sub); @endphp

                <div class="border-b border-hairline last:border-0" wire:key="sub-{{ $sub->id }}">
                    <div class="flex flex-wrap items-center gap-4 px-5 py-4">

                        <div class="min-w-[12rem] flex-1">
                            <a href="{{ route('admin.users.show', $sub->user) }}" wire:navigate
                               class="block truncate text-[0.89rem] transition hover:text-brand">{{ $sub->user?->name ?? 'Deleted account' }}</a>
                            <div class="mt-0.5 truncate text-[0.76rem] text-paper/35">{{ $sub->user?->email }}</div>
                        </div>

                        <div class="w-32 shrink-0">
                            <div class="text-[0.84rem] text-paper/75">{{ $sub->plan?->name }}</div>
                            <div class="text-[0.74rem] text-paper/35">{{ $sub->plan?->priceForHumans() }}</div>
                        </div>

                        <div class="w-24 shrink-0">
                            <span @class([
                                'rounded-full px-2.5 py-0.5 text-[0.72rem] font-medium',
                                'bg-success/15 text-success' => $s['tone'] === 'success',
                                'bg-warning/15 text-warning' => $s['tone'] === 'warning',
                                'bg-raised text-paper/45' => $s['tone'] === 'muted',
                            ])>{{ $s['label'] }}</span>
                        </div>

                        <div class="w-40 shrink-0 text-[0.76rem] text-paper/40">
                            <div>{{ $sub->starts_at?->format('j M Y') ?? '—' }}</div>
                            <div class="text-paper/30">
                                {{ $sub->ends_at ? ($sub->ends_at->isFuture() ? 'until ' : 'ended ').$sub->ends_at->format('j M Y') : 'no end date' }}
                            </div>
                        </div>

                        <div class="w-28 shrink-0">
                            @if ($sub->gateway === 'manual')
                                <span class="rounded-full bg-brand/12 px-2 py-0.5 text-[0.68rem] font-medium text-brand">By hand</span>
                            @else
                                <div class="truncate text-[0.72rem] text-paper/35">{{ $sub->gateway ?? '—' }}</div>
                                @if ($sub->external_id)
                                    <div class="truncate font-mono text-[0.68rem] text-paper/25" title="{{ $sub->external_id }}">{{ $sub->external_id }}</div>
                                @endif
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-1.5">
                            @if ($sub->status === 'active' && ! $sub->cancelled_at)
                                <x-admin.icon-button icon="hourglass-half" variant="ghost" label="Do not renew"
                                                     wire:click="confirm({{ $sub->id }}, 'lapse')" />
                            @endif

                            @if ($s['label'] !== 'Lapsed' && $sub->status === 'active')
                                <x-admin.icon-button icon="ban" variant="muted" label="Revoke now"
                                                     wire:click="confirm({{ $sub->id }}, 'revoke')" />
                            @endif
                        </div>
                    </div>

                    {{-- ── Confirmation, inline ──
                         In the row rather than a modal: the thing you are
                         about to end is the thing you are looking at. --}}
                    @if ($confirming === $sub->id)
                        <div @class([
                            'border-t px-5 py-4',
                            'border-warning/25 bg-warning/[0.06]' => $confirmAction === 'lapse',
                            'border-danger/25 bg-danger/[0.06]' => $confirmAction === 'revoke',
                        ])>
                            @if ($confirmAction === 'lapse')
                                <p class="max-w-[80ch] text-[0.82rem] leading-relaxed text-paper/65">
                                    <strong class="text-paper/85">They keep access until
                                        {{ $sub->ends_at?->format('j M Y') ?? 'the period ends' }}</strong>, then it lapses on its own.
                                </p>
                            @else
                                <p class="max-w-[80ch] text-[0.82rem] leading-relaxed text-paper/65">
                                    <strong class="text-danger">Access stops immediately</strong>, including the part of the period
                                    they already paid for. Use this for a refund, a chargeback or an abuse case — not for somebody
                                    who simply asked to cancel.
                                </p>
                            @endif

                            @if ($sub->gateway && $sub->gateway !== 'manual')
                                {{-- The sentence that stops you taking money
                                     from somebody who thinks they left. --}}
                                <p class="mt-3 max-w-[80ch] rounded-lg border border-danger/25 bg-danger/[0.07] px-3.5 py-2.5 text-[0.78rem] leading-relaxed text-danger">
                                    <x-icon name="triangle-exclamation" style="solid" class="mr-1 text-[0.7rem]" />
                                    This does <strong>not</strong> cancel anything at {{ $sub->gateway }}. The billing agreement lives
                                    there, and this table only records it — so unless you cancel it in
                                    {{ $sub->gateway }} too, they keep being charged for something they can no longer use.
                                    @if ($sub->external_id)
                                        Their id there is <span class="font-mono">{{ $sub->external_id }}</span>.
                                    @endif
                                </p>
                            @endif

                            <div class="mt-3.5 flex items-center gap-3">
                                <button type="button"
                                        wire:click="{{ $confirmAction === 'lapse' ? 'lapse' : 'revoke' }}({{ $sub->id }})"
                                        @class([
                                            'rounded-lg px-4 py-2 text-[0.83rem] font-medium text-white transition hover:opacity-90',
                                            'bg-warning' => $confirmAction === 'lapse',
                                            'bg-danger' => $confirmAction === 'revoke',
                                        ])>
                                    {{ $confirmAction === 'lapse' ? 'Set to not renew' : 'Revoke access now' }}
                                </button>

                                <button type="button" wire:click="dismiss"
                                        class="text-[0.82rem] text-paper/40 transition hover:text-paper">Cancel</button>
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <div class="px-5 py-12 text-center">
                    <span class="mx-auto grid size-11 place-items-center rounded-full bg-raised text-paper/25">
                        <x-icon name="credit-card" style="regular" class="text-[0.95rem]" />
                    </span>
                    <p class="mx-auto mt-3 max-w-[46ch] text-[0.83rem] leading-relaxed text-paper/45">
                        @if ($status !== 'all' || $search || $plan)
                            Nothing matches that filter.
                        @else
                            No subscriptions yet — there is no way to buy one until the PayPal module is built.
                            Until then, the form above is how somebody gets access.
                        @endif
                    </p>
                </div>
            @endforelse
        </div>

        @if ($this->rows->hasPages())
            <x-admin.pagination :paginator="$this->rows" />
        @endif
    @endif
</div>
