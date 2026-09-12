<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Codes that grant TIME, not discounts.
 *
 * ── WHY TIME AND NOT A PERCENTAGE ────────────────────────────────────────
 *
 * PayPal has no coupon concept. What it has is a trial period defined ON THE
 * PLAN — free or reduced for the first cycle, applied to everybody who signs
 * up through that plan — and plan-wide price changes. There is no way to say
 * "take 20% off this one person's subscription".
 *
 * So a discount coupon is not hard here, it is impossible, and a table
 * holding a percentage nothing could ever apply would be the most expensive
 * kind of feature: one that looks finished.
 *
 * Granting days sidesteps the gateway completely. A redemption writes an
 * ordinary subscriptions row with gateway = 'coupon' and an ends_at, which
 * every access check in the application already understands. It covers the
 * four things coupons are actually for — launch promotions, creator codes,
 * support gestures, and win-backs — and none of them need PayPal to agree.
 *
 * If a real price discount is ever wanted, it is a SECOND PAYPAL PLAN with a
 * trial period, made for a campaign and retired afterwards. That is a
 * different thing and deserves a different name.
 *
 * ── THE UNIQUE INDEX IS THE GUARD, NOT THE IF STATEMENT ──────────────────
 *
 * One redemption per person per code, enforced by the database. Checking in
 * PHP works right up until the moment it matters: a code that goes round a
 * Discord server has two people arriving in the same millisecond, and both
 * read "not redeemed yet" before either writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();

            // Stored upper-case and compared upper-case. Nobody types a
            // promo code the way it was printed.
            $table->string('code', 40)->unique();

            /*
             * restrictOnDelete, unlike most things here.
             *
             * A coupon whose plan was deleted grants nothing — it would take
             * the code, write a row pointing at a missing plan, and the
             * person would be left with an "active subscription" that every
             * access check reads as no plan at all. Better to refuse to
             * delete the plan.
             */
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();

            /*
             * How long the access lasts, in days — not the plan's own
             * interval. A code for "two weeks of Pro" is a normal thing to
             * want and the plan says a month; these are different questions.
             */
            $table->unsignedSmallInteger('days')->default(30);

            // null means unlimited. The counter beside it is what makes a
            // limit enforceable without counting rows on every redemption.
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemptions')->default(0);

            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->boolean('is_active')->default(true);

            // Internal. Which campaign, which creator, which apology — the
            // thing you will want in six months when the report says a code
            // was redeemed four hundred times and nobody remembers whose.
            $table->string('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['is_active', 'expires_at']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The subscription it produced. Nullable because the row must
            // survive the subscription being cleaned up — the redemption
            // still happened, and "has this person used this code?" has to
            // stay answerable.
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('redeemed_at')->nullable();

            $table->timestamps();

            // See the note at the top: this is the guard.
            $table->unique(['coupon_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
