<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    protected $guarded = [];

    public function sounds(): BelongsToMany
    {
        return $this->belongsToMany(Sound::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
