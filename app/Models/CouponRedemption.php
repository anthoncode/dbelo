<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Who used which code, and what it gave them.
 *
 * Kept even after the subscription it produced is gone. "Has this person
 * already used this code?" has to stay answerable for as long as the code
 * exists, and it is the row the unique index guards.
 */
class CouponRedemption extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['redeemed_at' => 'datetime'];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
