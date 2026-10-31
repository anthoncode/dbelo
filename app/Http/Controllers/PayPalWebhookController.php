<?php

namespace App\Http\Controllers;

use App\Models\WebhookEvent;
use App\Services\PayPal\PayPalClient;
use App\Services\PayPal\SubscriptionEvents;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Where PayPal tells us what happened.
 *
 * ── THIS IS THE SOURCE OF TRUTH, NOT THE RETURN URL ──────────────────────
 *
 * When somebody approves a subscription, PayPal sends them back to the site
 * and also sends this endpoint an event. It is tempting to grant the plan on
 * the return, because that is the moment the person is looking at the
 * screen — and it is wrong. The return is a browser redirect: it is lost if
 * they close the tab, it can be replayed by anybody who copies the URL, and
 * it says only that PayPal handed the browser back, not that money moved.
 *
 * This arrives server to server, is signed, and is retried for three days if
 * we are down. So the return URL gives a person something to read, and this
 * decides who has Pro.
 *
 * ── FOUR THINGS HAVE TO BE RIGHT, AND THEY ARE IN THIS ORDER ─────────────
 *
 *   1. ANSWER 200 ALMOST ALWAYS. A non-2xx makes PayPal retry, and retrying
 *      an event we rejected on purpose — a forged one, or a type we do not
 *      handle — means being hammered for three days over a decision that is
 *      not going to change. The only thing worth a 500 is a failure that a
 *      retry could genuinely fix.
 *
 *   2. RECORD BEFORE ACTING. The row is written first so that a crash
 *      halfway through leaves evidence instead of silence, and so the unique
 *      index can refuse the duplicate that PayPal's parallel retries create.
 *
 *   3. VERIFY THE SIGNATURE. Without it, this URL is "anybody who can POST
 *      JSON grants themselves Pro forever". It is the single most dangerous
 *      endpoint in the application.
 *
 *   4. NEVER TRUST THE BODY'S NUMBERS OVER OUR OWN. The event says which
 *      subscription changed; what that subscription is worth is read from
 *      our plans table. A forged body that passed verification would still
 *      not be able to invent a price.
 */
class PayPalWebhookController extends Controller
{
    public function __invoke(Request $request, PayPalClient $paypal, SubscriptionEvents $events): Response
    {
        $payload = $request->json()->all();

        $eventId = (string) ($payload['id'] ?? '');
        $eventType = (string) ($payload['event_type'] ?? '');

        /*
         * A body with no id is not a PayPal webhook. 400 and nothing else —
         * no row, because there is no id to make one unique by, and a table
         * of un-keyed junk is how the duplicate guard stops working.
         */
        if ($eventId === '' || $eventType === '') {
            return response('', 400);
        }

        /*
         * Written down before anything is decided about it.
         *
         * A null return means this exact event id is already in the table:
         * PayPal retried, or sent two copies in parallel. Answering 200 ends
         * the retries, and doing nothing else is the entire point — the
         * first copy already granted whatever it was going to grant.
         */
        $event = WebhookEvent::record($eventId, [
            'gateway' => 'paypal',
            'event_type' => $eventType,
            'mode' => $paypal->mode(),
            'verified' => false,
            'status' => WebhookEvent::PENDING,
            'resource_id' => $payload['resource']['id'] ?? null,
            'payload' => $payload,
        ]);

        if (! $event) {
            return response('', 200);
        }

        /*
         * ── THE SIGNATURE ───────────────────────────────────────────────
         *
         * verifyWebhookSignature() asks PayPal itself whether this body and
         * these headers really came from them. It needs PAYPAL_WEBHOOK_ID,
         * and returns false when that is missing — which is the correct
         * direction: an unconfigured site rejects everything rather than
         * trusting everything.
         *
         * A rejected event is recorded and answered 200. Not 403: a forged
         * request should not learn whether its forgery was detected, and a
         * genuine PayPal event that fails verification will fail every retry
         * too, so asking for three days of them helps nobody. The row is
         * what raises the alarm, on the admin screen, where somebody can
         * look at it.
         */
        if (! $paypal->verifyWebhookSignature($request->headers->all(), $payload)) {
            $event->markRejected();

            Log::warning('PayPal webhook failed signature verification', [
                'event_id' => $eventId,
                'event_type' => $eventType,
                'ip' => $request->ip(),
            ]);

            return response('', 200);
        }

        $event->forceFill(['verified' => true])->save();

        try {
            $events->handle($event);
        } catch (Throwable $e) {
            $event->markFailed($e->getMessage());

            report($e);

            /*
             * 500, and this is the ONE case that earns it.
             *
             * Everything above is a decision that a retry cannot improve. A
             * handler that threw is different: the event was real, it was
             * verified, and something on this end broke — a database that
             * was down for a second, a plan row being edited. Those come
             * back, and PayPal retrying for three days is exactly the
             * safety net wanted.
             *
             * The row stays FAILED, so the admin screen shows it even if
             * every retry also fails.
             */
            return response('', 500);
        }

        return response('', 200);
    }
}
