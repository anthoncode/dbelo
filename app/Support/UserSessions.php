<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Ending somebody's sessions, in one place.
 *
 * Deleting the row IS the logout: the database session driver looks a
 * session up by id on the next request and finds nothing. There is no
 * gentler mechanism and none is needed.
 *
 * Auth::logoutOtherDevices() would be the framework's way, but it needs the
 * plaintext password and depends on the AuthenticateSession middleware being
 * in the stack. Deleting rows works whatever the caller has to hand, which
 * matters because the most important caller — a password RESET — never sees
 * a plaintext password at all.
 */
class UserSessions
{
    /**
     * @param  string|null  $except  session id to keep, usually the current one
     * @return int  how many were ended
     */
    public static function revokeFor(int $userId, ?string $except = null): int
    {
        try {
            if (! Schema::hasTable('sessions')) {
                return 0;
            }

            return DB::table('sessions')
                ->where('user_id', $userId)
                ->when($except, fn ($q) => $q->where('id', '!=', $except))
                ->delete();
        } catch (Throwable $e) {
            /*
             * Logged loudly, but not rethrown.
             *
             * The caller is in the middle of changing a password. Failing to
             * end the other sessions is bad; failing to change the password
             * because we could not end them is worse — the person would be
             * left with the old password AND the intruder still inside.
             */
            Log::error('Could not revoke sessions', ['user' => $userId, 'error' => $e->getMessage()]);

            return 0;
        }
    }
}
