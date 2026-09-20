<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Prunable;

/**
 * A URL somebody asked for and did not get, with a count beside it.
 *
 * This is the same shape as the searches table and for the same reason: the
 * interesting thing is never the single event, it is how many people hit the
 * same wall. One row per path, a counter, and the two dates that say whether
 * it is still happening.
 */
class NotFound extends Model
{
    use Prunable;

    protected $table = 'not_founds';

    public $timestamps = false;

    protected $fillable = [
        'path', 'hits', 'first_seen_at', 'last_seen_at', 'referrer', 'user_agent', 'status', 'redirect_id',
    ];

    protected $casts = [
        'hits' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * The probes every public site receives, whether or not it ever ran
     * WordPress. Matching them is not security — it is housekeeping, so the
     * list an admin opens is the list of things that are actually broken.
     */
    protected const NOISE = [
        'wp-', 'wordpress', 'xmlrpc', '.php', '.env', '.git', '.aws', '.ssh',
        'phpmyadmin', 'phpunit', 'vendor/', 'cgi-bin', 'autodiscover',
        'administrator/', 'adminer', 'shell', 'config.json', 'backup.sql',
        '.well-known/traffic-advice', 'ads.txt', 'app-ads.txt',
    ];

    public function redirect(): BelongsTo
    {
        return $this->belongsTo(Redirect::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    public function scopeNoise(Builder $query): Builder
    {
        return $query->where('status', 'noise');
    }

    public static function looksLikeNoise(string $path): bool
    {
        $lower = strtolower($path);

        foreach (self::NOISE as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record one miss.
     *
     * An upsert with an increment, so this is a single statement no matter
     * how many misses arrive at once, and two requests landing together
     * cannot lose a count between a read and a write.
     */
    public static function bump(string $path, ?string $referrer, ?string $agent): void
    {
        $now = now();

        DB::table('not_founds')->upsert(
            [[
                'path' => $path,
                'hits' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'referrer' => $referrer ? Str::limit($referrer, 490, '') : null,
                'user_agent' => $agent ? Str::limit($agent, 245, '') : null,
                'status' => self::looksLikeNoise($path) ? 'noise' : 'open',
            ]],
            ['path'],
            [
                'hits' => DB::raw('not_founds.hits + 1'),
                'last_seen_at' => $now,
                // The referrer is overwritten on purpose: the most recent one
                // is the one still sending people here.
                'referrer' => DB::raw('values(referrer)'),
                'user_agent' => DB::raw('values(user_agent)'),
            ],
        );
    }

    /** Is anything still linking here, or is this only direct traffic? */
    public function isLinked(): bool
    {
        return $this->referrer && ! str_contains($this->referrer, request()->getHost());
    }

    /**
     * The most likely thing the visitor meant.
     *
     * Deliberately computed only when somebody opens the "create a redirect"
     * row, never for a whole page of results: it reads three slug columns and
     * scores them in PHP, which is fine once and wasteful twelve times.
     */
    public function suggestion(): ?array
    {
        $needle = strtolower(Str::afterLast($this->path, '/'));

        if (strlen($needle) < 3) {
            return null;
        }

        $candidates = collect();

        $candidates = $candidates->merge(
            Sound::query()->select('slug')->limit(2000)->pluck('slug')
                ->map(fn ($slug) => ['path' => "sounds/{$slug}", 'kind' => 'Sound'])
        );

        if (\Illuminate\Support\Facades\Schema::hasTable('posts')) {
            $candidates = $candidates->merge(
                Post::query()->select('slug', 'type')->limit(2000)->get()
                    ->map(fn ($post) => [
                        'path' => $post->type === 'post' ? "blog/{$post->slug}" : $post->slug,
                        'kind' => $post->type === 'post' ? 'Post' : 'Page',
                    ])
            );
        }

        $best = null;
        $bestScore = 0;

        foreach ($candidates as $candidate) {
            similar_text($needle, strtolower(Str::afterLast($candidate['path'], '/')), $percent);

            if ($percent > $bestScore) {
                $bestScore = $percent;
                $best = $candidate;
            }
        }

        // Below roughly two thirds the "suggestion" is noise dressed up as
        // help, and a wrong pre-filled field is worse than an empty one.
        return $best && $bestScore >= 65
            ? $best + ['score' => (int) round($bestScore)]
            : null;
    }

    /**
     * What gets deleted, and what is kept however old it is.
     *
     * ── THE ONLY TABLE HERE THAT FILLS UP WITH SOMEBODY ELSE'S RUBBISH ───
     *
     * A 404 row is one per PATH, not one per visit, so honest traffic barely
     * moves it. Bots are the problem: a scanner works through ten thousand
     * WordPress paths that never existed on this site, and every one of them
     * becomes a row that nobody will ever read or act on.
     *
     * That is exactly what the `noise` status already marks, so pruning it
     * needs no new concept — just a deadline. A month is long enough to
     * notice a pattern worth blocking and short enough that the table never
     * becomes mostly rubbish.
     *
     * AN OPEN 404 IS NEVER PRUNED, at any age. Open means nobody has decided
     * about it, and deleting an undecided broken URL is deciding on the
     * operator's behalf that it did not matter — the same rule the error
     * groups follow, for the same reason.
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where(function (Builder $q) {
                $q->where('status', 'noise')
                    ->where('last_seen_at', '<', now()->subDays(30));
            })
            ->orWhere(function (Builder $q) {
                // Dealt with, and quiet for half a year.
                $q->whereNotIn('status', ['open', 'noise'])
                    ->where('last_seen_at', '<', now()->subDays(180));
            });
    }
}
