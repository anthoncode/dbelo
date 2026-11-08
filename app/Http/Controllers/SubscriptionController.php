<?php

namespace App\Http\Controllers;

use App\Services\PayPal\PayPalClient;
use App\Services\PayPal\PayPalException;
use App\Services\PayPal\SubscriptionEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Ending a subscription, from the account screen.
 *
 * ── WHY THIS DID NOT EXIST UNTIL NOW ─────────────────────────────────────
 *
 * The plan page said, in words, that cancelling had to go through email.
 * That was the honest thing to show while there was no way to cancel at
 * PayPal — a button that marked a subscription cancelled only on this side
 * would be the worst of the three possible outcomes: the customer believes
 * they are done, PayPal keeps charging them, and nobody notices for a
 * month.
 *
 * What changed is not PayPal's API, which always had this. It is that
 * there is now a client that can call it, and a handler that already knows
 * what a cancellation means to this application. The button was blocked on
 * those two things and not on the idea.
 *
 * ── CANCELLING IS NOT SWITCHING OFF ──────────────────────────────────────
 *
 * Somebody who cancels on the 3rd has paid until the 30th and keeps every
 * day of it. PayPal stops the renewal; the plan stays live until the period
 * they already bought runs out.
 *
 * That rule lives in SubscriptionEvents::applyCancellation(), shared with
 * the webhook, and this calls it rather than repeating it. A second
 * implementation that forgot the rule would take four weeks from somebody
 * — silently, because the person would assume that is how it works.
 */
class SubscriptionController extends Controller
{
    public function cancel(Request $request, PayPalClient $paypal, SubscriptionEvents $events): RedirectResponse
    {
        $user = $request->user();

        $back = fn (string $key, ?string $message = null) => redirect()
            ->route('plan.show')
            ->with($key, $message ?? true);

        $subscription = $user->activeSubscription();

        /*
         * Nothing to cancel, said plainly rather than as an error. The most
         * likely way to arrive here is a second click on a button that
         * already worked, or a stale page — neither is a mistake worth
         * shouting about.
         */
        if (! $subscription || ! $subscription->plan?->isRecurring()) {
            return $back('cancel_error', 'There is no recurring plan to cancel.');
        }

        if ($subscription->cancelled_at) {
            return $back('cancel_done');
        }

        if (! $paypal->isConfigured() || blank($subscription->external_id)) {
            Log::error('Cancellation attempted without a usable PayPal subscription', [
                'subscription' => $subscription->id,
                'configured' => $paypal->isConfigured(),
            ]);

            return $back('cancel_error', 'We could not reach PayPal. Nothing was changed — please contact us.');
        }

        try {
            $paypal->post("/v1/billing/subscriptions/{$subscription->external_id}/cancel", [
                // PayPal requires a reason and shows it in the merchant's
                // own records. Written for the person who will read it
                // there months from now, not for a log.
                'reason' => 'Cancelled by the subscriber from their account page',
            ]);
        } catch (PayPalException $e) {
            report($e);

            /*
             * NOTHING is written locally when this fails, and that
             * asymmetry is the whole safety of this endpoint.
             *
             * Marking it cancelled here while PayPal kept billing is
             * exactly the outcome the missing button was avoiding. Leaving
             * it untouched means the worst case is a person who has to try
             * again — annoying, and honest.
             */
            return $back('cancel_error', 'PayPal could not cancel the subscription. Nothing was changed — please try again or contact us.');
        }

        /*
         * Read it back rather than assuming.
         *
         * The cancel call answers 204 with no body, so it says that PayPal
         * accepted the instruction and nothing about WHEN the paid period
         * ends — which is the one fact the customer cares about. That lives
         * in billing_info.next_billing_time, and the only way to know it is
         * to ask.
         */
        try {
            $resource = $paypal->get("/v1/billing/subscriptions/{$subscription->external_id}");
        } catch (PayPalException $e) {
            report($e);

            /*
             * Cancelled at PayPal, and we could not read the end date.
             *
             * The cancellation is still recorded — forgetting it because a
             * second call failed would leave a subscription that looks live
             * here and is dead there. With no resource, applyCancellation()
             * leaves ends_at null, which keeps access open: the safe
             * direction, and the webhook or the nightly reconcile will
             * correct it.
             */
            $events->applyCancellation($subscription, []);

            return $back('cancel_done');
        }

        $events->applyCancellation($subscription, $resource);

        return $back('cancel_done');
    }
}
