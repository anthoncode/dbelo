<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        // Everyone who registers goes on the list, and the checkbox on the
        // form is ticked by default. Recording the exact wording they were
        // shown costs nothing now and is the only thing that makes it
        // possible to prove consent — or to migrate to strict opt-in — once
        // the country of operation is settled.
        $subscriber = Subscriber::add($user->email, [
            'user_id' => $user->id,
            'name' => $user->name,
            'source' => 'registration',
            'consent_text' => 'Send me new sounds and the occasional offer.',
            'ip_address' => request()->ip(),
        ]);

        if (! filter_var($input['newsletter'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            $subscriber->unsubscribe();
        }

        return $user;
    }
}
