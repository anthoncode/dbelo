<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MusicAttribute extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'has_vocals' => 'boolean',
        ];
    }

    public function sound(): BelongsTo
    {
        return $this->belongsTo(Sound::class);
    }
}
