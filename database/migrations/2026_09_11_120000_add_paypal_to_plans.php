<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The link between a dbelo plan row and the same plan inside PayPal.
 *
 * ── WHY FOUR COLUMNS AND NOT ONE ─────────────────────────────────────────
 *
 * PayPal's sandbox and live environments are separate installations that
 * share nothing. A plan created in sandbox has an id like P-5ML4271244454,
 * and that id does not exist in live — a request for it comes back 404, at
 * the worst possible moment, which is the first real customer.
 *
 * Storing one id per environment means the switch from sandbox to live is
 * one line in .env and nothing else. Storing a single id would mean either
 * wiping the ids at launch (and losing the ability to go back to sandbox to
 * reproduce a bug) or discovering on launch day that every plan points at a
 * test account.
 *
 * The product id is stored alongside because PayPal nests plans under a
 * product: a plan cannot be created without one, and creating a second
 * product per plan produces a catalogue full of duplicates that cannot be
 * deleted — PayPal has no delete for either.
 *
 * ── WHY NULL IS A NORMAL STATE ───────────────────────────────────────────
 *
 * Three of the four seeded plans will never have an id here:
 *
 *   free       — nothing to charge, so nothing to create at PayPal.
 *   day-pass   — a one-off purchase, which goes through the Orders API.
 *                Orders do not use plans at all.
 *   (any plan that has not been synced yet)
 *
 * So "has a paypal_plan_id" is not the same question as "is sellable", and
 * code must not treat null as broken. Plan::isRecurring() is the question
 * that actually matters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // Live environment.
            $table->string('paypal_product_id')->nullable()->after('is_active');
            $table->string('paypal_plan_id')->nullable()->after('paypal_product_id');

            // Sandbox environment. Kept forever, not wiped at launch: the
            // ability to reproduce a billing bug against test money is worth
            // two columns.
            $table->string('paypal_product_id_sandbox')->nullable()->after('paypal_plan_id');
            $table->string('paypal_plan_id_sandbox')->nullable()->after('paypal_product_id_sandbox');

            /*
             * When the PayPal side was last confirmed to match this row.
             *
             * PayPal cannot change the price of an existing plan the way a
             * form here can: a price change is a new plan, and the old one
             * keeps billing its existing subscribers at the old price. This
             * column is what lets the admin screen say "the price here is
             * $14, PayPal is still charging $10, you changed this and never
             * synced" instead of finding out from a bank statement.
             */
            $table->timestamp('paypal_synced_at')->nullable()->after('paypal_plan_id_sandbox');

            // The price that was actually pushed to PayPal, in cents. Compared
            // against price_cents to detect the drift described above.
            $table->unsignedInteger('paypal_price_cents')->nullable()->after('paypal_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn([
                'paypal_product_id',
                'paypal_plan_id',
                'paypal_product_id_sandbox',
                'paypal_plan_id_sandbox',
                'paypal_synced_at',
                'paypal_price_cents',
            ]);
        });
    }
};
