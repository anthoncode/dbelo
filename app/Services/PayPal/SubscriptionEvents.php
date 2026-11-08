<?php

namespace App\Services\PayPal;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What each PayPal event does to this application's own records.
 *
 * Separated from the controller because the controller's job is HTTP —
 * record, verify, answer a status code — and this one's job is the
 * business: who has Pro, and what money came in. Mixing them would put
 * "grant a subscription" inside something whose other responsibility is
 * choosing between 200 and 500.
 *
 * ── CANCELLED IS NOT EXPIRED, AND THAT DISTINCTION IS THE WHOLE FILE ─────
 *
 * Somebody who cancels on the 3rd has paid until the 30th. Switching them
 * off on the 3rd takes away four weeks they bought, and it is the most
 * common way a subscription integration robs its own customers — quietly,
 * because the person just assumes that is how it works and leaves.
 *
 * So CANCELLED records the intention and sets an end date. EXPIRED is what
 * actually turns Pro off. The Subscription model already draws that line:
 * isActive() is status === 'active' AND (ends_at is null OR in the future).
 *
 * ── WHAT IS DELIBERATELY NOT DONE HERE ──────────────────────────────────
 *
 * No emails. A webhook handler that sends mail is a webhook handler that
 * fails when the mail server is slow, and PayPal retries the whole event —
 * which means a second grant attempt because of a mail problem. Anything
 * worth telling somebody belongs in a job, dispatched after this returns.
 */
class SubscriptionEvents
{
    /**
     * Event types this application acts on.
     *
     * Everything else is recorded and marked IGNORED, which is a different
     * state from HANDLED on purpose: PayPal sends types nobody subscribed
     * to, and filing those as handled would make a type that genuinely
     * should have been processed indistinguishable from them.
     */
    public const HANDLED = [
        'BILLING.SUBSCRIPTION.ACTIVATED',
        'BILLING.SUBSCRIPTION.CANCELLED',
        'BILLING.SUBSCRIPTION.SUSPENDED',
        'BILLING.SUBSCRIPTION.EXPIRED',
        'BILLING.SUBSCRIPTION.UPDATED',
        'BILLING.SUBSCRIPTION.PAYMENT.FAILED',
        'PAYMENT.SALE.COMPLETED',
        'PAYMENT.SALE.REFUNDED',
        'PAYMENT.SALE.REVERSED',
    ];

    public function handle(WebhookEvent $event): void
    {
        $resource = (array) ($event->payload['resource'] ?? []);

        match ($event->event_type) {
            'BILLING.SUBSCRIPTION.ACTIVATED' => $this->activated($event, $resource),
            'BILLING.SUBSCRIPTION.CANCELLED' => $this->cancelled($event, $resource),
            'BILLING.SUBSCRIPTION.SUSPENDED' => $this->suspended($event, $resource),
            'BILLING.SUBSCRIPTION.EXPIRED' => $this->expired($event, $resource),
            'BILLING.SUBSCRIPTION.UPDATED' => $this->updated($event, $resource),
            'BILLING.SUBSCRIPTION.PAYMENT.FAILED' => $this->paymentFailed($event, $resource),
            'PAYMENT.SALE.COMPLETED' => $this->saleCompleted($event, $resource),
            'PAYMENT.SALE.REFUNDED', 'PAYMENT.SALE.REVERSED' => $this->saleReversed($event, $resource),
            default => $event->markIgnored('No handler for '.$event->event_type),
        };
    }

    /* ═════════════════════ The subscription itself ═════════════════════ */

    /**
     * Pro is on.
     *
     * ── WHY THE USER IS FOUND THROUGH custom_id ─────────────────────────
     *
     * The event carries the PayPal payer's email, and matching on that is
     * the obvious shortcut and a bad one: somebody can pay with a PayPal
     * account whose email is not the one they registered with here, and
     * matching on it would either fail or — far worse — attach the
     * subscription to a stranger who happens to own that address.
     *
     * So the checkout puts OUR user id into custom_id when it creates the
     * subscription, and this reads it back. It is the only identifier that
     * was ours before PayPal touched it.
     *
     * updateOrCreate on external_id, so a re-delivered ACTIVATED or a
     * subscription that was suspended and came back does not produce a
     * second row for one PayPal subscription.
     */
    protected function activated(WebhookEvent $event, array $resource): void
    {
        $subscription = $this->activate($resource);

        if (! $subscription) {
            $event->markIgnored($this->whyUnmatched(
                (string) ($resource['id'] ?? ''),
                $this->userFrom($resource),
                $this->planFrom($resource),
            ));

            return;
        }

        $event->markHandled($subscription->external_id);
    }

    /**
     * Turn a PayPal subscription resource into an active local one.
     *
     * ── PUBLIC, AND CALLED FROM TWO PLACES ──────────────────────────────
     *
     * The webhook calls it, and so does the checkout return page. They have
     * to agree completely: whichever arrives first grants the plan, and if
     * the two wrote subscriptions differently, a person's access would
     * depend on whether PayPal's event beat their browser back to the site.
     * That is a race nobody should be able to lose, so there is one method
     * and no second opinion.
     *
     * Safe to call twice because it keys on the PayPal subscription id. The
     * second caller updates the row the first created rather than making
     * another.
     *
     * THE CALLER MUST HAVE VERIFIED THE RESOURCE. The webhook does it with
     * the signature; the return page does it by fetching the subscription
     * from PayPal with our own credentials rather than trusting the query
     * string it was handed. A resource that arrived any other way has no
     * business here.
     */
    public function activate(array $resource): ?Subscription
    {
        $externalId = (string) ($resource['id'] ?? '');
        $user = $this->userFrom($resource);
        $plan = $this->planFrom($resource);

        if ($externalId === '' || ! $user || ! $plan) {
            return null;
        }

        return DB::transaction(function () use ($user, $plan, $externalId, $resource) {
            return Subscription::updateOrCreate(
                ['gateway' => 'paypal', 'external_id' => $externalId],
                [
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'starts_at' => $this->time($resource['start_time'] ?? null) ?? now(),
                    // Cleared, not left alone: a subscription that was
                    // cancelled and then reactivated still carries the old
                    // end date, and leaving it would switch Pro off on a
                    // date nobody chose any more.
                    'ends_at' => null,
                    'cancelled_at' => null,
                ],
            );
        });
    }

    /**
     * They cancelled. They keep what they paid for.
     *
     * ends_at comes from PayPal's own billing_info when it is there, because
     * PayPal knows exactly which day the paid period runs out and guessing
     * it from the interval drifts. When it is absent the subscription is
     * left running and flagged — ending somebody's access on a date this
     * code invented is worse than a row somebody has to look at.
     */
    protected function cancelled(WebhookEvent $event, array $resource): void
    {
        $subscription = $this->subscriptionFrom($resource);

        if (! $subscription) {
            $event->markIgnored('No local subscription for '.($resource['id'] ?? '?'));

            return;
        }

        $this->applyCancellation($subscription, $resource);

        $event->markHandled($subscription->external_id);
    }

    /**
     * Write down that a subscription will not renew.
     *
     * ── PUBLIC, AND CALLED FROM TWO PLACES ──────────────────────────────
     *
     * The webhook calls it, and so does the Cancel button on the account
     * screen — which cancels at PayPal with our own credentials and then
     * has to record the same thing. Exactly the shape of activate() above,
     * and for the same reason: two code paths that both end a subscription
     * must not be able to disagree about what ending one means.
     *
     * It matters more here than almost anywhere, because the rule being
     * applied is the one that protects the customer: somebody who cancels
     * on the 3rd has paid until the 30th, and a second implementation that
     * forgot that would quietly take four weeks away from them.
     *
     * THE CALLER MUST HAVE VERIFIED THE RESOURCE. The webhook does it with
     * the signature; the button does it by reading the subscription back
     * from PayPal over an authenticated connection.
     */
    public function applyCancellation(Subscription $subscription, array $resource): void
    {
        $until = $this->time($resource['billing_info']['next_billing_time'] ?? null);

        $subscription->forceFill([
            // STILL 'active'. isActive() reads ends_at, and this person has
            // paid through it. The status says what the subscription IS,
            // not what the customer has decided about its future.
            'status' => $until && $until->isFuture() ? 'active' : 'cancelled',
            'cancelled_at' => now(),
            'ends_at' => $until,
        ])->save();

        if (! $until) {
            /*
             * No next_billing_time means PayPal gave us nothing to measure
             * the remaining paid period from. ends_at stays null, which
             * isActive() reads as "no end" — so the person KEEPS access
             * rather than losing it to a missing field.
             *
             * That is the safe direction and it is deliberate: the cost of
             * being wrong here is somebody getting a few extra days, not
             * somebody losing days they paid for. It is logged because it
             * needs a human eventually, not because it is urgent.
             */
            Log::warning('PayPal cancellation had no next_billing_time; subscription left open', [
                'subscription' => $subscription->id,
                'external_id' => $subscription->external_id,
            ]);
        }
    }

    /**
     * PayPal paused it, almost always after failed payments.
     *
     * Treated as an immediate stop, unlike a cancellation: a suspension
     * means the money did not arrive, so there is no paid period left to
     * honour.
     */
    protected function suspended(WebhookEvent $event, array $resource): void
    {
        $this->setStatus($event, $resource, 'past_due');
    }

    /** The end. This is what actually turns Pro off. */
    protected function expired(WebhookEvent $event, array $resource): void
    {
        $subscription = $this->subscriptionFrom($resource);

        if (! $subscription) {
            $event->markIgnored('No local subscription for '.($resource['id'] ?? '?'));

            return;
        }

        $subscription->forceFill([
            'status' => 'expired',
            'ends_at' => $subscription->ends_at ?? now(),
        ])->save();

        $event->markHandled($subscription->external_id);
    }

    /**
     * The plan or the amount changed inside PayPal.
     *
     * Only the plan is followed, and only when it maps to one of ours. The
     * amount is deliberately not copied: what a plan is worth is a fact this
     * application owns, and letting an inbound event rewrite it is how a
     * subscription ends up priced at whatever the last webhook said.
     */
    protected function updated(WebhookEvent $event, array $resource): void
    {
        $subscription = $this->subscriptionFrom($resource);
        $plan = $this->planFrom($resource);

        if (! $subscription) {
            $event->markIgnored('No local subscription for '.($resource['id'] ?? '?'));

            return;
        }

        if ($plan && $plan->id !== $subscription->plan_id) {
            $subscription->forceFill(['plan_id' => $plan->id])->save();
        }

        $event->markHandled($subscription->external_id);
    }

    /**
     * A charge did not go through.
     *
     * Recorded, nothing switched off. PayPal retries a failed payment on its
     * own schedule and most of them succeed on the second attempt — a card
     * that expired over a weekend, a bank that declined once. Removing
     * access on the first failure punishes the people whose payment is
     * about to work.
     *
     * SUSPENDED is what arrives when PayPal gives up, and that one does act.
     */
    protected function paymentFailed(WebhookEvent $event, array $resource): void
    {
        $subscription = $this->subscriptionFrom($resource);

        Log::notice('PayPal subscription payment failed', [
            'external_id' => $resource['id'] ?? null,
            'subscription' => $subscription?->id,
            'attempts' => $resource['billing_info']['failed_payments_count'] ?? null,
        ]);

        $event->markHandled($subscription?->external_id);
    }

    /* ═══════════════════════════ The money ═══════════════════════════ */

    /**
     * A payment landed — the first one and every renewal after it.
     *
     * ── THE AMOUNT COMES FROM THE EVENT, AND HERE THAT IS CORRECT ───────
     *
     * Everywhere else in this file the rule is to trust our own data over
     * the body. The ledger is the exception, and it is not an inconsistency:
     * a Transaction row records WHAT PAYPAL DID, including the fee it kept,
     * which is a number only PayPal knows. A ledger built from our list
     * prices would show what we meant to charge rather than what arrived,
     * and would be useless for the one job it has — reconciling against the
     * gateway.
     *
     * The signature check above is what makes those numbers trustworthy.
     *
     * updateOrCreate on (gateway, external_id), matching the table's unique
     * key, so a retried delivery updates one row instead of inventing a
     * second month of revenue.
     */
    protected function saleCompleted(WebhookEvent $event, array $resource): void
    {
        $saleId = (string) ($resource['id'] ?? '');

        if ($saleId === '') {
            $event->markIgnored('Sale event with no id');

            return;
        }

        $subscription = $this->subscriptionFrom($resource, 'billing_agreement_id');

        $gross = $this->cents($resource['amount']['total'] ?? null);
        $fee = $this->cents($resource['transaction_fee']['value'] ?? null);

        Transaction::updateOrCreate(
            ['gateway' => 'paypal', 'external_id' => $saleId],
            [
                'user_id' => $subscription?->user_id,
                'subscription_id' => $subscription?->id,
                'plan_id' => $subscription?->plan_id,
                'plan_name' => $subscription?->plan?->name,
                'email' => $subscription?->user?->email,
                'type' => 'subscription',
                'status' => 'completed',
                'amount_cents' => $gross,
                'fee_cents' => $fee,
                // Written rather than computed on read: the fee is PayPal's
                // and can change between one charge and the next, so a net
                // derived later from today's rate would quietly rewrite
                // history.
                'net_cents' => max(0, $gross - $fee),
                'currency' => strtoupper((string) ($resource['amount']['currency'] ?? 'USD')),
                'paid_at' => $this->time($resource['create_time'] ?? null) ?? now(),
                'payload' => $resource,
            ],
        );

        $event->markHandled($saleId);
    }

    /**
     * Money went back out.
     *
     * The ORIGINAL sale is marked refunded rather than a negative row being
     * added, because Transaction::scopeEarned() filters on status — a refund
     * is not a smaller sale, it is a sale that stopped existing. That
     * decision is already written in the model and this has to match it or
     * the revenue figures count the same month twice.
     */
    protected function saleReversed(WebhookEvent $event, array $resource): void
    {
        $saleId = (string) ($resource['sale_id'] ?? $resource['id'] ?? '');

        $transaction = Transaction::where('gateway', 'paypal')
            ->where('external_id', $saleId)
            ->first();

        if (! $transaction) {
            $event->markIgnored('No transaction matching sale '.$saleId);

            return;
        }

        $back = $this->cents($resource['amount']['total'] ?? null);

        $transaction->forceFill([
            'status' => $back > 0 && $back < $transaction->amount_cents
                ? 'partially_refunded'
                : 'refunded',
            'refunded_cents' => $back ?: $transaction->amount_cents,
            'refunded_at' => $this->time($resource['create_time'] ?? null) ?? now(),
        ])->save();

        $event->markHandled($saleId);
    }

    /* ═══════════════════════════ Helpers ═══════════════════════════ */

    protected function setStatus(WebhookEvent $event, array $resource, string $status): void
    {
        $subscription = $this->subscriptionFrom($resource);

        if (! $subscription) {
            $event->markIgnored('No local subscription for '.($resource['id'] ?? '?'));

            return;
        }

        $subscription->forceFill(['status' => $status])->save();

        $event->markHandled($subscription->external_id);
    }

    protected function subscriptionFrom(array $resource, string $key = 'id'): ?Subscription
    {
        $externalId = (string) ($resource[$key] ?? '');

        return $externalId === ''
            ? null
            : Subscription::where('gateway', 'paypal')->where('external_id', $externalId)->first();
    }

    /**
     * Our user, from the id the checkout attached.
     *
     * NEVER from the payer's email. Somebody can pay with a PayPal account
     * registered to a different address than the one they signed up with
     * here, and matching on it would either miss them or hand the
     * subscription to whoever else owns that address.
     */
    protected function userFrom(array $resource): ?User
    {
        $id = (int) ($resource['custom_id'] ?? 0);

        return $id > 0 ? User::find($id) : null;
    }

    /** Our plan, from the PayPal plan id — in whichever mode we are in. */
    protected function planFrom(array $resource): ?Plan
    {
        $planId = (string) ($resource['plan_id'] ?? '');

        if ($planId === '') {
            return null;
        }

        return Plan::where('paypal_plan_id', $planId)
            ->orWhere('paypal_plan_id_sandbox', $planId)
            ->first();
    }

    /**
     * Why a subscription could not be attached, in words.
     *
     * Written out rather than a bare "ignored", because this is the message
     * somebody reads on the admin screen at the worst possible moment:
     * a customer has paid and does not have Pro. "No handler" sends them
     * looking at the code; "no user with custom_id 41" sends them to the
     * row that is actually wrong.
     */
    protected function whyUnmatched(string $externalId, ?User $user, ?Plan $plan): string
    {
        return collect([
            $externalId === '' ? 'the event carried no subscription id' : null,
            ! $user ? 'no local user matched custom_id' : null,
            ! $plan ? 'no local plan matched the PayPal plan id' : null,
        ])->filter()->implode('; ');
    }

    /** PayPal sends ISO 8601; a bad one must not take the handler down. */
    protected function time(?string $value): ?\Illuminate\Support\Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * "9.00" into 900.
     *
     * Through a string and round(), never (int) ($value * 100): in binary
     * floating point 9.00 * 100 can land on 899.9999999999999, and (int)
     * truncates that to 899. A cent lost per charge is the kind of error
     * that is invisible until somebody reconciles a year of it.
     */
    protected function cents(?string $value): int
    {
        return blank($value) ? 0 : (int) round(((float) $value) * 100);
    }
}
