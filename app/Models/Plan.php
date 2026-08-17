<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'allows_premium' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function isUnlimited(): bool
    {
        return $this->daily_download_limit === null;
    }

    public function isFree(): bool
    {
        return $this->price_cents === 0;
    }

    public function priceForHumans(): string
    {
        return $this->isFree()
            ? 'Free'
            : '$'.number_format($this->price_cents / 100, 2);
    }
}
