<?php

namespace App\Services\PayPal;

use App\Models\WebhookEvent;

/**
 * Which handler owns which PayPal event type.
 *
 * ── WHY THIS EXISTS AT ALL ───────────────────────────────────────────────
 *
 * There are now two handlers — subscriptions and one-off orders — and the
 * webhook controller has exactly one job: record, verify, answer a status
 * code. The moment it also knows that PAYMENT.SALE.* is a subscription and
 * PAYMENT.CAPTURE.* is an order, it is making a business decision in a class
 * whose other responsibility is choosing between 200 and 500.
 *
 * So the mapping lives here, in one place, derived from the handlers' own
 * HANDLED lists rather than restated. A third payment type later adds one
 * line to this file and touches nothing else.
 *
 * ── THE OVERLAP GUARD ────────────────────────────────────────────────────
 *
 * Dispatching on list membership is only safe while the lists are disjoint.
 * If a type were ever added to both, the first match would win silently and
 * the other handler would simply never run — a bug with no symptom except
 * money that stops being recorded. overlap() exists so that a test can
 * assert what a comment can only hope for.
 */
class EventRouter
{
    public function __construct(
        private SubscriptionEvents $subscriptions,
        private OrderEvents $orders,
    ) {}

    public function handle(WebhookEvent $event): void
    {
        $type = (string) $event->event_type;

        match (true) {
            in_array($type, SubscriptionEvents::HANDLED, true) => $this->subscriptions->handle($event),
            in_array($type, OrderEvents::HANDLED, true) => $this->orders->handle($event),

            /*
             * Recorded and marked IGNORED, which is a different state from
             * HANDLED on purpose: PayPal sends types nobody subscribed to,
             * and filing those as handled would make a type that genuinely
             * should have been processed indistinguishable from them.
             */
            default => $event->markIgnored('No handler for '.$type),
        };
    }

    /**
     * Every type the application acts on.
     *
     * This is the list to tick in PayPal's dashboard, and the reason it is a
     * method rather than a paragraph in a document: the dashboard and the
     * code drift apart the first time somebody adds a handler and forgets,
     * and the symptom is an event that never arrives.
     *
     * @return string[]
     */
    public static function handled(): array
    {
        return array_merge(SubscriptionEvents::HANDLED, OrderEvents::HANDLED);
    }

    /**
     * Types claimed by more than one handler. Must always be empty.
     *
     * @return string[]
     */
    public static function overlap(): array
    {
        return array_values(array_intersect(SubscriptionEvents::HANDLED, OrderEvents::HANDLED));
    }
}
