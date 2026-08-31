<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Synonym extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['replacements' => 'array'];
    }

    public function search(): BelongsTo
    {
        return $this->belongsTo(Search::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * "car, automobile , VEHICLE" → ['car', 'automobile', 'vehicle']
     *
     * Typed by hand into a text field, so it has to survive stray commas,
     * capitals and double spaces.
     */
    public static function parse(string $input): array
    {
        return collect(explode(',', $input))
            ->map(fn ($word) => Str::lower(trim($word)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
