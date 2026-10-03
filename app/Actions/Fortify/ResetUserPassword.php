<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $wasVerified = $user->hasVerifiedEmail();
        $user->forceFill([
            'password' => $input['password'],
            // A validated password-reset/invitation token proves access to this email address.
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        if (! $wasVerified) {
            event(new Verified($user));
        }
    }
}
