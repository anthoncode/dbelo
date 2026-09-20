<?php

namespace App\Models;

use App\Support\FooterLinks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $guarded = [];

    /**
     * The footer lists the first six top-level categories and caches their
     * names for an hour. Renaming one, or changing the order, should show up
     * on the site immediately — not "sometime today".
     */
    protected static function booted(): void
    {
        static::saved(fn () => FooterLinks::flush());
        static::deleted(fn () => FooterLinks::flush());
    }

    public function sounds(): HasMany
    {
        return $this->hasMany(Sound::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order');
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id')->orderBy('sort_order');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
