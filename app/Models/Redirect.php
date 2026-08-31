<?php

namespace App\Models;

use App\Services\RedirectResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * One rule: "when somebody asks for this, send them there instead".
 *
 * The rules only ever run on a 404, so a redirect can never shadow a real
 * page. That is worth saying out loud, because it is the property that makes
 * this table safe to let an admin type into: the worst a wrong rule can do is
 * misroute a URL that was already broken.
 */
class Redirect extends Model
{
    protected $fillable = [
        'from', 'to', 'status', 'is_wildcard', 'source', 'note', 'created_by',
    ];

    protected $casts = [
        'status' => 'integer',
        'is_wildcard' => 'boolean',
        'hits' => 'integer',
        'last_hit_at' => 'datetime',
    ];

    public const SOURCES = [
        'manual' => 'Typed by hand',
        'claim' => 'Sound removed after a claim',
        'post' => 'Page or post moved',
        'sound' => 'Sound moved',
    ];

    /**
     * Any write invalidates the cached map. Without this the panel says the
     * rule was saved and the site keeps using the old one — the kind of bug
     * that costs an hour because both halves look correct.
     */
    protected static function booted(): void
    {
        $flush = fn () => Cache::forget(RedirectResolver::MAP);

        static::saved($flush);
        static::deleted($flush);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notFounds()
    {
        return $this->hasMany(NotFound::class);
    }

    public function scopeWildcards(Builder $query): Builder
    {
        return $query->where('is_wildcard', true);
    }

    public function isGone(): bool
    {
        return $this->status === 410;
    }

    public function label(): string
    {
        return match ($this->status) {
            410 => 'Gone',
            302 => 'Temporary',
            default => 'Permanent',
        };
    }

    public function tone(): string
    {
        return match ($this->status) {
            410 => 'danger',
            302 => 'warning',
            default => 'success',
        };
    }

    /**
     * Follow an existing chain to its end, once, at save time.
     *
     * A → B and then B → C is two round trips for the visitor and, if
     * somebody later writes C → A, an infinite loop that only shows up in
     * production. Collapsing here means the table can never hold a chain, so
     * the resolver never has to follow one.
     */
    public static function collapse(string $to, int $depth = 0): string
    {
        if ($depth >= 5 || str_starts_with($to, 'http')) {
            return $to;
        }

        $next = static::query()
            ->where('from', RedirectResolver::normalise($to))
            ->whereNotNull('to')
            ->where('is_wildcard', false)
            ->value('to');

        return $next ? static::collapse($next, $depth + 1) : $to;
    }

    /**
     * Anything already pointing at this path now points past it.
     *
     * Called after a rule is created, so writing B → C repairs the A → B
     * that was written last month instead of quietly turning it into a hop.
     */
    public static function repointTo(string $from, string $to): int
    {
        return static::query()
            ->where('to', $from)
            ->orWhere('to', '/'.$from)
            ->update(['to' => $to]);
    }
}
