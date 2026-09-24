<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ends another account's signed-in sessions after an administrative password reset or deactivation, without touching
 * anyone else. Three layers, matching the real session model:
 *
 * - the remember-me token is rotated, so every long-lived "remember me" cookie of that account stops working;
 * - with the database session driver, that account's session rows are deleted;
 * - with any driver, `AuthenticateSession` (web middleware) logs out a session whose stored password hash no longer
 *   matches, and `EnsureUserIsActive` logs out an inactive account on its next request.
 */
class UserSessions
{
    /** Rotate the remember token inside the caller's transaction; delete stored sessions only after it commits. */
    public static function invalidate(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->save();
        $userId = (int) $user->getKey();

        DB::afterCommit(function () use ($userId): void {
            if (config('session.driver') !== 'database') {
                return;
            }
            DB::connection(config('session.connection'))
                ->table((string) config('session.table', 'sessions'))
                ->where('user_id', $userId)
                ->delete();
        });
    }
}
