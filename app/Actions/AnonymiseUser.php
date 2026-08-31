<?php

namespace App\Actions;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * "Delete" a user without deleting the row.
 *
 * A sound bank cannot afford real deletes. Every download row carries a
 * frozen copy of the licence that was granted, and that row is the only
 * proof the grant ever happened. Delete the user and the proof goes with
 * them — for both sides.
 *
 * So the personal data is destroyed and everything with legal or catalogue
 * value survives:
 *
 *   Destroyed  name · email · avatar · bio · website · password ·
 *              passkeys · 2FA · Google link · favourites · collections ·
 *              open sessions
 *   Kept       uploaded sounds (now credited to "Deleted user") ·
 *              downloads with their licence snapshots · audit trail
 *
 * The username is freed, so it can be claimed by someone else. The email
 * becomes an address at `.invalid`, a TLD the DNS root will never resolve,
 * so nothing can ever be sent to it by accident.
 */
class AnonymiseUser
{
    public function __invoke(User $user): void
    {
        $this->guard($user);

        // Counted before the wipe so the audit entry can say what survived.
        $sounds = $user->sounds()->count();
        $downloads = $user->downloads()->count();

        DB::transaction(function () use ($user) {
            $this->deleteAvatar($user);

            $user->forceFill([
                'name' => 'Deleted user',
                'username' => null,
                'email' => "deleted-{$user->id}@deleted.invalid",
                'email_verified_at' => null,
                // No password hash can ever match this: the account is
                // unreachable rather than merely locked.
                'password' => Hash::make(Str::random(64)),
                'remember_token' => Str::random(60),
                'avatar_path' => null,
                'bio' => null,
                'website' => null,
                'oauth_provider' => null,
                'oauth_id' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'status' => User::STATUS_SUSPENDED,
                'suspended_at' => now(),
                'suspension_reason' => 'This account was deleted.',
                'anonymised_at' => now(),
            ])->save();

            // Preferences say what a person likes. That is exactly the kind
            // of data that has to go, and none of it is proof of anything.
            $user->favorites()->detach();

            // Personal collections are private folders and go. A featured
            // one is a public pack — dbelo's own content that happens to sit
            // under this account, and deleting it would break a live page.
            $user->collections()->where('is_featured', false)->delete();

            $user->subscriptions()
                ->whereNull('cancelled_at')
                ->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            $this->purgeCredentials($user);
        });

        // Note what is NOT in this entry: no name, no email. An audit trail
        // that keeps a copy of what was just erased is not an anonymisation.
        ActivityLog::record(
            'user.anonymised',
            $user,
            "User #{$user->id} anonymised",
            ['sounds_kept' => $sounds, 'downloads_kept' => $downloads],
        );
    }

    protected function guard(User $user): void
    {
        if ($user->id === auth()->id()) {
            throw new RuntimeException('You cannot delete your own account from here.');
        }

        if ($user->isAdmin()) {
            throw new RuntimeException('Admins cannot be deleted. Change the role to user first.');
        }

        if ($user->isAnonymised()) {
            throw new RuntimeException('This account has already been deleted.');
        }
    }

    protected function deleteAvatar(User $user): void
    {
        if (! $user->avatar_path) {
            return;
        }

        $disk = Storage::disk(config('dbelo.storage.avatars', 'public'));

        if ($disk->exists($user->avatar_path)) {
            $disk->delete($user->avatar_path);
        }
    }

    /**
     * Passkeys and open sessions are credentials, not data: leaving either
     * behind would mean the account is still reachable after "deletion".
     */
    protected function purgeCredentials(User $user): void
    {
        foreach (['passkeys', 'sessions'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('user_id', $user->id)->delete();
            }
        }
    }
}
