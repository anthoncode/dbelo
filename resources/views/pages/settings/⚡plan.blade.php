<?php

use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * What this account actually is, and what it can do today.
 *
 * ── WHY THIS PAGE EXISTS ─────────────────────────────────────────────────
 *
 * Because none of it was anywhere. Every figure below already lived in the
 * database and in the admin panel — which plan, when it ends, downloads used
 * against the quota, what was paid — and the person it belongs to had no
 * screen to see any of it on. Somebody could buy a plan from the home page
 * and never lay eyes on it again.
 *
 * Nothing here is computed in a new way. currentPlan(), downloadsToday() and
 * remainingDownloadsToday() are the same methods DownloadController enforces
 * with, which is the only way this page can be trusted: a screen that works
 * out the quota by itself is a screen that will one day disagree with the
 * gate, and the visitor will believe the screen.
 *
 * ── WHAT IS NOT HERE, AND WHY ────────────────────────────────────────────
 *
 * A Cancel button. PayPal is not connected yet — no credentials, no webhook
 * route — so a button would either do nothing or mark a subscription
 * cancelled here while PayPal kept billing. That is the worst of the three
 * possible outcomes, and it is the one nobody would notice for a month.
 * When the gateway is live, cancelling gets built properly and lands here.
 */
new #[Layout('layouts.site')] #[Title('Your plan')] class extends Component
{
    #[Computed]
    public function subscription()
    {
        return Auth::user()->activeSubscription();
    }

    #[Computed]
    public function plan()
    {
        return Auth::user()->currentPlan();
    }

    /**
     * One word for the state, decided here so the badge and the sentence
     * below it can never disagree.
     *
     * `cancelled` is the interesting one: the subscription is still ACTIVE
     * and still works — what changed is that it will not renew. Showing that
     * as "cancelled" full stop would tell somebody their paid time is gone
     * when it is not, and showing it as "active" would hide the one fact
     * they need to act on.
     */
    #[Computed]
    public function state(): string
    {
        $subscription = $this->subscription;

        if (! $subscription) {
            return $this->plan ? 'free' : 'none';
        }

        if ($subscription->cancelled_at) {
            return 'cancelled';
        }

        /*
         * A subscription granted by hand from the admin panel.
         *
         * gateway is 'manual' rather than 'paypal' because nothing was
         * charged — see grant() on the admin screen, which sets it precisely
         * so a gift can never be counted as a sale. That distinction has to
         * survive all the way to here, because this page's job is to say
         * what happens NEXT: a PayPal subscription renews and charges again,
         * a granted one simply runs out. Printing "Renews 26 October" to
         * somebody who was never billed, and never will be, is a promise the
         * site cannot keep in either direction — they would expect access to
         * continue, or brace for a charge that is not coming.
         */
        return $subscription->gateway === 'manual' ? 'granted' : 'active';
    }

    /** Used today, and the ceiling — null means no ceiling. */
    #[Computed]
    public function usedToday(): int
    {
        return Auth::user()->downloadsToday();
    }

    #[Computed]
    public function dailyLimit(): ?int
    {
        return $this->plan?->isUnlimited() ? null : $this->plan?->daily_download_limit;
    }

    /**
     * Payments this account has made, newest first.
     *
     * Read straight from the ledger with no aggregation: a receipt list that
     * nets refunds off the totals is a receipt list that matches nothing the
     * person can find in their PayPal account.
     */
    #[Computed]
    public function payments()
    {
        return Transaction::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit(24)
            ->get();
    }

    public function mount(): void
    {
        abort_unless(Auth::check(), 403);
    }
}; ?>

<x-pages::settings.layout
    :heading="__('Plan')"
    :subheading="__('What your account can do, what you have used today, and what you have paid.')">

    <div class="space-y-8">

        {{-- ══════════════════════════════════════════════════════════════
             WHAT YOU ARE ON
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-card bg-surface p-6 shadow-soft-md dark:bg-surface-dark">

            @if ($this->plan)
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <div class="micro">{{ __('Your plan') }}</div>
                        <h2 class="mt-1.5 text-[1.5rem] font-semibold tracking-tight">{{ $this->plan->name }}</h2>

                        <div class="mt-1 text-[0.9rem] text-ink/55 dark:text-paper/55">
                            {{ $this->plan->priceForHumans() }}@unless ($this->plan->isFree()) <span class="text-ink/35 dark:text-paper/35">/ {{ $this->plan->interval }}</span>@endunless
                        </div>
                    </div>

                    {{-- Written out in full rather than assembled from a
                         variable: Tailwind only emits a class it saw as a
                         whole string at build time. --}}
                    @if ($this->state === 'granted')
                        <span class="inline-flex items-center gap-2 rounded-full bg-info/15 px-3.5 py-1.5 text-[0.75rem] font-medium text-info">
                            <x-icon name="gift" style="solid" class="text-[0.7rem]" />
                            {{ __('Given by dbelo') }}
                        </span>
                    @elseif ($this->state === 'cancelled')
                        <span class="inline-flex items-center gap-2 rounded-full bg-warning/15 px-3.5 py-1.5 text-[0.75rem] font-medium text-warning">
                            <x-icon name="clock" style="solid" class="text-[0.7rem]" />
                            {{ __('Will not renew') }}
                        </span>
                    @elseif ($this->state === 'active')
                        <span class="inline-flex items-center gap-2 rounded-full bg-success/15 px-3.5 py-1.5 text-[0.75rem] font-medium text-success">
                            <x-icon name="circle-check" style="solid" class="text-[0.7rem]" />
                            {{ __('Active') }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-2 rounded-full bg-ink/[0.06] px-3.5 py-1.5 text-[0.75rem] font-medium text-ink/55 dark:bg-paper/10 dark:text-paper/55">
                            {{ __('Free account') }}
                        </span>
                    @endif
                </div>

                {{-- ── THE DATES ─────────────────────────────────────────
                     The question the whole page was built to answer. Each
                     branch says the date AND what happens on it, because
                     "ends 14 March" without the verb is ambiguous in the
                     one direction that matters: does it stop, or does it
                     charge me again? --}}
                @if ($this->subscription)
                    <div class="mt-6 grid gap-4 border-t border-ink/[0.07] pt-6 sm:grid-cols-2 dark:border-paper/10">
                        <div>
                            <div class="micro">{{ __('Started') }}</div>
                            <div class="mt-1 text-[0.95rem]">
                                {{ $this->subscription->starts_at?->format('j F Y') ?? '—' }}
                            </div>
                        </div>

                        <div>
                            @if ($this->state === 'granted')
                                {{-- A gift, not a purchase. It ends; it does
                                     not renew and it never charges. --}}
                                <div class="micro">{{ __('Access until') }}</div>
                                <div class="mt-1 text-[0.95rem]">
                                    {{ $this->subscription->ends_at?->format('j F Y') ?? '—' }}
                                </div>
                                <p class="mt-1.5 text-[0.8rem] leading-relaxed text-ink/45 dark:text-paper/45">
                                    {{ __('This plan was given to you rather than bought. Nothing will be charged and it does not renew on its own.') }}
                                </p>
                            @elseif ($this->state === 'cancelled')
                                <div class="micro">{{ __('Access until') }}</div>
                                <div class="mt-1 text-[0.95rem]">
                                    {{ $this->subscription->ends_at?->format('j F Y') ?? __('End of the paid period') }}
                                </div>
                                <p class="mt-1.5 text-[0.8rem] leading-relaxed text-ink/45 dark:text-paper/45">
                                    {{ __('Cancelled, but not over: everything keeps working until that date and you will not be charged again.') }}
                                </p>
                            @elseif ($this->subscription->ends_at)
                                <div class="micro">{{ __('Renews') }}</div>
                                <div class="mt-1 text-[0.95rem]">{{ $this->subscription->ends_at->format('j F Y') }}</div>
                                <p class="mt-1.5 text-[0.8rem] leading-relaxed text-ink/45 dark:text-paper/45">
                                    {{ __('That is :days days from today.', ['days' => max(0, (int) now()->diffInDays($this->subscription->ends_at, false))]) }}
                                </p>
                            @else
                                <div class="micro">{{ __('Renews') }}</div>
                                <div class="mt-1 text-[0.95rem]">{{ __('Every :interval', ['interval' => $this->plan->interval]) }}</div>
                            @endif
                        </div>
                    </div>
                @endif
            @else
                {{-- No plan row at all. Not an error: it is what a fresh
                     account looks like before any plan has been assigned. --}}
                <div class="micro">{{ __('Your plan') }}</div>
                <h2 class="mt-1.5 text-[1.5rem] font-semibold tracking-tight">{{ __('No plan yet') }}</h2>
                <p class="mt-2 max-w-[54ch] text-[0.9rem] leading-relaxed text-ink/55 dark:text-paper/55">
                    {{ __('You can browse and listen to everything. Downloading needs a plan.') }}
                </p>
            @endif

            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('home') }}#plans" wire:navigate
                   class="inline-flex items-center gap-2 rounded-full bg-action px-5 py-2.5 text-[0.85rem] text-white transition duration-300 ease-dbelo hover:-translate-y-0.5 hover:brightness-110">
                    {{ $this->plan && ! $this->plan->isFree() ? __('See the other plans') : __('See the plans') }}
                    <x-icon name="arrow-right" style="solid" class="text-[0.7rem]" />
                </a>
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             TODAY'S DOWNLOADS

             The same three methods DownloadController enforces with. If this
             bar and the gate ever disagree, one of them is lying to somebody
             at the moment they are trying to take a file — so there is only
             one source for both.
             ══════════════════════════════════════════════════════════════ --}}
        @if ($this->plan)
            <div class="rounded-card bg-surface p-6 shadow-soft-md dark:bg-surface-dark">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <div class="micro">{{ __('Downloads today') }}</div>
                        <div class="mt-1.5 text-[1.5rem] font-semibold tabular-nums tracking-tight">
                            {{ $this->usedToday }}@if ($this->dailyLimit !== null)<span class="text-ink/30 dark:text-paper/30"> / {{ $this->dailyLimit }}</span>@endif
                        </div>
                    </div>

                    <div class="text-right text-[0.85rem] text-ink/50 dark:text-paper/50">
                        @if ($this->dailyLimit === null)
                            {{ __('No daily limit on this plan.') }}
                        @else
                            {{ __(':n left', ['n' => max(0, $this->dailyLimit - $this->usedToday)]) }}
                        @endif
                    </div>
                </div>

                @if ($this->dailyLimit !== null)
                    @php
                        $ratio = $this->dailyLimit > 0
                            ? min(1, $this->usedToday / $this->dailyLimit)
                            : 1;
                    @endphp

                    <div class="mt-4 h-2 overflow-hidden rounded-full bg-ink/[0.07] dark:bg-paper/10">
                        {{-- Width inline, colour by class: the percentage is
                             a runtime number and Tailwind cannot emit a class
                             it never saw. --}}
                        <div style="width: {{ round($ratio * 100) }}%"
                             @class([
                                 'h-full rounded-full transition-all duration-500 ease-dbelo',
                                 'bg-danger' => $ratio >= 1,
                                 'bg-warning' => $ratio >= 0.8 && $ratio < 1,
                                 'bg-brand' => $ratio < 0.8,
                             ])></div>
                    </div>
                @endif

                <p class="mt-4 text-[0.82rem] leading-relaxed text-ink/45 dark:text-paper/45">
                    {{ __('The count resets at midnight in the site timezone. Downloading a sound you already took does not count again.') }}
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-x-6 gap-y-2 border-t border-ink/[0.07] pt-5 text-[0.85rem] dark:border-paper/10">
                    <span class="flex items-center gap-2">
                        <x-icon name="{{ $this->plan->allows_premium ? 'circle-check' : 'circle-xmark' }}" style="solid"
                                class="text-[0.8rem] {{ $this->plan->allows_premium ? 'text-success' : 'text-ink/25 dark:text-paper/25' }}" />
                        {{ $this->plan->allows_premium ? __('Premium sounds included') : __('Premium sounds not included') }}
                    </span>
                </div>
            </div>
        @endif

        {{-- ══════════════════════════════════════════════════════════════
             WHAT YOU HAVE PAID

             Straight from the ledger, newest first, with refunds shown as
             refunds rather than subtracted. Somebody comparing this against
             their PayPal history must find the same rows.
             ══════════════════════════════════════════════════════════════ --}}
        <div class="rounded-card bg-surface p-6 shadow-soft-md dark:bg-surface-dark">
            <div class="micro">{{ __('Payments') }}</div>

            @if ($this->payments->isEmpty())
                <p class="mt-3 text-[0.9rem] text-ink/50 dark:text-paper/50">
                    {{ __('Nothing yet. Anything you pay for will be listed here.') }}
                </p>
            @else
                <div class="mt-4 divide-y divide-ink/[0.07] dark:divide-paper/10">
                    @foreach ($this->payments as $payment)
                        <div class="flex flex-wrap items-center justify-between gap-3 py-3" wire:key="pay-{{ $payment->id }}">
                            <div class="min-w-0">
                                <div class="text-[0.9rem]">{{ $payment->plan_name ?: __('Payment') }}</div>
                                <div class="micro mt-0.5">{{ $payment->paid_at?->format('j F Y') ?? '—' }}</div>
                            </div>

                            <div class="flex items-center gap-3">
                                @if ($payment->isRefunded())
                                    <span class="rounded-full bg-warning/15 px-2.5 py-1 text-[0.7rem] text-warning">{{ __('Refunded') }}</span>
                                @elseif ($payment->status !== 'completed')
                                    <span class="rounded-full bg-ink/[0.06] px-2.5 py-1 text-[0.7rem] text-ink/50 dark:bg-paper/10 dark:text-paper/50">{{ $payment->status }}</span>
                                @endif

                                <span class="tabular-nums text-[0.95rem]">{{ $payment->amountForHumans() }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- The honest note about cancelling, in place of a button that
             cannot yet do what it says. --}}
        @if ($this->state === 'active' && $this->plan && ! $this->plan->isFree())
            <p class="text-[0.85rem] leading-relaxed text-ink/45 dark:text-paper/45">
                {{ __('To stop the renewal, write to us at') }}
                <a href="mailto:{{ config('dbelo.legal.support_email') }}" class="text-brand underline underline-offset-2">{{ config('dbelo.legal.support_email') }}</a>{{ __(' and we will take care of it. Cancelling from this page is coming.') }}
            </p>
        @endif
    </div>
</x-pages::settings.layout>
