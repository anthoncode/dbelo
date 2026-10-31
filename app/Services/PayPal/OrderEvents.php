<?php

namespace App\Services\PayPal;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-off purchases: the day pass, and anything else bought once.
 *
 * ── WHY THIS IS A SEPARATE FILE FROM SubscriptionEvents ──────────────────
 *
 * Not tidiness. The two use different PayPal APIs, carry different event
 * types, and — the part that matters — disagree about who decides that
 * money moved.
 *
 *   A SUBSCRIPTION is granted by the WEBHOOK. PayPal charges the card on its
 *   own schedule and tells us afterwards. We are never in the loop, so the
 *   signed event is the only evidence there is.
 *
 *   A ONE-OFF is granted by OUR OWN CAPTURE CALL. The buyer approves, comes
 *   back, and this application calls /capture itself over an authenticated
 *   connection. We are the ones who moved the money, so we already know it
 *   moved — before any webhook arrives.
 *
 * Putting both in one class would mean one file whose first rule is "never
 * trust the return URL" and whose second rule is "the return URL is the
 * authority". Two files, each honest about its own flow.
 *
 * ── SO WHAT IS THE WEBHOOK FOR HERE? ────────────────────────────────────
 *
 * Three things the capture-on-return cannot cover:
 *
 *   1. THE ABANDONED TAB. They press Pay, PayPal redirects, and their
 *      browser never arrives — closed laptop, dead battery, a tunnel. The
 *      order sits APPROVED and the money is authorised but never taken.
 *      CHECKOUT.ORDER.APPROVED lets us capture it server-side, which is both
 *      the honest thing (they agreed to pay) and the only way they get what
 *      they bought.
 *
 *   2. REFUNDS. Those happen in PayPal's own interface, days later, with
 *      nobody on this site at all.
 *
 *   3. A CAPTURE WE NEVER SAW SUCCEED. Our /capture call can time out after
 *      PayPal did the work. PAYMENT.CAPTURE.COMPLETED closes that gap.
 *
 * Every entry point therefore funnels into grant(), which is idempotent on
 * the capture id. Whichever path arrives first does the work; the rest do
 * nothing. Two code paths that both grant access must not be able to
 * disagree about what granting means.
 */
class OrderEvents
{
    /**
     * Event types this class acts on.
     *
     * PAYMENT.CAPTURE.*, never PAYMENT.SALE.* — that distinction cost real
     * time on the subscription side and is worth stating twice. Orders v2
     * captures emit CAPTURE events; Subscriptions v1 recurring charges emit
     * SALE events. The two sets never overlap, which is what lets EventRouter
     * dispatch on membership alone.
     */
    public const HANDLED = [
        'CHECKOUT.ORDER.APPROVED',
        'PAYMENT.CAPTURE.COMPLETED',
        'PAYMENT.CAPTURE.DENIED',
        'PAYMENT.CAPTURE.REFUNDED',
        'PAYMENT.CAPTURE.REVERSED',
    ];

    public function __construct(private PayPalClient $paypal) {}

    public function handle(WebhookEvent $event): void
    {
        $resource = (array) ($event->payload['resource'] ?? []);

        match ($event->event_type) {
            'CHECKOUT.ORDER.APPROVED' => $this->orderApproved($event, $resource),
            'PAYMENT.CAPTURE.COMPLETED' => $this->captureCompleted($event, $resource),
            'PAYMENT.CAPTURE.DENIED' => $this->captureDenied($event, $resource),
            'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED' => $this->captureReversed($event, $resource),
            default => $event->markIgnored('No handler for '.$event->event_type),
        };
    }

    /* ═══════════════════════════ The events ═══════════════════════════ */

    /**
     * They agreed to pay and the money has NOT been taken yet.
     *
     * This is the abandoned-tab case. An APPROVED order is an authorisation
     * with nothing collected: leave it and it voids itself in a few days,
     * having taken no money and given nothing — the worst outcome for both
     * sides, because the buyer believes they bought something.
     *
     * Capturing here is safe because /capture is sent with an idempotency key
     * derived from the order id: if the browser DID come back and the return
     * handler captured a second earlier, PayPal returns that same capture
     * rather than charging twice.
     */
    protected function orderApproved(WebhookEvent $event, array $resource): void
    {
        $orderId = (string) ($resource['id'] ?? '');

        if ($orderId === '') {
            $event->markIgnored('Order approved event with no id');

            return;
        }

        try {
            $order = $this->capture($orderId);
        } catch (PayPalException $e) {
            /*
             * Not markFailed, and the difference decides whether PayPal
             * hammers this endpoint for three days.
             *
             * A 422 here usually means ORDER_ALREADY_CAPTURED — the return
             * handler got there first and this event has nothing left to do.
             * That is success, not failure. Anything else genuinely failed
             * and is worth a retry, so it is re-thrown for the controller to
             * turn into a 500.
             */
            if ($e->issue === 'ORDER_ALREADY_CAPTURED') {
                $event->markIgnored('Already captured by the return handler');

                return;
            }

            throw $e;
        }

        $capture = $this->captureFrom($order);

        if (! $capture) {
            $event->markFailed('Captured order '.$orderId.' but the response carried no capture');

            return;
        }

        $this->recordAndGrant($event, $order, $capture);
    }

    /**
     * The money is in. This is the event that is allowed to be the only one.
     *
     * Reached when our own capture call timed out after PayPal had already
     * done the work, or when orderApproved above captured server-side. In
     * both cases the capture resource IS the event resource, so there is
     * nothing to fetch.
     */
    protected function captureCompleted(WebhookEvent $event, array $resource): void
    {
        $captureId = (string) ($resource['id'] ?? '');

        if ($captureId === '') {
            $event->markIgnored('Capture event with no id');

            return;
        }

        /*
         * The capture event does not name the order, only itself and a
         * supplementary link. custom_id, though, travels on the capture —
         * we put it on the purchase unit and PayPal copies it down. That is
         * the whole reason the buyer can be identified from this event
         * without a second API call.
         */
        $this->recordAndGrant($event, null, $resource);
    }

    /**
     * PayPal refused the money.
     *
     * Recorded as a failed transaction and nothing else: no access granted,
     * and no access taken away either, because a denied capture never gave
     * any. The row exists so that "I paid and got nothing" has an answer.
     */
    protected function captureDenied(WebhookEvent $event, array $resource): void
    {
        $captureId = (string) ($resource['id'] ?? '');
        $user = $this->userFrom($resource);
        $plan = $this->planFrom($resource);

        Transaction::updateOrCreate(
            ['gateway' => 'paypal', 'external_id' => $captureId ?: 'denied-'.$event->event_id],
            [
                'user_id' => $user?->id,
                'plan_id' => $plan?->id,
                'plan_name' => $plan?->name,
                'email' => $user?->email,
                'type' => 'one_time',
                'status' => 'failed',
                'amount_cents' => $this->cents($resource['amount']['value'] ?? null),
                'currency' => strtoupper((string) ($resource['amount']['currency_code'] ?? 'USD')),
                'payload' => $resource,
            ],
        );

        $event->markHandled($captureId ?: null);
    }

    /**
     * Money went back out.
     *
     * Two halves, and the second is the one that is easy to forget: the
     * transaction is marked refunded AND the pass is ended. A refunded day
     * pass that keeps working is a free day pass, and nothing else in the
     * application would ever notice.
     *
     * The original transaction is marked rather than a negative row added,
     * matching what SubscriptionEvents::saleReversed() does and what
     * Transaction::scopeEarned() expects. Two different answers to "is a
     * refund a sale?" in one schema is how a revenue figure counts a month
     * twice.
     */
    protected function captureReversed(WebhookEvent $event, array $resource): void
    {
        // A REFUNDED event is about the refund, and names the capture it
        // reverses in links; a REVERSED event is about the capture itself.
        $captureId = (string) ($resource['id'] ?? '');
        $linked = $this->linkedCaptureId($resource);

        $transaction = Transaction::where('gateway', 'paypal')
            ->whereIn('external_id', array_filter([$linked, $captureId]))
            ->first();

        if (! $transaction) {
            $event->markIgnored('No transaction matching capture '.($linked ?: $captureId));

            return;
        }

        $back = $this->cents($resource['amount']['value'] ?? null);
        $full = $back === 0 || $back >= $transaction->amount_cents;

        DB::transaction(function () use ($transaction, $back, $full, $resource) {
            $transaction->forceFill([
                'status' => $full ? 'refunded' : 'partially_refunded',
                'refunded_cents' => $back ?: $transaction->amount_cents,
                'refunded_at' => $this->time($resource['create_time'] ?? null) ?? now(),
            ])->save();

            /*
             * Only a FULL refund ends the pass.
             *
             * A partial refund is a price adjustment, not a cancellation —
             * taking the whole pass away because somebody was given two
             * dollars back would be worse than doing nothing.
             */
            if (! $full) {
                return;
            }

            $pass = Subscription::where('gateway', 'paypal')
                ->where('external_id', $transaction->external_id)
                ->first();

            $pass?->forceFill([
                'status' => 'refunded',
                'ends_at' => now(),
                'cancelled_at' => now(),
            ])->save();
        });

        $event->markHandled($transaction->external_id);
    }

    /* ═══════════════════════════ Granting ═══════════════════════════ */

    /**
     * Write the money down and open the door. The only place that does both.
     *
     * Public because CheckoutController's return handler calls it with the
     * capture it just made. One method, two callers, no second opinion about
     * what a paid pass means.
     *
     * @param  array|null  $order  The order, when the caller has it. Only used
     *                             for the purchase unit's custom_id, which the
     *                             capture usually carries anyway.
     */
    public function recordAndGrant(WebhookEvent $event, ?array $order, array $capture): void
    {
        $captureId = (string) ($capture['id'] ?? '');

        if ($captureId === '') {
            $event->markFailed('Capture with no id');

            return;
        }

        // custom_id is on the capture; fall back to the order's purchase unit
        // for the shapes where PayPal omits it.
        $source = filled($capture['custom_id'] ?? null)
            ? $capture
            : (array) (($order['purchase_units'][0] ?? []) ?: []);

        $user = $this->userFrom($source);
        $plan = $this->planFrom($source);

        if (! $user || ! $plan) {
            /*
             * Money arrived that cannot be attributed. FAILED, not IGNORED:
             * somebody has paid and has nothing, and that has to appear in
             * red on the admin screen rather than in a list of events
             * deliberately skipped.
             *
             * The transaction is still written, with whatever is known. A
             * payment with no row is a payment nobody can find.
             */
            $this->orphan($captureId, $capture, $user, $plan);

            $event->markFailed($this->whyUnmatched($user, $plan, $source));

            return;
        }

        DB::transaction(function () use ($captureId, $capture, $user, $plan) {
            $gross = $this->cents($capture['amount']['value'] ?? null);
            $breakdown = (array) ($capture['seller_receivable_breakdown'] ?? []);
            $fee = $this->cents($breakdown['paypal_fee']['value'] ?? null);

            $pass = $this->grant($plan, $user, $captureId, $capture);

            Transaction::updateOrCreate(
                ['gateway' => 'paypal', 'external_id' => $captureId],
                [
                    'user_id' => $user->id,
                    'subscription_id' => $pass->id,
                    'plan_id' => $plan->id,
                    // A snapshot, in the words of the day it happened. The
                    // plan can be renamed or repriced later and this row
                    // still says what was sold.
                    'plan_name' => $plan->name,
                    'email' => $user->email,
                    'type' => 'one_time',
                    'status' => 'completed',
                    'amount_cents' => $gross,
                    'fee_cents' => $fee,
                    // Written, never derived on read: PayPal's fee changes
                    // between one payment and the next, and a net computed
                    // later from today's rate quietly rewrites history.
                    'net_cents' => max(0, $gross - $fee),
                    'currency' => strtoupper((string) ($capture['amount']['currency_code'] ?? $plan->currency ?? 'USD')),
                    'paid_at' => $this->time($capture['create_time'] ?? null) ?? now(),
                    'payload' => $capture,
                ],
            );
        });

        $event->markHandled($captureId);
    }

    /**
     * Open the door, for exactly as long as was paid for.
     *
     * ── WHY A SUBSCRIPTION ROW FOR SOMETHING THAT IS NOT A SUBSCRIPTION ──
     *
     * Because access in this application is answered by one method —
     * User::activeSubscription(), which asks for status 'active' and an
     * ends_at that is null or in the future. A day pass is precisely that
     * with a near ends_at. Inventing a second table would mean every
     * permission check in the codebase having to ask twice, and the day
     * somebody adds a third kind of access it would ask three times.
     *
     * ── STACKING ────────────────────────────────────────────────────────
     *
     * Buying a second pass while one is live extends from the existing end,
     * not from now. Otherwise the second purchase would shorten the first,
     * which is both wrong and the kind of wrong that looks like theft.
     */
    public function grant(Plan $plan, User $user, string $captureId, array $capture = []): Subscription
    {
        $live = $user->subscriptions()
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->orderByDesc('ends_at')
            ->first();

        $from = $live?->ends_at ?? now();

        return Subscription::updateOrCreate(
            ['gateway' => 'paypal', 'external_id' => $captureId],
            [
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => $this->time($capture['create_time'] ?? null) ?? now(),
                'ends_at' => $plan->passEndsAt($from),
                'cancelled_at' => null,
            ],
        );
    }

    /* ═══════════════════════════ Helpers ═══════════════════════════ */

    /**
     * Capture an order, idempotently.
     *
     * The request id is derived from the order id and nothing else, which is
     * the one case where a FIXED key is what we want: a second capture of the
     * same order must never be a second charge. That is the opposite of the
     * sync command, where a fixed key pinned a broken payload — the
     * difference is that here the payload is empty, so it cannot change.
     *
     * @throws PayPalException
     */
    public function capture(string $orderId): array
    {
        return $this->paypal->post(
            "/v2/checkout/orders/{$orderId}/capture",
            [],
            requestId: 'capture-'.$orderId,
        );
    }

    /** The capture buried in a captured order's response. */
    public function captureFrom(array $order): ?array
    {
        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;

        return is_array($capture) ? $capture : null;
    }

    /**
     * Which capture a refund reversed.
     *
     * A REFUNDED event's resource is the REFUND, and the capture it undoes is
     * only named in its links — as the href of the rel 'up'. Reading the id
     * off the end of a URL is not pretty, and it is what PayPal provides.
     */
    protected function linkedCaptureId(array $resource): ?string
    {
        foreach ((array) ($resource['links'] ?? []) as $link) {
            if (($link['rel'] ?? '') === 'up' && filled($link['href'] ?? null)) {
                $id = basename(parse_url((string) $link['href'], PHP_URL_PATH) ?: '');

                return $id !== '' ? $id : null;
            }
        }

        return null;
    }

    /**
     * Our user, from the id the checkout attached.
     *
     * NEVER from the payer's email — the same rule as SubscriptionEvents,
     * and for the same reason: somebody can pay with a PayPal account
     * registered to a different address than the one they signed up with
     * here, and matching on it would either miss them or hand the purchase
     * to whoever else owns that address.
     */
    protected function userFrom(array $resource): ?User
    {
        $id = (int) ($resource['custom_id'] ?? 0);

        return $id > 0 ? User::find($id) : null;
    }

    /**
     * Which plan was bought.
     *
     * An order has no plan id — that concept only exists for subscriptions.
     * So the checkout writes the plan's slug into reference_id, and this
     * reads it back. The AMOUNT is deliberately not used to identify the
     * plan: two plans can cost the same, and a price that changed between
     * purchase and webhook would silently match the wrong one.
     */
    protected function planFrom(array $resource): ?Plan
    {
        $slug = trim((string) ($resource['reference_id'] ?? ''));

        if ($slug === '' || $slug === 'default') {
            return null;
        }

        return Plan::where('slug', $slug)->first();
    }

    /**
     * A payment that could not be attributed, written down anyway.
     *
     * Deliberately not thrown away. This row is the only trace that money
     * arrived, and somebody is going to go looking for it.
     */
    protected function orphan(string $captureId, array $capture, ?User $user, ?Plan $plan): void
    {
        Transaction::updateOrCreate(
            ['gateway' => 'paypal', 'external_id' => $captureId],
            [
                'user_id' => $user?->id,
                'plan_id' => $plan?->id,
                'plan_name' => $plan?->name,
                'email' => $user?->email,
                'type' => 'one_time',
                // 'completed' would be a lie about access, and 'failed' a lie
                // about the money. Pending is the honest word: something is
                // owed to somebody and a human has to finish it.
                'status' => 'pending',
                'amount_cents' => $this->cents($capture['amount']['value'] ?? null),
                'currency' => strtoupper((string) ($capture['amount']['currency_code'] ?? 'USD')),
                'paid_at' => $this->time($capture['create_time'] ?? null) ?? now(),
                'payload' => $capture,
            ],
        );

        Log::error('PayPal one-off payment could not be attributed', [
            'capture' => $captureId,
            'custom_id' => $capture['custom_id'] ?? null,
            'reference_id' => $capture['reference_id'] ?? null,
        ]);
    }

    /**
     * Why a purchase could not be attached, in words.
     *
     * This is the sentence somebody reads on the admin screen at the worst
     * possible moment: a customer has paid and has nothing. "No handler"
     * sends them into the code; "no local user matched custom_id 41" sends
     * them to the row that is actually wrong.
     */
    protected function whyUnmatched(?User $user, ?Plan $plan, array $source): string
    {
        return collect([
            ! $user ? 'no local user matched custom_id '.($source['custom_id'] ?? '(absent)') : null,
            ! $plan ? 'no local plan matched reference_id '.($source['reference_id'] ?? '(absent)') : null,
        ])->filter()->implode('; ');
    }

    /** PayPal sends ISO 8601; a bad one must not take the handler down. */
    protected function time(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * "9.00" into 900.
     *
     * Through a string and round(), never (int) ($value * 100): in binary
     * floating point 9.00 * 100 can land on 899.9999999999999, and (int)
     * truncates that to 899. A cent lost per payment is invisible until
     * somebody reconciles a year of it.
     */
    protected function cents(?string $value): int
    {
        return blank($value) ? 0 : (int) round(((float) $value) * 100);
    }
}
