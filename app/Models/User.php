<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string|null $username
 * @property string $email
 * @property string $role
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $avatar_path
 * @property string|null $bio
 * @property string|null $website
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'username', 'email', 'password', 'avatar_path', 'bio', 'website'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_COLLABORATOR = 'collaborator';

    public const ROLE_USER = 'user';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    // ---------------------------------------------------------------
    // Relationships
    // ---------------------------------------------------------------

    public function sounds(): HasMany
    {
        return $this->hasMany(Sound::class);
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(Download::class);
    }

    public function favorites(): BelongsToMany
    {
        return $this->belongsToMany(Sound::class, 'favorites')
            ->withPivot('created_at');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    // ---------------------------------------------------------------
    // Roles
    // ---------------------------------------------------------------

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function canUpload(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_COLLABORATOR], true);
    }

    // ---------------------------------------------------------------
    // Subscription & download quota
    // ---------------------------------------------------------------

    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->active()
            ->with('plan')
            ->latest('starts_at')
            ->first();
    }

    public function currentPlan(): ?Plan
    {
        return $this->activeSubscription()?->plan
            ?? Plan::where('slug', 'free')->first();
    }

    public function downloadsToday(): int
    {
        return $this->downloads()
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
    }

    /**
     * The whole business model in one method: a NULL daily limit means
     * unlimited, which is exactly what a paid subscription grants.
     */
    public function canDownload(?Sound $sound = null): bool
    {
        $plan = $this->currentPlan();

        if (! $plan) {
            return false;
        }

        if ($sound?->is_premium && ! $plan->allows_premium) {
            return false;
        }

        if ($plan->isUnlimited()) {
            return true;
        }

        return $this->downloadsToday() < $plan->daily_download_limit;
    }

    public function remainingDownloadsToday(): ?int
    {
        $plan = $this->currentPlan();

        if (! $plan || $plan->isUnlimited()) {
            return null;   // null = unlimited
        }

        return max(0, $plan->daily_download_limit - $this->downloadsToday());
    }
}
