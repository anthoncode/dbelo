<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment, as it happened.
 *
 * Read-mostly on purpose. Rows are written by the PayPal webhook handler and
 * by the manual entry on the admin screen, and nothing else should ever edit
 * one — a ledger that gets corrected in place cannot be reconciled against
 * the gateway that holds the real record.
 */
class Transaction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /* ═══════════════════════════ Scopes ═══════════════════════════ */

    /**
     * Money you actually kept.
     *
     * Refunded rows are excluded entirely rather than netted off, because a
     * refund is not a smaller sale — it is a sale that stopped existing, and
     * a total that averages the two tells you neither.
     */
    public function scopeEarned(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeInPeriod(Builder $query, ?string $since): Builder
    {
        return $since ? $query->where('paid_at', '>=', $since) : $query;
    }

    /* ═══════════════════════════ Reading ═══════════════════════════ */

    public function amountForHumans(): string
    {
        return '$'.number_format($this->amount_cents / 100, 2);
    }

    public function netForHumans(): string
    {
        return '$'.number_format($this->net_cents / 100, 2);
    }

    /** What share the gateway kept, for the row that shows it. */
    public function feeRate(): ?float
    {
        return $this->amount_cents > 0
            ? $this->fee_cents / $this->amount_cents * 100
            : null;
    }

    public function isRefunded(): bool
    {
        return in_array($this->status, ['refunded', 'partially_refunded'], true);
    }
}
