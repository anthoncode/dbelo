<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\UserSessions;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * Every session ends here, with no exception kept — unlike a password
     * CHANGE, where the browser doing the changing is spared.
     *
     * The difference is what the two mean. Changing a password is
     * housekeeping by somebody who is already in control. Resetting a
     * forgotten one is what people do when they have LOST control, and it is
     * the exact move somebody makes on discovering an intruder. Sparing any
     * existing session would be sparing the one they are trying to remove.
     *
     * This runs as the reset callback, before the framework establishes any
     * new session, so nothing legitimate is caught by it.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
        ])->save();

        $ended = UserSessions::revokeFor($user->id);

        ActivityLog::record(
            'user.password.reset_by_owner',
            $user,
            $ended > 0
                ? "Password reset — {$ended} ".str('session')->plural($ended).' ended'
                : 'Password reset',
            ['sessions_ended' => $ended],
        );
    }
}
