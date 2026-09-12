<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money that actually moved.
 *
 * ── A TRANSACTION IS HISTORY, NOT A VIEW OF THE PRESENT ──────────────────
 *
 * Everything a receipt needs is COPIED here, not looked up. plan_id is kept
 * for convenience, but plan_name and amount_cents are snapshots — because a
 * plan gets repriced and a row that reads its price through a relation would
 * quietly rewrite what somebody paid in March. A ledger whose past changes
 * is not a ledger.
 *
 * ── THE FEE IS STORED, AND THAT IS THE WHOLE POINT ───────────────────────
 *
 * PayPal reports what it kept on every capture: 3.49% + $0.49, plus 1.5%
 * cross-border. Gross revenue is the number that feels good; net is the
 * number that pays the server. On a $9 day pass those are $9.00 and $8.06 —
 * an eleven per cent difference that compounds across every report you will
 * ever read. Storing fee_cents and net_cents means the panel can show the
 * one that matters without guessing a rate that varies by country.
 *
 * ── THE UNIQUE INDEX IS THE MOST IMPORTANT LINE IN THIS FILE ─────────────
 *
 * PayPal resends a webhook when it does not get a 200, and sometimes sends
 * the same event twice anyway. Deduplicating in PHP works until two requests
 * arrive at once and both pass the "have we seen this?" check before either
 * writes. A unique index on (gateway, external_id) makes a double-credited
 * payment impossible at the database, not merely unlikely in the code.
 *
 * ── AND A PLACE FOR WHAT WE DID NOT PREDICT ──────────────────────────────
 *
 * This schema is being written before a single PayPal payload has been seen.
 * The columns above are the ones every gateway has and every report needs;
 * `payload` holds the raw event, so the day something is missing the answer
 * is already in the database instead of gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            /*
             * Nullable and nullOnDelete, unlike subscriptions.
             *
             * A subscription without its user is meaningless, so that one
             * cascades. A payment without its user is still a payment: the
             * money was received, it may need refunding, and it belongs in
             * a year-end total whether or not the account still exists.
             */
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();

            // The snapshot. What this was, in the words of the day it happened.
            $table->string('plan_name')->nullable();
            $table->string('email')->nullable();

            // subscription · one_time — a renewal and a day pass are both
            // income and are not the same business, and separating them
            // afterwards from an amount is guesswork.
            $table->string('type', 20)->default('subscription');

            // completed · pending · failed · refunded · partially_refunded
            $table->string('status', 24)->default('completed');

            $table->string('gateway', 30)->default('paypal');

            // PayPal's capture id. Nullable because a manual entry has none.
            $table->string('external_id')->nullable();

            /*
             * Integers, always. A price in a float is a price that is
             * 798.9999999 one day, and money that rounds differently
             * depending on which report added it up.
             */
            $table->unsignedInteger('amount_cents')->default(0);
            $table->unsignedInteger('fee_cents')->default(0);
            $table->unsignedInteger('net_cents')->default(0);
            $table->unsignedInteger('refunded_cents')->default(0);

            $table->string('currency', 3)->default('USD');

            /*
             * When the money moved, which is NOT when the row was written.
             * A webhook can arrive minutes or hours late, and a monthly total
             * built on created_at puts a payment in the wrong month whenever
             * one lands just after midnight on the first.
             */
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            // The raw event, for the questions this schema did not anticipate.
            $table->json('payload')->nullable();

            $table->timestamps();

            // See the note above: this is what makes a replayed webhook
            // impossible to double-credit rather than merely unlikely.
            $table->unique(['gateway', 'external_id']);

            $table->index(['status', 'paid_at']);
            $table->index(['user_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
