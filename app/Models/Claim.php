<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Claim extends Model
{
    protected $guarded = [];

    public const STATUS_NEW = 'new';

    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected function casts(): array
    {
        return [
            'sworn' => 'boolean',
            'sound_taken_down_at' => 'datetime',
            'contributor_notified_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Claim $claim) {
            $claim->uuid ??= (string) Str::uuid();
        });
    }

    // ---------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------

    public function sound(): BelongsTo
    {
        // withTrashed: accepting a claim removes the sound, and the claim
        // still has to show what it was about.
        return $this->belongsTo(Sound::class)->withTrashed();
    }

    public function contributor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contributor_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    // ---------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------

    /** Claims that still need a decision. This is the number on the badge. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_NEW, self::STATUS_REVIEWING]);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_NEW, self::STATUS_REVIEWING], true);
    }

    /** What the claimant quotes when they follow up. */
    public function reference(): string
    {
        return 'CL-'.strtoupper(substr($this->uuid, 0, 8));
    }

    public function rightLabel(): string
    {
        return match ($this->right_claimed) {
            'trademark' => 'Trademark',
            'voice' => 'Voice / likeness',
            'privacy' => 'Privacy',
            'other' => 'Other right',
            default => 'Copyright',
        };
    }
}
