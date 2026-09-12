<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A code that grants days of a plan.
 *
 * The redemption lives here rather than in a controller because it will have
 * two callers — the public form that does not exist yet, and support acting
 * on somebody's behalf — and a rule enforced in two places is a rule that
 * disagrees with itself the first time one of them is fixed.
 */
class Coupon extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /* ═══════════════════════════ Reading ═══════════════════════════ */

    /** Codes nobody could redeem right now, excluded. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('max_redemptions')->orWhereColumn('redemptions', '<', 'max_redemptions'));
    }

    /**
     * Why this code cannot be used, or null when it can.
     *
     * A sentence rather than a boolean, because "invalid code" is the least
     * helpful thing a form can say: expired, used up, not started yet and
     * mistyped are four different problems and only one of them is the
     * person's fault.
     */
    public function reason(): ?string
    {
        if (! $this->is_active) {
            return 'This code has been switched off.';
        }

        if ($this->starts_at && $this->starts_at->isFuture()) {
            return 'This code does not start until '.$this->starts_at->toFormattedDateString().'.';
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return 'This code expired on '.$this->expires_at->toFormattedDateString().'.';
        }

        if ($this->max_redemptions !== null && $this->redemptions >= $this->max_redemptions) {
            return 'This code has been fully claimed.';
        }

        return null;
    }

    public function isUsable(): bool
    {
        return $this->reason() === null;
    }

    /** null when there is no cap. */
    public function remaining(): ?int
    {
        return $this->max_redemptions === null
            ? null
            : max(0, $this->max_redemptions - $this->redemptions);
    }

    /* ═══════════════════════════ Redeeming ═══════════════════════════ */

    /**
     * Hand somebody the days this code is worth.
     *
     * WHY A TRANSACTION WITH A ROW LOCK. Every check below is only true for
     * as long as nothing else changes the row, and the moment that matters
     * is exactly the moment a code goes round a Discord server: two people
     * arrive together, both read "3 of 100 claimed", both write, and the cap
     * is not a cap. lockForUpdate makes the second one wait for the first.
     *
     * The unique index on (coupon_id, user_id) is the second line of
     * defence, for the same person in two tabs. It is caught below and
     * turned into a sentence rather than a 500.
     *
     * @throws RuntimeException with a message meant to be shown to the person
     */
    public function redeemFor(User $user): Subscription
    {
        return DB::transaction(function () use ($user) {
            $coupon = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if (! $coupon) {
                throw new RuntimeException('That code no longer exists.');
            }

            if ($why = $coupon->reason()) {
                throw new RuntimeException($why);
            }

            /*
             * One subscription at a time.
             *
             * activeSubscription() reads the newest active row and nothing
             * else, so a second one is not a bonus — it is time that exists
             * in the table and is never granted to anybody. Refusing is
             * kinder than silently swallowing the code.
             */
            if ($user->activeSubscription()) {
                throw new RuntimeException('This account already has an active subscription. The code will still work once it ends.');
            }

            $subscription = Subscription::create([
                'user_id' => $user->id,
                'plan_id' => $coupon->plan_id,
                'status' => 'active',
                // Not 'manual' and not 'paypal'. A comped account, a repaired
                // webhook and a promo code are three different stories, and a
                // report that cannot tell them apart cannot answer which
                // campaign worked.
                'gateway' => 'coupon',
                'external_id' => $coupon->code,
                'starts_at' => now(),
                'ends_at' => now()->addDays($coupon->days),
            ]);

            try {
                CouponRedemption::create([
                    'coupon_id' => $coupon->id,
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                    'redeemed_at' => now(),
                ]);
            } catch (QueryException $e) {
                // The unique index fired: this person already used this code.
                throw new RuntimeException('This account has already used that code.');
            }

            $coupon->increment('redemptions');

            return $subscription;
        });
    }

    /* ═══════════════════════════ Making one ═══════════════════════════ */

    /**
     * A code somebody can read out loud.
     *
     * No O, 0, I or 1: they are the four characters that get mistyped when a
     * code is spoken, screenshotted or printed, and a promo code exists to be
     * passed around by exactly those routes.
     */
    public static function suggestCode(string $prefix = ''): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $body = '';

        for ($i = 0; $i < 6; $i++) {
            $body .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $code = strtoupper(Str::of($prefix)->replaceMatches('/[^A-Za-z0-9]/', '')->limit(10, ''));

        return $code === '' ? $body : $code.$body;
    }

    public static function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }
}
