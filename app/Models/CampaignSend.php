<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;

/**
 * Proof that a given person was sent a given campaign.
 *
 * Written BEFORE the email goes out, not after: the unique index then makes
 * a duplicate impossible even if the worker dies mid-send and the job is
 * retried.
 */
class CampaignSend extends Model
{
    use Prunable;

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    /**
     * One row per email per campaign, kept for a year.
     *
     * The totals that matter — sent, opened, clicked — are counters on the
     * campaign itself, so a year-old campaign keeps its numbers after every
     * one of its rows is gone. What disappears is the per-recipient detail,
     * which is only ever read in the days after a send.
     *
     * It is also the one table here holding an address alongside a behaviour,
     * so not keeping it forever is the privacy-respecting default as well as
     * the tidy one.
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subYear());
    }
}
