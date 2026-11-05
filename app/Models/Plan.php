<?php

namespace App\Models;

use App\Services\PayPal\PayPalClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'allows_premium' => 'boolean',
            'is_active' => 'boolean',
            'paypal_synced_at' => 'datetime',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function isUnlimited(): bool
    {
        return $this->daily_download_limit === null;
    }

    public function isFree(): bool
    {
        return $this->price_cents === 0;
    }

    public function priceForHumans(): string
    {
        return $this->isFree()
            ? 'Free'
            : '$'.number_format($this->price_cents / 100, 2);
    }

    /* ═══════════════════════════ PayPal ═══════════════════════════ */

    /**
     * Does this plan bill again by itself?
     *
     * This is the question that decides WHICH PayPal API a plan uses, and it
     * is not the same as "costs money":
     *
     *   free      → free, no API at all
     *   pro/year  → recurring, Subscriptions API, needs a PayPal plan id
     *   day-pass  → costs money, bills once, Orders API, never gets a plan id
     *
     * Anything that checks `price_cents > 0` to decide whether a PayPal plan
     * is missing will report the day pass as broken forever.
     */
    public function isRecurring(): bool
    {
        return $this->price_cents > 0 && in_array($this->interval, ['month', 'year'], true);
    }

    /** A one-off purchase: paid, but never billed again. */
    public function isOneOff(): bool
    {
        return $this->price_cents > 0 && ! $this->isRecurring();
    }

    /**
     * The column holding this environment's ids.
     *
     * Sandbox and live ids live in different columns on purpose — see the
     * migration. Everything reads them through here so that no caller has to
     * remember which suffix belongs to which mode.
     */
    private function suffix(?string $mode = null): string
    {
        $mode = $mode ?? app(PayPalClient::class)->mode();

        return $mode === PayPalClient::LIVE ? '' : '_sandbox';
    }

    public function paypalPlanId(?string $mode = null): ?string
    {
        return $this->{'paypal_plan_id'.$this->suffix($mode)} ?: null;
    }

    public function paypalProductId(?string $mode = null): ?string
    {
        return $this->{'paypal_product_id'.$this->suffix($mode)} ?: null;
    }

    public function setPayPalIds(string $productId, string $planId, ?string $mode = null): void
    {
        $suffix = $this->suffix($mode);

        $this->forceFill([
            'paypal_product_id'.$suffix => $productId,
            'paypal_plan_id'.$suffix => $planId,
            'paypal_synced_at' => now(),
            'paypal_price_cents' => $this->price_cents,
        ])->save();
    }

    /** Ready to sell through PayPal right now, in this environment. */
    public function isSyncedWithPayPal(?string $mode = null): bool
    {
        return ! $this->isRecurring() || $this->paypalPlanId($mode) !== null;
    }

    /**
     * The price was edited here and never pushed to PayPal.
     *
     * The dangerous half of this is invisible without the check: PayPal keeps
     * billing existing subscribers at the price its plan was created with,
     * so the admin screen can show $14 while every renewal collects $10, for
     * as long as nobody looks.
     */
    public function paypalPriceDrifted(): bool
    {
        return $this->isRecurring()
            && $this->paypal_price_cents !== null
            && (int) $this->paypal_price_cents !== (int) $this->price_cents;
    }

    /** What PayPal calls a billing interval. */
    public function paypalIntervalUnit(): string
    {
        return match ($this->interval) {
            'year' => 'YEAR',
            'week' => 'WEEK',
            'day' => 'DAY',
            default => 'MONTH',
        };
    }

    /**
     * When a pass bought now stops working.
     *
     * ── THE SAME COLUMN MEANS TWO DIFFERENT THINGS ──────────────────────
     *
     * For a recurring plan, `interval` is how often PayPal charges. For a
     * one-off, nothing is ever charged again, so the same column means how
     * long the thing lasts. One column, two meanings, decided by
     * isRecurring() — which is why this method is only ever called on a
     * one-off and says so.
     *
     * Reading the duration from the column rather than hardcoding one day is
     * what lets a week pass be added next year by inserting a row, with no
     * code change and nobody having to remember this method exists.
     *
     * $from makes stacking possible: buying a second pass while one is live
     * extends from the existing end instead of from now. See
     * OrderEvents::grant().
     */
    /*
     * ── CarbonInterface, NOT Illuminate\Support\Carbon ──────────────────
     *
     * This application uses immutable dates, so a datetime cast hands back a
     * CarbonImmutable — and the narrower hint rejected it:
     *
     *   TypeError: passEndsAt(): Argument #1 ($from) must be of type
     *   ?Illuminate\Support\Carbon, Carbon\CarbonImmutable given
     *
     * Which is not a theoretical tidiness problem. $from is only ever
     * non-null when somebody already holds a live pass, so this was the
     * stacking path: the SECOND pass anybody bought while one was running
     * would have thrown, in production, after taking their money.
     *
     * CarbonInterface is what both classes implement, and it is what a
     * method that only reads a date and adds to it actually needs.
     *
     * The body works under either kind without a change: addDay() mutates
     * and returns $this on the mutable one and returns a new instance on the
     * immutable one, and this returns that value rather than relying on the
     * variable having changed.
     */
    public function passEndsAt(?\Carbon\CarbonInterface $from = null): \Carbon\CarbonInterface
    {
        $from = ($from ?? now())->copy();

        return match ($this->interval) {
            'day' => $from->addDay(),
            'week' => $from->addWeek(),
            'month' => $from->addMonth(),
            'year' => $from->addYear(),

            /*
             * An unrecognised interval on a one-off plan is a data problem,
             * and the shortest window is the safe direction to be wrong in:
             * under-granting is a support message, over-granting is revenue
             * given away silently and for as long as nobody notices.
             */
            default => $from->addDay(),
        };
    }
}
