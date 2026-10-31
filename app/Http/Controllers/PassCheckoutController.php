<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\WebhookEvent;
use App\Services\PayPal\OrderEvents;
use App\Services\PayPal\PayPalClient;
use App\Services\PayPal\PayPalException;
use App\Support\PublicUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Buying a pass: paid once, never billed again.
 *
 * ── WHY NOT CheckoutController ───────────────────────────────────────────
 *
 * Because almost nothing is shared. A subscription is created at PayPal,
 * approved, and then charged by PayPal forever without us. A pass is an
 * ORDER: created, approved, and then CAPTURED BY US, once, and that is the
 * end of it. Different endpoints, different event types, and — the part that
 * would have made one class dishonest — a different answer to "who decides
 * the money moved".
 *
 * For a subscription, the webhook decides and the return URL is decoration.
 * Here the capture call is the decision, and it happens in return() below.
 *
 * ── THE ORDER OF THE THREE STEPS ────────────────────────────────────────
 *
 *   start    create an order at PayPal and send the person to approve it
 *   return   they approved — CAPTURE THE MONEY, then grant the pass
 *   cancel   they changed their mind; nothing to undo
 *
 * An APPROVED order that is never captured takes no money and gives nothing,
 * and voids itself after a few days. That is the worst possible outcome,
 * because the buyer thinks they bought something. It is also why
 * OrderEvents handles CHECKOUT.ORDER.APPROVED: if their browser never makes
 * it back here, the webhook captures server-side instead.
 */
class PassCheckoutController extends Controller
{
    /**
     * POST, never GET.
     *
     * This creates a real order at PayPal. A GET would be fetched by link
     * prefetchers, by crawlers, and twice by anyone who double-clicks —
     * each one a dangling unapproved order in the PayPal account.
     *
     * It is also what replaces the link that used to be here. That link
     * pointed at /settings/plan, which offers "See the plans", which points
     * back at this button: a closed loop with no way to pay, shipped and not
     * noticed. The same loop was fixed for the recurring plans days earlier
     * and this half was left behind.
     */
    public function start(Request $request, Plan $plan, PayPalClient $paypal): RedirectResponse
    {
        $user = $request->user();

        $back = fn (string $message) => redirect()
            ->route('plan.show')
            ->with('checkout_error', $message);

        if (! $paypal->isConfigured()) {
            Log::error('Pass checkout attempted with PayPal not configured');

            return $back('Passes are not available right now. Nothing was charged.');
        }

        if (! $plan->is_active || ! $plan->isOneOff()) {
            /*
             * isOneOff(), not "is not recurring": a free plan is neither,
             * and sending somebody to PayPal to pay nothing would fail at
             * PayPal with an amount of 0.00 and an error nobody could read.
             */
            return $back('That is not something you can buy once.');
        }

        /*
         * Already covered.
         *
         * Selling a day pass to somebody on Pro is selling them a day of
         * what they already have every day. The page should not offer it and
         * this refuses it anyway, because the page and this method are two
         * surfaces and only one of them is in front of the buyer.
         */
        $current = $user->activeSubscription();

        if ($current && $current->plan && $current->plan->isRecurring()) {
            return $back('Your '.$current->plan->name.' plan already includes this.');
        }

        /*
         * Can PayPal get back here at all? See PublicUrl — on a development
         * machine the answer is no, and without this check the symptom is
         * PayPal's own page hanging with no error anywhere.
         */
        $returnUrl = route('pass.return');

        if (! PublicUrl::reachable($returnUrl)) {
            Log::error('Pass checkout blocked: PayPal could not return to this site', [
                'return_url' => $returnUrl,
                'problem' => PublicUrl::problem($returnUrl),
            ]);

            return $back(config('app.debug')
                ? 'PayPal cannot return to '.$returnUrl.' — '.PublicUrl::problem($returnUrl).'. Open the site through a public https address and try again.'
                : 'Passes are not available right now. Nothing was charged.');
        }

        try {
            $order = $paypal->post('/v2/checkout/orders', [
                // CAPTURE, not AUTHORIZE. An authorisation has to be captured
                // within days or it expires, and holding money we have not
                // taken, for a nine dollar download pass, is complexity with
                // no upside.
                'intent' => 'CAPTURE',

                'purchase_units' => [[
                    /*
                     * The plan's slug, and this is how the webhook knows what
                     * was bought. An order carries no plan id — that concept
                     * only exists for subscriptions — so reference_id is the
                     * carrier. Identifying the plan by its AMOUNT instead
                     * would break the day two plans cost the same, and would
                     * match the wrong one if a price changed in between.
                     */
                    'reference_id' => $plan->slug,

                    /*
                     * OUR user id, travelling to PayPal and back. The only
                     * identifier in the exchange that was ours before PayPal
                     * touched it. Matching on the payer's email instead would
                     * attach the pass to whoever owns that address, which is
                     * not necessarily the person who just paid.
                     */
                    'custom_id' => (string) $user->id,

                    // Shown on PayPal's screen and on the buyer's statement.
                    'description' => mb_substr($plan->name.' — '.config('app.name'), 0, 127),

                    'amount' => [
                        'currency_code' => strtoupper((string) ($plan->currency ?: $paypal->currency())),
                        'value' => PayPalClient::amount((int) $plan->price_cents),
                    ],
                ]],

                'application_context' => [
                    'brand_name' => $paypal->brandName(),
                    'locale' => 'en-US',
                    // No address: this is a download, not a parcel, and every
                    // field between a person and a purchase costs conversions.
                    'shipping_preference' => 'NO_SHIPPING',
                    // PAY_NOW so the button on PayPal's screen says exactly
                    // that, and the commitment happens there rather than on a
                    // second screen here.
                    'user_action' => 'PAY_NOW',
                    'return_url' => $returnUrl,
                    'cancel_url' => route('pass.cancel'),
                ],
            ], requestId: 'order-'.$user->id.'-'.$plan->id.'-'.now()->format('YmdHis'));
        } catch (PayPalException $e) {
            report($e);

            return $back('PayPal could not start the purchase. Nothing was charged.');
        }

        $approve = $this->approvalLink($order);

        if (! $approve) {
            Log::error('PayPal order created with no approval link', [
                'order' => $order['id'] ?? null,
            ]);

            return $back('PayPal did not return a payment link. Nothing was charged.');
        }

        // away() and not to(): this leaves the application entirely, and
        // redirect()->to() would run it through the URL generator, which
        // exists to build links to ourselves.
        return redirect()->away($approve);
    }

    /**
     * They approved it. Now take the money.
     *
     * ── THE QUERY STRING IS NOT EVIDENCE ────────────────────────────────
     *
     * PayPal appends ?token=<order id> to this URL. Anybody can type that,
     * with any id. So the token is used only to ask PayPal a question —
     * "what is this order?" — answered over an authenticated connection with
     * our own credentials. The answer is the evidence.
     *
     * And the answer is checked for two things, not one: that the order is
     * approved, and that its custom_id is the person standing here. Without
     * the second, somebody could paste another customer's order id and be
     * granted their pass.
     */
    public function return(Request $request, PayPalClient $paypal, OrderEvents $orders): RedirectResponse
    {
        $user = $request->user();
        $orderId = (string) $request->query('token', '');

        if ($orderId === '' || ! $user) {
            return redirect()->route('plan.show');
        }

        try {
            $order = $paypal->get("/v2/checkout/orders/{$orderId}");
        } catch (PayPalException $e) {
            report($e);

            /*
             * Not an error page. This call failing says our connection to
             * PayPal hiccuped, not that the money did not move — and if the
             * buyer approved, CHECKOUT.ORDER.APPROVED will arrive and the
             * webhook will capture it. The honest message is "we are
             * checking", never "it failed".
             */
            return redirect()->route('plan.show')->with('checkout_pending', true);
        }

        $unit = (array) ($order['purchase_units'][0] ?? []);

        if ((string) ($unit['custom_id'] ?? '') !== (string) $user->id) {
            Log::warning('Pass return with an order belonging to somebody else', [
                'user' => $user->id,
                'order' => $orderId,
            ]);

            return redirect()->route('plan.show');
        }

        $status = (string) ($order['status'] ?? '');

        /*
         * COMPLETED means the webhook (or a replayed return) already
         * captured it. Nothing to charge — just make sure the pass exists,
         * because the capture succeeding and our records being written are
         * two different events and only one of them is PayPal's job.
         */
        if ($status === 'COMPLETED') {
            $this->grantFrom($order, $orders, $orderId);

            return redirect()->route('plan.show')->with('checkout_done', true);
        }

        if ($status !== 'APPROVED') {
            // CREATED or PAYER_ACTION_REQUIRED: they got here without
            // finishing. Nothing was charged and nothing is owed.
            return redirect()->route('plan.show')->with('checkout_pending', true);
        }

        try {
            $captured = $orders->capture($orderId);
        } catch (PayPalException $e) {
            if ($e->issue === 'ORDER_ALREADY_CAPTURED') {
                // The webhook won the race. Fine — grant from what exists.
                $this->grantFrom($order, $orders, $orderId);

                return redirect()->route('plan.show')->with('checkout_done', true);
            }

            report($e);

            /*
             * The capture genuinely failed. The order stays APPROVED, which
             * means CHECKOUT.ORDER.APPROVED is still coming and the webhook
             * will try again — so this is pending, not failed. Saying
             * "declined" here would be a guess, and the wrong one would send
             * somebody who HAS paid away believing they have not.
             */
            return redirect()->route('plan.show')->with('checkout_pending', true);
        }

        $this->grantFrom($captured, $orders, $orderId);

        return redirect()->route('plan.show')->with('checkout_done', true);
    }

    /**
     * They changed their mind on PayPal's screen.
     *
     * Nothing to undo: the order was never approved and PayPal voids it on
     * its own. No message either — somebody who decided not to buy does not
     * need the site to remark on it.
     */
    public function cancel(): RedirectResponse
    {
        return redirect()->route('plan.show');
    }

    /* ═══════════════════════════ Helpers ═══════════════════════════ */

    /**
     * Record the purchase through the same path the webhook uses.
     *
     * ── WHY A WebhookEvent ROW FOR SOMETHING THAT IS NOT A WEBHOOK ───────
     *
     * Because OrderEvents::recordAndGrant() reports what it did by marking
     * an event handled or failed, and that reporting is the thing the admin
     * screen reads. A capture made from the return URL that wrote no row
     * would be a purchase with no trace anywhere — and the case most worth
     * tracing is exactly this one, where our own call moved the money.
     *
     * The row is marked as locally originated so that nobody reading the
     * table later mistakes it for something PayPal sent. `verified` is true
     * in the only sense that matters: this did not arrive over a public URL
     * at all, it came back from an authenticated call we made ourselves.
     */
    protected function grantFrom(array $order, OrderEvents $orders, string $orderId): void
    {
        $capture = $orders->captureFrom($order);

        if (! $capture) {
            Log::error('Pass return with a completed order carrying no capture', [
                'order' => $orderId,
            ]);

            return;
        }

        $event = WebhookEvent::record('local-capture-'.($capture['id'] ?? $orderId), [
            'gateway' => 'paypal',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'mode' => app(PayPalClient::class)->mode(),
            'verified' => true,
            'status' => WebhookEvent::PENDING,
            'resource_id' => $capture['id'] ?? null,
            'payload' => ['resource' => $capture, 'source' => 'checkout_return'],
        ]);

        /*
         * Null means this capture has already been recorded — by the webhook
         * arriving first, or by the buyer reloading this page. Doing nothing
         * is the entire point: the first pass through already granted it.
         */
        if (! $event) {
            return;
        }

        $orders->recordAndGrant($event, $order, $capture);
    }

    /**
     * The link that sends the buyer to PayPal.
     *
     * Two rels, because PayPal has two request shapes and they answer
     * differently: an order built with `application_context` (what start()
     * uses) comes back with rel `approve`, while one built with
     * `payment_source.paypal.experience_context` comes back with
     * `payer-action`. Accepting both costs one line and survives the day
     * somebody modernises the request above.
     */
    protected function approvalLink(array $order): ?string
    {
        foreach ((array) ($order['links'] ?? []) as $link) {
            if (in_array($link['rel'] ?? '', ['approve', 'payer-action'], true) && filled($link['href'] ?? null)) {
                return (string) $link['href'];
            }
        }

        return null;
    }
}
