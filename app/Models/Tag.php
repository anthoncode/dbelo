<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    /**
     * How many tags one sound may carry.
     *
     * Not a storage limit — a search one. Twenty words already describe a
     * recording completely; past that the extra terms match everything and
     * stop distinguishing anything, which makes the whole catalogue worse
     * rather than that one sound better.
     */
    public const MAX_PER_SOUND = 20;

    protected $guarded = [];

    public function sounds(): BelongsToMany
    {
        return $this->belongsToMany(Sound::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Turn "thunder, storm, Rumble" into tag ids, making any that are new.
     *
     * ── WHY THIS LIVES ON THE MODEL ──────────────────────────────────────
     *
     * Two screens type tags into a comma-separated box — the sound's own
     * edit page and the inline editor in Admin → Sounds — and a third
     * (bulk tagging) is coming. Parsing written in each of them is a rule
     * that disagrees with itself the first time one copy is fixed: one
     * screen would fold case and another would not, and the catalogue would
     * quietly grow "Thunder" beside "thunder".
     *
     * ── WHY IT MATCHES ON SLUG, NOT NAME ─────────────────────────────────
     *
     * firstOrCreate keys on Str::slug($name), so Thunder, thunder and
     * THUNDER all land on the same row, and the first spelling used is the
     * one kept for display. Matching on the raw name would split the
     * vocabulary on capitalisation alone — the single fastest way to ruin
     * search in a library this size.
     *
     * @return array<int, int>
     */
    public static function idsFromList(?string $list, int $max = self::MAX_PER_SOUND): array
    {
        return collect(explode(',', (string) $list))
            ->map(fn ($name) => trim($name))
            ->filter()
            ->unique(fn ($name) => Str::slug($name))
            ->take($max)
            ->map(fn ($name) => static::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name],
            )->id)
            ->values()
            ->all();
    }
}
