<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Subscriber extends Model
{
    protected $guarded = [];

    public const STATUS_SUBSCRIBED = 'subscribed';

    public const STATUS_UNSUBSCRIBED = 'unsubscribed';

    public const STATUS_BOUNCED = 'bounced';

    protected function casts(): array
    {
        return [
            'wants_digest' => 'boolean',
            'wants_promos' => 'boolean',
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Subscriber $subscriber) {
            $subscriber->token ??= Str::random(48);
            $subscriber->subscribed_at ??= now();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ---------------------------------------------------------------
    // Scopes
    // ---------------------------------------------------------------

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUBSCRIBED);
    }

    /**
     * Who should get this kind of email.
     *
     * A person who turned off offers but kept the weekly sounds is still an
     * active subscriber — asking for the status alone would email them
     * anyway, which is exactly the breach of trust that produces a spam
     * report rather than an unsubscribe.
     */
    public function scopeWanting(Builder $query, string $type): Builder
    {
        return $query->active()->where(
            $type === Campaign::TYPE_DIGEST ? 'wants_digest' : 'wants_promos',
            true
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function isSubscribed(): bool
    {
        return $this->status === self::STATUS_SUBSCRIBED;
    }

    public function unsubscribeUrl(): string
    {
        return route('newsletter.unsubscribe', $this->token);
    }

    public function unsubscribe(?string $only = null): void
    {
        // "Only offers" keeps half the relationship instead of losing it.
        if ($only === Campaign::TYPE_PROMO) {
            $this->update(['wants_promos' => false]);

            return;
        }

        if ($only === Campaign::TYPE_DIGEST) {
            $this->update(['wants_digest' => false]);

            return;
        }

        $this->update([
            'status' => self::STATUS_UNSUBSCRIBED,
            'wants_digest' => false,
            'wants_promos' => false,
            'unsubscribed_at' => now(),
        ]);
    }

    public function resubscribe(): void
    {
        $this->update([
            'status' => self::STATUS_SUBSCRIBED,
            'wants_digest' => true,
            'wants_promos' => true,
            'unsubscribed_at' => null,
            'subscribed_at' => $this->subscribed_at ?? now(),
        ]);
    }

    /**
     * Add someone, or bring back a row that already exists.
     *
     * Never resurrects an unsubscribe: someone who opted out and later
     * appears in an import stays out. That is the one rule in this file
     * that must not have an exception.
     */
    public static function add(string $email, array $attributes = []): self
    {
        $email = Str::lower(trim($email));

        $subscriber = static::firstOrNew(['email' => $email]);

        if (! $subscriber->exists) {
            $subscriber->fill($attributes)->save();
        }

        return $subscriber;
    }
}
