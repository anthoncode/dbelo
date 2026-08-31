<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One day's tally for one error group.
 *
 * A separate table rather than a JSON column on the group, because this
 * counter is written from web requests and from the queue worker at the same
 * time, and read-modify-write on a JSON blob silently loses increments the
 * moment two of them overlap.
 */
class ErrorDaily extends Model
{
    protected $table = 'error_daily';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ErrorGroup::class, 'error_group_id');
    }

    /**
     * Add to today's tally, race-safe and portable.
     *
     * insertOrIgnore leans on the unique index to make the first write a
     * no-op when the row already exists, then increment does the arithmetic
     * in the database rather than in PHP. Two statements instead of one
     * MySQL-only "ON DUPLICATE KEY UPDATE count = count + VALUES(count)" —
     * this is one of the few places where staying portable costs nothing.
     */
    public static function add(int $groupId, int $delta): void
    {
        $date = now()->toDateString();

        DB::table('error_daily')->insertOrIgnore([
            'error_group_id' => $groupId,
            'date' => $date,
            'count' => 0,
        ]);

        DB::table('error_daily')
            ->where('error_group_id', $groupId)
            ->where('date', $date)
            ->increment('count', $delta);
    }
}
