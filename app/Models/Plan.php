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
}
