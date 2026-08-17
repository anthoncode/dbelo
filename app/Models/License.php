<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class License extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requires_attribution' => 'boolean',
            'allows_commercial' => 'boolean',
            'allows_derivatives' => 'boolean',
        ];
    }

    public function sounds(): HasMany
    {
        return $this->hasMany(Sound::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Frozen copy stored on every download, so the terms a user accepted
     * survive any future change to this license.
     */
    public function toSnapshot(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'summary' => $this->summary,
            'requires_attribution' => $this->requires_attribution,
            'allows_commercial' => $this->allows_commercial,
            'allows_derivatives' => $this->allows_derivatives,
            'frozen_at' => now()->toIso8601String(),
        ];
    }
}
