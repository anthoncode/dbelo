<?php

use App\Models\ActivityLog;
use App\Models\Coupon;
use App\Models\Plan;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Codes that grant days of a plan.
 *
 * ── THEY DO NOT DISCOUNT ANYTHING, AND THAT IS NOT A SHORTCUT ────────────
 *
 * PayPal has no coupon concept: a trial period belongs to a plan and applies
 * to everybody who signs up through it, and price changes move the plan for
 * every subscriber at once. There is no way to take twenty per cent off one
 * person's subscription, so a percentage field here would be a control that
 * could never do anything.
 *
 * Granting days needs no gateway at all. A redemption writes an ordinary
 * subscriptions row with gateway = 'coupon', which every access check
 * already understands, and nothing about it can be blocked by an API.
 *
 * ── REDEEMING HAPPENS ELSEWHERE ──────────────────────────────────────────
 *
 * The rule lives in Coupon::redeemFor(), locked and transactional, because
 * it will have two callers: the public form that comes with checkout, and
 * support acting for somebody. This screen creates and retires codes; it
 * does not redeem them. A rule written twice disagrees with itself the first
 * time one copy is fixed.
 *
 * ── CODES ARE RETIRED, NOT DELETED ───────────────────────────────────────
 *
 * Deleting one takes its redemptions with it, and those are the only record
 * of who was given what. Switching a code off stops it dead and keeps the
 * history, which is what somebody actually wants nine times out of ten.
 * Deleting is still possible for a code nobody used.
 */
new #[Layout('layouts.admin')] #[Title('Coupons')] class extends Component {
    use WithPagination;

    #[Url(except: 'all')] public string $filter = 'all';

    // ── The new one ──
    public string $code = '';
    public string $planId = '';
    public string $days = '30';
    public string $maxRedemptions = '';
    public string $expiresAt = '';
    public string $note = '';

    public ?int $confirmingDelete = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $this->code = Coupon::suggestCode();
    }

    public function updated(string $property): void
    {
        if ($property === 'filter') {
            $this->resetPage();
        }
    }

    #[Computed]
    public function ready(): bool
    {
        return Schema::hasTable('coupons');
    }

    #[Computed]
    public function rows()
    {
        return Coupon::query()
            ->with(['plan:id,name,slug'])
            ->when($this->filter === 'usable', fn ($q) => $q->usable())
            // Everything that cannot be redeemed right now, for any of the
            // four reasons — off, not started, expired, or used up.
            ->when($this->filter === 'spent', fn ($q) => $q->where(fn ($w) => $w
                ->where('is_active', false)
                ->orWhere('expires_at', '<=', now())
                ->orWhereColumn('redemptions', '>=', 'max_redemptions')))
            ->latest('id')
            ->paginate(10);
    }

    #[Computed]
    public function plans()
    {
        return Plan::where('price_cents', '>', 0)->orderBy('sort_order')->get();
    }

    public function reroll(): void
    {
        $this->code = Coupon::suggestCode();
    }

    public function create(): void
    {
        $this->code = Coupon::normalise($this->code);

        $this->validate([
            'code' => ['required', 'string', 'min:4', 'max:40', 'regex:/^[A-Z0-9-]+$/', 'unique:coupons,code'],
            'planId' => ['required', 'exists:plans,id'],
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
            'maxRedemptions' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'expiresAt' => ['nullable', 'date', 'after:today'],
            'note' => ['nullable', 'string', 'max:120'],
        ], [
            'code.regex' => 'Letters, numbers and dashes only — this gets typed by hand.',
            'code.unique' => 'There is already a code with that name.',
            'planId.required' => 'Which plan does it grant?',
            'expiresAt.after' => 'An expiry in the past would make a code nobody can use.',
        ]);

        $coupon = Coupon::create([
            'code' => $this->code,
            'plan_id' => $this->planId,
            'days' => (int) $this->days,
            'max_redemptions' => $this->maxRedemptions === '' ? null : (int) $this->maxRedemptions,
            'expires_at' => $this->expiresAt ?: null,
            'note' => trim($this->note) ?: null,
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);

        ActivityLog::record('coupon.created', null,
            $coupon->code.' — '.$coupon->days.' days of '.$coupon->plan?->name,
            ['coupon' => $coupon->code, 'plan' => $coupon->plan?->slug, 'max' => $coupon->max_redemptions]);

        $this->reset(['planId', 'maxRedemptions', 'expiresAt', 'note']);
        $this->days = '30';
        $this->code = Coupon::suggestCode();

        unset($this->rows);

        session()->flash('ok', $coupon->code.' created.');
    }

    /** Off, or back on. The history stays either way. */
    public function toggle(int $id): void
    {
        $coupon = Coupon::find($id);

        if (! $coupon) {
            return;
        }

        $coupon->update(['is_active' => ! $coupon->is_active]);

        ActivityLog::record('coupon.updated', null,
            $coupon->code.($coupon->is_active ? ' switched on' : ' switched off'),
            ['coupon' => $coupon->code]);

        unset($this->rows);
    }

    public function delete(int $id): void
    {
        $coupon = Coupon::withCount('claims')->find($id);

        if (! $coupon) {
            return;
        }

        // Refused rather than cascaded. Those rows are the only record of
        // who was given what, and a code with redemptions is history.
        if ($coupon->claims_count > 0) {
            session()->flash('ok', $coupon->code.' has been used '.$coupon->claims_count.' time(s), so it was switched off instead of deleted.');
            $coupon->update(['is_active' => false]);
            $this->confirmingDelete = null;
            unset($this->rows);

            return;
        }

        $code = $coupon->code;
        $coupon->delete();

        ActivityLog::record('coupon.deleted', null, $code.' deleted', ['coupon' => $code]);

        $this->confirmingDelete = null;
        unset($this->rows);

        session()->flash('ok', $code.' deleted.');
    }
}; ?>

<div class="space-y-5">

    @if (! $this->ready)
        <x-admin.migration-pending what="Coupons" table="coupons" />
    @else

        @if (session('ok'))
            <div class="rounded-2xl border border-success/25 bg-success/[0.06] px-5 py-3.5 text-[0.86rem] text-paper/75">
                <x-icon name="circle-check" style="solid" class="mr-1.5 text-[0.8rem] text-success" />
                {{ session('ok') }}
            </div>
        @endif

        <div class="grid gap-5 lg:grid-cols-[22rem_1fr]">

            {{-- ══════════════════════ New code ══════════════════════ --}}
            <form wire:submit="create" class="h-fit space-y-4 rounded-2xl border border-hairline bg-panel p-5">
                <div class="flex items-center gap-3">
                    <x-admin.icon-chip icon="ticket" tone="brand" />
                    <div class="text-[0.95rem] font-medium">New code</div>
                </div>

                <div>
                    <span class="mb-1.5 block text-[0.78rem] text-paper/50">Code</span>
                    <div class="flex items-center gap-2">
                        <input type="text" wire:model="code" maxlength="40"
                               class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 font-mono text-[0.9rem] uppercase tracking-wide focus:outline-none focus:ring-1 focus:ring-brand" />
                        <x-admin.icon-button icon="dice" variant="ghost" label="Another one" wire:click="reroll" />
                    </div>
                    @error('code') <span class="mt-1 block text-[0.75rem] text-danger">{{ $message }}</span> @enderror
                    <p class="mt-1.5 text-[0.73rem] leading-relaxed text-paper/30">
                        Suggestions leave out O, 0, I and 1 — the four characters that get mistyped when a code is read
                        aloud or off a screenshot.
                    </p>
                </div>

                <label class="block">
                    <span class="mb-1.5 block text-[0.78rem] text-paper/50">Grants</span>
                    <select wire:model="planId"
                            class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.86rem] focus:outline-none focus:ring-1 focus:ring-brand">
                        <option value="">Choose a plan…</option>
                        @foreach ($this->plans as $plan)
                            <option value="{{ $plan->id }}">{{ $plan->name }}</option>
                        @endforeach
                    </select>
                    @error('planId') <span class="mt-1 block text-[0.75rem] text-danger">{{ $message }}</span> @enderror
                </label>

                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="mb-1.5 block text-[0.78rem] text-paper/50">For how many days</span>
                        <input type="number" wire:model="days" min="1" max="3650"
                               class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.86rem] tabular-nums focus:outline-none focus:ring-1 focus:ring-brand" />
                        @error('days') <span class="mt-1 block text-[0.75rem] text-danger">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="mb-1.5 block text-[0.78rem] text-paper/50">Max uses</span>
                        <input type="number" wire:model="maxRedemptions" min="1" placeholder="no limit"
                               class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.86rem] tabular-nums focus:outline-none focus:ring-1 focus:ring-brand" />
                        @error('maxRedemptions') <span class="mt-1 block text-[0.75rem] text-danger">{{ $message }}</span> @enderror
                    </label>
                </div>

                <label class="block">
                    <span class="mb-1.5 block text-[0.78rem] text-paper/50">Expires <span class="text-paper/30">— optional</span></span>
                    <input type="date" wire:model="expiresAt"
                           class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.86rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                    @error('expiresAt') <span class="mt-1 block text-[0.75rem] text-danger">{{ $message }}</span> @enderror
                </label>

                <label class="block">
                    <span class="mb-1.5 block text-[0.78rem] text-paper/50">What it is for</span>
                    <input type="text" wire:model="note" maxlength="120" placeholder="Launch week · @creator · apology"
                           class="w-full rounded-lg border-0 bg-raised px-3 py-2.5 text-[0.86rem] focus:outline-none focus:ring-1 focus:ring-brand" />
                    <span class="mt-1.5 block text-[0.73rem] leading-relaxed text-paper/30">
                        Internal, never shown. It is what you will want in six months when a code has four hundred
                        redemptions and nobody remembers whose it was.
                    </span>
                </label>

                <button type="submit" class="w-full rounded-lg bg-action px-4 py-2.5 text-[0.85rem] font-medium text-white transition hover:opacity-90">
                    Create
                </button>
            </form>

            {{-- ══════════════════════ The codes ══════════════════════ --}}
            <div class="space-y-4">
                <div class="flex flex-wrap items-center gap-2.5">
                    @foreach (['all' => 'All', 'usable' => 'Usable now', 'spent' => 'Finished'] as $key => $label)
                        <button type="button" wire:click="$set('filter', '{{ $key }}')"
                                @class([
                                    'rounded-lg px-3.5 py-2 text-[0.83rem] transition',
                                    'bg-raised text-paper' => $filter === $key,
                                    'text-paper/45 hover:bg-raised/60 hover:text-paper/80' => $filter !== $key,
                                ])
                                wire:key="f-{{ $key }}">{{ $label }}</button>
                    @endforeach
                </div>

                <div class="overflow-hidden rounded-2xl border border-hairline bg-panel">
                    @forelse ($this->rows as $coupon)
                        @php
                            $why = $coupon->reason();
                            $left = $coupon->remaining();
                        @endphp

                        <div class="border-b border-hairline last:border-0" wire:key="c-{{ $coupon->id }}">
                            <div class="flex flex-wrap items-center gap-4 px-5 py-4"
                                 x-data="{
                                     copied: false,
                                     copy() {
                                         navigator.clipboard.writeText('{{ $coupon->code }}');
                                         this.copied = true;
                                         setTimeout(() => this.copied = false, 1500);
                                     },
                                 }">

                                <div class="min-w-[10rem] flex-1">
                                    {{-- Click to copy. A promo code exists to be
                                         pasted somewhere else, and retyping it
                                         by hand is how a campaign gets a code
                                         that nobody can redeem. --}}
                                    <button type="button" x-on:click="copy()"
                                            class="group/code flex items-center gap-2 font-mono text-[0.92rem] tracking-wide transition hover:text-brand">
                                        {{ $coupon->code }}
                                        <x-icon name="copy" style="regular"
                                                class="text-[11px] text-paper/25 opacity-0 transition group-hover/code:opacity-100" />
                                        <span x-show="copied" x-cloak class="text-[0.7rem] text-success">copied</span>
                                    </button>

                                    <div class="mt-0.5 truncate text-[0.75rem] text-paper/35">
                                        {{ $coupon->days }} days of {{ $coupon->plan?->name }}
                                        @if ($coupon->note) · <span class="text-paper/25">{{ $coupon->note }}</span> @endif
                                    </div>
                                </div>

                                <div class="w-28 shrink-0 text-[0.78rem]">
                                    <div class="tabular-nums text-paper/70">
                                        {{ $coupon->redemptions }}{{ $coupon->max_redemptions ? ' / '.$coupon->max_redemptions : '' }}
                                    </div>
                                    <div class="text-[0.7rem] text-paper/30">
                                        {{ $left === null ? 'no limit' : $left.' left' }}
                                    </div>
                                </div>

                                <div class="w-32 shrink-0 text-[0.76rem] text-paper/40">
                                    {{ $coupon->expires_at ? 'until '.$coupon->expires_at->format('j M Y') : 'no expiry' }}
                                </div>

                                <div class="w-28 shrink-0">
                                    @if ($why)
                                        <span class="rounded-full bg-raised px-2.5 py-0.5 text-[0.72rem] text-paper/45"
                                              title="{{ $why }}">Finished</span>
                                    @else
                                        <span class="rounded-full bg-success/15 px-2.5 py-0.5 text-[0.72rem] font-medium text-success">Usable</span>
                                    @endif
                                </div>

                                <div class="flex shrink-0 items-center gap-1.5">
                                    <x-admin.icon-button :icon="$coupon->is_active ? 'toggle-on' : 'toggle-off'"
                                                         variant="ghost"
                                                         :label="$coupon->is_active ? 'Switch off' : 'Switch on'"
                                                         wire:click="toggle({{ $coupon->id }})" />

                                    <x-admin.icon-button icon="trash" variant="muted" label="Delete"
                                                         wire:click="$set('confirmingDelete', {{ $coupon->id }})" />
                                </div>
                            </div>

                            @if ($confirmingDelete === $coupon->id)
                                <div class="border-t border-danger/25 bg-danger/[0.06] px-5 py-4">
                                    <p class="max-w-[80ch] text-[0.82rem] leading-relaxed text-paper/65">
                                        @if ($coupon->redemptions > 0)
                                            <strong class="text-paper/85">This code has been used {{ $coupon->redemptions }} time(s).</strong>
                                            Deleting it would take the record of who was given what with it, so it will be
                                            <strong>switched off instead</strong> — which stops it dead and keeps the history.
                                        @else
                                            Nobody has used this code, so there is nothing to lose. It will be deleted.
                                        @endif
                                    </p>

                                    <div class="mt-3.5 flex items-center gap-3">
                                        <button type="button" wire:click="delete({{ $coupon->id }})"
                                                class="rounded-lg bg-danger px-4 py-2 text-[0.83rem] font-medium text-white transition hover:opacity-90">
                                            {{ $coupon->redemptions > 0 ? 'Switch it off' : 'Delete it' }}
                                        </button>
                                        <button type="button" wire:click="$set('confirmingDelete', null)"
                                                class="text-[0.82rem] text-paper/40 transition hover:text-paper">Cancel</button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="px-5 py-12 text-center">
                            <span class="mx-auto grid size-11 place-items-center rounded-full bg-raised text-paper/25">
                                <x-icon name="ticket" style="regular" class="text-[0.95rem]" />
                            </span>
                            <p class="mx-auto mt-3 max-w-[46ch] text-[0.83rem] leading-relaxed text-paper/45">
                                No codes yet. The form beside this one makes one.
                            </p>
                        </div>
                    @endforelse
                </div>

                @if ($this->rows->hasPages())
                    <x-admin.pagination :paginator="$this->rows" />
                @endif

                {{-- ══════════════════════════════════════════════════════
                     WHERE THE CODE ACTUALLY GETS USED
                     ══════════════════════════════════════════════════════ --}}
                <div class="rounded-2xl border border-hairline bg-panel px-5 py-4">
                    <div class="text-[0.9rem] text-paper/70">Nobody can redeem these yet</div>
                    <p class="mt-1.5 max-w-[85ch] text-[0.8rem] leading-relaxed text-paper/40">
                        The rule is written and guarded — <span class="font-mono text-paper/60">Coupon::redeemFor()</span>,
                        locked and transactional, one use per person enforced by the database. What does not exist is a
                        form for somebody to type a code into; that arrives with checkout.
                        <span class="text-paper/60">Until then</span>, giving somebody access is the grant form on
                        Subscriptions, which does the same thing without a code.
                    </p>
                </div>
            </div>
        </div>
    @endif
</div>
