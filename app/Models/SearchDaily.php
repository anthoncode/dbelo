<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;

/**
 * One row per term per day. The only reason it exists is so the trend
 * column means something — a term that spiked last week is not the same as
 * one searched once a year ago.
 */
class SearchDaily extends Model
{
    use Prunable;

    protected $table = 'search_daily';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['day' => 'date'];
    }

    public function search(): BelongsTo
    {
        return $this->belongsTo(Search::class);
    }

    /**
     * A year of daily detail, and no more.
     *
     * This table is one row per term per day, so a hundred searched terms is
     * thirty-six thousand rows a year — the fastest-growing table in the
     * project and the one with the shortest useful life. The chart nobody
     * opens is the chart of what people searched for in a fortnight two years
     * ago.
     *
     * NOTHING IS LOST THAT MATTERS. The term itself lives on `searches` with
     * its total count and its first and last seen dates, so "has anybody ever
     * looked for this" stays answerable forever. What goes is the day-by-day
     * shape of an old year.
     */
    public function prunable(): Builder
    {
        return static::query()->where('day', '<', now()->subYear()->toDateString());
    }
}
