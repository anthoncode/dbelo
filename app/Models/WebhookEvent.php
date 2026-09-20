<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\Eloquent\Prunable;

/**
 * One webhook delivery from a payment gateway.
 *
 * The table's docblock explains why this exists; this class holds the one
 * operation that has to be exactly right — recording an event without
 * processing the same one twice.
 */
class WebhookEvent extends Model
{
    use Prunable;

    public const PENDING = 'pending';

    public const HANDLED = 'handled';

    public const IGNORED = 'ignored';

    public const REJECTED = 'rejected';

    public const FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'verified' => 'boolean',
            'handled_at' => 'datetime',
        ];
    }

    /**
     * Write the event down, or return null if it has been seen before.
     *
     * WHY THE INSERT IS THE CHECK. The obvious shape — "look it up, and
     * insert if missing" — is wrong here for a reason that only shows up in
     * production: PayPal retries in parallel, so two copies of the same
     * event can both pass the lookup before either has written a row. Both
     * then grant a month. Letting the unique index refuse the second INSERT
     * is the only version that cannot be raced.
     *
     * A null return therefore means "already recorded, stop here" — not an
     * error, the normal outcome of a retry.
     */
    public static function record(string $eventId, array $data): ?self
    {
        try {
            return static::create(array_merge($data, ['event_id' => $eventId]));
        } catch (QueryException $e) {
            // 23000 / 23505: unique violation. Anything else is a real
            // database problem and must not be swallowed as a duplicate.
            if (! in_array($e->getCode(), ['23000', '23505'], true)) {
                throw $e;
            }

            return null;
        }
    }

    public function markHandled(?string $resourceId = null): void
    {
        $this->forceFill([
            'status' => self::HANDLED,
            'resource_id' => $resourceId ?? $this->resource_id,
            'handled_at' => now(),
            'error' => null,
        ])->save();
    }

    /**
     * A real event this application has no handler for.
     *
     * Distinct from handled on purpose: PayPal sends event types nobody
     * subscribed to, and if those were filed as handled, an event type that
     * genuinely should have been processed would be indistinguishable from
     * them.
     */
    public function markIgnored(string $why = ''): void
    {
        $this->forceFill([
            'status' => self::IGNORED,
            'handled_at' => now(),
            'error' => $why ?: null,
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => self::FAILED,
            'attempts' => $this->attempts + 1,
            // Logs have a habit of being the one place a stack trace does not
            // fit. Truncate rather than risk the write failing.
            'error' => mb_substr($error, 0, 2000),
        ])->save();
    }

    public function markRejected(string $why = 'signature verification failed'): void
    {
        $this->forceFill([
            'status' => self::REJECTED,
            'handled_at' => now(),
            'error' => $why,
        ])->save();
    }

    /* ═══════════════════════════ Reading ═══════════════════════════ */

    /** Events that still need attention. Drives the admin notice. */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PENDING, self::FAILED]);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('event_type', $type);
    }

    /** A short, readable version of the event type for a table cell. */
    public function shortType(): string
    {
        return str_replace(['BILLING.SUBSCRIPTION.', 'PAYMENT.', 'CHECKOUT.ORDER.'], '', (string) $this->event_type);
    }

    /**
     * Settled events, after ninety days.
     *
     * ── WHY NOT SOONER, AND WHY NOT THE OTHER TWO STATES ─────────────────
     *
     * This table's real job is the unique key that stops one webhook being
     * processed twice. PayPal retries a delivery for up to three days, so
     * anything younger than that is still actively guarding against a
     * duplicate. Ninety days is that window with a very wide margin, and it
     * keeps a quarter of billing history readable when a customer disputes
     * a charge.
     *
     * PENDING and FAILED are never pruned. Those two mean "this arrived and
     * was not dealt with" — the only rows on the table anybody would ever
     * need to act on, and the ones a cleanup must never quietly remove.
     */
    public function prunable(): Builder
    {
        return static::query()
            ->whereIn('status', [self::HANDLED, self::IGNORED, self::REJECTED])
            ->where('created_at', '<', now()->subDays(90));
    }
}
