<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per term per day. The only reason it exists is so the trend
 * column means something — a term that spiked last week is not the same as
 * one searched once a year ago.
 */
class SearchDaily extends Model
{
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
}
