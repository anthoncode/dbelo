<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every webhook PayPal sends us, written down before it is acted on.
 *
 * ── WHY THIS TABLE EXISTS AT ALL ─────────────────────────────────────────
 *
 * A webhook handler that only grants access leaves no trace. When a customer
 * writes "I paid and I do not have Pro", the only honest answer available is
 * "I do not know what PayPal told us". This table turns that into a lookup.
 *
 * ── PAYPAL DELIVERS THE SAME EVENT MORE THAN ONCE ────────────────────────
 *
 * This is documented behaviour, not a fault: if the endpoint does not answer
 * 200 fast enough — a slow query, a deploy, a restart — PayPal retries, with
 * backoff, for three days. It will also occasionally deliver a duplicate of
 * something that did succeed.
 *
 * Without a guard, each retry of PAYMENT.SALE.COMPLETED extends the
 * subscription by another month. The customer pays for one month and gets
 * four, and nothing in the system looks wrong.
 *
 * The unique index on (gateway, event_id) is that guard, and it is an index
 * rather than a SELECT-then-INSERT because retries arrive in parallel: two
 * deliveries of the same event can both pass a "have we seen this?" check
 * microseconds apart. The database refusing the second INSERT is the only
 * version of this that actually holds.
 *
 * gateway is part of the key because event ids are only unique within a
 * provider. If a second processor is ever added, its ids are its own
 * namespace, and a collision here would silently drop a real event.
 *
 * ── WHY THE RAW PAYLOAD IS KEPT ──────────────────────────────────────────
 *
 * Handlers have bugs. When one is fixed, the events it mishandled are
 * already gone from PayPal's retry window — but they are here, and they can
 * be replayed. Keeping the payload is the difference between "we can repair
 * the twelve accounts this affected" and "we can apologise".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();

            // Not hardcoded to paypal. The column is what makes the unique
            // index below safe if anything else is ever plugged in.
            $table->string('gateway', 30)->default('paypal');

            // PayPal's own id for the event (WH-...). The idempotency key.
            $table->string('event_id');

            // BILLING.SUBSCRIPTION.ACTIVATED, PAYMENT.SALE.COMPLETED, ...
            $table->string('event_type', 80);

            // Which environment produced it. A sandbox event arriving at a
            // live site is a misconfigured webhook, and without this column
            // it looks like a real payment that failed to apply.
            $table->string('mode', 10)->default('sandbox');

            /*
             * Did the signature check pass?
             *
             * Stored rather than assumed, because the handler records the
             * event BEFORE verifying it. An unverified event that keeps
             * arriving is somebody probing the endpoint, and that is worth
             * being able to see.
             */
            $table->boolean('verified')->default(false);

            /*
             * pending  — written, not yet processed
             * handled  — processed, something changed
             * ignored  — a real event this application does not care about
             * rejected — failed signature verification
             * failed   — the handler threw; see `error`
             *
             * 'ignored' is deliberately distinct from 'handled'. PayPal sends
             * a dozen event types nobody subscribed to; if they were marked
             * handled, a genuinely unhandled event type would be invisible.
             */
            $table->string('status', 20)->default('pending');

            // The subscription or order id inside the payload, lifted out so
            // "show me everything that happened to this subscription" is one
            // query instead of a JSON scan.
            $table->string('resource_id')->nullable()->index();

            $table->json('payload')->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('handled_at')->nullable();

            $table->timestamps();

            // The guard. See the note above.
            $table->unique(['gateway', 'event_id']);

            // "What is stuck?" — the query the admin screen will run.
            $table->index(['status', 'created_at']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
