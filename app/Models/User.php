<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
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
 * @property string $status
 * @property Carbon|null $email_verified_at
 * @property string|null $oauth_provider
 * @property string|null $oauth_id
 * @property Carbon|null $anonymised_at
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
/**
 * ── WHY MustVerifyEmail IS ON THE CLASS ──────────────────────────────────
 *
 * It was the commented-out import Laravel ships with, and leaving it that
 * way made the whole verification feature a decoration.
 *
 * The METHODS were always here: hasVerifiedEmail(), markEmailAsVerified()
 * and sendEmailVerificationNotification() come from a trait inside
 * Illuminate\Foundation\Auth\User, which is why nothing ever crashed and
 * why the resend button on /email/verify worked. What was missing is the
 * INTERFACE — and the interface is what Laravel's SendEmailVerificationNotification
 * listener checks before sending anything on the Registered event.
 *
 * So: config/fortify.php had Features::emailVerification() on, the download
 * gate had `verify_email` on by default, and the screen told people "we
 * have sent you a link" — while nobody was ever sent one. Three switches
 * agreeing with each other and one class not implementing the contract
 * they all depend on.
 *
 * A Google account never sees any of this. GoogleAuthController stamps
 * email_verified_at at creation and never fires Registered, and the
 * listener checks hasVerifiedEmail() too — Google confirmed the address
 * before it reached us, and asking again would be asking twice.
 */
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_COLLABORATOR = 'collaborator';

    public const ROLE_USER = 'user';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

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
            'suspended_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'anonymised_at' => 'datetime',
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

    public function collections(): HasMany
    {
        return $this->hasMany(Collection::class);
    }

    // ---------------------------------------------------------------
    // Account status
    // ---------------------------------------------------------------

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Signing in with Google is itself proof of the address: Google would
     * not hand over an account whose email it had not already confirmed.
     */
    public function isVerified(): bool
    {
        return $this->email_verified_at !== null || $this->oauth_provider !== null;
    }

    /**
     * How this account proved it is real: 'google', 'email', or null when
     * it never did. The admin needs the difference — an unverified email
     * account is someone to chase, a Google account is nothing to chase.
     */
    public function verificationSource(): ?string
    {
        if ($this->oauth_provider) {
            return $this->oauth_provider;
        }

        return $this->email_verified_at ? 'email' : null;
    }

    public function isAnonymised(): bool
    {
        return $this->anonymised_at !== null;
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeSuspended($query)
    {
        return $query->where('status', self::STATUS_SUSPENDED);
    }

    /** Deleted accounts are still rows; almost no screen wants to see them. */
    public function scopeReal($query)
    {
        return $query->whereNull('anonymised_at');
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
        return ! $this->isSuspended()
            && in_array($this->role, [self::ROLE_ADMIN, self::ROLE_COLLABORATOR], true);
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
        if ($this->isSuspended()) {
            return false;
        }

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
