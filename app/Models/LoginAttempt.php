<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to sign in.
 *
 * Written for every outcome, including the successful ones. A log of only
 * failures cannot answer the question that actually matters after a
 * break-in — "did they get in, and when?" — and by the time you want that
 * answer it is too late to start recording it.
 */
class LoginAttempt extends Model
{
    use MassPrunable;

    protected $guarded = [];

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'is_admin' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function succeeded(): bool
    {
        return $this->outcome === 'success';
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('outcome', ['failed', 'lockout', 'two_factor']);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('email', 'like', $like)
            ->orWhere('ip_address', 'like', $like)
            ->orWhere('user_agent', 'like', $like));
    }

    /**
     * Ninety days.
     *
     * Long enough to investigate anything anybody reports, short enough that
     * a table with a row per attempt never becomes the biggest thing in the
     * database. Successful ADMIN sign-ins are kept for a year: that is the
     * trail somebody asks for long after the fact.
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where(fn (Builder $q) => $q
                ->where('is_admin', false)
                ->where('created_at', '<', now()->subDays(90)))
            ->orWhere(fn (Builder $q) => $q
                ->where('is_admin', true)
                ->where('created_at', '<', now()->subDays(365)));
    }
}
