@props([
    /** @var \App\Models\Plan */
    'plan',

    /** The plan this visitor is already on, or null. */
    'currentPlanId' => null,

    /** Is this visitor already on a plan that bills again? */
    'onRecurring' => false,

    /** The name of that plan, so the refusal can say which one. */
    'currentPlanName' => null,

    /** Full-width (pricing card) or sized to its text (inline row). */
    'block' => false,

    /** 'brand' | 'action' | 'muted' — which of the three button looks. */
    'tone' => 'muted',
])

{{--
    ══════════════════════════════════════════════════════════════════════
    THE ONE CONTROL THAT DECIDES WHAT A PLAN OFFERS SOMEBODY

    ── WHY THIS IS A COMPONENT ───────────────────────────────────────────

    Because the same decision is now made in two places — the pricing cards
    on the home page and the plan screen in settings — and this exact kind
    of duplication has already shipped three broken buttons on this site:

      · "Choose Pro" pointed at /register, which Fortify guards with
        `guest`, so a signed-in visitor was bounced straight back and the
        page appeared to do nothing.

      · "Subscribe to download this pack" led into /settings/plan, which
        offered "See the plans", which led back. A closed loop.

      · "Get the Day Pass" did the same thing, and was missed when the
        recurring cards were fixed, because it was a second copy of the
        same decision in a different file.

    Each was fixed on its own, and each fix left the other copies intact.
    So the rule this file exists to enforce: ONE DEFINITION, EVERY SURFACE.
    A new page that offers a plan uses this and inherits every state,
    including the ones nobody remembered to think about.

    ── THE SIX STATES, IN ORDER ──────────────────────────────────────────

    The order matters: each branch assumes the ones above it were false.

      1. already on it         → a badge, not a button
      2. a guest               → sign up first; there is nobody to bill yet
      3. free, viewer is paid  → not a downgrade button; cancelling is on
                                 the account page, and this is a plan list
      4. pass, viewer is paid  → already included in what they have
      5. recurring, already    → would be a SECOND subscription. Refused,
         paying for another       and told how to switch
      6. recurring, no PayPal  → honestly disabled
      7. recurring, ready      → POST, start a subscription
      8. bought once           → POST, start an order

    What is NOT here is a Cancel button. PayPal keeps billing a subscription
    until it is cancelled at PayPal, so a button that marked it cancelled
    only on this side would be the worst of the three possible outcomes —
    and the one nobody would notice for a month.
    ══════════════════════════════════════════════════════════════════════
--}}

@php
    $base = $block
        ? 'block w-full rounded-full py-3 text-center text-[0.88rem] font-medium transition duration-300 ease-dbelo hover:-translate-y-0.5'
        : 'inline-block rounded-full px-7 py-3 text-[0.88rem] font-medium transition duration-300 ease-dbelo hover:-translate-y-0.5';

    $paint = match ($tone) {
        'brand' => 'bg-brand text-white shadow-brand hover:shadow-brand-lg',
        'action' => 'bg-action text-white hover:brightness-110',
        default => 'bg-ink/[0.05] text-ink/70 hover:bg-ink/[0.09] dark:bg-paper/[0.08] dark:text-paper/70',
    };

    $quiet = $block
        ? 'block w-full rounded-full bg-ink/[0.04] py-3 text-center text-[0.88rem] text-ink/40 dark:bg-paper/[0.06] dark:text-paper/40'
        : 'inline-flex items-center gap-2 rounded-full bg-ink/[0.04] px-7 py-3 text-[0.88rem] text-ink/50 dark:bg-paper/[0.06] dark:text-paper/50';

    $badge = $block
        ? 'flex items-center justify-center gap-2 rounded-full bg-success/12 py-3 text-center text-[0.88rem] font-medium text-success'
        : 'inline-flex items-center gap-2 rounded-full bg-success/12 px-7 py-3 text-[0.88rem] font-medium text-success';
@endphp

@if ($currentPlanId === $plan->id)
    {{-- Offering somebody the plan they are already on is the clearest
         possible sign that a page is not looking at who is reading it. --}}
    <div class="{{ $badge }}">
        <x-icon name="circle-check" style="solid" class="text-[0.78rem]" />
        {{ $plan->isOneOff() ? __('Active now') : __('Your plan') }}
    </div>

@elseif (auth()->guest())
    {{-- A link is right here: signing up buys nothing, so there is no order
         to protect from prefetchers. And a plan is attached to a user —
         there is nobody to attach it to yet. --}}
    <a href="{{ route('register') }}" wire:navigate class="{{ $base }} {{ $paint }}">
        @if ($plan->isFree())
            {{ __('Start free') }}
        @elseif ($plan->isOneOff())
            {{ __('Get the :plan', ['plan' => $plan->name]) }}
        @else
            {{ __('Choose :plan', ['plan' => $plan->name]) }}
        @endif
    </a>

@elseif ($plan->isFree())
    {{-- The viewer is signed in and on something else, so Free is a
         downgrade. Cancelling is not built — PayPal would keep billing —
         so this says what is true instead of offering a button that lies:
         when the paid plan ends, currentPlan() falls back here by itself. --}}
    <div class="{{ $quiet }}">
        {{ __('Available when your current plan ends') }}
    </div>

@elseif ($plan->isOneOff() && $onRecurring)
    {{-- Selling a day of what somebody already has every day is charging
         them for nothing, which is worse than a missing button. Said out
         loud rather than hidden: a person who can see the pass advertised
         deserves to know why it is not for sale to them. --}}
    <div class="{{ $quiet }}">
        <x-icon name="circle-check" style="solid" class="text-[0.78rem]" />
        {{ __('Included in your plan') }}
    </div>

@elseif (! $plan->isOneOff() && $onRecurring)
    {{-- ── ALREADY PAYING FOR A DIFFERENT PLAN ───────────────────────
         Taking this would create a SECOND subscription at PayPal without
         ending the first — $89 a year and $14 a month at once, from one
         button. PayPal's /revise exists for switching and is not built
         yet; until it is, refusing is the only honest answer.

         Cancelling on their behalf would be worse: it decides that
         somebody wants to lose their current plan before they have agreed
         to the new one. So this names the way through instead, and the
         Cancel button on the account page is what makes that sentence
         true rather than a brush-off. --}}
    <div class="{{ $quiet }}">
        {{ $currentPlanName
            ? __('Cancel :plan first', ['plan' => $currentPlanName])
            : __('Cancel your current plan first') }}
    </div>

@elseif (! $plan->isOneOff() && ! $plan->isSyncedWithPayPal())
    {{-- No twin at PayPal yet. A button that starts a checkout for a plan
         nobody can be billed for fails on PayPal's own screen, which is the
         worst place to discover it. The fix is `paypal:sync-plans`, and it
         belongs to the operator, not to whoever is reading this. --}}
    <div class="{{ $quiet }}">
        {{ __('Not available yet') }}
    </div>

@else
    {{-- A FORM, NOT A LINK.

         Submitting it creates something real at PayPal — a subscription or
         an order. A link would be fetched by prefetchers, by crawlers, and
         twice by anyone who double-clicks, each one a dangling unapproved
         record in the PayPal account.

         Two routes because they are two different PayPal APIs: a
         subscription is charged by PayPal forever without us, while a pass
         is an order this application captures itself, once. --}}
    <form method="POST" action="{{ $plan->isOneOff() ? route('pass.start', $plan) : route('checkout.start', $plan) }}">
        @csrf
        <button type="submit" class="{{ $base }} {{ $paint }}">
            @if ($plan->isOneOff())
                {{ __('Get the :plan', ['plan' => $plan->name]) }}
            @else
                {{ __('Subscribe to :plan', ['plan' => $plan->name]) }}
            @endif
        </button>
    </form>
@endif
