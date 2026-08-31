<?php

namespace App\Models;

use App\Support\ActivityCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Audit trail. Every staff action that changes someone else's account or
 * content lands here, so "what happened to this user?" always has an answer.
 *
 * Three properties this table is expected to have, in order of importance:
 *
 *   1. It is APPEND-ONLY. There is no edit and no delete anywhere in the
 *      panel. A log a person can rewrite is not evidence of anything, and
 *      the one person most motivated to rewrite it is whoever the awkward
 *      row is about. Rows leave only by the retention policy, on a
 *      schedule, by category.
 *   2. It is READABLE AFTER THE FACT. The actor's name and the subject's
 *      label are copied in at write time, because the rows that matter most
 *      are about accounts and files that have since been deleted.
 *   3. It NEVER breaks the thing it is recording. See record().
 */
class ActivityLog extends Model
{
    use MassPrunable;

    protected $guarded = [];

    const UPDATED_AT = null;

    /**
     * Keys whose values must never reach this table.
     *
     * An audit log is read by more people, and kept far longer, than almost
     * any other table. A token that lands here by accident inside a `meta`
     * blob outlives every rotation policy you have.
     */
    private const NEVER_LOG = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'api_key', 'secret', 'access_token', 'refresh_token',
        'two_factor_secret', 'two_factor_recovery_codes', 'remember_token',
        'card', 'card_number', 'cvv', 'cvc', 'iban',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject()
    {
        return $this->morphTo();
    }

    /**
     * Write one entry.
     *
     * Never throws. An audit write that can take down the action it is
     * recording turns a missing migration into "the admin panel is broken",
     * and the operator's next move is to comment out the logging — which is
     * exactly the outcome the log exists to prevent. The failure is not
     * swallowed silently either: it goes to the application log, where a
     * missing trail can still be traced back to its cause.
     *
     * @param  array<string, mixed>  $meta  Use ['from' => …, 'to' => …] for a
     *                                      change. The screen renders that
     *                                      pair specially, and "what it was
     *                                      before" is the single most useful
     *                                      thing an audit row can carry.
     */
    public static function record(string $action, ?Model $subject = null, ?string $description = null, array $meta = []): ?self
    {
        try {
            return static::create([
                'user_id' => auth()->id(),
                'actor_name' => auth()->user()?->name ?? 'System',
                'action' => $action,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => $subject ? static::labelFor($subject) : null,
                'description' => $description,
                'meta' => static::scrub($meta) ?: null,
                'ip_address' => request()->ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Activity log write failed', [
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * A human name for whatever this was done to.
     *
     * Tried in the order a person would read them. Falls back to the class
     * name and id, which is still better than nothing once the record is
     * gone.
     */
    private static function labelFor(Model $subject): string
    {
        foreach (['title', 'name', 'email', 'slug', 'reference'] as $attribute) {
            if (filled($subject->getAttribute($attribute))) {
                return Str::limit((string) $subject->getAttribute($attribute), 180);
            }
        }

        return class_basename($subject).' #'.$subject->getKey();
    }

    /**
     * Recursively replace anything that looks like a credential.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private static function scrub(array $meta): array
    {
        foreach ($meta as $key => $value) {
            if (is_array($value)) {
                $meta[$key] = static::scrub($value);

                continue;
            }

            if (Str::contains(Str::lower((string) $key), self::NEVER_LOG)) {
                $meta[$key] = '[redacted]';
            }
        }

        return $meta;
    }

    /**
     * What gets deleted, and when.
     *
     * Tiered by category, because a single retention number is wrong in both
     * directions at once — see ActivityCatalog. Anything whose action is not
     * in the catalogue falls to the shortest tier, which is the safe
     * direction: an unclassified row is one nobody decided was worth keeping.
     *
     * Runs from `php artisan model:prune`, scheduled daily in
     * routes/console.php. MassPrunable, not Prunable: these rows have no
     * files or relations to tidy up, so a single DELETE per tier beats
     * loading a million models to delete them one at a time.
     */
    public function prunable(): Builder
    {
        $query = static::query();
        $grouped = ActivityCatalog::actionsByCategory();
        $shortest = min(array_column(ActivityCatalog::CATEGORIES, 'keepDays'));
        $known = [];

        foreach ($grouped as $category => $actions) {
            $keepDays = ActivityCatalog::CATEGORIES[$category]['keepDays'];
            $known = array_merge($known, $actions);

            $query->orWhere(function (Builder $q) use ($actions, $keepDays) {
                $q->whereIn('action', $actions)
                    ->where('created_at', '<', now()->subDays($keepDays));
            });
        }

        // Unlisted actions: shortest tier.
        $query->orWhere(function (Builder $q) use ($known, $shortest) {
            $q->whereNotIn('action', $known)
                ->where('created_at', '<', now()->subDays($shortest));
        });

        return $query;
    }

    /* ─────────────────────────── Scopes ─────────────────────────── */

    public function scopeCategory(Builder $query, string $category): Builder
    {
        $actions = ActivityCatalog::actionsByCategory()[$category] ?? [];

        return $query->whereIn('action', $actions);
    }

    /**
     * Free-text search across the parts a person actually remembers: who,
     * what it was called, and what the entry said.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($term)).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('description', 'like', $like)
                ->orWhere('actor_name', 'like', $like)
                ->orWhere('subject_label', 'like', $like)
                ->orWhere('action', 'like', $like)
                ->orWhere('ip_address', 'like', $like);
        });
    }

    /* ────────────────────────── Presentation ────────────────────────── */

    /** @return array{label: string, icon: string, category: string, severity: string} */
    public function meaning(): array
    {
        return ActivityCatalog::describe($this->action);
    }

    /**
     * The sentence for the row.
     *
     * Falls back to building one out of the registry label and the subject
     * snapshot, so an action recorded without a description is still a
     * readable line rather than a bare action name.
     */
    public function sentence(): string
    {
        if (filled($this->description)) {
            return $this->description;
        }

        $label = $this->meaning()['label'];

        return filled($this->subject_label)
            ? $label.' — '.$this->subject_label
            : $label;
    }

    /** Was this a recorded change, with a before and an after? */
    public function isChange(): bool
    {
        return isset($this->meta['from']) || isset($this->meta['to']);
    }
}
