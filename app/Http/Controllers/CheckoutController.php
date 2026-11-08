<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\PayPal\PayPalClient;
use App\Services\PayPal\PayPalException;
use App\Services\PayPal\SubscriptionEvents;
use App\Support\PublicUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Buying a plan.
 *
 * Three steps, and only the first one leaves this application:
 *
 *   start    create a subscription at PayPal and send the person to it
 *   return   PayPal sends them back approved — confirm and say so
 *   cancel   PayPal sends them back having changed their mind
 *
 * ── WHAT THIS DELIBERATELY DOES NOT DO ──────────────────────────────────
 *
 * It does not decide who has Pro on the strength of the browser coming
 * back. The return URL is a redirect: it can be closed, bookmarked,
 * replayed, or never reached at all because somebody's phone rang. The
 * webhook is the record that matters, and it arrives server to server with
 * a signature and three days of retries behind it.
 *
 * So why does `return` write anything at all? Because the webhook can
 * arrive seconds AFTER the person is already looking at the page, and "you
 * have paid, come back later to see it" is a terrible first minute of a
 * subscription. The return confirms the subscription DIRECTLY WITH PAYPAL,
 * using our own credentials, and then calls the same activation method the
 * webhook calls. Whichever gets there first wins; the second is a no-op.
 *
 * That is why SubscriptionEvents::activate() is public and shared rather
 * than copied: two code paths that both grant a plan must not be able to
 * disagree about what granting means.
 */
class CheckoutController extends Controller
{
    /**
     * POST, never GET, and the form in the view is the reason.
     *
     * This creates a real subscription at PayPal. A GET would be fetched by
     * link prefetchers, by crawlers, and twice by anyone who double-clicks
     * — each one a dangling unapproved subscription in the PayPal account.
     */
    public function start(Request $request, Plan $plan, PayPalClient $paypal): RedirectResponse
    {
        $user = $request->user();

        $back = fn (string $message) => redirect()
            ->route('plan.show')
            ->with('checkout_error', $message);

        if (! $paypal->isConfigured()) {
            Log::error('Checkout attempted with PayPal not configured');

            return $back('Subscriptions are not available right now. Nothing was charged.');
        }

        if (! $plan->is_active || ! $plan->isRecurring()) {
            return $back('That plan cannot be subscribed to.');
        }

        /*
         * The plan has no twin at PayPal.
         *
         * Caught here rather than letting the API call fail, because the
         * error PayPal returns for an unknown plan id says nothing a person
         * could act on — and the person who can act on it is the operator,
         * who needs the words "paypal:sync-plans".
         */
        if (! $plan->isSyncedWithPayPal()) {
            Log::error('Checkout attempted for a plan with no PayPal id', [
                'plan' => $plan->slug,
                'mode' => $paypal->mode(),
            ]);

            return $back('That plan is not ready for payment yet.');
        }

        // Already on it. Sending somebody to pay for what they have is the
        // clearest sign a page is not looking at who is reading it.
        if ($user->currentPlan()?->id === $plan->id) {
            return $back('You are already on '.$plan->name.'.');
        }

        /*
         * ── ALREADY PAYING FOR A DIFFERENT RECURRING PLAN ───────────────
         *
         * This guard used to compare only the SAME plan, and that left a
         * hole wide enough to charge somebody twice: a subscriber on Pro
         * Yearly was offered "Subscribe to Pro", and taking it would have
         * created a SECOND subscription at PayPal without cancelling the
         * first. $89 a year and $14 a month, at the same time, from one
         * button on the account page.
         *
         * PayPal has a proper answer for this — /revise moves an existing
         * subscription to another plan and prorates it — and until that is
         * built, refusing is the only honest option. Cancelling here on
         * their behalf would be worse: it decides for somebody that they
         * want to lose their current plan, before they have agreed to the
         * new one.
         *
         * So: refuse, and name the way through. The Cancel button on the
         * account page is what makes that sentence true rather than a
         * brush-off — the paid period is kept either way.
         */
        $current = $user->activeSubscription();

        if ($current && $current->plan?->isRecurring()) {
            return $back('You are on '.$current->plan->name.'. Cancel it first and you can subscribe to '.$plan->name.' — you keep the days you have already paid for.');
        }

        /*
         * ── CAN PAYPAL EVER GET BACK HERE? ──────────────────────────────
         *
         * This check exists because its absence produced the single most
         * confusing failure in the whole integration. Everything worked:
         * the plans synced, the button lit up, PayPal's approval screen
         * appeared, the buyer pressed "Agree & Subscribe" — and the page
         * simply hung. No error, nothing in the log, nothing to search for.
         *
         * The return_url was `http://dbelo.test/checkout/return`, a Herd
         * address that exists on one laptop. The browser could have found
         * it; PayPal could not, and stopped.
         *
         * Note that route() builds this from the CURRENT REQUEST's host, not
         * from APP_URL. So behind a tunnel it is not enough for APP_URL to
         * be public — the site has to be BROWSED through the public address.
         * That distinction is invisible and is most of why this guard says
         * the whole URL out loud instead of just refusing.
         *
         * The webhook has the same problem and no guard can fix it: PayPal's
         * servers post to /webhooks/paypal from the internet, and the
         * webhook is what actually grants the plan. A tunnel is therefore
         * not a convenience here, it is the only way the flow completes.
         */
        $returnUrl = route('checkout.return');

        if (! PublicUrl::reachable($returnUrl)) {
            Log::error('Checkout blocked: PayPal could not return to this site', [
                'return_url' => $returnUrl,
                'problem' => PublicUrl::problem($returnUrl),
                'fix' => 'Browse the site through a public https address (herd share) or use the deployed site.',
            ]);

            /*
             * Detailed only with APP_DEBUG on, and that asymmetry is the
             * point: in local development the operator IS the person looking
             * at the hung page, and sending them to the log file is how an
             * afternoon disappears. A real buyer gets nothing but the
             * reassurance that their money did not move.
             */
            return $back(config('app.debug')
                ? 'PayPal cannot return to '.$returnUrl.' — '.PublicUrl::problem($returnUrl).'. Open the site through a public https address and try again.'
                : 'Subscriptions are not available right now. Nothing was charged.');
        }

        try {
            $subscription = $paypal->post('/v1/billing/subscriptions', [
                'plan_id' => $plan->paypalPlanId(),

                /*
                 * OUR user id, travelling to PayPal and back.
                 *
                 * This is the only identifier in the whole exchange that was
                 * ours before PayPal touched it, and it is what the webhook
                 * reads to know whose subscription this is. Matching on the
                 * payer's email instead would attach the plan to whoever
                 * owns that address — which is not necessarily the person
                 * who just paid.
                 */
                'custom_id' => (string) $user->id,

                'subscriber' => [
                    'name' => ['given_name' => $user->name],
                    // Pre-filled, not enforced. It saves typing for the
                    // common case and PayPal lets them pay with a different
                    // account if that is the one they have — which is
                    // exactly why custom_id above exists.
                    'email_address' => $user->email,
                ],

                'application_context' => [
                    'brand_name' => $paypal->brandName(),
                    'locale' => 'en-US',
                    // No address is collected: this is a download, not a
                    // parcel, and every field between a person and a
                    // purchase costs conversions.
                    'shipping_preference' => 'NO_SHIPPING',
                    // The button on PayPal's screen says "Subscribe Now"
                    // rather than "Continue", so the commitment happens
                    // there instead of on a second screen here.
                    'user_action' => 'SUBSCRIBE_NOW',
                    // The checked value, not a second route() call: the two
                    // would be identical today and that is exactly how a
                    // guard quietly stops guarding the thing it names.
                    'return_url' => $returnUrl,
                    'cancel_url' => route('checkout.cancel'),
                ],
            ], requestId: 'sub-'.$user->id.'-'.$plan->id.'-'.now()->format('YmdHis'));
        } catch (PayPalException $e) {
            report($e);

            return $back('PayPal could not start the subscription. Nothing was charged.');
        }

        $approve = collect($subscription['links'] ?? [])
            ->firstWhere('rel', 'approve')['href'] ?? null;

        if (! $approve) {
            Log::error('PayPal subscription created with no approve link', [
                'subscription' => $subscription['id'] ?? null,
            ]);

            return $back('PayPal did not return a payment link. Nothing was charged.');
        }

        /*
         * away() and not to(): this leaves the application entirely, and
         * redirect()->to() would run it through the URL generator, which
         * exists to build links to ourselves.
         */
        return redirect()->away($approve);
    }

    /**
     * They approved it and PayPal sent them back.
     *
     * ── THE QUERY STRING IS NOT EVIDENCE ────────────────────────────────
     *
     * PayPal appends ?subscription_id=... to this URL. Anybody can type
     * that, with any id. So the id is used only to ask PayPal a question —
     * "what is the state of this subscription?" — answered over an
     * authenticated connection with our own credentials. The answer is the
     * evidence; the query string is just the question.
     *
     * And the answer is checked for two things, not one: that the
     * subscription is active, and that its custom_id is the person standing
     * here. Without the second, somebody could paste another customer's
     * subscription id and be granted their plan.
     */
    public function return(Request $request, PayPalClient $paypal, SubscriptionEvents $events): RedirectResponse
    {
        $user = $request->user();
        $id = (string) $request->query('subscription_id', '');

        if ($id === '' || ! $user) {
            return redirect()->route('plan.show');
        }

        try {
            $resource = $paypal->get("/v1/billing/subscriptions/{$id}");
        } catch (PayPalException $e) {
            report($e);

            /*
             * Not an error page. The payment may well have gone through —
             * this call failing says our connection to PayPal hiccuped, not
             * that the money did not move. The webhook will settle it
             * within seconds, so the honest message is "we are checking",
             * never "it failed".
             */
            return redirect()->route('plan.show')
                ->with('checkout_pending', true);
        }

        $belongsToThem = (string) ($resource['custom_id'] ?? '') === (string) $user->id;

        if (! $belongsToThem) {
            Log::warning('Checkout return with a subscription belonging to somebody else', [
                'user' => $user->id,
                'subscription' => $id,
            ]);

            return redirect()->route('plan.show');
        }

        /*
         * APPROVAL_PENDING and APPROVED are both "not paid yet": PayPal has
         * the mandate but the first charge has not settled. ACTIVE is the
         * one that means money moved.
         */
        if (($resource['status'] ?? '') !== 'ACTIVE') {
            return redirect()->route('plan.show')->with('checkout_pending', true);
        }

        // The same method the webhook calls. Idempotent, so whichever of
        // the two arrives first does the work and the other does nothing.
        $events->activate($resource);

        return redirect()->route('plan.show')->with('checkout_done', true);
    }

    /**
     * They changed their mind on PayPal's screen.
     *
     * Nothing to undo: the subscription at PayPal was never approved and
     * expires on its own. No message either — somebody who decided not to
     * buy does not need the site to remark on it.
     */
    public function cancel(): RedirectResponse
    {
        return redirect()->route('plan.show');
    }
}
