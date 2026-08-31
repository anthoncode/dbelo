<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\UserSessions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

/**
 * Changing your own password, from the settings screen.
 *
 * The part Fortify does not do: END THE OTHER SESSIONS.
 *
 * Without it, changing a password does nothing to whoever is already signed
 * in with the old one. Their session row is still valid, and SESSION_LIFETIME
 * is refreshed by every click — so an intruder who keeps browsing is never
 * logged out at all. The person doing the only thing they know to do believes
 * they have shut the door, and nothing has happened.
 *
 * Everyone assumes this already works, because Google and their bank do it.
 */
class UpdateUserPassword implements UpdatesUserPasswords
{
    use PasswordValidationRules;

    /**
     * @param  array<string, string>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => $this->passwordRules(),
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();

        // Everything except the browser doing the changing. Signing somebody
        // out of the tab they are working in, as a reward for improving their
        // security, is how people learn not to.
        $ended = UserSessions::revokeFor($user->id, except: session()->getId());

        if ($ended > 0) {
            ActivityLog::record(
                'user.password.changed',
                $user,
                "Password changed — {$ended} other ".str('session')->plural($ended).' ended',
                ['sessions_ended' => $ended],
            );
        }
    }
}
