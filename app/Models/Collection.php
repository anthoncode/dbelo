<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Collection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sounds(): BelongsToMany
    {
        // A curated pack has an intended sequence, so sort_order leads and
        // the timestamp only breaks ties.
        return $this->belongsToMany(Sound::class)
            ->withPivot(['sort_order', 'created_at'])
            ->orderByPivot('sort_order')
            ->orderByPivot('created_at', 'desc');
    }

    /**
     * Packs are the collections dbelo curates itself: public, and flagged
     * as featured so they can be shown as official.
     */
    public function scopePacks($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true)->where('is_public', true);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Keeps the denormalised counter honest. Called after any attach or
     * detach, so listings never have to count rows.
     */
    public function refreshCount(): void
    {
        $this->update(['sounds_count' => $this->sounds()->count()]);
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'collection';
        $slug = $base;
        $i = 2;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
