<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Campaign extends Model
{
    protected $guarded = [];

    public const TYPE_PROMO = 'promo';

    public const TYPE_DIGEST = 'digest';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    /** Who a promo can go to. */
    public const SEGMENTS = [
        'all' => 'Everyone on the list',
        'free' => 'Free accounts only',
        'paying' => 'Subscribers only',
        'contributors' => 'Contributors only',
    ];

    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'is_active' => 'boolean',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(CampaignSend::class);
    }

    public function scopePromos(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_PROMO);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    public function isDigest(): bool
    {
        return $this->type === self::TYPE_DIGEST;
    }

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    /** Anything past draft must never be edited: it is already in inboxes. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED], true);
    }

    public function segmentLabel(): string
    {
        return self::SEGMENTS[$this->segment] ?? 'Everyone';
    }

    public function html(): string
    {
        return Str::markdown((string) $this->body, [
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * The digest row, created on first use.
     *
     * A singleton rather than a settings table: it is a campaign that
     * happens to repeat, and treating it as one means it reuses the same
     * sending, the same segments and the same unsubscribe rules.
     */
    public static function digest(): self
    {
        return static::firstOrCreate(
            ['type' => self::TYPE_DIGEST],
            [
                'title' => 'Weekly digest',
                'subject' => 'New sounds this week',
                'preheader' => 'Everything added to the catalogue in the last seven days.',
                'segment' => 'all',
                'status' => self::STATUS_DRAFT,
                'blocks' => ['sounds', 'packs', 'post'],
                'schedule_day' => 4,          // Thursday: read, unlike Monday
                'schedule_time' => '10:00',
                'is_active' => false,
            ],
        );
    }
}
