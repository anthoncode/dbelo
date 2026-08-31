<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Search extends Model
{
    protected $guarded = [];

    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_IGNORED = 'ignored';

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function daily(): HasMany
    {
        return $this->hasMany(SearchDaily::class);
    }

    public function synonym()
    {
        return $this->hasOne(Synonym::class);
    }

    // ---------------------------------------------------------------
    // Normalisation
    // ---------------------------------------------------------------

    /**
     * One need, one row.
     *
     * Lowercased, accents folded, inner whitespace collapsed. Without this
     * "Puerta Chirriando", "puerta  chirriando" and "puerta chirriando"
     * are three rows, and the ranking that should tell you what to record
     * turns into noise.
     */
    public static function normalise(string $term): string
    {
        $term = Str::lower(trim($term));
        $term = Str::ascii($term);

        return (string) preg_replace('/\s+/', ' ', $term);
    }

    // ---------------------------------------------------------------
    // Scopes — the three questions this screen answers
    // ---------------------------------------------------------------

    /** "I do not have it." Money and a microphone. */
    public function scopeNotFound(Builder $query): Builder
    {
        return $query->where('results', 0);
    }

    /** "I have it and I am showing the wrong thing." Ten minutes of tags. */
    public function scopeNoClicks(Builder $query): Builder
    {
        return $query->where('results', '>', 0)->where('clicks', 0);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('last_seen_at', '>=', now()->subDays($days));
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function foundNothing(): bool
    {
        return $this->results === 0;
    }

    /**
     * Daily counts for the sparkline, oldest first, zero-filled so the shape
     * is honest: a gap has to look like a gap, not like a shorter bar.
     *
     * @return array<int, int>
     */
    public function trend(int $days = 14): array
    {
        $counts = $this->daily()
            ->where('day', '>=', now()->subDays($days - 1)->toDateString())
            ->pluck('count', 'day');

        return collect(range($days - 1, 0))
            ->map(fn ($back) => (int) ($counts[now()->subDays($back)->toDateString()] ?? 0))
            ->all();
    }

    /** Searched more in the last week than the week before it. */
    public function isRising(): bool
    {
        $trend = $this->trend(14);
        $previous = array_sum(array_slice($trend, 0, 7));
        $recent = array_sum(array_slice($trend, 7, 7));

        return $recent > $previous && $recent > 1;
    }
}
