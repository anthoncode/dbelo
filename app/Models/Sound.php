<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

class Sound extends Model
{
    use HasFactory, Searchable, SoftDeletes;

    protected $guarded = [];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REJECTED = 'rejected';

    /**
     * Under a copyright claim. Not the same as rejected: rejected means it
     * never made it in, claimed means it was live and is being disputed.
     * The page stays up with a notice so the URL keeps its place while the
     * claim is investigated; nothing plays and nothing downloads.
     */
    public const STATUS_CLAIMED = 'claimed';

    protected function casts(): array
    {
        return [
            'waveform' => 'array',
            'is_loopable' => 'boolean',
            'is_premium' => 'boolean',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
            'ai_suggestions' => 'array',
            'search_terms' => 'array',
            'ai_suggested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Every new sound gets a UUID automatically. It is used in public URLs
     * so the real IDs are never exposed.
     */
    protected static function booted(): void
    {
        static::creating(function (Sound $sound) {
            $sound->uuid ??= (string) Str::uuid();
        });

        // A soft delete keeps the files: the sound can come back.
        // A force delete must clear them, otherwise 60 MB masters pile up
        // on disk forever with nothing pointing at them.
        static::deleting(function (Sound $sound) {
            if (! $sound->isForceDeleting()) {
                return;
            }

            foreach ($sound->files as $file) {
                Storage::disk($file->disk)->delete($file->path);
            }
        });
    }

    // ---------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function files(): HasMany
    {
        return $this->hasMany(SoundFile::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(Download::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function musicAttribute(): HasOne
    {
        return $this->hasOne(MusicAttribute::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class);
    }

    public function favouritedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'favorites')->withPivot('created_at');
    }

    // ---------------------------------------------------------------
    // Scopes: reusable query fragments
    // ---------------------------------------------------------------

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at');
    }

    public function scopeSfx(Builder $query): Builder
    {
        return $query->where('type', 'sfx');
    }

    public function scopeMusic(Builder $query): Builder
    {
        return $query->where('type', 'music');
    }

    public function scopeFree(Builder $query): Builder
    {
        return $query->where('is_premium', false);
    }

    public function scopeUnderClaim(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CLAIMED);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function preview(): ?SoundFile
    {
        return $this->files->firstWhere('purpose', 'preview');
    }

    public function downloadFile(string $format = 'mp3'): ?SoundFile
    {
        return $this->files
            ->where('purpose', 'download')
            ->firstWhere('format', $format);
    }

    public function durationForHumans(): string
    {
        $seconds = $this->duration_ms / 1000;

        return $seconds < 60
            ? number_format($seconds, 1).'s'
            : gmdate('i:s', (int) $seconds);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->published_at !== null;
    }

    /**
     * Recent enough to be worth pointing at.
     *
     * ── WHY FOURTEEN DAYS ────────────────────────────────────────────────
     *
     * This catalogue grows in batches, not in a trickle — fifty sounds land
     * in an afternoon and then nothing for a week. A window of a day or two
     * would mark an entire upload at once and then nothing at all, which
     * teaches a returning visitor that the badge means "Marco uploaded
     * today" rather than "you have not heard this yet".
     *
     * Two weeks is long enough that somebody who visits monthly still finds
     * something marked, and short enough that the badge is never on most of
     * the page. A marker that is always lit stops being read — same reason
     * the notification dot in the panel is not permanent.
     */
    public const NEW_FOR_DAYS = 14;

    public function isNew(): bool
    {
        return $this->isPublished()
            && $this->published_at->isAfter(now()->subDays(self::NEW_FOR_DAYS));
    }

    public function isUnderClaim(): bool
    {
        return $this->status === self::STATUS_CLAIMED;
    }

    /**
     * Did the contributor say this was not their own recording?
     *
     * The Contributor Agreement §4 requires declaring third-party material.
     * When a claim arrives, this is the first thing worth looking at.
     */
    public function isThirdParty(): bool
    {
        return $this->source !== 'original';
    }

    // ---------------------------------------------------------------
    // Search index (Meilisearch via Scout)
    // ---------------------------------------------------------------

    /**
     * What Meilisearch stores. Flattened on purpose: the engine cannot
     * traverse relationships, so the category name and the tags are copied
     * in as plain values.
     */
    public function toSearchableArray(): array
    {
        $this->loadMissing(['category.parent', 'tags', 'license']);

        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => (string) $this->description,

            'category' => $this->category?->name,
            'category_slug' => $this->category?->slug,
            // Filtering by a parent category has to match its children too,
            // so the parent slug travels with every sound.
            'parent_category_slug' => $this->category?->parent?->slug ?? $this->category?->slug,

            'license_slug' => $this->license?->slug,
            'tags' => $this->tags->pluck('name')->all(),

            /*
             * Words that MATCH but are never rendered — mostly the Spanish
             * ones, so "truenos" finds a sound tagged "thunder".
             *
             * They are sent to the engine and nowhere else. Nothing on the
             * site reads this field, which is deliberate: it is allowed to
             * hold misspellings and regionalisms precisely because no visitor
             * will ever be shown them.
             *
             * Meilisearch only searches what config/scout.php lists under
             * searchableAttributes, so adding it here is half the change —
             * the other half is that list, and `scout:sync-index-settings`
             * after it.
             */
            'search_terms' => $this->search_terms ?? [],

            'type' => $this->type,
            'duration_ms' => (int) $this->duration_ms,
            'is_premium' => (bool) $this->is_premium,
            'is_loopable' => (bool) $this->is_loopable,

            'downloads_count' => (int) $this->downloads_count,
            'published_at' => $this->published_at?->getTimestamp() ?? 0,
        ];
    }

    /**
     * Drafts, sounds in review and rejected ones never reach the index.
     * Scout calls this on every save, so unpublishing removes the document
     * automatically.
     */
    public function shouldBeSearchable(): bool
    {
        return $this->status === 'published' && $this->published_at !== null;
    }

    public function searchableAs(): string
    {
        return 'sounds';
    }
}
