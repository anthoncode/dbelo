<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * A refused address.
 *
 * The active list is cached and the cache is busted by model events, the
 * same arrangement the redirects use — this is consulted on EVERY request,
 * and a query per request to find out that almost nobody is blocked is the
 * worst trade in the application.
 */
class IpBlock extends Model
{
    protected $guarded = [];

    public const CACHE_KEY = 'security.blocked.ips';

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        // saved AND deleted: a block that outlives its row is unreachable
        // except by clearing the whole cache, and the person it locks out is
        // usually the one who cannot ask.
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPermanent(): bool
    {
        return $this->expires_at === null;
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('expires_at')
            ->orWhere('expires_at', '>', now()));
    }

    /**
     * The addresses to refuse right now, as a plain array.
     *
     * A plain array, never a collection of models: an Eloquent collection in
     * the cache is serialised with NUL bytes in its protected-property keys,
     * and those do not survive a MySQL text column. It comes back broken on
     * the SECOND read, which is the hardest possible time to work out why.
     *
     * @return array<string, string>  ip => reason
     */
    public static function active(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(5), fn () => static::query()
            ->active()
            ->pluck('reason', 'ip_address')
            ->all());
    }

    /** Block an address for a while. Never permanently: this is automatic. */
    public static function auto(string $ip, string $reason, int $minutes = 60): void
    {
        static::updateOrCreate(
            ['ip_address' => $ip],
            ['reason' => $reason, 'source' => 'auto', 'expires_at' => now()->addMinutes($minutes)],
        );
    }
}
