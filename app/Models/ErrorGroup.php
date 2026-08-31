<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One distinct problem, however many times it happened.
 */
class ErrorGroup extends Model
{
    use Prunable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_context' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'regressed_at' => 'datetime',
        ];
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function daily(): HasMany
    {
        return $this->hasMany(ErrorDaily::class);
    }

    /* ─────────────────────────── State ─────────────────────────── */

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * New enough that somebody should look now.
     *
     * The alarm in an error tracker is not the count, it is the age of the
     * FIRST occurrence. Something seen for the first time an hour ago is a
     * thing you just broke; the same message at 40,000 occurrences since
     * March is scenery you have already decided to live with.
     */
    public function isNew(): bool
    {
        return $this->first_seen_at?->gt(now()->subDay()) ?? false;
    }

    /** Came back after somebody said it was fixed. */
    public function hasRegressed(): bool
    {
        return $this->regressed_at !== null && $this->isOpen();
    }

    public function resolve(): void
    {
        $this->update([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => auth()->id(),
            // Cleared on purpose: the next regression should be about THIS
            // fix, not still flagged for the one before it.
            'regressed_at' => null,
        ]);
    }

    public function mute(): void
    {
        $this->update(['status' => 'muted', 'resolved_at' => null, 'regressed_at' => null]);
    }

    public function reopen(): void
    {
        $this->update(['status' => 'open', 'resolved_at' => null, 'resolved_by' => null]);
    }

    /* ────────────────────────── Presentation ────────────────────────── */

    /** The message, short enough for a list row. */
    public function shortMessage(): string
    {
        return Str::limit(preg_replace('/\s+/', ' ', trim($this->message)), 160);
    }

    public function shortClass(): string
    {
        return class_basename($this->class);
    }

    /**
     * Where in the code, as "file:line".
     *
     * NOT called where(). Eloquent forwards unknown static calls to the
     * query builder through __callStatic, but a real public method wins that
     * race — so defining where() on a model silently turns every
     * ErrorGroup::where(...) in the application into a fatal
     * "cannot be called statically". Model methods must never take a name
     * the query builder already owns.
     */
    public function location(): ?string
    {
        return $this->file ? $this->file.':'.$this->line : null;
    }

    /**
     * Counts for the last N days, oldest first, with gaps filled.
     *
     * Zero-filled deliberately: a sparkline drawn only from the days that
     * have rows compresses a quiet fortnight into nothing and makes a single
     * old spike look like a current one.
     *
     * @return array<int, array{date: string, count: int}>
     */
    public function trend(int $days = 30): array
    {
        $rows = $this->daily()
            ->where('date', '>=', now()->subDays($days - 1)->toDateString())
            ->pluck('count', 'date');

        $series = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $series[] = ['date' => $date, 'count' => (int) ($rows[$date] ?? 0)];
        }

        return $series;
    }

    /* ─────────────────────────── Scopes ─────────────────────────── */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query->where(fn (Builder $q) => $q
            ->where('message', 'like', $like)
            ->orWhere('class', 'like', $like)
            ->orWhere('file', 'like', $like));
    }

    /**
     * Groups nobody has cared about for a long time.
     *
     * Only ever prunes what a person has already dispositioned — resolved or
     * muted — and only once it has been quiet for six months. An OPEN group
     * is never pruned however old it is: nobody looked at it, and deleting
     * an unexamined error is deciding it did not matter on the operator's
     * behalf.
     */
    public function prunable(): Builder
    {
        return static::query()
            ->whereIn('status', ['resolved', 'muted'])
            ->where('last_seen_at', '<', now()->subDays(180));
    }
}
