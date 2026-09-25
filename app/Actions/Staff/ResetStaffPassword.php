<?php

namespace App\Actions\Staff;

use App\Actions\Audit\AuditRecorder;
use App\Events\UserContextChanged;
use App\Models\User;
use App\Notifications\AdminAlert;
use App\Support\AccessRealtime;
use App\Support\AdminNotifier;
use App\Support\UserSessions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResetStaffPassword
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * Administrative reset by an active Super Admin: set a new temporary password the Super Admin chose, hashed by the
     * User cast, then end the account's other sessions and remember-me cookies. The password is never returned,
     * logged, audited or notified. A Super Admin changes their own password in Account profile instead.
     */
    public function execute(User $actor, User $staff, string $password): void
    {
        DB::transaction(function () use ($actor, $staff, $password): void {
            $actor = User::query()->whereKey($actor->getKey())->first();
            if ($actor === null || ! $actor->is_active || ! $actor->hasPermission('access_control.manage')) {
                throw new AuthorizationException('Only an active Super Admin may reset staff passwords.');
            }
            $staff = User::query()->whereKey($staff->getKey())->lockForUpdate()->firstOrFail();
            if ($staff->is($actor)) {
                throw ValidationException::withMessages(['password' => 'Change your own password from Account profile.']);
            }

            $staff->forceFill(['password' => $password])->save();
            UserSessions::invalidate($staff);
            /** Its open pages revalidate now and land on the login page instead of waiting for the next click. */
            AccessRealtime::usersChanged((int) $staff->id, UserContextChanged::STATUS);

            $this->audit->record(
                branch: null,
                actor: $actor,
                module: 'staff',
                action: 'staff.password_reset',
                auditableType: User::class,
                auditableId: (string) $staff->id,
                after: ['sessions_ended' => true],
                metadata: ['user_id' => $staff->id, 'employee_id' => $staff->employee_id, 'name' => $staff->name],
            );

            AdminNotifier::superAdmins(new AdminAlert(
                'staff',
                'Password reset for '.$staff->name,
                $actor->name.' set a new temporary password for '.$staff->name.' ('.($staff->employee_id ?? 'no Employee ID').'). Their other sessions were signed out.',
                route('super-admin.staff.index', ['search' => $staff->employee_id ?? $staff->email], false),
            ), except: $actor);
        });
    }
}
