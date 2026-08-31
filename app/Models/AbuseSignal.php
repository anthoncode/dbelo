<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One pattern of abuse, however many requests it took.
 *
 * Grouped on write for the same reason errors are: somebody walking the
 * catalogue makes thousands of requests, and that is ONE thing happening.
 */
class AbuseSignal extends Model
{
    use Prunable;

    protected $guarded = [];

    public const KINDS = [
        'rate' => ['label' => 'Request flood', 'icon' => 'gauge-high', 'what' => 'More requests from one address than a person can make.'],
        'enumeration' => ['label' => 'Catalogue walking', 'icon' => 'list-ol', 'what' => 'Many different sound pages from one address in a short window — the shape of someone copying the catalogue.'],
        'shared_account' => ['label' => 'Shared account', 'icon' => 'users', 'what' => 'One account downloading from many addresses at once. Not a bot: a password being passed around.'],
        'credential_stuffing' => ['label' => 'Credential stuffing', 'icon' => 'key', 'what' => 'One address trying many different email addresses.'],
        'spray' => ['label' => 'Targeted guessing', 'icon' => 'crosshairs', 'what' => 'One account attacked from many addresses.'],
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function meaning(): array
    {
        return self::KINDS[$this->kind] ?? ['label' => $this->kind, 'icon' => 'shield-halved', 'what' => ''];
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * Record a sighting, grouped.
     *
     * @param  int  $observed  how many events this window saw
     */
    public static function raise(string $kind, ?string $ip, ?int $userId, string $detail, int $observed = 1): void
    {
        $fingerprint = sha1($kind.'|'.($ip ?? '').'|'.($userId ?? ''));

        $signal = static::firstOrNew(['fingerprint' => $fingerprint]);

        $signal->fill([
            'kind' => $kind,
            'ip_address' => $ip,
            'user_id' => $userId,
            'detail' => $detail,
            'last_seen_at' => now(),
        ]);

        $signal->first_seen_at ??= now();
        $signal->count = ($signal->count ?? 0) + $observed;
        $signal->peak = max((int) $signal->peak, $observed);

        // A signal somebody dismissed comes back if it happens again. The
        // point of dismissing is "this one was fine", not "never tell me".
        if ($signal->exists && $signal->status === 'reviewed') {
            $signal->status = 'open';
        }

        $signal->save();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /** Quiet for three months and already dealt with. */
    public function prunable(): Builder
    {
        return static::query()
            ->whereIn('status', ['reviewed', 'ignored'])
            ->where('last_seen_at', '<', now()->subDays(90));
    }
}
