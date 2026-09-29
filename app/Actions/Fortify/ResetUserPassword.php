<?php

namespace App\Actions\Fortify;

use App\Actions\Audit\AuditRecorder;
use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Support\UserSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private AuditRecorder $audit) {}

    /**
     * Validate and reset the user's forgotten password. Every signed-in browser and push device of the account ends
     * with the old password (whoever held it may not be the owner), and the reset is audited without password material.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        DB::transaction(function () use ($user, $input): void {
            $user->forceFill([
                'password' => $input['password'],
            ])->save();
            UserSessions::invalidate($user);
            $this->audit->record(
                branch: null,
                actor: $user,
                module: 'account',
                action: 'account.password_reset_by_link',
                auditableType: User::class,
                auditableId: (string) $user->id,
            );
        });
    }
}
