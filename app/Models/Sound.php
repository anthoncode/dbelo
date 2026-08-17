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
use Illuminate\Support\Str;

class Sound extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'waveform' => 'array',
            'is_loopable' => 'boolean',
            'is_premium' => 'boolean',
            'is_featured' => 'boolean',
            'published_at' => 'datetime',
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

    public function musicAttribute(): HasOne
    {
        return $this->hasOne(MusicAttribute::class);
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
        return $this->status === 'published' && $this->published_at !== null;
    }
}
