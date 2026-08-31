<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;

class StatDaily extends Model
{
    protected $table = 'stats_daily';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['day' => 'date'];
    }

    /**
     * Add to a counter without reading it first.
     *
     * One statement, no race: two requests arriving in the same millisecond
     * both land, because the database does the addition. A read-then-write
     * would lose one of them.
     */
    public static function bump(string $metric, string $label = '', int $by = 1, ?string $day = null): void
    {
        DB::table('stats_daily')->upsert(
            [[
                'day' => $day ?? Clock::day(),
                'metric' => $metric,
                'label' => mb_substr($label, 0, 120),
                'value' => $by,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['day', 'metric', 'label'],
            // The raw expression is what makes it an increment rather than
            // an overwrite.
            ['value' => DB::raw('stats_daily.value + values(value)'), 'updated_at' => DB::raw('values(updated_at)')],
        );
    }

    /** Replace a derived counter outright. Used by the rollup, never by the site. */
    public static function put(string $day, string $metric, string $label, int $value): void
    {
        DB::table('stats_daily')->upsert(
            [[
                'day' => $day,
                'metric' => $metric,
                'label' => mb_substr($label, 0, 120),
                'value' => $value,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['day', 'metric', 'label'],
            ['value', 'updated_at'],
        );
    }
}
